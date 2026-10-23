<?php
/**
 * Select which *compiled source serializer* loads a classic UE1 archive.
 *
 * The baseline is the canonical Epic UE1 engine source at
 * L:/Source/Engine/UE1/Source. Game-specific source such as
 * UT99 v400 overrides only when the caller establishes that lineage.
 *
 * A package version is not a source-engine fingerprint: UT99 can load
 * legacy packages generated before UT99. Expose the override explicitly,
 * rather than trying alternative binary layouts and guessing a lineage.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use InvalidArgumentException;

final class CatalogUE1ClassicSourceFamily
{
    public const EPIC_UE1 = 'epic-ue1';
    public const UT99_V400 = 'ut99-v400';

    public static function select(int $version, ?string $sourceFamily = null): string
    {
        if ($sourceFamily !== null
            && !in_array($sourceFamily,[self::EPIC_UE1,self::UT99_V400],true)) {
            throw new InvalidArgumentException('Unknown classic UE1 source family');
        }
        $selected=$sourceFamily??($version>=62?self::UT99_V400:self::EPIC_UE1);
        if($selected===self::EPIC_UE1&&$version>=62){
            throw new InvalidArgumentException(
                'Epic UE1 baseline source cannot define UT99 package versions >=62');
        }
        return $selected;
    }
}
