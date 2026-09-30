<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Ut2004SnapshotBuilder
{
    public const MIN_VERSION = 60;
    public const MAX_VERSION = 128;
    public const SOURCE_POLICY = 'ue2-ut2004-v3369-package-v128';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        if ($version < self::MIN_VERSION || $version > self::MAX_VERSION) {
            throw new RuntimeException(
                'UT2004 package version ' . $version . ' is outside source-proven V5 range 60-128.'
            );
        }
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'UT2004',
            'policy' => self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.ut2004',
        ]);
    }
}
