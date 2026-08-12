<?php
/**
 * Restart-safe game-backup export.
 *
 * The first claim writes an immutable export plan containing the resolved output
 * path for every canonical/alias entry. Each copied + verified entry is appended
 * to an on-disk journal. Worker/server restarts reconstruct completion from that
 * journal and never delete an incomplete backup merely because the job restarted.
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
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupExportCheckpoint;
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;
use UnrealDb\Catalog\Infrastructure\Storage\LocalStoragePathGuard;

final class GameBackupExportJobHandler implements JobHandler
{
    private const MANIFEST_VERSION = 1;
    private const WORKFLOW_VERSION = 2;

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
        $checkpoint = new GameBackupExportCheckpoint($store, $backupKey);

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

        $backupPath = $store->backupPath($backupKey);
        if (is_dir($backupPath)) {
            $existing = $store->readManifest($backupKey);
            if ((string)($existing['status'] ?? '') === 'complete') {
                return [
                    'operation' => 'export_game_backup',
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'backup_key' => $backupKey,
                    'path' => $backupPath,
                    'summary' => $existing['summary'] ?? [],
                    'already_complete' => true,
                ];
            }
            $filesPath = $store->filesPath($backupKey);
            if (!is_dir($filesPath) && !@mkdir($filesPath, 0750, true) && !is_dir($filesPath)) {
                throw new \RuntimeException('Could not restore incomplete game-backup files directory.');
            }
        } else {
            $store->create($backupKey);
        }

        $plan = $checkpoint->readPlan();
        if ($plan === []) {
            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'backup_export_plan',
                'done' => 0,
                'total' => 100,
                'percent' => 1,
                'message' => 'Building immutable game-backup export plan.',
            ]);
            $plan = $this->buildPlan($gameId, $game, $backupKey);
            $checkpoint->writePlan($plan);
        }
        $this->validatePlan($plan, $gameId, $backupKey);

        $entries = is_array($plan['entries'] ?? null) ? array_values($plan['entries']) : [];
        $entriesTotal = count($entries);
        $bytesTotal = max(0, (int)($plan['bytes_total'] ?? 0));
        $createdAt = (string)($plan['created_at'] ?? gmdate('c'));
        $stateBase = [
            'backup_key' => $backupKey,
            'status' => 'building',
            'created_at' => $createdAt,
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'game_slug' => (string)$game['slug'],
        ];

        try {
            $completed = $this->completedJournalEntries($checkpoint, $entries, $store, $backupKey);
            $counters = $this->counters($completed);
            $this->writeState($store, $backupKey, $stateBase, $entriesTotal, $bytesTotal, $counters);

            $catalogRoot = dirname(__DIR__, 3);
            $storageRoot = (string)($this->config['storage_path'] ?? '');
            foreach ($entries as $position => $entry) {
                if (isset($completed[$position])) {
                    continue;
                }

                $context->checkpoint([
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'stage' => 'backup_export_copy',
                    'done' => $counters['done'],
                    'total' => max(1, $entriesTotal),
                    'percent' => 2 + (int)floor(($counters['done'] * 94) / max(1, $entriesTotal)),
                    'message' => 'Copying ' . ($position + 1) . '/' . max(1, $entriesTotal)
                        . ': ' . (string)($entry['original_name'] ?? 'package.bin'),
                    'entry_position' => $position,
                    'files_done' => $counters['done'],
                    'files_total' => $entriesTotal,
                    'bytes_done' => $counters['bytes'],
                    'bytes_total' => $bytesTotal,
                ]);

                $sourcePath = LocalStoragePathGuard::resolveFile(
                    $storageRoot,
                    $catalogRoot,
                    (string)($entry['catalog_relative_path'] ?? '')
                );
                $relative = GameBackupStore::safeRelativePath((string)($entry['exported_relative_path'] ?? ''));
                if ($relative === '') {
                    throw new \RuntimeException('Backup plan entry has an empty output path.');
                }
                $destination = $store->filesPath($backupKey)
                    . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                // A backup created by the old handler may already contain this
                // deterministic output even though no journal existed. Adopt it
                // when its expected identity verifies instead of copying again.
                $adopted = false;
                if (is_file($destination)) {
                    try {
                        $this->verifySource(
                            $destination,
                            (int)($entry['file_size'] ?? 0),
                            (string)($entry['md5'] ?? '')
                        );
                        $adopted = true;
                    } catch (Throwable) {
                        @unlink($destination);
                    }
                }
                if (!$adopted) {
                    $this->copyAndVerify(
                        $sourcePath,
                        $destination,
                        (int)($entry['file_size'] ?? 0),
                        (string)($entry['md5'] ?? '')
                    );
                }

                $journalEntry = $entry + [
                    'entry_position' => $position,
                    'completed_at' => gmdate('c'),
                    'recovered_existing_copy' => $adopted,
                ];
                $checkpoint->completeEntry($journalEntry);
                $completed[$position] = $journalEntry;
                $counters = $this->counters($completed);

                // One entry is the recovery unit. Persist progress/state after
                // every verified copy so an abrupt process death loses at most
                // the in-flight copy, never the earlier completed entries.
                $this->writeState($store, $backupKey, $stateBase, $entriesTotal, $bytesTotal, $counters);
                $context->checkpoint([
                    'workflow_version' => self::WORKFLOW_VERSION,
                    'stage' => 'backup_export_copy',
                    'done' => $counters['done'],
                    'total' => max(1, $entriesTotal),
                    'percent' => 2 + (int)floor(($counters['done'] * 94) / max(1, $entriesTotal)),
                    'message' => ($adopted ? 'Recovered existing verified backup copy' : 'Copied and verified')
                        . ' ' . $counters['done'] . '/' . max(1, $entriesTotal)
                        . ': ' . (string)($entry['original_name'] ?? 'package.bin'),
                    'entry_position' => $position,
                    'files_done' => $counters['done'],
                    'files_total' => $entriesTotal,
                    'bytes_done' => $counters['bytes'],
                    'bytes_total' => $bytesTotal,
                ]);
            }

            if (count($completed) !== $entriesTotal) {
                throw new \RuntimeException(
                    'Game-backup export journal is incomplete: ' . count($completed) . '/' . $entriesTotal . ' entries.'
                );
            }

            ksort($completed, SORT_NUMERIC);
            $manifestEntries = array_values(array_map(
                static function (array $entry): array {
                    unset($entry['entry_position'], $entry['completed_at'], $entry['recovered_existing_copy'], $entry['catalog_relative_path']);
                    return $entry;
                },
                $completed
            ));
            $counters = $this->counters($completed);

            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'backup_export_manifest',
                'done' => $entriesTotal,
                'total' => max(1, $entriesTotal),
                'percent' => 97,
                'message' => 'All file entries are durable; writing and validating the game-backup manifest.',
            ]);

            $manifest = [
                'format' => 'unrealdb-game-backup',
                'format_version' => self::MANIFEST_VERSION,
                'created_at' => $createdAt,
                'completed_at' => gmdate('c'),
                'source_game' => is_array($plan['source_game'] ?? null) ? $plan['source_game'] : [],
                'summary' => [
                    'entries' => count($manifestEntries),
                    'canonical_files' => (int)($plan['canonical_files'] ?? 0),
                    'aliases' => (int)($plan['aliases'] ?? 0),
                    'physical_files' => $counters['physical_files'],
                    'bytes' => $counters['bytes'],
                    'conflicts' => 0,
                    'renamed_variations' => $counters['renamed_variations'],
                    'paths_from_primary' => $counters['paths_from_primary'],
                    'paths_from_locations' => $counters['paths_from_locations'],
                    'paths_unsorted' => $counters['paths_unsorted'],
                    'copy_method' => 'file-copy',
                    'folder_policy' => 'recorded-paths-with-legacy-folder-fallback',
                    'same_name_policy' => 'numeric-suffix-before-extension',
                    'recovery_model' => 'immutable-plan-plus-entry-journal',
                ],
                'files' => $manifestEntries,
            ];

            $this->writeCsv($backupPath . DIRECTORY_SEPARATOR . 'files.csv', $manifestEntries);
            $this->writeReadme($backupPath . DIRECTORY_SEPARATOR . 'README.txt', $manifest);
            $store->publishManifest($backupKey, $manifest);
            $checkpoint->clear();

            $context->checkpoint([
                'workflow_version' => self::WORKFLOW_VERSION,
                'stage' => 'complete',
                'done' => max(1, $entriesTotal),
                'total' => max(1, $entriesTotal),
                'percent' => 100,
                'message' => 'Game backup completed and verified.',
                'backup_key' => $backupKey,
                'files' => count($manifestEntries),
                'bytes' => $counters['bytes'],
                'renamed_variations' => $counters['renamed_variations'],
                'paths_from_locations' => $counters['paths_from_locations'],
                'paths_unsorted' => $counters['paths_unsorted'],
            ]);

            return [
                'operation' => 'export_game_backup',
                'workflow_version' => self::WORKFLOW_VERSION,
                'backup_key' => $backupKey,
                'path' => $backupPath,
                'game_id' => $gameId,
                'game_name' => (string)$game['name'],
                'summary' => $manifest['summary'],
            ];
        } catch (JobCancellationRequested $error) {
            $current = $this->counters($this->completedJournalEntries($checkpoint, $entries, $store, $backupKey));
            $this->writeState($store, $backupKey, $stateBase + [
                'status' => 'cancelled',
                'last_error' => $error->getMessage(),
            ], $entriesTotal, $bytesTotal, $current);
            throw $error;
        } catch (Throwable $error) {
            $current = $this->counters($this->completedJournalEntries($checkpoint, $entries, $store, $backupKey));
            $this->writeState($store, $backupKey, $stateBase + [
                'status' => 'failed',
                'last_error' => $error->getMessage(),
            ], $entriesTotal, $bytesTotal, $current);
            throw $error;
        }
    }

    /** @param array<string,mixed> $game @return array<string,mixed> */
    private function buildPlan(int $gameId, array $game, string $backupKey): array
    {
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

        $engineKey = strtoupper(trim((string)($game['engine_key'] ?? '')));
        $claimed = [];
        $entries = [];
        $bytesTotal = 0;
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

            foreach ($logicalRows as $logical) {
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
                $relative = $this->allocateUniqueRelativePath($requestedRelative, $claimed);
                $claimed[strtolower($relative)] = true;
                $renamedForCollision = strcasecmp($relative, $requestedRelative) !== 0;
                $entries[] = [
                    'file_id' => (int)$file['id'],
                    'alias_id' => $logical['alias_id'],
                    'is_alias' => (bool)$logical['is_alias'],
                    'package_name' => (string)$logical['package_name'],
                    'original_name' => (string)$logical['original_name'],
                    'source_relative_path' => (string)$selection['path'],
                    'catalog_source_relative_path' => (string)($file['source_relative_path'] ?? ''),
                    'catalog_relative_path' => (string)$file['relative_path'],
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
                    'copy_status' => $renamedForCollision ? 'copied-renamed' : 'copied',
                ];
                $bytesTotal += max(0, (int)$logical['file_size']);
            }
        }

        return [
            'format' => 'unrealdb-game-backup-export-plan',
            'workflow_version' => self::WORKFLOW_VERSION,
            'backup_key' => $backupKey,
            'created_at' => gmdate('c'),
            'game_id' => $gameId,
            'canonical_files' => count($files),
            'aliases' => count($aliases),
            'bytes_total' => $bytesTotal,
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
            'entries' => $entries,
        ];
    }

    /** @param array<string,mixed> $plan */
    private function validatePlan(array $plan, int $gameId, string $backupKey): void
    {
        if ((string)($plan['format'] ?? '') !== 'unrealdb-game-backup-export-plan'
            || (int)($plan['workflow_version'] ?? 0) !== self::WORKFLOW_VERSION
            || (int)($plan['game_id'] ?? 0) !== $gameId
            || (string)($plan['backup_key'] ?? '') !== $backupKey
            || !is_array($plan['entries'] ?? null)) {
            throw new \RuntimeException('Incomplete game backup contains an unsupported or invalid export plan.');
        }
    }

    /**
     * @param list<array<string,mixed>> $planEntries
     * @return array<int,array<string,mixed>>
     */
    private function completedJournalEntries(
        GameBackupExportCheckpoint $checkpoint,
        array $planEntries,
        GameBackupStore $store,
        string $backupKey
    ): array {
        $completed = [];
        foreach ($checkpoint->journal() as $row) {
            $position = (int)($row['entry_position'] ?? -1);
            if ($position < 0 || !isset($planEntries[$position])) {
                continue;
            }
            $expected = $planEntries[$position];
            if ((int)($row['file_id'] ?? 0) !== (int)($expected['file_id'] ?? 0)
                || (int)($row['alias_id'] ?? 0) !== (int)($expected['alias_id'] ?? 0)
                || strcasecmp((string)($row['exported_relative_path'] ?? ''), (string)($expected['exported_relative_path'] ?? '')) !== 0) {
                continue;
            }
            $relative = GameBackupStore::safeRelativePath((string)$expected['exported_relative_path']);
            $path = $store->filesPath($backupKey) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            try {
                $this->verifySource($path, (int)$expected['file_size'], (string)$expected['md5']);
            } catch (Throwable) {
                continue;
            }
            $completed[$position] = $row;
        }
        ksort($completed, SORT_NUMERIC);
        return $completed;
    }

    /** @param array<int,array<string,mixed>> $completed @return array<string,int> */
    private function counters(array $completed): array
    {
        $result = [
            'done' => 0,
            'bytes' => 0,
            'physical_files' => 0,
            'renamed_variations' => 0,
            'paths_from_primary' => 0,
            'paths_from_locations' => 0,
            'paths_unsorted' => 0,
        ];
        foreach ($completed as $entry) {
            $result['done']++;
            $result['physical_files']++;
            $result['bytes'] += max(0, (int)($entry['file_size'] ?? 0));
            if (!empty($entry['renamed_for_collision'])) {
                $result['renamed_variations']++;
            }
            $source = (string)($entry['path_source'] ?? 'unsorted');
            if ($source === 'primary') {
                $result['paths_from_primary']++;
            } elseif ($source === 'location') {
                $result['paths_from_locations']++;
            } else {
                $result['paths_unsorted']++;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $stateBase @param array<string,int> $counters */
    private function writeState(
        GameBackupStore $store,
        string $backupKey,
        array $stateBase,
        int $entriesTotal,
        int $bytesTotal,
        array $counters
    ): void {
        $store->writeState($backupKey, $stateBase + [
            'files_done' => (int)($counters['done'] ?? 0),
            'files_total' => $entriesTotal,
            'bytes_done' => (int)($counters['bytes'] ?? 0),
            'bytes_total' => $bytesTotal,
            'physical_files' => (int)($counters['physical_files'] ?? 0),
            'conflicts' => 0,
            'renamed_variations' => (int)($counters['renamed_variations'] ?? 0),
            'paths_from_primary' => (int)($counters['paths_from_primary'] ?? 0),
            'paths_from_locations' => (int)($counters['paths_from_locations'] ?? 0),
            'paths_unsorted' => (int)($counters['paths_unsorted'] ?? 0),
        ]);
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
            $candidates[] = ['path' => $primary, 'source' => 'primary', 'exists' => 1, 'active' => 1, 'order' => 0];
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
        $part = $destination . '.part-' . bin2hex(random_bytes(5));
        try {
            if (!copy($source, $part)) {
                throw new \RuntimeException('Could not copy file into the game backup: ' . basename($destination));
            }
            @chmod($part, 0640);
            $this->verifySource($part, $expectedSize, $expectedMd5);
            if (is_file($destination) && !@unlink($destination)) {
                throw new \RuntimeException('Could not replace incomplete backup file: ' . basename($destination));
            }
            if (!@rename($part, $destination)) {
                throw new \RuntimeException('Could not publish copied game-backup file: ' . basename($destination));
            }
        } catch (Throwable $error) {
            @unlink($part);
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
