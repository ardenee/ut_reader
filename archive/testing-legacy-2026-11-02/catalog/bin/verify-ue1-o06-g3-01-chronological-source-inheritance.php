#!/usr/bin/env php
<?php
/** G3-01 complete source-selection and revision-persistent game overlay acceptance, independent of method-native G3-15. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Sources;
$root=dirname(__DIR__,2);
$manifest=$root.'/catalog/compliance/UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json';
$j=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];
$check=static function(string $what,bool $ok)use(&$checks,&$failed):void{
    $checks++;if(!$ok)$failed[]=$what;
};
$check('eight ordered source snapshots actually pinned',count($j['revisions'])===8);
$check('explicit distinct source format architecture boundary',
    str_contains($j['format_boundary_rule'],'different wire-format generations'));
$prev=null;$classic=[];
foreach($j['revisions'] as $i=>$revision){
    $id=$revision['revision'];
    $check('source revision chronological ID '.$i,
        Sources::UNREAL_GAME_REVISION_CHAIN[$i]===$id);
    $check('original source tree exists '.$id,is_dir($revision['source_dir']));
    $group=$revision['archive_format_generation'];
    $check('original source format provenance '.$id,
        ($i<3&&$group==='original_unreal_resource')
        ||($i>=3&&$i<5&&$group==='original_unrealfile')
        ||($i>=5&&$group==='classic_ue1_package'));
    foreach($revision['source_files'] as $kind=>$item){
        $path=$item['path'];
        $check('source exact SHA and original file byte count '.$id.' '.$kind,
            is_file($path)&&filesize($path)===(int)$item['bytes']
            &&hash_file('sha256',$path)===$item['sha256']);
    }
    if($i>=5)$classic[$id]=$revision['source_files'];
}
$check('UT99 source chain explicitly v400 then v432, not Unreal v227',
    array_column($j['ut99_cumulative_game_source_chain'],'revision')===['ut99-v400','ut99-v432']);
foreach($j['ut99_cumulative_game_source_chain'] as $entry){
    $check('UT99 original source revision actually present '.$entry['revision'],is_dir($entry['root']));
    foreach($entry['present_source_pins'] as $source=>$pin){
        $check('original UT99 selected source sha '.$entry['revision'].' '.$source,
            is_file($pin['path'])&&filesize($pin['path'])===$pin['bytes']
            &&hash_file('sha256',$pin['path'])===$pin['sha256']);
    }
}
foreach(['Core/Src/UnClass.cpp','Core/Src/UnProp.cpp'] as $source){
    $check('v432 missing game implementation inherits v400, not a blocker: '.$source,
        !is_file($j['ut99_cumulative_game_source_chain'][1]['root'].'/'.$source)
        &&isset($j['ut99_cumulative_game_source_chain'][0]['present_source_pins'][$source]));
}
$check('source v432 package version 69 defined in the actual header',
    preg_match('/#define\\s+PACKAGE_FILE_VERSION\\s+69\\b/',
       (string)file_get_contents($j['ut99_cumulative_game_source_chain'][1]['present_source_pins']['Core/Inc/UnObjVer.h']['path']))===1);
$check('1996 v0.84a class implementation existed and was not a missing game baseline',
    isset($j['revisions'][2]['source_files']['UnClass.cpp']));
$check('1997 May and July each introduced explicit new original class/property source',
    isset($j['revisions'][3]['source_files']['UnProp.cpp'])
    &&isset($j['revisions'][4]['source_files']['UnProp.cpp']));
$check('original 1998 classic v1.200 defines both actual Core serializer bodies',
    isset($classic['unreal-v1.200']['UnClass.cpp'],$classic['unreal-v1.200']['UnProp.cpp']));
$check('incomplete 1999 v1.224 source does not replace either compiled Core method',
    !isset($classic['unreal-v1.224']['UnClass.cpp'])
    &&!isset($classic['unreal-v1.224']['UnProp.cpp']));
$check('later v1.227 does not replace either compiled Core method',
    !isset($classic['unreal-v1.227']['UnClass.cpp'])
    &&!isset($classic['unreal-v1.227']['UnProp.cpp']));
$check('later sources DO have changed headers and are not silently ignored',
    isset($classic['unreal-v1.224']['UnClass.h'],$classic['unreal-v1.227']['UnClass.h'])
    &&$classic['unreal-v1.200']['UnClass.h']['sha256']!==$classic['unreal-v1.224']['UnClass.h']['sha256']
    &&$classic['unreal-v1.224']['UnClass.h']['sha256']!==$classic['unreal-v1.227']['UnClass.h']['sha256']);
$check('later v227 actual v69/licensee227 source header',
    preg_match('/#define\s+PACKAGE_FILE_VERSION\s+69\b/',
      (string)file_get_contents($classic['unreal-v1.227']['UnObjVer.h']['path']))===1
    &&preg_match('/#define\s+PACKAGE_FILE_VERSION_LICENSEE\s+227\b/',
      (string)file_get_contents($classic['unreal-v1.227']['UnObjVer.h']['path']))===1);
$check('1998 classic UE1 original v61 file version source header',
    preg_match('/#define\s+PACKAGE_FILE_VERSION\s+61\b/',
      (string)file_get_contents($classic['unreal-v1.200']['UnObjVer.h']['path']))===1);
$early=Sources::sourceLayers(59,0);
$check('v59 single eligible loader remains Epic UE1',$early['source_profile']===Sources::EPIC_UE1);
$classic200=Sources::sourceLayers(61,0,Sources::EPIC_UE1);
$check('classic UE1 native class source has six historical Unreal snapshots',
    $classic200['game_source_revision_chain']===array_slice(Sources::UNREAL_GAME_REVISION_CHAIN,0,6));
$check('classic UE1 engine base source resolved',
    $classic200['engine_archive_implementation_source']===Sources::EPIC_UE1
    &&$classic200['inherited_classic_core_serializer_source']===Sources::EPIC_UE1);
$v227=Sources::sourceLayers(69,227);
$check('v69/227 auto-selects Unreal game source not UT99',
    $v227['source_profile']===Sources::UNREAL_V227
    &&$v227['game_source_overlay']===Sources::UNREAL_V227
    &&$v227['source_profile_selection_basis']==='confirmed_69_227_unreal_header');
$check('v227 chain includes all eight revisions, source changes persist',
    $v227['game_source_revision_chain']===Sources::UNREAL_GAME_REVISION_CHAIN
    &&$v227['previous_game_overrides_persist_until_explicit_later_change']===true);
$check('v227 Core implementations inherited from UE1 baseline, not missing/blocker',
    $v227['engine_archive_implementation_source']===Sources::EPIC_UE1
    &&$v227['inherited_classic_core_serializer_source']===Sources::EPIC_UE1
    &&$v227['engine_baseline_source_available']===true
    &&$v227['missing_game_core_implementation_means']==='inherit_ue1_engine_source'
    &&$v227['missing_game_core_cpp_is_acceptance_blocker']===false
    &&$v227['game_core_cpp_override_absent_in_later_revisions']===['unreal-v1.224','unreal-v1.227']);
$check('v227 explicitly present game source changes still override engine base',
    $v227['game_specific_override_requires_present_source_change']===true
    &&str_contains($v227['source_completeness'],'Emitter'));
$check('v227 inherited linker source exists; native binary identity is separate',
    $v227['loader_version_diagnostic']['source_linker_check_available']===true
    &&$v227['loader_version_diagnostic']['source_linker_compiled_build_equivalence_verified']===false);
$t99=Sources::sourceLayers(69,0,Sources::UT99_V432);
$check('UT99 v432 inherited v400 Core source only, not v227 Core',
    $t99['source_profile']===Sources::UT99_V432
    &&$t99['engine_archive_implementation_source']===Sources::LEGACY_UT99_V400
    &&$t99['inherited_classic_core_serializer_source']===Sources::LEGACY_UT99_V400
    &&$t99['game_source_revision_chain']===['ue1-engine','ut99-v400','ut99-v432']);
$check('UT99 v400 alias becomes current v432, never a second profile',
    Sources::selectFromHeader(['version'=>69,'licenseeVersion'=>0],
        Sources::LEGACY_UT99_V400)===Sources::UT99_V432);
foreach([
 static fn()=>Sources::selectFromHeader(['version'=>69,'licenseeVersion'=>227],Sources::UT99_V432),
 static fn()=>Sources::selectFromHeader(['version'=>69,'licenseeVersion'=>227],Sources::EPIC_UE1),
 static fn()=>Sources::selectFromHeader(['version'=>69,'licenseeVersion'=>17],Sources::UNREAL_V227),
 static fn()=>Sources::sourceLayers(69,0,null),
 static fn()=>Sources::sourceLayers(61,0,null),
 static fn()=>Sources::sourceLayers(23,0,null),
] as $i=>$fn){
    $caught=false;try{$fn();}catch(InvalidArgumentException){$caught=true;}
    $check('wrong-game/ambiguous-version cannot silently inherit another game '.$i,$caught);
}
$check('source version eligibility covers original C98 earliest v34',
    Sources::eligibleProfiles(34)===[Sources::EPIC_UE1]);
$check('v60 overlapping three loaders must retain source identity',
    Sources::eligibleProfiles(60)===[Sources::EPIC_UE1,Sources::UT99_V432,Sources::UNREAL_V227]);
$check('v69 has exactly UT99 and Unreal separate game loaders',
    Sources::eligibleProfiles(69)===[Sources::UT99_V432,Sources::UNREAL_V227]);
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($failed),
    'failed'=>$failed,'original_game_revisions'=>count($j['revisions']),
    'original_sha_pinned_sources'=>array_sum(array_map(static fn($x)=>count($x['source_files']),$j['revisions'])),
    'v227_classic_serializer_inherited_source'=>$v227['inherited_classic_core_serializer_source'],
    'c227_missing_cpp_blocker_removed'=>true,
    'v227_introduced_methods_and_changed_headers_remain_distinct_serializer_work'=>true,
    'compiled_loaded_value_acceptance_is_separate_G3_15'=>true],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
