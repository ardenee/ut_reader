<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application port used to resolve the target game for profiled uploads.
 * Why: Upload orchestration needs the stable game slug for failed-file preservation without owning SQL or PDO.
 * Role: Narrow Application-layer game lookup contract implemented by persistence infrastructure.
 * Audit: Keep this port limited to data required by the profiled-upload use case.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload\Contract;

interface ProfiledUploadGameCatalog
{
    public function slug(int $gameId): ?string;
}
