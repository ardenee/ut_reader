<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the domain class `JobType` for job type.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Domain model/contract code representing core catalog behavior without presentation concerns.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

final class JobType
{
    public const FULL_SYNC_GAME = 'catalog.full_sync_game';
    public const FULL_SYNC_FILE = 'catalog.full_sync_file';
    public const FULL_SYNC_DEPENDENCY_FILE = 'catalog.full_sync_dependency_file';
    public const REBUILD_GAME_DEPENDENCIES = 'catalog.rebuild_game_dependencies';
    public const REBUILD_FILE_DEPENDENCIES = 'catalog.rebuild_file_dependencies';
    public const REBUILD_AFFECTED_DEPENDENCIES = 'catalog.rebuild_affected_dependencies';
    public const REBUILD_FILE_SEARCH_INDEX = 'catalog.rebuild_file_search_index';
    public const RECONCILE_CATALOG_PROJECTIONS = 'catalog.reconcile_catalog_projections';
    public const REPAIR_SOURCE_IDENTITY_FILE = 'catalog.repair_source_identity_file';
    public const REPAIR_SOURCE_IDENTITY_GAME = 'catalog.repair_source_identity_game';
    public const SOURCE_SCAN = 'catalog.source.scan';
    public const CLEAN_UNVERIFIED_DUPLICATES = 'catalog.clean_unverified_duplicates';
    public const REPAIR_UNVERIFIED_METADATA = 'catalog.repair_unverified_metadata';
    public const REFRESH_UNVERIFIED_GAME_MATCHES = 'catalog.refresh_unverified_game_matches';
    public const CROSS_GAME_COPY_BATCH = 'catalog.cross_game_copy_batch';
    public const GENERATE_MOD_PACKAGE = 'catalog.generate_mod_package';
    public const EXPORT_GAME_BACKUP = 'catalog.export_game_backup';
    public const IMPORT_GAME_BACKUP = 'catalog.import_game_backup';
    public const IMPORT_GAME_BACKUP_ENTRY = 'catalog.import_game_backup_entry';
    public const IMPORT_STAGED_PACKAGE = 'catalog.import_staged_package';
    public const IMPORT_STAGED_PAK = 'catalog.import_staged_pak';
    public const IMPORT_STAGED_PAK_ENTRY = 'catalog.import_staged_pak_entry';
    public const PREPARE_BUCKET_REDIRECT = 'catalog.prepare_bucket_redirect';
    public const PROCESS_BUCKET_UPLOAD = 'catalog.process_bucket_upload';
    public const RECONCILE_UNVERIFIED_STORAGE = 'catalog.reconcile_unverified_storage';
    public const PRUNE_STALE_ARTIFACTS = 'catalog.prune_stale_artifacts';
    public const PRUNE_UPLOAD_PROGRESS = 'catalog.prune_upload_progress';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FULL_SYNC_GAME,
            self::FULL_SYNC_FILE,
            self::FULL_SYNC_DEPENDENCY_FILE,
            self::REBUILD_GAME_DEPENDENCIES,
            self::REBUILD_FILE_DEPENDENCIES,
            self::REBUILD_AFFECTED_DEPENDENCIES,
            self::REBUILD_FILE_SEARCH_INDEX,
            self::RECONCILE_CATALOG_PROJECTIONS,
            self::REPAIR_SOURCE_IDENTITY_FILE,
            self::REPAIR_SOURCE_IDENTITY_GAME,
            self::SOURCE_SCAN,
            self::CLEAN_UNVERIFIED_DUPLICATES,
            self::REPAIR_UNVERIFIED_METADATA,
            self::REFRESH_UNVERIFIED_GAME_MATCHES,
            self::CROSS_GAME_COPY_BATCH,
            self::GENERATE_MOD_PACKAGE,
            self::EXPORT_GAME_BACKUP,
            self::IMPORT_GAME_BACKUP,
            self::IMPORT_GAME_BACKUP_ENTRY,
            self::IMPORT_STAGED_PACKAGE,
            self::IMPORT_STAGED_PAK,
            self::IMPORT_STAGED_PAK_ENTRY,
            self::PREPARE_BUCKET_REDIRECT,
            self::PROCESS_BUCKET_UPLOAD,
            self::RECONCILE_UNVERIFIED_STORAGE,
            self::PRUNE_STALE_ARTIFACTS,
            self::PRUNE_UPLOAD_PROGRESS,
        ];
    }
}
