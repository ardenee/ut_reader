<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Unreal2SnapshotBuilder
{
    public const SOURCE_POLICY = 'ue2-unreal2-package-v126';
    public const POLICY_FORWARD_COMPAT = 'ue2-unreal2-forward-loader-compatible';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal II',
            'policy' => $version > 126 ? self::POLICY_FORWARD_COMPAT : self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.unreal2',
        ]);
    }
}
