<?php
/**
 * UE1: Epic engine source is the base; game source adds known deltas.
 *
 * Archive version is NEVER a game identifier. In particular, Unreal
 * v1.227 (package 69, licensee 227) is not Unreal Tournament 99.
 * A later UE1 archive without established lineage is left unresolved,
 * rather than silently selecting the UT99 v432 source.
 * Missing game Core/Src implementations do not replace or invalidate the
 * Epic UE1 engine baseline. Apply only actual game-source changes; otherwise
 * the selected game inherits the engine's serializer. A new game-defined
 * class or explicit changed field is still an override to investigate.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use InvalidArgumentException;

final class CatalogUE1ClassicSourceFamily
{
    public const EPIC_UE1='epic-ue1';
    public const UT99_V432='ut99-v432';
    // Explicit post-v432 runtime provenance. Internally this inherits the
    // v432 archive family and applies only *proven* 469f source/compiled
    // changes; 69/0 alone does not identify 469f versus older UT99.
    public const UT99_V469F='ut99-v469f';
    // Historical v400 selection is a deprecated CLI alias for the
    // updated UT99 loader, never a second selectable source profile.
    public const LEGACY_UT99_V400='ut99-v400';
    public const UNREAL_V227='unreal-v227';
    // Explicit original shipped 2012 v227i Core.dll runtime ABI; never
    // inferred from v69/227 package headers. The archive source inherits
    // Unreal v227, while changed post-load UFunction ABI is build-specific.
    public const UNREAL_V227I='unreal-v227i';
    // Explicit original 2026 v227k compiled loader behavior. The 69/227
    // package header identifies Unreal, NOT the loaded Core.dll revision.
    public const UNREAL_V227K='unreal-v227k';
    /** Chronological game-source snapshots; each change persists until explicitly overridden.
     * Earlier native-resource/Unrealfile serializer generations have their own wire formats;
     * method inheritance is resolved within the applicable archive format, not by name alone.
     */
    public const UNREAL_GAME_REVISION_CHAIN=[
        'unreal-v0.82','unreal-v0.83','unreal-v0.84a',
        'unreal-v0.86x','unreal-v0.867','unreal-v1.200',
        'unreal-v1.224','unreal-v1.227',
    ];

    /**
     * PACKAGE_MIN_VERSION and PACKAGE_FILE_VERSION in the pinned
     * build-selected game engine source. Inclusive complete-backwards-
     * compatibility gates, NOT conclusive game identities, and not
     * absolute limits on the engine's attempted loading.
     *
     * Unreal v1.200  : Core/Inc/UnObjVer.h (34..61)
     * UT99 v432      : Core/Inc/UnObjVer.h (60..69)
     * UT99 v400      : inherited implementation baseline (60..68), not a separate profile
     * Unreal v1.227  : Core/Inc/UnObjVer.h (60..69)
     */
    public const GAME_VERSION_GATES=[
        self::EPIC_UE1=>['minimum'=>34,'maximum'=>61],
        self::UT99_V432=>['minimum'=>60,'maximum'=>69],
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
        if($sourceFamily===self::LEGACY_UT99_V400
            ||$sourceFamily===self::UT99_V469F){
            $sourceFamily=self::UT99_V432;
        }elseif(in_array($sourceFamily,[self::UNREAL_V227I,self::UNREAL_V227K],true)){
            $sourceFamily=self::UNREAL_V227;
        }
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
     * Unreal v1.200 CheckVersion is source-inspected. The public v432
     * Core/Engine serializer implementation is precompiled, so UT99 v400
     * checks are historical evidence, NOT proof of v432's exact prompt.
     * Unreal v1.227 Core/Src is likewise unavailable.
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
        // The source-selected inherited UE1/UT99 engine loader remains the
        // applicable code when game Core/Src is absent. Native binary
        // equivalence is tracked separately, not used to erase inheritance.
        $verified=($selectedSource===self::EPIC_UE1);
        $warningUnknown=$selectedSource===self::UT99_V432
            ?'inherited_ut99_v400_linker_source_compiled_equivalence_unchecked'
            :'inherited_ue1_linker_source_compiled_equivalence_unchecked';
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
                           :$warningUnknown)
                :($verified?'no_version_rejection_in_inspected_linker_check'
                           :$warningUnknown),
            'source_linker_check_available'=>true,
            'source_linker_implementation_selection'=>$selectedSource===self::UT99_V432
                ?'inherited_ut99_v400_core_source':'inherited_epic_ue1_core_source',
            'source_linker_compiled_build_equivalence_verified'=>$verified,
            'ut99_source_inheritance'=>'UT99 v400 Core/Src baseline; v432 prebuilt Core.lib overrides require native oracle',
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
        if($sourceFamily===self::LEGACY_UT99_V400
            ||$sourceFamily===self::UT99_V469F){
            $sourceFamily=self::UT99_V432;
        }elseif(in_array($sourceFamily,[self::UNREAL_V227I,self::UNREAL_V227K],true)){
            $sourceFamily=self::UNREAL_V227;
        }
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
            'engine_archive_implementation_source'=>$selected===self::UT99_V432
                ?self::LEGACY_UT99_V400:self::EPIC_UE1,
            'game_source_overlay'=>$v227?self::UNREAL_V227:
                ($selected===self::UT99_V432?self::UT99_V432:null),
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
            'game_source_revision_chain'=>$v227
                ?self::UNREAL_GAME_REVISION_CHAIN
                :($selected===self::EPIC_UE1
                    ?array_slice(self::UNREAL_GAME_REVISION_CHAIN,0,6)
                    :($sourceFamily===self::UT99_V469F
                        ?['ue1-engine','ut99-v400','ut99-v432','ut99-v469f']
                        :['ue1-engine','ut99-v400','ut99-v432'])),
            'explicit_runtime_source_overlay'=>in_array($sourceFamily,[
                self::UT99_V469F,self::UNREAL_V227I,self::UNREAL_V227K,
            ],true)?$sourceFamily:null,
            // Last *present* classic UE1 Core serializer implementation.
            // v1.224/v1.227 contain newer headers but no replacement
            // Core/Src/UnClass.cpp or UnProp.cpp; absence is not a reset.
            'inherited_classic_core_serializer_source'=>$selected===self::UT99_V432
                ?'ut99-v400':self::EPIC_UE1,
            'game_core_cpp_override_absent_in_later_revisions'=>$v227
                ?['unreal-v1.224','unreal-v1.227']
                :($selected===self::UT99_V432?['ut99-v432']:[]),
            'previous_game_overrides_persist_until_explicit_later_change'=>true,
            'archive_format_generation'=>'classic_ue1_package',
            // Absence of a game-specific replacement .cpp is inheritance,
            // NOT a new missing-Core blocker. A present changed declaration,
            // inline implementation or game-defined class still needs its
            // actual override semantics; never substitute another game.
            'full_game_core_source_available'=>$selected===self::EPIC_UE1,
            'engine_baseline_source_available'=>true,
            'missing_game_core_implementation_means'=>'inherit_ue1_engine_source',
            'game_specific_override_requires_present_source_change'=>true,
            'missing_game_core_cpp_is_acceptance_blocker'=>false,
            'source_completeness'=>$v227
                ?'UE1 engine source inherited for absent Core/Src; apply only v227 game source changes (including Emitter and changed headers/classes)'
                :($selected===self::UT99_V432
                    ?'UE1 engine plus inherited UT99 v400 Core/Src; apply only changed v432 game source and introduced methods'
                    :'Pinned Unreal v1.200 engine source baseline available'),
        ];
    }
}
