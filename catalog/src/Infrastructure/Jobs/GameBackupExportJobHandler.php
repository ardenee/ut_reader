<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `GameBackupExportJobHandler` for game backup export job handler.
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

/**
 * Builds full-copy game backups from recorded source paths. Legacy Unreal
 * package extensions are used only as a fallback when the recorded path does
 * not include the standard game folder needed for drag-and-drop restoration.
 */
final class GameBackupExportJobHandler implements JobHandler
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
        return $jobType === JobType::EXPORT_GAME_BACKUP;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if ($job->type !== JobType::EXPORT_GAME_BACKUP) {
            throw new \RuntimeException('Unsupported game-backup export job: ' . $job->type);
        }
        return $this->exportGame($job, $context);
    }

    /** @return array<string,mixed> */
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
            $locations = \catalog_all(
                $this->db,
                'SELECT l.id,l.file_id,l.source_relative_path,l.exists_in_source,l.last_seen_at,'
                . 'COALESCE(s.is_active,0) source_active '
                . 'FROM ue_file_locations l '
                . 'JOIN ue_files f ON f.id=l.file_id '
                . 'LEFT JOIN ue_sources s ON s.id=l.source_id '
                . 'WHERE f.game_id=? AND l.source_relative_path<>"" '
                . 'ORDER BY l.file_id,l.exists_in_source DESC,source_active DESC,l.last_seen_at DESC,l.id DESC',
                [$gameId]
            );

            $aliasesByFile = [];
            foreach ($aliases as $alias) {
                $aliasesByFile[(int)$alias['file_id']][] = $alias;
            }
            $locationsByFile = [];
            foreach ($locations as $location) {
                $locationsByFile[(int)$location['file_id']][] = $location;
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
                'renamed_variations' => 0,
                'paths_from_primary' => 0,
                'paths_from_locations' => 0,
                'paths_unsorted' => 0,
            ]);

            $manifestEntries = [];
            $claimed = [];
            $done = 0;
            $copiedBytes = 0;
            $physicalFiles = 0;
            $renamedVariations = 0;
            $pathsFromPrimary = 0;
            $pathsFromLocations = 0;
            $pathsUnsorted = 0;
            $catalogRoot = dirname(__DIR__, 3);
            $storageRoot = (string)($this->config['storage_path'] ?? '');
            $engineKey = strtoupper(trim((string)($game['engine_key'] ?? '')));

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

                    $selection = $this->selectRecordedPath(
                        $file,
                        $locationsByFile[(int)$file['id']] ?? [],
                        (string)$logical['original_name']
                    );
                    $requestedRelative = $this->outputRelativePath(
                        $engineKey,
                        (int)$file['id'],
                        (string)$file['extension'],
                        (string)$logical['original_name'],
                        (string)$selection['path']
                    );
                    if ($selection['source'] === 'primary') {
                        $pathsFromPrimary++;
                    } elseif ($selection['source'] === 'location') {
                        $pathsFromLocations++;
                    } else {
                        $pathsUnsorted++;
                    }

                    $relative = $this->allocateUniqueRelativePath($requestedRelative, $claimed);
                    $renamedForCollision = strcasecmp($relative, $requestedRelative) !== 0;
                    if ($renamedForCollision) {
                        $renamedVariations++;
                    }
                    $copyStatus = $renamedForCollision ? 'copied-renamed' : 'copied';

                    $destination = $paths['files_path'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    $this->copyAndVerify($sourcePath, $destination, (int)$logical['file_size'], (string)$logical['md5']);
                    $claimed[strtolower($relative)] = true;
                    $physicalFiles++;
                    $copiedBytes += (int)$logical['file_size'];

                    $manifestEntries[] = [
                        'file_id' => (int)$file['id'],
                        'alias_id' => $logical['alias_id'],
                        'is_alias' => (bool)$logical['is_alias'],
                        'package_name' => (string)$logical['package_name'],
                        'original_name' => (string)$logical['original_name'],
                        'source_relative_path' => (string)$selection['path'],
                        'catalog_source_relative_path' => (string)($file['source_relative_path'] ?? ''),
                        'path_source' => (string)$selection['source'],
                        'requested_relative_path' => $requestedRelative,
                        'exported_relative_path' => $relative,
                        'renamed_for_collision' => $renamedForCollision,
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
                            'conflicts' => 0,
                            'renamed_variations' => $renamedVariations,
                            'paths_from_primary' => $pathsFromPrimary,
                            'paths_from_locations' => $pathsFromLocations,
                            'paths_unsorted' => $pathsUnsorted,
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
                    'conflicts' => 0,
                    'renamed_variations' => $renamedVariations,
                    'paths_from_primary' => $pathsFromPrimary,
                    'paths_from_locations' => $pathsFromLocations,
                    'paths_unsorted' => $pathsUnsorted,
                    'copy_method' => 'file-copy',
                    'folder_policy' => 'recorded-paths-with-legacy-folder-fallback',
                    'same_name_policy' => 'numeric-suffix-before-extension',
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
                'renamed_variations' => $renamedVariations,
                'paths_from_locations' => $pathsFromLocations,
                'paths_unsorted' => $pathsUnsorted,
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

    /**
     * @param array<string,mixed> $file
     * @param list<array<string,mixed>> $locations
     * @return array{path:string,source:string}
     */
    private function selectRecordedPath(array $file, array $locations, string $originalName): array
    {
        $wantedName = strtolower($this->packageFilename($originalName));
        $candidates = [];

        $primary = GameBackupStore::safeRelativePath((string)($file['source_relative_path'] ?? ''));
        if ($primary !== '') {
            $candidates[] = [
                'path' => $primary,
                'source' => 'primary',
                'exists' => 1,
                'active' => 1,
                'order' => 0,
            ];
        }

        foreach ($locations as $index => $location) {
            $path = GameBackupStore::safeRelativePath((string)($location['source_relative_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $candidates[] = [
                'path' => $path,
                'source' => 'location',
                'exists' => !empty($location['exists_in_source']) ? 1 : 0,
                'active' => !empty($location['source_active']) ? 1 : 0,
                'order' => $index + 1,
            ];
        }

        if ($candidates === []) {
            return ['path' => '', 'source' => 'unsorted'];
        }

        usort($candidates, function (array $left, array $right) use ($wantedName): int {
            $leftName = strtolower($this->packageFilename(basename((string)$left['path'])));
            $rightName = strtolower($this->packageFilename(basename((string)$right['path'])));
            $leftExact = $wantedName !== '' && $leftName === $wantedName ? 1 : 0;
            $rightExact = $wantedName !== '' && $rightName === $wantedName ? 1 : 0;
            if ($leftExact !== $rightExact) {
                return $rightExact <=> $leftExact;
            }

            $leftDepth = substr_count((string)$left['path'], '/');
            $rightDepth = substr_count((string)$right['path'], '/');
            if ($leftDepth !== $rightDepth) {
                return $rightDepth <=> $leftDepth;
            }
            if ((int)$left['exists'] !== (int)$right['exists']) {
                return (int)$right['exists'] <=> (int)$left['exists'];
            }
            if ((int)$left['active'] !== (int)$right['active']) {
                return (int)$right['active'] <=> (int)$left['active'];
            }
            if ((string)$left['source'] !== (string)$right['source']) {
                return (string)$left['source'] === 'primary' ? -1 : 1;
            }
            return (int)$left['order'] <=> (int)$right['order'];
        });

        $selected = $candidates[0];
        return ['path' => (string)$selected['path'], 'source' => (string)$selected['source']];
    }

    private function packageFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        return preg_replace('/\.(uz|uz2|uz3)$/i', '', $name) ?? $name;
    }

    private function outputRelativePath(
        string $engineKey,
        int $fileId,
        string $fallbackExtension,
        string $originalName,
        string $recordedPath
    ): string {
        $originalName = GameBackupStore::safeRelativePath($this->packageFilename($originalName));
        if ($originalName === '') {
            $originalName = 'file-' . $fileId . '.' . $fallbackExtension;
        }

        $recordedPath = GameBackupStore::safeRelativePath($recordedPath);
        $slash = strrpos($recordedPath, '/');
        $directory = $slash === false ? '' : substr($recordedPath, 0, $slash);
        $legacyFolder = $this->legacyFolderForExtension($engineKey, $originalName, $fallbackExtension);

        if ($legacyFolder !== '' && !$this->directoryContainsFolder($directory, $legacyFolder)) {
            $directory = $directory !== '' ? $directory . '/' . $legacyFolder : $legacyFolder;
        }

        return ($directory !== '' ? $directory . '/' : '') . $originalName;
    }

    private function legacyFolderForExtension(string $engineKey, string $originalName, string $fallbackExtension): string
    {
        if (!in_array(strtoupper(trim($engineKey)), ['UE1', 'UE2', 'UE2.5'], true)) {
            return '';
        }

        $extension = strtolower((string)pathinfo($this->packageFilename($originalName), PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower(trim($fallbackExtension, '. '));
        }

        return match ($extension) {
            'unr', 'ut2', 'un2' => 'Maps',
            'u' => 'System',
            'utx' => 'Textures',
            'uax', 'est_uax', 'frt_uax', 'itt_uax' => 'Sounds',
            'umx' => 'Music',
            'usx' => 'StaticMeshes',
            'ukx' => 'Animations',
            'upx' => 'Prefabs',
            default => '',
        };
    }

    private function directoryContainsFolder(string $directory, string $folder): bool
    {
        if ($directory === '') {
            return false;
        }
        foreach (explode('/', $directory) as $part) {
            if (strcasecmp($part, $folder) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $claimed */
    private function allocateUniqueRelativePath(string $requestedRelative, array $claimed): string
    {
        $requestedRelative = GameBackupStore::safeRelativePath($requestedRelative);
        if ($requestedRelative === '') {
            throw new \RuntimeException('Game backup produced an empty output path.');
        }
        if (!isset($claimed[strtolower($requestedRelative)])) {
            return $requestedRelative;
        }

        $slash = strrpos($requestedRelative, '/');
        $directory = $slash === false ? '' : substr($requestedRelative, 0, $slash);
        $filename = $slash === false ? $requestedRelative : substr($requestedRelative, $slash + 1);
        $extension = (string)pathinfo($filename, PATHINFO_EXTENSION);
        $stem = (string)pathinfo($filename, PATHINFO_FILENAME);
        if ($stem === '') {
            $stem = $filename;
            $extension = '';
        }

        for ($number = 2; $number < 1000000; $number++) {
            $candidateName = $stem . ' (' . $number . ')' . ($extension !== '' ? '.' . $extension : '');
            $candidate = ($directory !== '' ? $directory . '/' : '') . $candidateName;
            if (!isset($claimed[strtolower($candidate)])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not allocate a unique backup filename for ' . $requestedRelative . '.');
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
                'catalog_source_relative_path', 'path_source', 'requested_relative_path', 'exported_relative_path',
                'renamed_for_collision', 'extension', 'file_size', 'md5', 'sha1', 'package_guid',
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
                    $entry['catalog_source_relative_path'] ?? '',
                    $entry['path_source'] ?? '',
                    $entry['requested_relative_path'] ?? '',
                    $entry['exported_relative_path'] ?? '',
                    !empty($entry['renamed_for_collision']) ? '1' : '0',
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
            . 'Same-name variations renamed: ' . (int)($summary['renamed_variations'] ?? 0) . "\n"
            . 'Paths selected from file records: ' . (int)($summary['paths_from_primary'] ?? 0) . "\n"
            . 'Paths selected from source locations: ' . (int)($summary['paths_from_locations'] ?? 0) . "\n"
            . 'Files without a recorded path: ' . (int)($summary['paths_unsorted'] ?? 0) . "\n\n"
            . "The files/ directory contains full independent file copies. No hard links are used.\n"
            . "Recorded folders are preserved. Flat legacy UE1/UE2 files are placed into standard game folders.\n"
            . "Same-name variations remain in the same folder and are renamed Name (2).ext, Name (3).ext, etc.\n"
            . "No _Conflicts directory is created.\n"
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
}
