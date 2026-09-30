<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

final class Uedb5UnrealSnapshotBuilder
{
    public const MIN_VERSION = 60;
    public const MAX_VERSION = 69;
    public const POLICY_V224 = 'ue1-unreal-v224-package-v68';
    public const POLICY_V69_SHARED = 'ue1-shared-v69-ut432-serializer';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE1PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        if ($version < self::MIN_VERSION || $version > self::MAX_VERSION) {
            throw new RuntimeException(
                'Unreal package version ' . $version . ' is outside source-proven V5 range 60-69.'
            );
        }
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal',
            'policy' => $version <= 68 ? self::POLICY_V224 : self::POLICY_V69_SHARED,
            'schema_prefix' => 'ue1.unreal',
        ]);
    }
}
