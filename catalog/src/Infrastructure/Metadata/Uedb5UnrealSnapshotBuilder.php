<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

final class Uedb5UnrealSnapshotBuilder
{
    public const POLICY_V120_EARLY = 'ue1-unreal-v120-loadable-v34-59';
    public const POLICY_V224 = 'ue1-unreal-v224-package-v68';
    public const POLICY_V69_SHARED = 'ue1-shared-v69-ut432-serializer';
    public const POLICY_FORWARD_COMPAT = 'ue1-unreal-forward-loader-compatible';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE1PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal',
            'policy' => $version > 69
                ? self::POLICY_FORWARD_COMPAT
                : ($version < 60 ? self::POLICY_V120_EARLY : ($version <= 68 ? self::POLICY_V224 : self::POLICY_V69_SHARED)),
            'schema_prefix' => 'ue1.unreal',
        ]);
    }
}
