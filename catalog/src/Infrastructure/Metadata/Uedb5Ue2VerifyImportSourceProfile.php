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
            // The December 2000 game loader accepts both older licensee-0
            // packages and its emitted 0x7F form through this VerifyImport path.
            $gameId === 2 && $version >= 60 && $version <= 69 && in_array($licensee, [0, 0x7F], true)
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V69_2000,
            // The corrected January 2003 Unreal II source has distinct
            // public/private export handling and no active package-remap alias.
            $gameId === 2 && $version === 126 && $licensee === 0
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UNREAL2_V126_2003,
            // Source: UT2003 2107, Core/Src/UnLinker.cpp.
            $gameId === 4 && $version === 120 && $licensee === 28
                => PdoUe2VerifyImportProjectionResolver::PROFILE_UT2003_V2107,
            // UT2004 v3186/v127, v3369/v128, v129 source (0x1D),
            // plus original observed v129/0x1B backwards-compatible import path.
            $gameId === 5 && (($version === 127 && $licensee === 29)
                || ($version === 128 && $licensee === 29)
                || ($version === 129 && in_array($licensee, [27, 29], true)))
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
            2 => str_starts_with($schema, 'ue2.unreal2.')
                && (($version >= 60 && $version <= 69
                        && $policy === strtolower(Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000))
                    || ($version === 126 && $policy === strtolower(Uedb5Unreal2SnapshotBuilder::POLICY_V126_GAME))),
            4 => $policy === strtolower(Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY)
                && str_starts_with($schema, 'ue2.ut2003.'),
            // The v127/v128 UT2004 loader's VerifyImport implementations are
            // source-identical. A v127 package may have been read/staged by
            // the v128 loader (already true for production copies); its
            // source_policy identifies the READER, not the historical writer.
            // Do not extend this claim to v118/v121: those still lack an
            // accepted end-to-end loader-compatibility differential.
            5 => str_starts_with($schema, 'ue2.ut2004.')
                && ($version === 127
                    ? in_array($policy, [
                        strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V127),
                        strtolower(Uedb5Ut2004SnapshotBuilder::POLICY_V128),
                    ], true)
                    : $policy === strtolower(match ($version) {
                        128 => Uedb5Ut2004SnapshotBuilder::POLICY_V128,
                        default => Uedb5Ut2004SnapshotBuilder::POLICY_V129,
                    })),
            default => false,
        };
        return $authorized ? $profile : null;
    }
}
