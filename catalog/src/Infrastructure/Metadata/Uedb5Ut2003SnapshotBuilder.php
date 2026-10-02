<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Ut2003SnapshotBuilder
{
    public const MIN_VERSION = 60;
    public const MAX_VERSION = 128;
    public const SOURCE_POLICY = 'ue2-ut2003-v2107-package-v120';
    public const POLICY_FORWARD_COMPAT = 'ue2-ut2003-forward-loader-compatible-v121-128';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        if ($version < self::MIN_VERSION || $version > self::MAX_VERSION) {
            throw new RuntimeException(
                'UT2003 package version ' . $version . ' is outside accepted V5 range 60-128.'
            );
        }
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'UT2003',
            'policy' => $version > 120 ? self::POLICY_FORWARD_COMPAT : self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.ut2003',
        ]);
    }
}
