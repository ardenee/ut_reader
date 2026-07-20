<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
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
        if ($rows === []) {
            return ['deleted_jobs' => 0, 'deleted_staged_files' => 0, 'limited' => false];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $this->deleteIds($ids);
        $deletedFiles = $this->deleteStagedFiles($rows);

        return [
            'deleted_jobs' => count($ids),
            'deleted_staged_files' => $deletedFiles,
            'limited' => count($ids) >= self::MAX_BULK_DELETE,
        ];
    }

    /** @return array{deleted_jobs:int,deleted_staged_files:int} */
    public function deleteTerminalJob(int $jobId): array
    {
        if ($jobId < 1) {
            return ['deleted_jobs' => 0, 'deleted_staged_files' => 0];
        }

        $statement = $this->db->prepare(
            'SELECT id,payload_json FROM ue_background_jobs '
            . 'WHERE id=? AND status IN ("completed","failed","dead_letter","cancelled") LIMIT 1'
        );
        $statement->execute([$jobId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['deleted_jobs' => 0, 'deleted_staged_files' => 0];
        }

        $this->deleteIds([$jobId]);
        return [
            'deleted_jobs' => 1,
            'deleted_staged_files' => $this->deleteStagedFiles([$row]),
        ];
    }

    /** @param list<int> $ids */
    private function deleteIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->db->beginTransaction();
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $statement = $this->db->prepare(
                    'DELETE FROM ue_background_jobs WHERE id IN (' . $placeholders . ') '
                    . 'AND status IN ("completed","failed","dead_letter","cancelled")'
                );
                $statement->execute($chunk);
            }
            $this->db->commit();
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
        $deleted = 0;
        foreach ($rows as $row) {
            $payload = $this->decodePayload((string)($row['payload_json'] ?? ''));
            $relativePath = trim((string)($payload['staged_path'] ?? ''));
            if ($relativePath === '') {
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

    private function queueName(string $queueName): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || strlen($queueName) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/', $queueName) !== 1) {
            throw new \InvalidArgumentException('A valid queue name is required.');
        }
        return $queueName;
    }
}
