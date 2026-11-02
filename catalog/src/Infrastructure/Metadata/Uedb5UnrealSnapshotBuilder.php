<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

final class Uedb5UnrealSnapshotBuilder
{
    public const POLICY_V120_EARLY = 'ue1-unreal-v120-loadable-v34-59';
    // Original v1.200 source accepts 34..61, but the runtime of later
    // Unreal Gold patches is not proven identical by package version alone.
    public const POLICY_V120_V60_61_FORMAT = 'ue1-unreal-v120-format-v60-61-game-revision-partial';
    public const POLICY_V224_PARTIAL = 'ue1-unreal-v224-v62-68-public-source-partial';
    public const POLICY_V69_UNATTRIBUTED = 'ue1-unreal-v69-game-revision-unattributed-partial';
    public const POLICY_V227_PARTIAL = 'ue1-unreal-v227-v69-licensee227-public-source-partial';
    public const POLICY_POST_V69_UNRESOLVED = 'ue1-unreal-post-v69-profile-admitted-unresolved';

    public static function policyForVersion(int $version, int $licensee): string
    {
        return match (true) {
            $version < 60 => self::POLICY_V120_EARLY,
            $version <= 61 && $licensee === 0 => self::POLICY_V120_V60_61_FORMAT,
            $version <= 68 => self::POLICY_V224_PARTIAL,
            $version === 69 && $licensee === 227 => self::POLICY_V227_PARTIAL,
            $version === 69 => self::POLICY_V69_UNATTRIBUTED,
            default => self::POLICY_POST_V69_UNRESOLVED,
        };
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public static function build(CatalogUE1PackageReader $reader, array $file): array
    {
        $header = $reader->getHeader();
        $version = (int)($header['version'] ?? -1);
        $licensee = (int)($header['licenseeVersion'] ?? -1);
        $policy = self::policyForVersion($version, $licensee);
        return Uedb5UE1TableSnapshotBuilder::build($reader, $file, [
            'label' => 'Unreal',
            'policy' => $policy,
            'schema_prefix' => 'ue1.unreal',
        ]);
    }
}
