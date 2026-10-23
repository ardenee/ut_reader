<?php
/**
 * UE1: Epic engine source is the base; game source adds known deltas.
 *
 * Archive version is NEVER a game identifier. In particular, Unreal
 * v1.227 (package 69, licensee 227) is not Unreal Tournament 99.
 * A later UE1 archive without established lineage is left unresolved,
 * rather than silently selecting the UT99 v400 source.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use InvalidArgumentException;

final class CatalogUE1ClassicSourceFamily
{
    public const EPIC_UE1='epic-ue1';
    public const UT99_V400='ut99-v400';
    public const UNREAL_V227='unreal-v227';

    /**
     * Identify a selected *archive source implementation*, not a
     * package's engine generation. Caller-proven game lineage overrides
     * the Epic UE1 engine baseline only where source exists.
     *
     * Versions > 61 cannot prove UT99 identity. Requiring provenance
     * prevents plausible but incorrect decoded fields from being exposed.
     */
    public static function select(int $version,?string $sourceFamily=null):string
    {
        if($sourceFamily!==null&&!in_array($sourceFamily,[
            self::EPIC_UE1,self::UT99_V400,self::UNREAL_V227
        ],true)){
            throw new InvalidArgumentException('Unknown UE1 game/source family');
        }
        if($sourceFamily===self::UNREAL_V227){
            // An Unreal v1.227 loader may also open older UE1 package
            // versions. The explicit game selection describes the loader
            // source, not necessarily the file's original producer.
            if($version<34||$version>69){
                throw new InvalidArgumentException(
                    'Archive version is outside the supported Unreal v1.227 loader range');
            }
            return self::UNREAL_V227;
        }
        if($sourceFamily===self::UT99_V400){
            return self::UT99_V400;
        }
        if($version>61){
            throw new InvalidArgumentException(
                'UE1 archive version alone does not identify the game. '.
                'Supply an established source lineage; do not assume UT99.');
        }
        return self::EPIC_UE1;
    }

    /**
     * The package/licensee header is a game identifier ONLY where
     * confirmed in the game source. No version-only UT99 selection.
     *
     * @param array<string,mixed> $header
     */
    public static function selectFromHeader(
        array $header,?string $sourceFamily=null
    ):string{
        $version=(int)($header['version']??0);
        $licensee=(int)($header['licenseeVersion']??($header['licensee']??0));
        if($version===69&&$licensee===227){
            if($sourceFamily!==null&&$sourceFamily!==self::UNREAL_V227){
                throw new InvalidArgumentException(
                    'This package identifies Unreal v1.227, not the requested game source');
            }
            return self::UNREAL_V227;
        }
        if($sourceFamily===self::UNREAL_V227&&$version===69
            &&$licensee!==0&&$licensee!==227){
            throw new InvalidArgumentException(
                'Conflicting explicit Unreal v1.227 source and package licensee identity');
        }
        return self::select($version,$sourceFamily);
    }

    /**
     * Always retain separate engine foundation, source revision and
     * game-specific implementation provenance.
     */
    public static function sourceLayers(
        int $version,int $licenseeVersion,?string $sourceFamily=null
    ):array{
        $selected=self::selectFromHeader(
            ['version'=>$version,'licenseeVersion'=>$licenseeVersion],
            $sourceFamily);
        $v227=$selected===self::UNREAL_V227;
        return [
            'engine_base'=>'epic-ue1',
            'engine_archive_implementation_source'=>
                $selected===self::UT99_V400?self::UT99_V400:self::EPIC_UE1,
            'game_source_overlay'=>$v227?self::UNREAL_V227:
                ($selected===self::UT99_V400?self::UT99_V400:null),
            'source_profile'=>$selected,
            'game_identity_inferred_from_version_only'=>false,
            'full_game_core_source_available'=>!$v227,
            'source_completeness'=>$v227
                ?'UE1 engine baseline + v227 game emitter overrides; '.
                 'v227 replacement Core/Src unavailable, core delta parity open'
                :'pinned source available; native comparison deferred',
        ];
    }
}
