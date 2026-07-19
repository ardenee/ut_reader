<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Jobs;

/**
 * Stable identifiers for jobs that can be executed outside an HTTP request.
 *
 * Job names are part of the durable database contract. Do not rename an
 * existing value; add a new value and keep a migration handler when needed.
 */
final class JobType
{
    public const REBUILD_GAME_DEPENDENCIES = 'catalog.rebuild_game_dependencies';
    public const REBUILD_FILE_DEPENDENCIES = 'catalog.rebuild_file_dependencies';
    public const REBUILD_AFFECTED_DEPENDENCIES = 'catalog.rebuild_affected_dependencies';
    public const REPAIR_SOURCE_IDENTITY_FILE = 'catalog.repair_source_identity_file';
    public const REPAIR_SOURCE_IDENTITY_GAME = 'catalog.repair_source_identity_game';
    public const CLEAN_UNVERIFIED_DUPLICATES = 'catalog.clean_unverified_duplicates';
    public const GENERATE_MOD_PACKAGE = 'catalog.generate_mod_package';
    public const PRUNE_UPLOAD_PROGRESS = 'catalog.prune_upload_progress';

    /**
     * @return list<string>
     */
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
            self::PRUNE_UPLOAD_PROGRESS,
        ];
    }
}
