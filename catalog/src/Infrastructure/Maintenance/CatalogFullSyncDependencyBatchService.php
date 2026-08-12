<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Refreshes a bounded batch of Full Sync dependency owners in one HTTP request.
 * Why: A game with tens of thousands of packages must not require one request per dependency owner; batching removes
 *      HTTP/session/bootstrap overhead while preserving per-file compact locks and per-package failure isolation.
 * Role: Full Sync maintenance service for the dependency-resolution phase only.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class CatalogFullSyncDependencyBatchService
{
    public const MAX_BATCH_SIZE = 100;
    private const DEADLOCK_RETRIES = 3;

    /**
     * @param array<string,mixed> $config
     * @param null|callable(array<string,mixed>):void $progress
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly mixed $progress = null,
        private readonly bool $recordFailures = true
    ) {
    }

    /**
     * @param list<int> $fileIds
     * @return array<string,mixed>
     */
    public function refresh(int $gameId, array $fileIds): array
    {
        if ($gameId < 1) {
            throw new RuntimeException('A valid game ID is required for dependency batch refresh.');
        }

        $fileIds = $this->normalizeFileIds($fileIds);
        if ($fileIds === []) {
            throw new RuntimeException('Dependency batch refresh requires at least one file ID.');
        }
        if (count($fileIds) > self::MAX_BATCH_SIZE) {
            throw new RuntimeException(
                'Dependency batch is too large: maximum ' . self::MAX_BATCH_SIZE . ' files per request.'
            );
        }

        $game = $this->db->prepare('SELECT id FROM ue_games WHERE id=?');
        $game->execute([$gameId]);
        if ($game->fetchColumn() === false) {
            throw new RuntimeException('Full Sync game no longer exists.');
        }

        $files = $this->filesForBatch($gameId, $fileIds);
        $rebuilder = new PdoCatalogDependencyRebuilder($this->db, $this->config);
        $total = count($fileIds);
        $succeeded = 0;
        $metadataRepairs = 0;
        $failures = [];
        $progressStride = max(1, (int)ceil($total / 20));

        $this->emit(0, $total, 'Starting dependency batch of ' . $total . ' package(s).');

        foreach ($fileIds as $index => $fileId) {
            $position = $index + 1;
            $file = $files[$fileId] ?? null;
            $name = is_array($file)
                ? (string)($file['original_name'] ?? ('file #' . $fileId))
                : ('file #' . $fileId);
            $failed = false;

            if (!is_array($file)) {
                $failed = true;
                $message = 'The package is no longer a verified file in the selected game.';
                $failures[] = [
                    'file_id' => $fileId,
                    'original_name' => $name,
                    'error' => $message,
                ];
                if ($this->recordFailures) {
                    $this->recordFailure($gameId, $fileId, $name, $position, $total, $message);
                }
            } else {
                try {
                    $repaired = $this->refreshOne(
                        $rebuilder,
                        $file,
                        $position,
                        $total,
                        $name
                    );
                    if ($repaired) {
                        $metadataRepairs++;
                    }
                    $succeeded++;
                } catch (Throwable $error) {
                    $failed = true;
                    $failures[] = [
                        'file_id' => $fileId,
                        'original_name' => $name,
                        'error' => $error->getMessage(),
                    ];
                    if ($this->recordFailures) {
                        $this->recordFailure(
                            $gameId,
                            $fileId,
                            $name,
                            $position,
                            $total,
                            $error->getMessage(),
                            $error
                        );
                        error_log(
                            '[UnrealDB Full Sync dependency batch] file_id=' . $fileId
                            . ' error=' . $error->getMessage()
                        );
                    }
                }
            }

            if ($failed || $position === $total || ($position % $progressStride) === 0) {
                $this->emit(
                    $position,
                    $total,
                    ($failed ? 'Dependency refresh failed for ' : 'Dependency-refreshed ')
                        . $position . '/' . $total . ': ' . $name
                        . ($metadataRepairs > 0 ? '; compact metadata repaired=' . $metadataRepairs : '')
                );
            }
        }

        return [
            'ok' => true,
            'game_id' => $gameId,
            'requested' => $total,
            'processed' => $total,
            'succeeded' => $succeeded,
            'failed' => count($failures),
            'metadata_repairs' => $metadataRepairs,
            'failures' => $failures,
            'summary_refresh_deferred' => true,
            'message' => 'Dependency batch complete: ' . $succeeded . '/' . $total
                . ' refreshed; compact metadata repaired=' . $metadataRepairs
                . '; package summaries deferred to Full Sync finalization.',
        ];
    }

    /** @param array<string,mixed> $file */
    private function refreshOne(
        PdoCatalogDependencyRebuilder $rebuilder,
        array $file,
        int $position,
        int $total,
        string $name
    ): bool {
        $fileId = (int)$file['id'];
        try {
            $this->runDependencyRebuild($rebuilder, $fileId, $position, $total, $name);
            return false;
        } catch (Throwable $error) {
            if (!$this->isCompactMetadataIntegrityFailure($error)) {
                throw $error;
            }

            $this->emit(
                max(0, $position - 1),
                $total,
                'Compact metadata is unreadable for ' . $position . '/' . $total . ': ' . $name
                    . '; reparsing the authoritative stored package before retrying dependencies.'
            );

            $maintenance = new CatalogFileMaintenanceActionService(
                $this->db,
                $this->config,
                null,
                null
            );
            $maintenance->execute('sync_reimport', [
                'file_id' => $fileId,
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
                'md5' => (string)$file['md5'],
                'package_guid' => (string)($file['package_guid'] ?? ''),
            ]);

            clearstatcache();
            $this->runDependencyRebuild($rebuilder, $fileId, $position, $total, $name);
            return true;
        }
    }

    private function runDependencyRebuild(
        PdoCatalogDependencyRebuilder $rebuilder,
        int $fileId,
        int $position,
        int $total,
        string $name
    ): void {
        clearstatcache();
        $this->retryDeadlock(
            static function () use ($rebuilder, $fileId, $name): void {
                $rebuilder->rebuild(
                    $fileId,
                    null,
                    0,
                    100,
                    'Full Sync batch dependency refresh for ' . $name,
                    false
                );
            },
            $position,
            $total,
            $name
        );
    }

    private function isCompactMetadataIntegrityFailure(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        foreach ([
            'blocked metadata file size mismatch',
            'blocked metadata container',
            'blocked metadata manifest',
            'blocked metadata section',
            'no compressed metadata row',
            'not using blocked metadata format version 2',
            'unsupported blocked metadata codec',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $fileIds @return array<int,array<string,mixed>> */
    private function filesForBatch(int $gameId, array $fileIds): array
    {
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,md5,package_guid FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" AND id IN (' . $placeholders . ')'
        );
        $statement->execute([$gameId, ...$fileIds]);

        $files = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $files[(int)$row['id']] = $row;
        }
        return $files;
    }

    /** @param list<int> $fileIds @return list<int> */
    private function normalizeFileIds(array $fileIds): array
    {
        $normalized = [];
        foreach ($fileIds as $fileId) {
            $id = (int)$fileId;
            if ($id < 1) {
                throw new RuntimeException('Dependency batch contains an invalid file ID.');
            }
            $normalized[$id] = true;
        }
        return array_map('intval', array_keys($normalized));
    }

    private function recordFailure(
        int $gameId,
        int $fileId,
        string $name,
        int $position,
        int $total,
        string $message,
        ?Throwable $error = null
    ): void {
        CatalogSystemErrorRecorder::record([
            'source_kind' => 'full-sync',
            'severity' => 'error',
            'error_type' => $error instanceof Throwable ? get_class($error) : 'dependency_file_unavailable',
            'message' => 'Dependency refresh failed for ' . $name . ': ' . $message,
            'source_file' => $error instanceof Throwable ? $error->getFile() : __FILE__,
            'source_line' => $error instanceof Throwable ? $error->getLine() : __LINE__,
            'trace_text' => $error instanceof Throwable ? $error->getTraceAsString() : '',
            'context' => [
                'operation' => 'sync_refresh_dependencies_batch',
                'game_id' => $gameId,
                'file_id' => $fileId,
                'original_name' => $name,
                'batch_position' => $position,
                'batch_total' => $total,
            ],
        ]);
    }

    private function retryDeadlock(
        callable $operation,
        int $position,
        int $total,
        string $name
    ): void {
        for ($attempt = 1; $attempt <= self::DEADLOCK_RETRIES; $attempt++) {
            try {
                $operation();
                return;
            } catch (Throwable $error) {
                if (!$this->isDeadlock($error) || $attempt === self::DEADLOCK_RETRIES) {
                    throw $error;
                }
                $this->emit(
                    max(0, $position - 1),
                    $total,
                    'Database conflict while refreshing ' . $position . '/' . $total . ': ' . $name
                        . '; retrying (' . $attempt . '/' . self::DEADLOCK_RETRIES . ').'
                );
                usleep(250000 * $attempt);
            }
        }
    }

    private function isDeadlock(Throwable $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return $code === '40001'
            || str_contains($message, 'deadlock found')
            || str_contains($message, 'serialization failure')
            || str_contains($message, 'error: 1213');
    }

    private function emit(int $done, int $total, string $message): void
    {
        if ($this->progress === null) {
            return;
        }
        $total = max(1, $total);
        $done = max(0, min($done, $total));
        ($this->progress)([
            'stage' => 'dependency_batch',
            'done' => $done,
            'total' => $total,
            'percent' => (int)floor(($done * 100) / $total),
            'message' => $message,
        ]);
    }
}
