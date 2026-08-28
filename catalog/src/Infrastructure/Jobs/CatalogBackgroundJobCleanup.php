<?php
/**
 * Bounded deletion of background-job history and the staged sources owned by it.
 *
 * Job rows are removed first, then staged-source candidates from those deleted
 * rows are reclaimed only when no surviving restartable/recovery job still
 * references the same staged_path.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use UnrealDb\Catalog\Infrastructure\Import\CatalogChunkedUploadCleanup;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

final class CatalogBackgroundJobCleanup
{
    private const MAX_BULK_DELETE = 10000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int,limited:bool} */
    public function cleanup(string $queueName, int $retentionDays): array
    {
        $queueName = $this->queueName($queueName);
        $retentionDays = max(1, min($retentionDays, 3650));
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $retentionDays . ' days')
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'SELECT id,payload_json FROM ue_background_jobs '
            . 'WHERE queue_name=? AND status IN ("completed","failed","dead_letter","cancelled") '
            . 'AND COALESCE(completed_at,dead_lettered_at,updated_at,created_at)<? '
            . 'ORDER BY id LIMIT ' . self::MAX_BULK_DELETE
        );
        $statement->execute([$queueName, $cutoff]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->deleteRows($rows, count($rows) >= self::MAX_BULK_DELETE, true);
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int} */
    public function deleteTerminalJob(int $jobId): array
    {
        $result = $this->deleteTerminalJobs([$jobId]);
        return [
            'deleted_jobs' => $result['deleted_jobs'],
            'deleted_staged_files' => $result['deleted_staged_files'],
            'deleted_staged_bytes' => $result['deleted_staged_bytes'],
        ];
    }

    /**
     * @param list<int> $jobIds
     * @return array{requested_jobs:int,deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int,skipped_jobs:int}
     */
    public function deleteTerminalJobs(array $jobIds, string $queueName = ''): array
    {
        $ids = $this->jobIds($jobIds);
        if ($ids === []) {
            return [
                'requested_jobs' => 0,
                'deleted_jobs' => 0,
                'deleted_staged_files' => 0,
                'deleted_staged_bytes' => 0,
                'skipped_jobs' => 0,
            ];
        }

        $queueName = trim($queueName) !== '' ? $this->queueName($queueName) : '';
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT id,payload_json FROM ue_background_jobs WHERE id IN (' . $placeholders . ') '
                . 'AND status IN ("completed","failed","dead_letter","cancelled")';
            $params = $chunk;
            if ($queueName !== '') {
                $sql .= ' AND queue_name=?';
                $params[] = $queueName;
            }
            $statement = $this->db->prepare($sql);
            $statement->execute($params);
            array_push($rows, ...($statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
        }

        $result = $this->deleteRows($rows, false, true);
        return [
            'requested_jobs' => count($ids),
            'deleted_jobs' => $result['deleted_jobs'],
            'deleted_staged_files' => $result['deleted_staged_files'],
            'deleted_staged_bytes' => $result['deleted_staged_bytes'],
            'skipped_jobs' => max(0, count($ids) - $result['deleted_jobs']),
        ];
    }

    /**
     * Delete already-observed hidden workflow leaves. These rows are descendants
     * of an operator-visible terminal root, so they do not need the root-status
     * guard, but their staged source/event log still needs normal cleanup.
     *
     * @param list<int> $jobIds
     * @return array{deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int}
     */
    public function deleteWorkflowJobs(array $jobIds): array
    {
        $ids = $this->jobIds($jobIds);
        if ($ids === []) {
            return ['deleted_jobs' => 0, 'deleted_staged_files' => 0, 'deleted_staged_bytes' => 0];
        }

        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT id,payload_json FROM ue_background_jobs WHERE id IN (' . $placeholders . ')'
            );
            $statement->execute($chunk);
            array_push($rows, ...($statement->fetchAll(PDO::FETCH_ASSOC) ?: []));
        }

        $result = $this->deleteRows($rows, false, false);
        return [
            'deleted_jobs' => $result['deleted_jobs'],
            'deleted_staged_files' => $result['deleted_staged_files'],
            'deleted_staged_bytes' => $result['deleted_staged_bytes'],
        ];
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int,limited:bool} */
    public function deleteTerminalMatching(string $queueName, string $status = ''): array
    {
        $queueName = $this->queueName($queueName);
        $status = strtolower(trim($status));
        if ($status !== '' && !CatalogJobDisplayStatus::isValidFilter($status)) {
            throw new \InvalidArgumentException('The selected status is not supported.');
        }
        if (in_array($status, ['queued', 'running'], true)) {
            throw new \InvalidArgumentException('Queued and running jobs cannot be deleted in bulk.');
        }

        $sql = 'SELECT id,payload_json FROM ue_background_jobs WHERE queue_name=? '
            . 'AND status IN ("completed","failed","dead_letter","cancelled")';
        $params = [$queueName];
        if ($status !== '') {
            $condition = CatalogJobDisplayStatus::filterCondition($status);
            $sql .= ' AND ' . $condition['sql'];
            array_push($params, ...$condition['params']);
        }
        $sql .= ' ORDER BY id LIMIT ' . self::MAX_BULK_DELETE;
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->deleteRows($rows, count($rows) >= self::MAX_BULK_DELETE, true);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{deleted_jobs:int,deleted_staged_files:int,deleted_staged_bytes:int,limited:bool}
     */
    private function deleteRows(array $rows, bool $limited, bool $terminalOnly): array
    {
        if ($rows === []) {
            return [
                'deleted_jobs' => 0,
                'deleted_staged_files' => 0,
                'deleted_staged_bytes' => 0,
                'limited' => $limited,
            ];
        }

        $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $rows)));
        $deletedIds = $this->deleteIds($ids, $terminalOnly);
        $deletedLookup = array_fill_keys($deletedIds, true);
        $deletedRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($deletedLookup[(int)$row['id']])
        ));
        $staged = $this->deleteStagedFiles($deletedRows);

        return [
            'deleted_jobs' => count($deletedIds),
            'deleted_staged_files' => $staged['files'],
            'deleted_staged_bytes' => $staged['bytes'],
            'limited' => $limited,
        ];
    }

    /** @param list<int> $ids @return list<int> */
    private function deleteIds(array $ids, bool $terminalOnly): array
    {
        if ($ids === []) {
            return [];
        }

        $deletedIds = [];
        $this->db->beginTransaction();
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $selectSql = 'SELECT id FROM ue_background_jobs WHERE id IN (' . $placeholders . ')';
                if ($terminalOnly) {
                    $selectSql .= ' AND status IN ("completed","failed","dead_letter","cancelled")';
                }
                $select = $this->db->prepare($selectSql . ' FOR UPDATE');
                $select->execute($chunk);
                $lockedIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($lockedIds === []) {
                    continue;
                }

                $lockedPlaceholders = implode(',', array_fill(0, count($lockedIds), '?'));
                $delete = $this->db->prepare(
                    'DELETE FROM ue_background_jobs WHERE id IN (' . $lockedPlaceholders . ')'
                );
                $delete->execute($lockedIds);
                array_push($deletedIds, ...$lockedIds);
            }
            $this->db->commit();
            return array_values(array_unique($deletedIds));
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{files:int,bytes:int}
     */
    private function deleteStagedFiles(array $rows): array
    {
        if ($rows === []) {
            return ['files' => 0, 'bytes' => 0];
        }

        $eventLog = new CatalogJobEventLog($this->config);
        $candidates = [];
        foreach ($rows as $row) {
            try {
                $eventLog->remove((int)($row['id'] ?? 0));
            } catch (\Throwable) {
                // Event logs are auxiliary and must not block job cleanup.
            }

            $payload = $this->decodePayload((string)($row['payload_json'] ?? ''));
            $relativePath = trim((string)($payload['staged_path'] ?? ''));
            if ($relativePath !== '') {
                $candidates[$relativePath] = true;
            }
        }

        if ($candidates === []) {
            return ['files' => 0, 'bytes' => 0];
        }

        $protected = $this->protectedStagedPaths(array_keys($candidates));
        $store = new CatalogIncomingFileStore($this->config);
        $chunkCleanup = new CatalogChunkedUploadCleanup($this->config);
        $files = 0;
        $bytes = 0;

        foreach (array_keys($candidates) as $relativePath) {
            if (isset($protected[$relativePath])) {
                continue;
            }
            if (str_starts_with($relativePath, 'local-pak:')
                || str_starts_with($relativePath, 'local-catalog:')) {
                continue;
            }

            if (str_starts_with($relativePath, 'chunk-upload:')) {
                $uploadId = substr($relativePath, strlen('chunk-upload:'));
                try {
                    $stats = $chunkCleanup->deleteWithStats($uploadId);
                    if ($stats['deleted']) {
                        $files++;
                        $bytes += max(0, (int)$stats['bytes']);
                    }
                } catch (\Throwable) {
                    // Missing/already-pruned chunk stores do not block job cleanup.
                }
                continue;
            }

            try {
                $path = $store->resolve($relativePath);
                $size = max(0, (int)(filesize($path) ?: 0));
                $store->delete($relativePath);
                if (!is_file($path)) {
                    $files++;
                    $bytes += $size;
                }
            } catch (\Throwable) {
                // Missing/already-pruned staged inputs do not block job cleanup.
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * A failed/dead-letter/cancelled job may be retried, and a completed job can
     * explicitly retain its source for recovery. Never remove a staged source
     * while any such surviving job still references it.
     *
     * @param list<string> $paths
     * @return array<string,true>
     */
    private function protectedStagedPaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map(static fn(string $path): string => trim($path), $paths),
            static fn(string $path): bool => $path !== ''
        )));
        if ($paths === []) {
            return [];
        }

        $protected = [];
        foreach (array_chunk($paths, 250) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->prepare(
                'SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.staged_path")) staged_path '
                . 'FROM ue_background_jobs WHERE JSON_VALID(payload_json)=1 '
                . 'AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.staged_path")) IN (' . $placeholders . ') '
                . 'AND (status IN ("queued","running","failed","dead_letter","cancelled") '
                . 'OR (status="completed" AND JSON_VALID(result_json)=1 '
                . 'AND JSON_UNQUOTE(JSON_EXTRACT(result_json,"$.source_retained")) IN ("true","1")))'
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $path) {
                $value = trim((string)$path);
                if ($value !== '') {
                    $protected[$value] = true;
                }
            }
        }
        return $protected;
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param list<int> $jobIds @return list<int> */
    private function jobIds(array $jobIds): array
    {
        $ids = [];
        foreach ($jobIds as $jobId) {
            $id = (int)$jobId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (count($ids) > self::MAX_BULK_DELETE) {
            throw new \InvalidArgumentException('No more than ' . self::MAX_BULK_DELETE . ' jobs can be deleted at once.');
        }
        return $ids;
    }

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queueName;
    }
}
