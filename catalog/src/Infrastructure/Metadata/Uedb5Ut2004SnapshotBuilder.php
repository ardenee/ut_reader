<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Ut2004SnapshotBuilder
{
    public const POLICY_V128 = 'ue2-ut2004-v3369-package-v128';
    public const POLICY_V129 = 'ue2-ut2004-ut2004src-v129-64bit';
    public const POLICY_POST_V129_UNRESOLVED = 'ue2-ut2004-post-v129-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        return Uedb5LegacySnapshotBuilder::build($reader, $file, [
            'label' => 'UT2004',
            'policy' => $version > 129
                ? self::POLICY_POST_V129_UNRESOLVED
                : ($version === 129 ? self::POLICY_V129 : self::POLICY_V128),
            'schema_prefix' => 'ue2.ut2004',
        ]);
    }
}
