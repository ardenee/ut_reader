<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

/**
 * Source-release authority for the UE5 Zen file/table reader only.
 *
 * Cooked Zen packages can omit versioning information. Never infer an engine
 * release from IoStore TOC v8 or from the layout compatibility alone.
 * Registered game 14 is Satisfactory 1.2 (Epic UE5.6.1 release).
 * Older direct UE5.8.3 original-asset parity fixtures remain independently
 * selectable and do not silently change their historical source policy.
 */
final class Uedb5ZenSourcePolicy
{
    public const UE5_56 = 'ue5-5.6.1-release-cef1527368284855f68e631bec945b262bdfb050';
    public const UE5_58 = 'ue5-5.8.3-release-396c9f059903aed5fec78ecd3d437a40c6415368';

    public static function forGameId(int $gameId): string
    {
        return match ($gameId) {
            14 => self::UE5_56,
            default => throw new RuntimeException(
                'No Epic UE5 Zen source release is registered for game_id=' . $gameId .
                '. Do not infer a source version from an unversioned cooked header.'
            ),
        };
    }

    public static function assertSupported(string $policy): void
    {
        if (!in_array($policy, [self::UE5_56, self::UE5_58], true)) {
            throw new RuntimeException('Unsupported Epic UE5 Zen source release policy.');
        }
    }
}
