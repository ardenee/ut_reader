<?php
/** Builds primary and catalogue-alias provider lookup keys for UEDB5. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5ProviderKeyBuilder
{
    public const SOURCE_PRIMARY = 1;
    public const SOURCE_ALIAS = 2;

    /** @param array<string,mixed> $registration @param list<array<string,mixed>> $aliases */
    public static function build(array $registration, array $aliases = []): array
    {
        $fileId = (int)($registration['file_id'] ?? 0);
        $gameId = (int)($registration['game_id'] ?? 0);
        $kind = (int)($registration['package_key_kind'] ?? 0);
        $key = (string)($registration['package_key'] ?? '');
        if ($fileId < 1 || $gameId < 1 || $kind < 1 || $key === '') {
            throw new RuntimeException('UEDB5 provider keys require complete staged registration identity.');
        }
        $rows = [[
            'source_kind'=>self::SOURCE_PRIMARY,'source_id'=>$fileId,'game_id'=>$gameId,
            'package_key_kind'=>$kind,'package_key'=>$key,'file_id'=>$fileId,
        ]];
        if (!in_array($kind, [Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME, Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME], true)) {
            return $rows;
        }
        foreach ($aliases as $alias) {
            $alias = (array)$alias;
            $aliasId = (int)($alias['id'] ?? 0);
            $name = (string)($alias['package_name'] ?? '');
            if ($aliasId < 1 || $name === '') { continue; }
            $normalized = $kind === Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME
                ? CatalogUnrealIdentityHash::fnameKey($name)
                : CatalogUnrealIdentityHash::nameKey($name);
            $rows[] = [
                'source_kind'=>self::SOURCE_ALIAS,
                'source_id'=>$aliasId,
                'game_id'=>$gameId,
                'package_key_kind'=>$kind,
                'package_key'=>md5($normalized, true),
                'file_id'=>$fileId,
            ];
        }
        return $rows;
    }
}
