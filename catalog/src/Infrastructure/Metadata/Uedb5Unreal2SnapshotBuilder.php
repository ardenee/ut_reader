<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Unreal2SnapshotBuilder
{
    public const POLICY_V69_2000 = 'ue2-unreal2-2000-12-09-package-v69';
    public const POLICY_V126_GENERIC = 'ue2-warfare-v126-serialization';
    public const POLICY_POST_V126_UNRESOLVED = 'ue2-unreal2-post-v126-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal II',
            'policy' => ($version >= 60 && $version <= 69)
                ? self::POLICY_V69_2000
                : ($version > 126 ? self::POLICY_POST_V126_UNRESOLVED : self::POLICY_V126_GENERIC),
            'schema_prefix' => 'ue2.unreal2',
        ]);
    }
}
