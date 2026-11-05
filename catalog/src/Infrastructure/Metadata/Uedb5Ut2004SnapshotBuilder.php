<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

final class Uedb5Ut2004SnapshotBuilder
{
    public const POLICY_V127 = 'ue2-ut2004-v3186-package-v127';
    public const POLICY_V128 = 'ue2-ut2004-v3369-package-v128';
    public const POLICY_V129 = 'ue2-ut2004-ut2004src-v129-64bit';
    public const POLICY_OTHER_VERSIONS_UNATTRIBUTED = 'ue2-ut2004-package-source-unattributed';
    public const POLICY_POST_V129_UNRESOLVED = 'ue2-ut2004-post-v129-profile-admitted-unresolved';

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE2PackageReader $reader, array $file): array
    {
        $version = (int)($reader->getHeader()['version'] ?? -1);
        $licensee = (int)($reader->getHeader()['licenseeVersion'] ?? -1);
        $policy = match (true) {
            $version === 127 && $licensee === 0x1D => self::POLICY_V127,
            $version === 128 && $licensee === 0x1D => self::POLICY_V128,
            $version === 129 && in_array($licensee, [0x1B, 0x1D], true) => self::POLICY_V129,
            $version > 129 => self::POLICY_POST_V129_UNRESOLVED,
            default => self::POLICY_OTHER_VERSIONS_UNATTRIBUTED,
        };
        return Uedb5UE2TableSnapshotBuilder::build($reader, $file, [
            'label' => 'UT2004',
            'policy' => $policy,
            'schema_prefix' => 'ue2.ut2004',
        ]);
    }
}
