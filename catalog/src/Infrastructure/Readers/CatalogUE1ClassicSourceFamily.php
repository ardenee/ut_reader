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
     * PACKAGE_MIN_VERSION and PACKAGE_FILE_VERSION in the pinned
     * build-selected game engine source. Inclusive complete-backwards-
     * compatibility gates, NOT conclusive game identities, and not
     * absolute limits on the engine's attempted loading.
     *
     * Unreal v1.200  : Core/Inc/UnObjVer.h (34..61)
     * UT99 v400      : Core/Inc/UnObjVer.h (60..68)
     * Unreal v1.227  : Core/Inc/UnObjVer.h (60..69)
     */
    public const GAME_VERSION_GATES=[
        self::EPIC_UE1=>['minimum'=>34,'maximum'=>61],
        self::UT99_V400=>['minimum'=>60,'maximum'=>68],
        self::UNREAL_V227=>['minimum'=>60,'maximum'=>69],
    ];

    /** @return list<string> */
    public static function eligibleProfiles(int $version):array
    {
        $profiles=[];
        foreach(self::GAME_VERSION_GATES as $family=>$gate){
            if($version>=$gate['minimum']&&$version<=$gate['maximum']){
                $profiles[]=$family;
            }
        }
        return $profiles;
    }

    /**
     * Source-accurate ULinkerLoad CheckVersion rule: the defined minimum
     * version is a *prompt*, not an automatic rejection. Its current
     * PACKAGE_FILE_VERSION is used for saving and initial archive setup,
     * not as a checked upper load cutoff. Use eligibleProfiles only to
     * prioritize likely candidates and warn when leaving the advertised
     * complete-backward-compatibility range.
     */
    public static function select(int $version,?string $sourceFamily=null):string
    {
        if($sourceFamily!==null&&!array_key_exists(
            $sourceFamily,self::GAME_VERSION_GATES)){
            throw new InvalidArgumentException('Unknown UE1 game/source family');
        }
        $eligible=self::eligibleProfiles($version);
        if($sourceFamily!==null){
            // Explicit known loader provenance takes priority over an
            // archive-version inference. Epic's linker does not reject
            // this outright; record its source warning separately.
            return $sourceFamily;
        }
        if(count($eligible)===1){
            // Selecting a sole eligible *known source profile* does NOT
            // establish that the game created this package.
            return $eligible[0];
        }
        if($eligible===[]){
            throw new InvalidArgumentException(sprintf(
                'No source profile covers UE1 package version %d within configured compatibility gates',
                $version));
        }
        throw new InvalidArgumentException(sprintf(
            'UE1 package version %d has overlapping source profiles [%s]; select known loader provenance or compare candidates',
            $version,implode(', ',$eligible)));
    }

    /**
     * Source-version diagnostic for known loader selection.
     *
     * This does NOT attempt to emulate GUI YesNo or run a game loader.
     * Only the two pinned v1.200 and UT99 sources include the inspected
     * CheckVersion below-minimum user warning; the partial v1.227 tree
     * has no replacement Core/Src for asserting its runtime prompt.
     */
    public static function loaderVersionDiagnostic(
        int $version,string $selectedSource
    ):array{
        if(!isset(self::GAME_VERSION_GATES[$selectedSource])){
            throw new InvalidArgumentException('Unknown UE1 game/source family');
        }
        $gate=self::GAME_VERSION_GATES[$selectedSource];
        $below=$version<$gate['minimum'];
        $above=$version>$gate['maximum'];
        $verified=($selectedSource!==self::UNREAL_V227);
        return [
            'source_profile'=>$selectedSource,
            'archive_version'=>$version,
            'source_minimum_warning_version'=>$gate['minimum'],
            'source_save_version'=>$gate['maximum'],
            'within_declared_compatibility_range'=>!$below&&!$above,
            'below_source_minimum'=>$below,
            'above_source_save_version'=>$above,
            'epic_linker_version_action'=>$below
                ?($verified?'warn_user_and_allow_continue_or_abort'
                           :'minimum_warning_behavior_unverified_for_v227')
                :($verified?'no_version_rejection_in_inspected_linker_check'
                           :'v227_linker_implementation_unavailable'),
            'source_linker_check_available'=>$verified,
            'runtime_load_success_verified'=>false,
        ];
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
                    'Header identifies Unreal v1.227 (69/227), conflicting with requested game source');
            }
            return self::select($version,self::UNREAL_V227);
        }
        if($sourceFamily===self::UNREAL_V227&&$version===69
            &&$licensee!==0&&$licensee!==227){
            throw new InvalidArgumentException(
                'Conflicting Unreal v1.227 selection and package licensee version');
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
            'eligible_profiles'=>self::eligibleProfiles($version),
            'loader_version_diagnostic'=>
                self::loaderVersionDiagnostic($version,$selected),
            'source_profile_selection_basis'=>$sourceFamily!==null
                ?'explicit_loader_provenance'
                :($version===69&&$licenseeVersion===227
                    ?'confirmed_69_227_unreal_header'
                    :'sole_version_eligible_profile_not_producer_identity'),
            'game_identity_inferred_from_version_only'=>false,
            'full_game_core_source_available'=>!$v227,
            'source_completeness'=>$v227
                ?'UE1 engine baseline + v227 game emitter overrides; '.
                 'v227 replacement Core/Src unavailable, core delta parity open'
                :'pinned source available; native comparison deferred',
        ];
    }
}
