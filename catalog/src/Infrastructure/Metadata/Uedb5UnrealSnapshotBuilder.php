<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

final class Uedb5UnrealSnapshotBuilder
{
    public const POLICY_V120_EARLY = 'ue1-unreal-v120-loadable-v34-59';
    public const POLICY_V224_PARTIAL = 'ue1-unreal-v224-v60-68-public-source-partial';
    public const POLICY_V227_PARTIAL = 'ue1-unreal-v227-v69-public-source-partial';
    public const POLICY_POST_V69_UNRESOLVED = 'ue1-unreal-post-v69-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE1PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal',
            'policy' => $version > 69
                ? self::POLICY_POST_V69_UNRESOLVED
                : ($version < 60
                    ? self::POLICY_V120_EARLY
                    : ($version <= 68 ? self::POLICY_V224_PARTIAL : self::POLICY_V227_PARTIAL)),
            'schema_prefix' => 'ue1.unreal',
        ]);
    }
}
