<?php
/** Stable game-id to UEDB5 source/storage contract mapping. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5GameSourceRegistry
{
    /** @return list<int> */
    public static function gameIds(): array
    {
        return [2, 3, 4, 5, 6, 7, 12, 14];
    }

    public static function sourceKey(int $gameId): string
    {
        return match ($gameId) {
            2 => 'unreal2',
            3 => 'ut99',
            4 => 'ut2003',
            5 => 'ut2004',
            6 => 'ut3',
            7 => 'ut4',
            12 => 'unrealgold',
            14 => 'ue5', // Satisfactory: separate classic/Zen reader dispatch
            default => throw new RuntimeException('No UEDB5 source contract is registered for game_id=' . $gameId . '.'),
        };
    }

    /** Stable on-disk games/<key>/verified directory key. */
    public static function storageKey(int $gameId): string
    {
        // The UE5 IoStore publisher retains source members under the actual
        // game slug, not the engine name. Zen source validation is a separate
        // route and never assumes the classic games/<key>/verified layout.
        return $gameId === 14 ? 'satisfactory' : self::sourceKey($gameId);
    }
}
