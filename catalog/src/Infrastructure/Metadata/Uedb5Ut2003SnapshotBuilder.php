<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Ut2003SnapshotBuilder
{
    public const SOURCE_POLICY = 'ue2-ut2003-v2107-package-v120';
    public const POLICY_POST_V120_UNRESOLVED = 'ue2-ut2003-post-v120-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'UT2003',
            'policy' => $version > 120 ? self::POLICY_POST_V120_UNRESOLVED : self::SOURCE_POLICY,
            'schema_prefix' => 'ue2.ut2003',
        ]);
    }
}
