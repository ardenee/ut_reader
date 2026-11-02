<?php
/**
 * UE1-only source-revision authorization for ORIGINAL VerifyImport logic.
 *
 * Package-file version and licensee alone do not authenticate the native
 * game loader. Later Unreal and UT99 builds share format versions with
 * earlier builds but did not publish corresponding VerifyImport method bodies.
 * Do not borrow v1.200/v1.400 loader logic for these revisions.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver as Verify;

final class Uedb5Ue1VerifyImportSourceProfile
{
    public static function fromGameVersion(int $gameId, ?int $version, int $licensee): ?string
    {
        if ($version === null || $licensee !== 0) {
            return null;
        }
        try {
            $sourceKey = Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (\RuntimeException) {
            return null;
        }
        if ($sourceKey === 'unrealgold'
            && $version >= 34 && $version < 60
            && Uedb5UnrealSnapshotBuilder::policyForVersion($version, $licensee)
                === Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY) {
            return Verify::PROFILE_UNREAL_V120;
        }
        if ($sourceKey === 'ut99'
            && $version >= 60 && $version <= 68
            && Uedb5Ut99SnapshotBuilder::policyForVersion($version, $licensee)
                === Uedb5Ut99SnapshotBuilder::POLICY_RETAIL) {
            return Verify::PROFILE_UT99_V1400;
        }
        return null;
    }

    /** @param array<string,mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): ?string
    {
        $file = (array)($snapshot['file'] ?? []);
        $gameId = (int)($file['game_id'] ?? 0);
        $summary = (array)($snapshot['sections']['summary'][0] ?? []);
        $version = isset($summary['package_version']) ? (int)$summary['package_version'] : null;
        $licensee = (int)($summary['licensee_version'] ?? 0);
        $profile = self::fromGameVersion($gameId, $version, $licensee);
        if ($profile === null) {
            return null;
        }
        $policy = strtolower(trim((string)($snapshot['source_policy'] ?? '')));
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        if ($profile === Verify::PROFILE_UNREAL_V120
            && str_starts_with($schema, 'ue1.unreal.')
            && $policy === strtolower(Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY)) {
            return $profile;
        }
        if ($profile === Verify::PROFILE_UT99_V1400
            && str_starts_with($schema, 'ue1.ut99.')
            && $policy === strtolower(Uedb5Ut99SnapshotBuilder::POLICY_RETAIL)) {
            return $profile;
        }
        return null;
    }
}
