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
    public const REPAIR_COMPACT_METADATA_FILE = 'catalog.repair_compact_metadata_file';
    public const REBUILD_GAME_DEPENDENCIES = 'catalog.rebuild_game_dependencies';
    public const REBUILD_FILE_DEPENDENCIES = 'catalog.rebuild_file_dependencies';
    public const REBUILD_AFFECTED_DEPENDENCIES = 'catalog.rebuild_affected_dependencies';
    public const REBUILD_FILE_SEARCH_INDEX = 'catalog.rebuild_file_search_index';
    public const SCAN_POSSIBLE_MISNAMED_FILES = 'catalog.scan_possible_misnamed_files';
    public const RECONCILE_CATALOG_PROJECTIONS = 'catalog.reconcile_catalog_projections';
    public const RECONCILE_CATALOG_PROJECTION_FILE = 'catalog.reconcile_catalog_projection_file';
    public const REPAIR_SOURCE_IDENTITY_FILE = 'catalog.repair_source_identity_file';
    public const REPAIR_SOURCE_IDENTITY_GAME = 'catalog.repair_source_identity_game';
    public const SOURCE_SCAN = 'catalog.source.scan';
    public const CLEAN_BACKGROUND_JOB_HISTORY = 'catalog.clean_background_job_history';
    public const CLEAN_UNVERIFIED_DUPLICATES = 'catalog.clean_unverified_duplicates';
    public const HASH_UNVERIFIED_DUPLICATE = 'catalog.hash_unverified_duplicate';
    public const DELETE_UNVERIFIED_DUPLICATE = 'catalog.delete_unverified_duplicate';
    public const REPAIR_UNVERIFIED_METADATA = 'catalog.repair_unverified_metadata';
    public const REFRESH_UNVERIFIED_GAME_MATCHES = 'catalog.refresh_unverified_game_matches';
    public const UNVERIFIED_BULK_ACTION = 'catalog.unverified_bulk_action';
    public const UNVERIFIED_BULK_ACTION_BATCH = 'catalog.unverified_bulk_action_batch';
    public const GAME_FILE_REASSIGN = 'catalog.game_file_reassign';
    public const GAME_FILE_REASSIGN_BATCH = 'catalog.game_file_reassign_batch';
    public const CROSS_GAME_COPY_BATCH = 'catalog.cross_game_copy_batch';
    public const PROFILED_UPLOAD_BATCH = 'catalog.profiled_upload_batch';
    public const GENERATE_MOD_PACKAGE = 'catalog.generate_mod_package';
    public const EXPORT_GAME_BACKUP = 'catalog.export_game_backup';
    public const IMPORT_GAME_BACKUP = 'catalog.import_game_backup';
    public const IMPORT_GAME_BACKUP_ENTRY = 'catalog.import_game_backup_entry';
    public const IMPORT_STAGED_PACKAGE = 'catalog.import_staged_package';
    public const IMPORT_STAGED_PAK = 'catalog.import_staged_pak';
    public const IMPORT_STAGED_PAK_ENTRY = 'catalog.import_staged_pak_entry';
    public const IMPORT_STAGED_ARCHIVE = 'catalog.import_staged_archive';
    public const PREPARE_BUCKET_REDIRECT = 'catalog.prepare_bucket_redirect';
    public const PROCESS_BUCKET_UPLOAD = 'catalog.process_bucket_upload';
    public const PROCESS_PUBLIC_UPLOAD = 'catalog.process_public_upload';
    public const PROCESS_BUCKET_ARCHIVE = 'catalog.process_bucket_archive';
    public const PROCESS_BUCKET_STAGED_PACKAGE = 'catalog.process_bucket_staged_package';
    public const RECONCILE_UNVERIFIED_STORAGE = 'catalog.reconcile_unverified_storage';
    public const PRUNE_STALE_ARTIFACTS = 'catalog.prune_stale_artifacts';
    public const PRUNE_PUBLIC_UPLOADS = 'catalog.prune_public_uploads';
    public const PRUNE_UPLOAD_PROGRESS = 'catalog.prune_upload_progress';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::FULL_SYNC_GAME,
            self::FULL_SYNC_FILE,
            self::FULL_SYNC_DEPENDENCY_FILE,
            self::REPAIR_COMPACT_METADATA_FILE,
            self::REBUILD_GAME_DEPENDENCIES,
            self::REBUILD_FILE_DEPENDENCIES,
            self::REBUILD_AFFECTED_DEPENDENCIES,
            self::REBUILD_FILE_SEARCH_INDEX,
            self::SCAN_POSSIBLE_MISNAMED_FILES,
            self::RECONCILE_CATALOG_PROJECTIONS,
            self::RECONCILE_CATALOG_PROJECTION_FILE,
            self::REPAIR_SOURCE_IDENTITY_FILE,
            self::REPAIR_SOURCE_IDENTITY_GAME,
            self::SOURCE_SCAN,
            self::CLEAN_BACKGROUND_JOB_HISTORY,
            self::CLEAN_UNVERIFIED_DUPLICATES,
            self::HASH_UNVERIFIED_DUPLICATE,
            self::DELETE_UNVERIFIED_DUPLICATE,
            self::REPAIR_UNVERIFIED_METADATA,
            self::REFRESH_UNVERIFIED_GAME_MATCHES,
            self::UNVERIFIED_BULK_ACTION,
            self::UNVERIFIED_BULK_ACTION_BATCH,
            self::GAME_FILE_REASSIGN,
            self::GAME_FILE_REASSIGN_BATCH,
            self::CROSS_GAME_COPY_BATCH,
            self::PROFILED_UPLOAD_BATCH,
            self::GENERATE_MOD_PACKAGE,
            self::EXPORT_GAME_BACKUP,
            self::IMPORT_GAME_BACKUP,
            self::IMPORT_GAME_BACKUP_ENTRY,
            self::IMPORT_STAGED_PACKAGE,
            self::IMPORT_STAGED_PAK,
            self::IMPORT_STAGED_PAK_ENTRY,
            self::IMPORT_STAGED_ARCHIVE,
            self::PREPARE_BUCKET_REDIRECT,
            self::PROCESS_BUCKET_UPLOAD,
            self::PROCESS_PUBLIC_UPLOAD,
            self::PROCESS_BUCKET_ARCHIVE,
            self::PROCESS_BUCKET_STAGED_PACKAGE,
            self::RECONCILE_UNVERIFIED_STORAGE,
            self::PRUNE_STALE_ARTIFACTS,
            self::PRUNE_PUBLIC_UPLOADS,
            self::PRUNE_UPLOAD_PROGRESS,
        ];
    }
}