<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the domain class `JobResourcePolicy` for job type.
 * Why: It keeps resource/concurrency policy out of handlers and pages.
 * Role: Domain model/contract code representing durable-job resource limits.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

use Closure;
use Throwable;

final class JobResourcePolicy
{
    public const DEPENDENCY_HEAVY = 'dependency-heavy';
    public const FULL_SYNC_UNIT = 'full-sync-unit';
    public const AFFECTED_DEPENDENCY_BATCH = 'affected-dependency-batch';
    public const SEARCH_HEAVY = 'search-heavy';
    public const IMPORT_HEAVY = 'import-heavy';
    public const ARCHIVE_IMPORT_HEAVY = 'archive-import-heavy';
    public const BUCKET_PROCESSING = 'bucket-processing';
    public const UNVERIFIED_MATCHES = 'unverified-matches';
    public const STORAGE_HEAVY = 'storage-heavy';
    public const PACKAGE_HEAVY = 'package-heavy';
    public const HOUSEKEEPING = 'housekeeping';
    public const DEFAULT = 'default';
    public const PROJECTION_CONCURRENCY_KEY = 'projection:catalog-maintenance';

    private static ?Closure $limitResolver = null;

    /** @param callable(string,int):int|null $resolver */
    public static function setLimitResolver(?callable $resolver): void
    {
        self::$limitResolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /** @return array<string,array{label:string,default:int,description:string}> */
    public static function definitions(): array
    {
        return [
            self::DEPENDENCY_HEAVY => [
                'label' => 'Dependency and projection work',
                'default' => 1,
                'description' => 'Full Sync coordinators, whole-game/file dependency rebuilds, projection reconciliation and source-identity repairs. Database intensive.',
            ],
            self::FULL_SYNC_UNIT => [
                'label' => 'Full Sync file units',
                'default' => 2,
                'description' => 'Independent per-file Full Sync reimport and dependency units. Completed units remain durable and are never replayed after a workflow restart.',
            ],
            self::AFFECTED_DEPENDENCY_BATCH => [
                'label' => 'Affected dependency batches',
                'default' => 2,
                'description' => 'Targeted dependency refresh batches after a new provider is imported. Independent files may run concurrently; per-file locks and compact publication retries protect overlap.',
            ],
            self::SEARCH_HEAVY => [
                'label' => 'Search-index rebuilds',
                'default' => 1,
                'description' => 'Rebuilds file search projections and indexes.',
            ],
            self::IMPORT_HEAVY => [
                'label' => 'Normal staged package imports',
                'default' => 8,
                'description' => 'Independent per-file Unreal package imports protected by per-file concurrency keys.',
            ],
            self::ARCHIVE_IMPORT_HEAVY => [
                'label' => 'Archive and backup imports',
                'default' => 1,
                'description' => 'PAK and game-backup import coordinators/entry units. Entry units remain serial by default to avoid archive/storage contention and preserve canonical-before-alias restore ordering.',
            ],
            self::BUCKET_PROCESSING => [
                'label' => 'Upload Bucket processing',
                'default' => 8,
                'description' => 'Redirect preparation, uploaded-file processing and unverified metadata repair.',
            ],
            self::UNVERIFIED_MATCHES => [
                'label' => 'Unverified dependency matching',
                'default' => 2,
                'description' => 'Builds cached exact object-path/game compatibility evidence for Upload Bucket files. Database and metadata read intensive.',
            ],
            self::STORAGE_HEAVY => [
                'label' => 'Storage and backup maintenance',
                'default' => 1,
                'description' => 'Backup export, duplicate cleanup and storage reconciliation.',
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
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::PROJECTION_CONCURRENCY_KEY
            ),
            JobType::FULL_SYNC_FILE => new JobResourceProfile(
                self::FULL_SYNC_UNIT,
                self::configuredLimit(self::FULL_SYNC_UNIT, 2),
                self::positiveKey('import:file-id:', $payload['file_id'] ?? null)
            ),
            JobType::FULL_SYNC_DEPENDENCY_FILE => new JobResourceProfile(
                self::FULL_SYNC_UNIT,
                self::configuredLimit(self::FULL_SYNC_UNIT, 2),
                self::positiveKey('dependency:file:', $payload['file_id'] ?? null)
            ),
            JobType::REBUILD_GAME_DEPENDENCIES => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('dependency:game:', $payload['game_id'] ?? null)
            ),
            JobType::REBUILD_FILE_DEPENDENCIES => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('dependency:file:', $payload['file_id'] ?? null)
            ),
            JobType::REBUILD_AFFECTED_DEPENDENCIES => self::affectedDependencyProfile($payload),
            JobType::RECONCILE_CATALOG_PROJECTIONS => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::projectionKey($payload)
            ),
            JobType::REBUILD_FILE_SEARCH_INDEX => new JobResourceProfile(
                self::SEARCH_HEAVY,
                self::configuredLimit(self::SEARCH_HEAVY, 1),
                self::positiveKey('search:file:', $payload['file_id'] ?? null)
            ),
            JobType::REPAIR_SOURCE_IDENTITY_FILE => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('source-identity:file:', $payload['file_id'] ?? null)
            ),
            JobType::REPAIR_SOURCE_IDENTITY_GAME => new JobResourceProfile(
                self::DEPENDENCY_HEAVY,
                self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
                self::positiveKey('source-identity:game:', $payload['game_id'] ?? null)
            ),
            JobType::PREPARE_BUCKET_REDIRECT,
            JobType::PROCESS_BUCKET_UPLOAD,
            JobType::REPAIR_UNVERIFIED_METADATA => new JobResourceProfile(
                self::BUCKET_PROCESSING,
                self::configuredLimit(self::BUCKET_PROCESSING, 8),
                self::bucketFileKey($payload)
            ),
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES => new JobResourceProfile(
                self::UNVERIFIED_MATCHES,
                self::configuredLimit(self::UNVERIFIED_MATCHES, 2),
                self::unverifiedMatchKey($payload)
            ),
            JobType::IMPORT_STAGED_PACKAGE => new JobResourceProfile(
                self::IMPORT_HEAVY,
                self::configuredLimit(self::IMPORT_HEAVY, 8),
                self::importFileKey($payload)
            ),
            JobType::IMPORT_STAGED_PAK => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::configuredLimit(self::ARCHIVE_IMPORT_HEAVY, 1),
                self::positiveKey('import:game:', $payload['game_id'] ?? null)
            ),
            JobType::IMPORT_STAGED_PAK_ENTRY => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::configuredLimit(self::ARCHIVE_IMPORT_HEAVY, 1),
                self::positiveKey('pak-entry-parent:', $payload['workflow_parent_job_id'] ?? null)
            ),
            JobType::IMPORT_GAME_BACKUP,
            JobType::IMPORT_GAME_BACKUP_ENTRY => new JobResourceProfile(
                self::ARCHIVE_IMPORT_HEAVY,
                self::configuredLimit(self::ARCHIVE_IMPORT_HEAVY, 1),
                JobType::IMPORT_GAME_BACKUP_ENTRY === $jobType
                    ? self::positiveKey('backup-entry-parent:', $payload['workflow_parent_job_id'] ?? null)
                    : self::positiveKey('import:game:', $payload['game_id'] ?? null)
            ),
            JobType::EXPORT_GAME_BACKUP => new JobResourceProfile(
                self::STORAGE_HEAVY,
                self::configuredLimit(self::STORAGE_HEAVY, 1),
                self::positiveKey('game-backup-export:', $payload['game_id'] ?? null)
            ),
            JobType::CLEAN_UNVERIFIED_DUPLICATES,
            JobType::RECONCILE_UNVERIFIED_STORAGE => new JobResourceProfile(
                self::STORAGE_HEAVY,
                self::configuredLimit(self::STORAGE_HEAVY, 1),
                $jobType === JobType::CLEAN_UNVERIFIED_DUPLICATES
                    ? 'unverified-duplicate-cleanup'
                    : 'unverified-storage-reconciliation'
            ),
            JobType::GENERATE_MOD_PACKAGE => new JobResourceProfile(
                self::PACKAGE_HEAVY,
                self::configuredLimit(self::PACKAGE_HEAVY, 1),
                self::positiveKey('package:file:', $payload['file_id'] ?? null)
            ),
            JobType::PRUNE_UPLOAD_PROGRESS,
            JobType::PRUNE_STALE_ARTIFACTS => new JobResourceProfile(
                self::HOUSEKEEPING,
                self::configuredLimit(self::HOUSEKEEPING, 2),
                $jobType === JobType::PRUNE_STALE_ARTIFACTS ? 'stale-artifact-pruning' : null
            ),
            default => new JobResourceProfile(
                self::DEFAULT,
                self::configuredLimit(self::DEFAULT, 4)
            ),
        };
    }

    /** @param array<string,mixed> $payload */
    private static function affectedDependencyProfile(array $payload): JobResourceProfile
    {
        $batchIds = $payload['affected_file_ids'] ?? null;
        if (is_array($batchIds) && $batchIds !== []) {
            return new JobResourceProfile(
                self::AFFECTED_DEPENDENCY_BATCH,
                self::configuredLimit(self::AFFECTED_DEPENDENCY_BATCH, 2),
                self::affectedBatchKey($payload)
            );
        }

        return new JobResourceProfile(
            self::DEPENDENCY_HEAVY,
            self::configuredLimit(self::DEPENDENCY_HEAVY, 1),
            self::affectedDependencyKey($payload)
        );
    }

    private static function configuredLimit(string $resourceClass, int $default): int
    {
        $name = 'UNREALDB_JOB_RESOURCE_LIMIT_' . strtoupper(str_replace('-', '_', $resourceClass));
        $raw = getenv($name);
        $limit = $default;
        if ($raw !== false && $raw !== '') {
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value !== false) {
                $limit = (int)$value;
            }
        }
        $limit = max(1, min($limit, 100));

        if (self::$limitResolver !== null) {
            try {
                $resolved = (self::$limitResolver)($resourceClass, $limit);
                $limit = max(1, min((int)$resolved, 100));
            } catch (Throwable $error) {
                error_log('[UnrealDB jobs] Could not resolve saved resource limit for '
                    . $resourceClass . ': ' . $error->getMessage());
            }
        }

        return $limit;
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
