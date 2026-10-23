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

    /**
     * Source hierarchy: the Epic UE1 engine always supplies the object
     * archive base. This source-branch selector picks the implementation
     * revision for those inherited archive operations; it does NOT name
     * the game that produced the package. Game overrides are separate.
     */
    public static function sourceLayers(
        int $version,int $licenseeVersion,?string $sourceFamily=null
    ):array{
        $wire=self::select($version,$sourceFamily);
        return [
            'engine_base'=>'epic-ue1',
            'engine_archive_implementation_source'=>$wire,
            'game_source_overlay'=>$version===69&&$licenseeVersion===227
                ?'unreal-v227':null,
            'game_identity_inferred_from_version_only'=>false,
            'full_game_core_source_available'=>
                !($version===69&&$licenseeVersion===227),
        ];
    }

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
