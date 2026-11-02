<?php
/** UE2-only source attribution for static import/export-table VerifyImport projections. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe2VerifyImportProjectionResolver;

final class Uedb5Ue2VerifyImportSourceProfile
{
    public static function fromGameVersion(int $gameId, ?int $version, ?int $licensee): ?string
    {
        if ($version === null || $licensee === null) {
            return null;
        }
        return match (true) {
            // Source: Unreal II 2000-12-09, Core/Src/UnLinker.cpp.
            $gameId === 2 && $version === 69 && $licensee === 0
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000,
            // Source: UT2003 2107, Core/Src/UnLinker.cpp.
            $gameId === 4 && $version === 120 && $licensee === 28
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107,
            // Sources: UT2004 3369 v128 and UT2004Src v129, Core/Src/UnLinker.cpp.
            $gameId === 5 && (($version === 128 && $licensee === 29)
                || ($version === 129 && $licensee === 27))
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UT2004_V129,
            default => null,
        };
    }

    /** Require source-identical metadata, not merely a plausible version number. */
    public static function fromSnapshot(array $snapshot): ?string
    {
        $file = (array)($snapshot['file'] ?? []);
        $summary = (array)(((array)($snapshot['sections'] ?? []))['summary'][0] ?? []);
        $gameId = (int)($file['game_id'] ?? 0);
        $version = isset($summary['package_version']) ? (int)$summary['package_version'] : null;
        $licensee = isset($summary['licensee_version']) ? (int)$summary['licensee_version'] : null;
        $profile = self::fromGameVersion($gameId, $version, $licensee);
        if ($profile === null) {
            return null;
        }
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        $schema = strtolower(trim((string)(((array)($snapshot['section_schemas'] ?? []))['imports'] ?? '')));
        $authorized = match ($gameId) {
            2 => $policy === strtolower(Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000)
                && str_starts_with($schema, 'ue2.unreal2.'),
            4 => $policy === strtolower(Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY)
                && str_starts_with($schema, 'ue2.ut2003.'),
            5 => $policy === strtolower($version === 128
                    ? Uedb5Ut2004SnapshotBuilder::POLICY_V128
                    : Uedb5Ut2004SnapshotBuilder::POLICY_V129)
                && str_starts_with($schema, 'ue2.ut2004.'),
            default => false,
        };
        return $authorized ? $profile : null;
    }
}
