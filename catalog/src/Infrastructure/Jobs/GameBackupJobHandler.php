<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GameBackupJobHandler` for game backup job handler.
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
use UnrealDb\Catalog\Infrastructure\Storage\LocalStoragePathGuard;

final class GameBackupJobHandler implements JobHandler
{
    private const MANIFEST_VERSION = 1;
    private const MAX_IMPORT_ERRORS = 200;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return in_array($jobType, [JobType::EXPORT_GAME_BACKUP, JobType::IMPORT_GAME_BACKUP], true);
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        return match ($job->type) {
            JobType::EXPORT_GAME_BACKUP => $this->exportGame($job, $context),
            JobType::IMPORT_GAME_BACKUP => $this->importGame($job, $context),
            default => throw new \RuntimeException('Unsupported game-backup job: ' . $job->type),
        };
    }

    private function exportGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogPackageAliases.php';

        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $store = new GameBackupStore($this->config);
        $game = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,g.profile_id,p.profile_name,p.engine_key,p.allowed_extensions_json,'
            . 'p.package_version_min,p.package_version_max,p.licensee_version_min,p.licensee_version_max '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
            [$gameId]
        );
        if (!$game) {
            throw new \RuntimeException('Source game no longer exists: ' . $gameId);
        }

        $existingPath = $store->backupPath($backupKey);
        if (is_dir($existingPath)) {
            $existing = $store->readManifest($backupKey);
            if ((string)($existing['status'] ?? '') === 'complete') {
                return [
                    'operation' => 'export_game_backup',
                    'backup_key' => $backupKey,
                    'path' => $existingPath,
                    'summary' => $existing['summary'] ?? [],
                    'already_complete' => true,
                ];
            }
            $store->delete($backupKey);
        }

        $createdAt = gmdate('c');
        $paths = $store->create($backupKey);
        $stateBase = [
            'backup_key' => $backupKey,
            'status' => 'building',
            'created_at' => $createdAt,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'game_slug' => (string)$game['slug'],
        ];

        try {
            $files = \catalog_all(
                $this->db,
                'SELECT id,game_id,package_name,original_name,source_relative_path,relative_path,extension,'
                . 'file_size,md5,sha1,package_guid,package_version,licensee_version '
                . 'FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY id',
                [$gameId]
            );
            \catalog_package_aliases_ensure($this->db);
            $aliases = \catalog_all(
                $this->db,
                'SELECT id,file_id,game_id,package_name,original_name,package_guid,md5,file_size '
                . 'FROM ue_file_package_aliases WHERE game_id=? ORDER BY file_id,id',
                [$gameId]
            );
            $aliasesByFile = [];
            foreach ($aliases as $alias) {
                $aliasesByFile[(int)$alias['file_id']][] = $alias;
            }

            $entriesTotal = count($files) + count($aliases);
            $bytesTotal = 0;
            foreach ($files as $file) {
                $bytesTotal += max(0, (int)$file['file_size']);
                foreach ($aliasesByFile[(int)$file['id']] ?? [] as $alias) {
                    $bytesTotal += max(0, (int)$alias['file_size']);
                }
            }
            $store->writeState($backupKey, $stateBase + [
                'files_done' => 0,
                'files_total' => $entriesTotal,
                'bytes_done' => 0,
                'bytes_total' => $bytesTotal,
                'physical_files' => 0,
                'conflicts' => 0,
            ]);

            $manifestEntries = [];
            $claimed = [];
            $done = 0;
            $copiedBytes = 0;
            $physicalFiles = 0;
            $conflicts = 0;
            $catalogRoot = dirname(__DIR__, 3);
            $storageRoot = (string)($this->config['storage_path'] ?? '');

            foreach ($files as $file) {
                $logicalRows = [[
                    'alias_id' => null,
                    'package_name' => (string)$file['package_name'],
                    'original_name' => (string)$file['original_name'],
                    'package_guid' => (string)($file['package_guid'] ?? ''),
                    'md5' => (string)$file['md5'],
                    'file_size' => (int)$file['file_size'],
                    'is_alias' => false,
                ]];
                foreach ($aliasesByFile[(int)$file['id']] ?? [] as $alias) {
                    $logicalRows[] = [
                        'alias_id' => (int)$alias['id'],
                        'package_name' => (string)$alias['package_name'],
                        'original_name' => (string)$alias['original_name'],
                        'package_guid' => (string)($alias['package_guid'] ?? ''),
                        'md5' => (string)$alias['md5'],
                        'file_size' => (int)$alias['file_size'],
                        'is_alias' => true,
                    ];
                }

                $sourcePath = LocalStoragePathGuard::resolveFile(
                    $storageRoot,
                    $catalogRoot,
                    (string)$file['relative_path']
                );

                foreach ($logicalRows as $logical) {
                    $context->checkpoint([
                        'stage' => 'copying',
                        'done' => $done,
                        'total' => max(1, $entriesTotal),
                        'percent' => 2 + (int)floor(($done * 94) / max(1, $entriesTotal)),
                        'message' => 'Copying ' . ($done + 1) . '/' . max(1, $entriesTotal) . ': ' . (string)$logical['original_name'],
                        'files_done' => $done,
                        'files_total' => $entriesTotal,
                        'bytes_done' => $copiedBytes,
                        'bytes_total' => $bytesTotal,
                    ]);

                    $relative = $this->outputRelativePath($file, (string)$logical['original_name']);
                    $claimKey = strtolower($relative);
                    $copyStatus = 'copied';
                    if (isset($claimed[$claimKey])) {
                        if (hash_equals((string)$claimed[$claimKey]['md5'], (string)$logical['md5'])) {
                            $relative = (string)$claimed[$claimKey]['relative'];
                            $copyStatus = 'shared-identical';
                        } else {
                            $conflicts++;
                            $relative = $this->conflictRelativePath(
                                (int)$file['id'],
                                isset($logical['alias_id']) ? (int)$logical['alias_id'] : null,
                                (string)$logical['original_name']
                            );
                            $claimKey = strtolower($relative);
                        }
                    }

                    if ($copyStatus === 'copied') {
                        $destination = $paths['files_path'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                        $this->copyAndVerify($sourcePath, $destination, (int)$logical['file_size'], (string)$logical['md5']);
                        $claimed[$claimKey] = ['md5' => (string)$logical['md5'], 'relative' => $relative];
                        $physicalFiles++;
                        $copiedBytes += (int)$logical['file_size'];
                    }

                    $manifestEntries[] = [
                        'file_id' => (int)$file['id'],
                        'alias_id' => $logical['alias_id'],
                        'is_alias' => (bool)$logical['is_alias'],
                        'package_name' => (string)$logical['package_name'],
                        'original_name' => (string)$logical['original_name'],
                        'source_relative_path' => (string)($file['source_relative_path'] ?? ''),
                        'exported_relative_path' => $relative,
                        'extension' => (string)$file['extension'],
                        'file_size' => (int)$logical['file_size'],
                        'md5' => (string)$logical['md5'],
                        'sha1' => (string)($file['sha1'] ?? ''),
                        'package_guid' => (string)$logical['package_guid'],
                        'package_version' => (int)($file['package_version'] ?? 0),
                        'licensee_version' => (int)($file['licensee_version'] ?? 0),
                        'copy_status' => $copyStatus,
                    ];
                    $done++;

                    if (($done % 20) === 0 || $done === $entriesTotal) {
                        $store->writeState($backupKey, $stateBase + [
                            'files_done' => $done,
                            'files_total' => $entriesTotal,
                            'bytes_done' => $copiedBytes,
                            'bytes_total' => $bytesTotal,
                            'physical_files' => $physicalFiles,
                            'conflicts' => $conflicts,
                        ]);
                    }
                }
            }

            $context->checkpoint([
                'stage' => 'manifest',
                'done' => $entriesTotal,
                'total' => max(1, $entriesTotal),
                'percent' => 97,
                'message' => 'Writing and validating the game-backup manifest.',
            ]);

            $completedAt = gmdate('c');
            $manifest = [
                'format' => 'unrealdb-game-backup',
                'format_version' => self::MANIFEST_VERSION,
                'created_at' => $createdAt,
                'completed_at' => $completedAt,
                'source_game' => [
                    'id' => $gameId,
                    'name' => (string)$game['name'],
                    'slug' => (string)$game['slug'],
                    'profile_id' => (int)($game['profile_id'] ?? 0),
                    'profile_name' => (string)($game['profile_name'] ?? ''),
                    'engine_key' => (string)($game['engine_key'] ?? ''),
                    'allowed_extensions' => json_decode((string)($game['allowed_extensions_json'] ?? '[]'), true) ?: [],
                    'package_version_min' => $game['package_version_min'] !== null ? (int)$game['package_version_min'] : null,
                    'package_version_max' => $game['package_version_max'] !== null ? (int)$game['package_version_max'] : null,
                    'licensee_version_min' => $game['licensee_version_min'] !== null ? (int)$game['licensee_version_min'] : null,
                    'licensee_version_max' => $game['licensee_version_max'] !== null ? (int)$game['licensee_version_max'] : null,
                ],
                'summary' => [
                    'entries' => count($manifestEntries),
                    'canonical_files' => count($files),
                    'aliases' => count($aliases),
                    'physical_files' => $physicalFiles,
                    'bytes' => $copiedBytes,
                    'conflicts' => $conflicts,
                    'copy_method' => 'file-copy',
                ],
                'files' => $manifestEntries,
            ];
            $this->writeCsv($paths['path'] . DIRECTORY_SEPARATOR . 'files.csv', $manifestEntries);
            $this->writeReadme($paths['path'] . DIRECTORY_SEPARATOR . 'README.txt', $manifest);
            $store->publishManifest($backupKey, $manifest);

            $context->checkpoint([
                'stage' => 'complete',
                'done' => max(1, $entriesTotal),
                'total' => max(1, $entriesTotal),
                'percent' => 100,
                'message' => 'Game backup completed and verified.',
                'backup_key' => $backupKey,
                'files' => count($manifestEntries),
                'bytes' => $copiedBytes,
            ]);

            return [
                'operation' => 'export_game_backup',
                'backup_key' => $backupKey,
                'path' => $paths['path'],
                'game_id' => $gameId,
                'game_name' => (string)$game['name'],
                'summary' => $manifest['summary'],
            ];
        } catch (JobCancellationRequested $error) {
            $store->writeState($backupKey, $stateBase + ['status' => 'cancelled', 'last_error' => $error->getMessage()]);
            throw $error;
        } catch (Throwable $error) {
            $store->writeState($backupKey, $stateBase + ['status' => 'failed', 'last_error' => $error->getMessage()]);
            throw $error;
        }
    }

    private function importGame(ClaimedJob $job, JobExecutionContext $context): array
    {
        require_once __DIR__ . '/../../../lib/CatalogSupport.php';
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';

        $gameId = $this->positiveInt($job->payload, 'game_id');
        $backupKey = $this->requiredString($job->payload, 'backup_key');
        $userId = isset($job->payload['user_id']) && (int)$job->payload['user_id'] > 0
            ? (int)$job->payload['user_id']
            : null;
        $strict = !array_key_exists('strict_profile', $job->payload) || (bool)$job->payload['strict_profile'];
        $rebuildDependencies = !array_key_exists('rebuild_dependencies', $job->payload) || (bool)$job->payload['rebuild_dependencies'];
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
            return $aliasOrder !== 0 ? $aliasOrder : ((int)($a['file_id'] ?? 0) <=> (int)($b['file_id'] ?? 0));
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
                $temporary = '';
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
                if (count($errors) < self::MAX_IMPORT_ERRORS) {
                    $errors[] = [
                        'file' => $originalName,
                        'exported_relative_path' => $relative,
                        'error' => $this->shortError($error),
                    ];
                }
            } finally {
                if ($temporary !== '' && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
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

        $report = [
            'operation' => 'import_game_backup',
            'backup_key' => $backupKey,
            'target_game_id' => $gameId,
            'target_game_name' => (string)$game['name'],
            'completed_at' => gmdate('c'),
            'entries' => $total,
            'imported' => $imported,
            'duplicates' => $duplicates,
            'aliases' => $aliases,
            'failed' => $failed,
            'dependency_rebuild' => $rebuildDependencies,
            'errors' => $errors,
            'errors_truncated' => $failed > count($errors),
        ];
        $reportPath = $store->backupPath($backupKey) . DIRECTORY_SEPARATOR . 'import-job-' . $job->id . '.json';
        file_put_contents(
            $reportPath,
            json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            LOCK_EX
        );

        $context->checkpoint([
            'stage' => 'complete',
            'done' => $total,
            'total' => $total,
            'percent' => 100,
            'message' => $failed === 0
                ? 'Game backup import completed.'
                : 'Game backup import completed with ' . $failed . ' failed file(s).',
            'imported' => $imported,
            'duplicates' => $duplicates,
            'aliases' => $aliases,
            'failed' => $failed,
        ]);
        return $report;
    }

    /** @param array<string,mixed> $file */
    private function outputRelativePath(array $file, string $originalName): string
    {
        $originalName = GameBackupStore::safeRelativePath(basename(str_replace('\\', '/', $originalName)));
        if ($originalName === '') {
            $originalName = 'file-' . (int)$file['id'] . '.' . (string)$file['extension'];
        }
        $sourceRelative = GameBackupStore::safeRelativePath((string)($file['source_relative_path'] ?? ''));
        if ($sourceRelative === '') {
            return '_Unsorted/' . $originalName;
        }
        $slash = strrpos($sourceRelative, '/');
        $directory = $slash === false ? '' : substr($sourceRelative, 0, $slash);
        return ($directory !== '' ? $directory . '/' : '') . $originalName;
    }

    private function conflictRelativePath(int $fileId, ?int $aliasId, string $originalName): string
    {
        $name = GameBackupStore::safeRelativePath(basename(str_replace('\\', '/', $originalName)));
        $folder = '_Conflicts/file-' . $fileId . ($aliasId !== null && $aliasId > 0 ? '-alias-' . $aliasId : '');
        return $folder . '/' . ($name !== '' ? $name : 'package.bin');
    }

    private function copyAndVerify(string $source, string $destination, int $expectedSize, string $expectedMd5): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create backup folder: ' . $directory);
        }
        if (!copy($source, $destination)) {
            throw new \RuntimeException('Could not copy file into the game backup: ' . basename($destination));
        }
        @chmod($destination, 0640);
        try {
            $this->verifySource($destination, $expectedSize, $expectedMd5);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        }
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

    /** @param list<array<string,mixed>> $entries */
    private function writeCsv(string $path, array $entries): void
    {
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not create backup CSV manifest.');
        }
        try {
            fputcsv($handle, [
                'file_id', 'alias_id', 'is_alias', 'package_name', 'original_name', 'source_relative_path',
                'exported_relative_path', 'extension', 'file_size', 'md5', 'sha1', 'package_guid',
                'package_version', 'licensee_version', 'copy_status',
            ], ',', '"', '');
            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry['file_id'] ?? '',
                    $entry['alias_id'] ?? '',
                    !empty($entry['is_alias']) ? '1' : '0',
                    $entry['package_name'] ?? '',
                    $entry['original_name'] ?? '',
                    $entry['source_relative_path'] ?? '',
                    $entry['exported_relative_path'] ?? '',
                    $entry['extension'] ?? '',
                    $entry['file_size'] ?? 0,
                    $entry['md5'] ?? '',
                    $entry['sha1'] ?? '',
                    $entry['package_guid'] ?? '',
                    $entry['package_version'] ?? 0,
                    $entry['licensee_version'] ?? 0,
                    $entry['copy_status'] ?? '',
                ], ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $manifest */
    private function writeReadme(string $path, array $manifest): void
    {
        $game = is_array($manifest['source_game'] ?? null) ? $manifest['source_game'] : [];
        $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
        $text = "UnrealDB game backup\n"
            . "====================\n\n"
            . 'Game: ' . (string)($game['name'] ?? '') . "\n"
            . 'Slug: ' . (string)($game['slug'] ?? '') . "\n"
            . 'Engine: ' . (string)($game['engine_key'] ?? '') . "\n"
            . 'Completed: ' . (string)($manifest['completed_at'] ?? '') . "\n"
            . 'Manifest entries: ' . (int)($summary['entries'] ?? 0) . "\n"
            . 'Physical copied files: ' . (int)($summary['physical_files'] ?? 0) . "\n"
            . 'Copied bytes: ' . (int)($summary['bytes'] ?? 0) . "\n"
            . 'Path conflicts: ' . (int)($summary['conflicts'] ?? 0) . "\n\n"
            . "The files/ directory contains full independent file copies. No hard links are used.\n"
            . "Use Admin > Game Backups on another UnrealDB installation to import this backup,\n"
            . "or copy the complete backup directory into that installation's configured backup path.\n";
        if (file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException('Could not create backup README.');
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
        return $message !== '' ? mb_substr($message, 0, 1000) : get_class($error);
    }
}
