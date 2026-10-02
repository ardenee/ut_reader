<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Unreal2SnapshotBuilder
{
    public const MIN_VERSION = 60;
    public const MAX_VERSION = 128;
    public const SOURCE_POLICY = 'ue2-unreal2-package-v126';
    public const POLICY_FORWARD_COMPAT = 'ue2-unreal2-forward-loader-compatible-v127-128';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        if ($version < self::MIN_VERSION || $version > self::MAX_VERSION) {
            throw new RuntimeException(
                'Unreal II package version ' . $version . ' is outside accepted V5 range 60-128.'
            );
        }
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal II',
            'policy' => $version > 126 ? self::POLICY_FORWARD_COMPAT : self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.unreal2',
        ]);
    }
}
