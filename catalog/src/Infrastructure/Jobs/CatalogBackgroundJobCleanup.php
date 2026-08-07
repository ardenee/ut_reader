<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogBackgroundJobCleanup` for catalog background job cleanup.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
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
    private const TERMINAL_STATUSES = ['completed', 'failed', 'dead_letter', 'cancelled'];
    private const MAX_BULK_DELETE = 10000;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int,limited:bool} */
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
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $this->deleteRows($rows, count($rows) >= self::MAX_BULK_DELETE);
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int} */
    public function deleteTerminalJob(int $jobId): array
    {
        $result = $this->deleteTerminalJobs([$jobId]);
        return [
            'deleted_jobs' => $result['deleted_jobs'],
            'deleted_staged_files' => $result['deleted_staged_files'],
        ];
    }

    /**
     * @param list<int> $jobIds
     * @return array{requested_jobs:int,deleted_jobs:int,deleted_staged_files:int,skipped_jobs:int}
     */
    public function deleteTerminalJobs(array $jobIds, string $queueName = ''): array
    {
        $ids = $this->jobIds($jobIds);
        if ($ids === []) {
            return [
                'requested_jobs' => 0,
                'deleted_jobs' => 0,
                'deleted_staged_files' => 0,
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
            array_push($rows, ...$statement->fetchAll(PDO::FETCH_ASSOC));
        }
        $result = $this->deleteRows($rows, false);
        return [
            'requested_jobs' => count($ids),
            'deleted_jobs' => $result['deleted_jobs'],
            'deleted_staged_files' => $result['deleted_staged_files'],
            'skipped_jobs' => max(0, count($ids) - $result['deleted_jobs']),
        ];
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int,limited:bool} */
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
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $this->deleteRows($rows, count($rows) >= self::MAX_BULK_DELETE);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{deleted_jobs:int,deleted_staged_files:int,limited:bool}
     */
    private function deleteRows(array $rows, bool $limited): array
    {
        if ($rows === []) {
            return ['deleted_jobs' => 0, 'deleted_staged_files' => 0, 'limited' => $limited];
        }
        $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $rows)));
        $deletedIds = $this->deleteIds($ids);
        $deletedLookup = array_fill_keys($deletedIds, true);
        $deletedRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($deletedLookup[(int)$row['id']])
        ));
        return [
            'deleted_jobs' => count($deletedIds),
            'deleted_staged_files' => $this->deleteStagedFiles($deletedRows),
            'limited' => $limited,
        ];
    }

    /** @param list<int> $ids @return list<int> */
    private function deleteIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $deletedIds = [];
        $this->db->beginTransaction();
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $select = $this->db->prepare(
                    'SELECT id FROM ue_background_jobs WHERE id IN (' . $placeholders . ') '
                    . 'AND status IN ("completed","failed","dead_letter","cancelled") FOR UPDATE'
                );
                $select->execute($chunk);
                $lockedIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
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

    /** @param list<array<string,mixed>> $rows */
    private function deleteStagedFiles(array $rows): int
    {
        $store = new CatalogIncomingFileStore($this->config);
        $chunkCleanup = new CatalogChunkedUploadCleanup($this->config);
        $eventLog = new CatalogJobEventLog($this->config);
        $deleted = 0;
        foreach ($rows as $row) {
            try {
                $eventLog->remove((int)($row['id'] ?? 0));
            } catch (\Throwable) {
                // Event logs are auxiliary and must not block job cleanup.
            }
            $payload = $this->decodePayload((string)($row['payload_json'] ?? ''));
            $relativePath = trim((string)($payload['staged_path'] ?? ''));
            if ($relativePath === '') {
                continue;
            }
            if (str_starts_with($relativePath, 'chunk-upload:')) {
                $uploadId = substr($relativePath, strlen('chunk-upload:'));
                try {
                    if ($chunkCleanup->delete($uploadId)) {
                        $deleted++;
                    }
                } catch (\Throwable) {
                    // Missing or already-pruned chunk stores do not block cleanup.
                }
                continue;
            }
            try {
                $path = $store->resolve($relativePath);
                $store->delete($relativePath);
                if (!is_file($path)) {
                    $deleted++;
                }
            } catch (\Throwable) {
                // Missing or already-pruned staged inputs do not block job cleanup.
            }
        }
        return $deleted;
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
