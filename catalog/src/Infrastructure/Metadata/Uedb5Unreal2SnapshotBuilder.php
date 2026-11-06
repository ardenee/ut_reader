<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Unreal2SnapshotBuilder
{
    public const POLICY_V69_2000 = 'ue2-unreal2-2000-12-09-package-v69';
    public const POLICY_V126_GAME = 'ue2-unreal2-2003-01-07-package-v126';
    public const POLICY_V126_GENERIC = 'ue2-warfare-v126-serialization';
    public const POLICY_POST_V126_UNRESOLVED = 'ue2-unreal2-post-v126-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        $licensee = (int)($reader->getHeader()['licenseeVersion'] ?? -1);
        $policy = match (true) {
            // The December 2000 Unreal II source has PACKAGE_MIN_VERSION=60;
            // legacy licensee-0 packages v60-69 use this same game loader.
            $version >= 60 && $version <= 69 && in_array($licensee, [0, 0x7F], true) => self::POLICY_V69_2000,
            $version === 126 && $licensee === 0 => self::POLICY_V126_GAME,
            $version > 126 => self::POLICY_POST_V126_UNRESOLVED,
            default => self::POLICY_V126_GENERIC,
        };
        return Uedb5UE2TableSnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal II',
            'policy' => $policy,
            'schema_prefix' => 'ue2.unreal2',
        ]);
    }
}
