<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Unreal2SnapshotBuilder
{
    public const MIN_VERSION = 60;
    public const MAX_VERSION = 126;
    public const SOURCE_POLICY = 'ue2-unreal2-package-v126';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        if ($version < self::MIN_VERSION || $version > self::MAX_VERSION) {
            throw new RuntimeException(
                'Unreal II package version ' . $version . ' is outside source-proven V5 range 60-126.'
            );
        }
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal II',
            'policy' => self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.unreal2',
        ]);
    }
}
