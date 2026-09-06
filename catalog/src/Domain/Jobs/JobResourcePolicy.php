<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the domain class `JobResourcePolicy` for job type.
 * Why: It keeps resource/concurrency policy out of handlers and pages.
 * Role: Pure Domain model/contract code representing durable-job resource profiles.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

final class JobResourcePolicy
{
    public const DEPENDENCY_HEAVY = 'dependency-heavy';
    public const FULL_SYNC_UNIT = 'full-sync-unit';
    public const AFFECTED_DEPENDENCY_BATCH = 'affected-dependency-batch';
    public const SEARCH_HEAVY = 'search-heavy';
    public const IMPORT_HEAVY = 'import-heavy';
    public const SOURCE_ARCHIVE_IMPORT = 'source-archive-import';
    public const ARCHIVE_IMPORT_HEAVY = 'archive-import-heavy';
    public const BUCKET_PROCESSING = 'bucket-processing';
    public const UNVERIFIED_MATCHES = 'unverified-matches';
    public const UNVERIFIED_FILE_MAINTENANCE = 'unverified-file-maintenance';
    public const GAME_FILE_REASSIGNMENT = 'game-file-reassignment';
    public const STORAGE_HEAVY = 'storage-heavy';
    public const PACKAGE_HEAVY = 'package-heavy';
    public const HOUSEKEEPING = 'housekeeping';
    public const DEFAULT = 'default';
    public const PROJECTION_CONCURRENCY_KEY = 'projection:catalog-maintenance';

    /** @return array<string,array{label:string,default:int,description:string}> */
    public static function definitions(): array
    {
        return [
            self::DEPENDENCY_HEAVY => [
                'label' => 'Dependency and projection work',
                'default' => 1,
                'description' => 'Full Sync coordinators, whole-game dependency rebuilds, projection reconciliation and source-identity repairs. Database intensive.',
            ],
            self::FULL_SYNC_UNIT => [
                'label' => 'Full Sync file units',
                'default' => 2,
                'description' => 'Independent per-file Full Sync reimport, compact-metadata repair and dependency units. Completed units remain durable and are never replayed after a workflow restart.',
            ],
            self::AFFECTED_DEPENDENCY_BATCH => [
                'label' => 'Dependency file units and affected batches',
                'default' => 4,
                'description' => 'Independent per-file dependency rebuilds, bounded targeted dependency batches and projection-reconciliation file units. Work is isolated by file/concurrency key; lower this if dependency maintenance causes database pressure.',
            ],
            self::SEARCH_HEAVY => [
                'label' => 'Search and catalogue diagnostics',
                'default' => 1,
                'description' => 'Search-index rebuilds and bounded catalogue-wide diagnostic scans. Database read intensive.',
            ],
            self::IMPORT_HEAVY => [
                'label' => 'Normal staged package imports',
                'default' => 8,
                'description' => 'Independent per-file Unreal package imports protected by per-file concurrency keys.',
            ],
            self::SOURCE_ARCHIVE_IMPORT => [
                'label' => 'Staged source archive imports',
                'default' => 4,
                'description' => 'Independent uploaded ZIP/7z/RAR/PAK source roots. Each worker stays with one source tree; this limit controls how many source archives may be expanded concurrently.',
            ],
            self::ARCHIVE_IMPORT_HEAVY => [
                'label' => 'Archive and backup coordinators',
                'default' => 1,
                'description' => 'Serial archive/backup coordinator and entry work that should not fan out as independent source roots.',
            ],
            self::BUCKET_PROCESSING => [
                'label' => 'Upload Bucket processing',
                'default' => 8,
                'description' => 'Redirect preparation, uploaded-file processing, archive-extracted file processing and unverified metadata repair.',
            ],
            self::UNVERIFIED_MATCHES => [
                'label' => 'Unverified dependency matching',
                'default' => 2,
                'description' => 'Builds cached exact object-path/game compatibility evidence for Upload Bucket files. Database and metadata read intensive.',
            ],
            self::UNVERIFIED_FILE_MAINTENANCE => [
                'label' => 'Unverified file maintenance',
                'default' => 2,
                'description' => 'Independent unverified duplicate hash/delete and storage-reconciliation file units. Errors are isolated to one physical queue file.',
            ],
            self::GAME_FILE_REASSIGNMENT => [
                'label' => 'Game file reassignment',
                'default' => 2,
                'description' => 'Moves verified files back to Unverified Files or safely verifies them in another game before retiring the source copy.',
            ],
            self::STORAGE_HEAVY => [
                'label' => 'Storage and backup maintenance',
                'default' => 1,
                'description' => 'Backup export and storage-maintenance coordinators. Their independently recoverable child units use narrower resource classes where safe.',
            ],
            self::PACKAGE_HEAVY => [
                'label' => 'Generated download packages',
                'default' => 1,
                'description' => 'Generated mod/package archives that perform sustained file-system work.',
            ],
            self::HOUSEKEEPING => [
                'label' => 'Housekeeping',
                'default' => 2,
                'description' => 'Stale artifact and upload-progress pruning.',
            ],
            self::DEFAULT => [
                'label' => 'Other background jobs',
                'default' => 4,
                'description' => 'Jobs without a specialised resource profile.',
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function for(string $jobType, array $payload): JobResourceProfile
    {
        return match ($jobType) {
            JobType::FULL_SYNC_GAME => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::defaultLimit(1),
                self::PROJECTION_CONCURRENCY_KEY
            ),
            JobType::FULL_SYNC_FILE,
            JobType::REPAIR_COMPACT_METADATA_FILE => new JobResourceProfile(
                self::FULL_SYNC_UNIT,
                self::defaultLimit(2),
                self::positiveKey('import:file-id:', $payload['file_id'] ?? null)
            ),
            JobType::FULL_SYNC_DEPENDENCY_FILE => self::fullSyncDependencyProfile($payload),
            JobType::REBUILD_GAME_DEPENDENCIES => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('dependency:game:', $payload['game_id'] ?? null)
            ),
            JobType::REBUILD_FILE_DEPENDENCIES => new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::defaultLimit(4),
                self::positiveKey('dependency:file:', $payload['file_id'] ?? null)
            ),
            JobType::REBUILD_AFFECTED_DEPENDENCIES => self::affectedDependencyProfile($payload),
            JobType::RECONCILE_CATALOG_PROJECTIONS => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::defaultLimit(1),
                self::projectionKey($payload)
            ),
            JobType::RECONCILE_CATALOG_PROJECTION_FILE => new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::defaultLimit(4),
                self::positiveKey('projection:file:', $payload['affected_file_id'] ?? null)
            ),
            JobType::REBUILD_FILE_SEARCH_INDEX => new JobResourceProfile(
                self::SEARCH_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('search:file:', $payload['file_id'] ?? null)
            ),
            JobType::SCAN_POSSIBLE_MISNAMED_FILES => new JobResourceProfile(
                self::SEARCH_HEAVY,
                self::defaultLimit(1),
                'diagnostic:possible-misnamed-files'
            ),
            JobType::REPAIR_SOURCE_IDENTITY_FILE => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('source-identity:file:', $payload['file_id'] ?? null)
            ),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('source-identity:game:', $payload['game_id'] ?? null)
            ),
            JobType::PROCESS_PUBLIC_UPLOAD => new JobResourceProfile(
                self::BUCKET_PROCESSING,
                self::defaultLimit(8),
                self::positiveKey('public-upload:', $payload['public_upload_id'] ?? null)
            ),
            JobType::PREPARE_BUCKET_REDIRECT,
            JobType::PROCESS_BUCKET_UPLOAD,
            JobType::PROCESS_BUCKET_STAGED_PACKAGE,
            JobType::REPAIR_UNVERIFIED_METADATA => new JobResourceProfile(
                self::BUCKET_PROCESSING,
                self::defaultLimit(8),
                self::bucketFileKey($payload)
            ),
            JobType::PROCESS_BUCKET_ARCHIVE => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::defaultLimit(1),
                self::bucketFileKey($payload)
            ),
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES => new JobResourceProfile(
                self::UNVERIFIED_MATCHES,
                self::defaultLimit(2),
                self::unverifiedMatchKey($payload)
            ),
            JobType::GAME_FILE_REASSIGN => new JobResourceProfile(
                self::STORAGE_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('game-file-reassign:source-game:', $payload['source_game_id'] ?? null)
            ),
            JobType::GAME_FILE_REASSIGN_BATCH => new JobResourceProfile(
                self::GAME_FILE_REASSIGNMENT,
                self::defaultLimit(2),
                self::gameFileReassignmentKey($payload)
            ),
            JobType::IMPORT_STAGED_PACKAGE => new JobResourceProfile(
                self::IMPORT_HEAVY,
                self::defaultLimit(8),
                self::importFileKey($payload)
            ),
            JobType::IMPORT_STAGED_PAK,
            JobType::IMPORT_STAGED_ARCHIVE => new JobResourceProfile(
                self::SOURCE_ARCHIVE_IMPORT,
                self::defaultLimit(4),
                self::importFileKey($payload)
            ),
            JobType::IMPORT_STAGED_PAK_ENTRY => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('pak-entry-parent:', $payload['workflow_parent_job_id'] ?? null)
            ),
            JobType::IMPORT_GAME_BACKUP,
            JobType::IMPORT_GAME_BACKUP_ENTRY => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::defaultLimit(1),
                JobType::IMPORT_GAME_BACKUP_ENTRY === $jobType
                    ? self::positiveKey('backup-entry-parent:', $payload['workflow_parent_job_id'] ?? null)
                    : self::positiveKey('import:game:', $payload['game_id'] ?? null)
            ),
            JobType::EXPORT_GAME_BACKUP => new JobResourceProfile(
                self::STORAGE_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('game-backup-export:', $payload['game_id'] ?? null)
            ),
            JobType::CLEAN_UNVERIFIED_DUPLICATES => new JobResourceProfile(
                self::STORAGE_HEAVY,
                self::defaultLimit(1),
                'unverified-duplicate-cleanup'
            ),
            JobType::HASH_UNVERIFIED_DUPLICATE,
            JobType::DELETE_UNVERIFIED_DUPLICATE => new JobResourceProfile(
                self::UNVERIFIED_FILE_MAINTENANCE,
                self::defaultLimit(2),
                self::unverifiedQueueFileKey($payload)
            ),
            JobType::RECONCILE_UNVERIFIED_STORAGE => self::unverifiedReconcileProfile($payload),
            JobType::GENERATE_MOD_PACKAGE => new JobResourceProfile(
                self::PACKAGE_HEAVY,
                self::defaultLimit(1),
                self::positiveKey('package:file:', $payload['file_id'] ?? null)
            ),
            JobType::PRUNE_UPLOAD_PROGRESS,
            JobType::PRUNE_PUBLIC_UPLOADS => new JobResourceProfile(
                self::HOUSEKEEPING,
                self::defaultLimit(2),
                'public-upload-prune'
            ),
            JobType::PRUNE_STALE_ARTIFACTS => new JobResourceProfile(
                self::HOUSEKEEPING,
                self::defaultLimit(2),
                JobType::PRUNE_STALE_ARTIFACTS === $jobType && !empty($payload['prune_unit'])
                    ? 'stale-artifact-prune:' . strtolower(trim((string)$payload['prune_unit']))
                    : (JobType::PRUNE_STALE_ARTIFACTS === $jobType ? 'stale-artifact-pruning' : null)
            ),
            default => new JobResourceProfile(
                self::DEFAULT,
                self::defaultLimit(4)
            ),
        };
    }

    /** @param array<string,mixed> $payload */
    private static function fullSyncDependencyProfile(array $payload): JobResourceProfile
    {
        $batchIds = $payload['file_ids'] ?? null;
        if (is_array($batchIds) && $batchIds !== []) {
            $parentId = max(0, (int)($payload['workflow_parent_job_id'] ?? 0));
            $startId = max(0, (int)($payload['batch_start_file_id'] ?? 0));
            $key = $parentId > 0 && $startId > 0
                ? 'dependency:full-sync-batch:' . $parentId . ':' . $startId
                : null;
            return new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::defaultLimit(4),
                $key
            );
        }

        return new JobResourceProfile(
            self::FULL_SYNC_UNIT,
            self::defaultLimit(2),
            self::positiveKey('dependency:file:', $payload['file_id'] ?? null)
        );
    }

    /** @param array<string,mixed> $payload */
    private static function affectedDependencyProfile(array $payload): JobResourceProfile
    {
        $affectedFileId = (int)($payload['affected_file_id'] ?? 0);
        if ($affectedFileId > 0) {
            $sourceFileId = max(0, (int)($payload['file_id'] ?? 0));
            return new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::defaultLimit(4),
                'dependency:affected-file-unit:' . $sourceFileId . ':' . $affectedFileId
            );
        }

        $batchIds = $payload['affected_file_ids'] ?? null;
        if (is_array($batchIds) && $batchIds !== []) {
            return new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::defaultLimit(4),
                self::affectedBatchKey($payload)
            );
        }

        return new JobResourceProfile(
            self::DEPENDENCY_HEAVY,
            self::defaultLimit(1),
            self::affectedDependencyKey($payload)
        );
    }

    /** @param array<string,mixed> $payload */
    private static function unverifiedReconcileProfile(array $payload): JobResourceProfile
    {
        if (trim((string)($payload['reconcile_queue_name'] ?? '')) !== '') {
            return new JobResourceProfile(
                self::UNVERIFIED_FILE_MAINTENANCE,
                self::defaultLimit(2),
                self::unverifiedQueueFileKey([
                    'queue_game_id' => $payload['reconcile_game_id'] ?? 0,
                    'queue_name' => $payload['reconcile_queue_name'] ?? '',
                ])
            );
        }
        return new JobResourceProfile(
            self::STORAGE_HEAVY,
            self::defaultLimit(1),
            'unverified-storage-reconciliation'
        );
    }

    private static function defaultLimit(int $default): int
    {
        return max(1, min($default, 100));
    }

    /** @param array<string,mixed> $payload */
    private static function affectedDependencyKey(array $payload): ?string
    {
        $gameKey = self::positiveKey('dependency:affected-game:', $payload['game_id'] ?? null);
        if ($gameKey !== null) {
            return $gameKey;
        }
        return self::positiveKey('dependency:affected-file:', $payload['file_id'] ?? null);
    }

    /** @param array<string,mixed> $payload */
    private static function affectedBatchKey(array $payload): ?string
    {
        $sourceFileId = (int)($payload['file_id'] ?? 0);
        $batchNumber = (int)($payload['batch_number'] ?? 0);
        if ($sourceFileId < 1 || $batchNumber < 1) {
            return null;
        }
        return 'dependency:affected-batch:' . $sourceFileId . ':' . $batchNumber;
    }

    /** @param array<string,mixed> $payload */
    private static function bucketFileKey(array $payload): string
    {
        $fileId = (int)($payload['file_id'] ?? 0);
        if ($fileId > 0) {
            return 'bucket:file-id:' . $fileId;
        }

        foreach (['upload_id', 'staged_path', 'source_relative_path', 'original_name'] as $field) {
            $value = strtolower(trim(str_replace('\\', '/', (string)($payload[$field] ?? ''))));
            if ($value !== '') {
                return 'bucket:file:' . substr(hash('sha256', $field . ':' . $value), 0, 48);
            }
        }

        return 'bucket:unidentified';
    }

    /** @param array<string,mixed> $payload */
    private static function unverifiedMatchKey(array $payload): string
    {
        $fileId = (int)($payload['file_id'] ?? 0);
        if ($fileId > 0) {
            return 'unverified-match:file:' . $fileId;
        }
        return 'unverified-match:' . (strtolower(trim((string)($payload['scope'] ?? 'bucket'))) ?: 'bucket');
    }

    /** @param array<string,mixed> $payload */
    private static function unverifiedQueueFileKey(array $payload): string
    {
        $gameId = max(0, (int)($payload['queue_game_id'] ?? 0));
        $name = strtolower(trim(str_replace('\\', '/', (string)($payload['queue_name'] ?? ''))));
        return 'unverified:file:' . $gameId . ':' . substr(hash('sha256', $name), 0, 40);
    }

    /** @param array<string,mixed> $payload */
    private static function gameFileReassignmentKey(array $payload): string
    {
        $parentId = max(0, (int)($payload['workflow_parent_job_id'] ?? 0));
        $startId = max(0, (int)($payload['batch_start_id'] ?? 0));
        if ($parentId > 0 && $startId > 0) {
            return 'game-file-reassign:batch:' . $parentId . ':' . $startId;
        }
        return 'game-file-reassign:batch:' . substr(hash('sha256', json_encode($payload) ?: ''), 0, 40);
    }

    /** @param array<string,mixed> $payload */
    private static function importFileKey(array $payload): string
    {
        $fileId = (int)($payload['file_id'] ?? 0);
        if ($fileId > 0) {
            return 'import:file-id:' . $fileId;
        }

        foreach (['sha256', 'staged_path', 'source_relative_path', 'original_name'] as $field) {
            $value = strtolower(trim(str_replace('\\', '/', (string)($payload[$field] ?? ''))));
            if ($value !== '') {
                return 'import:file:' . substr(hash('sha256', $field . ':' . $value), 0, 48);
            }
        }

        return 'import:unidentified';
    }

    /** @param array<string,mixed> $payload */
    private static function projectionKey(array $payload): string
    {
        return self::PROJECTION_CONCURRENCY_KEY;
    }

    private static function positiveKey(string $prefix, mixed $value): ?string
    {
        $id = (int)$value;
        return $id > 0 ? $prefix . $id : null;
    }
}
