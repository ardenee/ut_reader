<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GameBackupImportJobHandler` for game backup import job handler.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;

/**
 * Restores every entry in a game backup and retains every per-file result.
 * No failure-count cap is permitted because backup restore is also a complete
 * file-validation operation.
 */
final class GameBackupImportJobHandler implements JobHandler
{
    private const MANIFEST_VERSION = 1;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::IMPORT_GAME_BACKUP;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type !== JobType::IMPORT_GAME_BACKUP) {
            throw new \RuntimeException('Unsupported game-backup import job: ' . $job->type);
        }

        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';

        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || (bool)$job->payload['strict_profile'];
        $rebuildDependencies = !array_key_exists('rebuild_dependencies', $job->payload)
            || (bool)$job->payload['rebuild_dependencies'];

        $store = new GameBackupStore($this->config);
        $manifest = $store->readManifest($backupKey);
        if ((string)($manifest['format'] ?? '') !== 'unrealdb-game-backup'
            || (int)($manifest['format_version'] ?? 0) !== self::MANIFEST_VERSION
            || (string)($manifest['status'] ?? '') !== 'complete') {
            throw new \RuntimeException('The selected backup is incomplete or uses an unsupported manifest format.');
        }

        $game = \catalog_one($this->db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new \RuntimeException('Target game no longer exists: ' . $gameId);
        }

        $entries = is_array($manifest['files'] ?? null) ? array_values($manifest['files']) : [];
        usort($entries, static function (array $a, array $b): int {
            $aliasOrder = ((int)!empty($a['is_alias'])) <=> ((int)!empty($b['is_alias']));
            return $aliasOrder !== 0
                ? $aliasOrder
                : ((int)($a['file_id'] ?? 0) <=> (int)($b['file_id'] ?? 0));
        });
        if ($entries === []) {
            throw new \RuntimeException('The selected backup manifest contains no files.');
        }

        $tempDirectory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'game-backup-import';
        if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0750, true) && !is_dir($tempDirectory)) {
            throw new \RuntimeException('Could not create game-backup import workspace.');
        }

        $imported = 0;
        $duplicates = 0;
        $aliases = 0;
        $failed = 0;
        $errors = [];
        $total = count($entries);

        foreach ($entries as $index => $entry) {
            $originalName = (string)($entry['original_name'] ?? 'package.bin');
            $relative = (string)($entry['exported_relative_path'] ?? '');
            $sourceRelative = trim(str_replace('\\', '/', (string)($entry['source_relative_path'] ?? '')), '/');
            if ($sourceRelative === '') {
                $sourceRelative = $relative;
            } else {
                $slash = strrpos($sourceRelative, '/');
                $sourceRelative = ($slash === false ? '' : substr($sourceRelative, 0, $slash + 1)) . $originalName;
            }

            $context->checkpoint([
                'stage' => 'importing',
                'done' => $index,
                'total' => $total,
                'percent' => 2 + (int)floor(($index * 86) / max(1, $total)),
                'message' => 'Importing backup file ' . ($index + 1) . '/' . $total . ': ' . $originalName,
                'imported' => $imported,
                'duplicates' => $duplicates,
                'aliases' => $aliases,
                'failed' => $failed,
                'validated' => $index,
            ]);

            $temporary = '';
            try {
                $source = $store->resolveBackupFile($backupKey, $relative);
                $this->verifySource($source, (int)($entry['file_size'] ?? 0), (string)($entry['md5'] ?? ''));
                $temporary = tempnam($tempDirectory, 'restore-' . $job->id . '-');
                if ($temporary === false || !copy($source, $temporary)) {
                    throw new \RuntimeException('Could not create an import working copy.');
                }

                $result = \scanner_scan_uploaded_file(
                    $this->db,
                    $this->config,
                    $gameId,
                    $temporary,
                    $originalName,
                    $userId,
                    $strict,
                    static function (array $progress) use ($context, $index, $total, $originalName): void {
                        $context->heartbeatIfDue([
                            'stage' => 'importing',
                            'done' => $index,
                            'total' => $total,
                            'percent' => 2 + (int)floor(($index * 86) / max(1, $total)),
                            'message' => 'Importing backup file ' . ($index + 1) . '/' . $total . ': ' . $originalName
                                . ' — ' . (string)($progress['message'] ?? 'scanning'),
                        ]);
                    },
                    false,
                    [
                        'source_relative_path' => $sourceRelative,
                        'defer_dependency_rebuild' => true,
                    ]
                );

                $status = (string)($result[0] ?? 'imported');
                if ($status === 'duplicate') {
                    $duplicates++;
                } elseif ($status === 'alias') {
                    $aliases++;
                } else {
                    $imported++;
                }
            } catch (JobCancellationRequested $error) {
                throw $error;
            } catch (Throwable $error) {
                $failed++;
                $errors[] = [
                    'index' => $index + 1,
                    'file' => $originalName,
                    'exported_relative_path' => $relative,
                    'source_relative_path' => $sourceRelative,
                    'error_type' => get_class($error),
                    'error' => $this->shortError($error),
                ];
            } finally {
                if ($temporary !== '' && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        if (($imported + $duplicates + $aliases + $failed) !== $total) {
            throw new \RuntimeException('Backup import validation accounting is incomplete.');
        }

        if ($rebuildDependencies && ($imported + $aliases + $duplicates) > 0) {
            $context->checkpoint([
                'stage' => 'dependencies',
                'done' => $total,
                'total' => $total,
                'percent' => 90,
                'message' => 'Rebuilding dependency links once after the backup import.',
                'imported' => $imported,
                'duplicates' => $duplicates,
                'aliases' => $aliases,
                'failed' => $failed,
                'validated' => $total,
            ]);
            \scanner_rebuild_game(
                $this->db,
                $this->config,
                $gameId,
                static function (array $progress) use ($context): void {
                    $context->heartbeatIfDue($progress);
                },
                90,
                99
            );
        }

        $reportFilename = 'import-job-' . $job->id . '.json';
        $report = [
            'operation' => 'import_game_backup',
            'job_id' => $job->id,
            'backup_key' => $backupKey,
            'target_game_id' => $gameId,
            'target_game_name' => (string)$game['name'],
            'completed_at' => gmdate('c'),
            'entries' => $total,
            'validated' => $total,
            'validation_complete' => true,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'aliases' => $aliases,
            'failed' => $failed,
            'dependency_rebuild' => $rebuildDependencies,
            'errors' => $errors,
            'errors_complete' => true,
            'errors_truncated' => false,
            'report_filename' => $reportFilename,
        ];

        $reportPath = $store->backupPath($backupKey) . DIRECTORY_SEPARATOR . $reportFilename;
        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (file_put_contents($reportPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the complete game-backup import report.');
        }
        @chmod($reportPath, 0640);

        $context->checkpoint([
            'stage' => 'complete',
            'done' => $total,
            'total' => $total,
            'percent' => 100,
            'message' => $failed === 0
                ? 'Game backup import completed; every file was validated.'
                : 'Game backup import completed; all ' . $total . ' files were validated and ' . $failed . ' failed.',
            'imported' => $imported,
            'duplicates' => $duplicates,
            'aliases' => $aliases,
            'failed' => $failed,
            'validated' => $total,
            'validation_complete' => true,
        ]);

        return $report;
    }

    private function verifySource(string $path, int $expectedSize, string $expectedMd5): void
    {
        $actualSize = filesize($path);
        if ($actualSize === false || ($expectedSize >= 0 && (int)$actualSize !== $expectedSize)) {
            throw new \RuntimeException('File size verification failed for ' . basename($path) . '.');
        }
        if ($expectedMd5 !== '') {
            $actualMd5 = md5_file($path);
            if (!is_string($actualMd5) || !hash_equals(strtolower($expectedMd5), strtolower($actualMd5))) {
                throw new \RuntimeException('MD5 verification failed for ' . basename($path) . '.');
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('Game backup requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException('Game backup requires ' . $field . '.');
        }
        return $value;
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' ? mb_substr($message, 0, 4000) : get_class($error);
    }
}
