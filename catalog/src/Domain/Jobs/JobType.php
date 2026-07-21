<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

final class JobType
{
    public const REBUILD_GAME_DEPENDENCIES = 'catalog.rebuild_game_dependencies';
    public const REBUILD_FILE_DEPENDENCIES = 'catalog.rebuild_file_dependencies';
    public const REBUILD_AFFECTED_DEPENDENCIES = 'catalog.rebuild_affected_dependencies';
    public const REPAIR_SOURCE_IDENTITY_FILE = 'catalog.repair_source_identity_file';
    public const REPAIR_SOURCE_IDENTITY_GAME = 'catalog.repair_source_identity_game';
    public const CLEAN_UNVERIFIED_DUPLICATES = 'catalog.clean_unverified_duplicates';
    public const GENERATE_MOD_PACKAGE = 'catalog.generate_mod_package';
    public const EXPORT_GAME_BACKUP = 'catalog.export_game_backup';
    public const IMPORT_GAME_BACKUP = 'catalog.import_game_backup';
    public const IMPORT_STAGED_PACKAGE = 'catalog.import_staged_package';
    public const IMPORT_STAGED_PAK = 'catalog.import_staged_pak';
    public const RECONCILE_UNVERIFIED_STORAGE = 'catalog.reconcile_unverified_storage';
    public const PRUNE_STALE_ARTIFACTS = 'catalog.prune_stale_artifacts';
    public const PRUNE_UPLOAD_PROGRESS = 'catalog.prune_upload_progress';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::REBUILD_GAME_DEPENDENCIES,
            self::REBUILD_FILE_DEPENDENCIES,
            self::REBUILD_AFFECTED_DEPENDENCIES,
            self::REPAIR_SOURCE_IDENTITY_FILE,
            self::REPAIR_SOURCE_IDENTITY_GAME,
            self::CLEAN_UNVERIFIED_DUPLICATES,
            self::GENERATE_MOD_PACKAGE,
            self::EXPORT_GAME_BACKUP,
            self::IMPORT_GAME_BACKUP,
            self::IMPORT_STAGED_PACKAGE,
            self::IMPORT_STAGED_PAK,
            self::RECONCILE_UNVERIFIED_STORAGE,
            self::PRUNE_STALE_ARTIFACTS,
            self::PRUNE_UPLOAD_PROGRESS,
        ];
    }
}
