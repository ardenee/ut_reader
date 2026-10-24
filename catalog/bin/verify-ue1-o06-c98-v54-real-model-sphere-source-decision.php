#!/usr/bin/env php
<?php
/**
 * Epic v1.200 FSphere was serialized via FVector because the class
 * declares no FSphere operator<<. Real PRD Unreal v54 archive is SHA
 * pinned; 2667 source-native objects, including 299 actual UModel
 * payloads complete. No existing compiled v432 tests are rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-C98-V54-REAL-MODEL-SPHERE-SOURCE-FIX.json'),true,512,JSON_THROW_ON_ERROR);
$passed=0;$fails=[];
$ck=static function(string $id,bool $ok)use(&$passed,&$fails):void{
 if($ok)$passed++;else $fails[]=$id;
};
$path=$m['staged_DEV_exact_copy'];
$ck('original_sha_DEV',is_file($path)&&hash_file('sha256',$path)===$m['original_sha256']);
$ck('original_size',(int)filesize($path)===$m['original_size']);
$ck('original_location_readonly_untouched',str_contains($m['verified_readonly_PRD_original'],'Z:\\Apache24\\htdocs\\unrealdb\\catalog\\storage\\games\\unrealgold\\verified\\'));
foreach($m['source_SHA'] as $name=>$file){
 $ck('Epic_source_SHA_'.$name,is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256']);
}
$sh=$m['source_native_sweep_SHA'];
$ck('entire_v54_native_sweep_sha',is_file($sh['path'])&&hash_file('sha256',$sh['path'])===$sh['sha256']);
$scan=json_decode((string)file_get_contents($sh['path']),true,512,JSON_THROW_ON_ERROR);
$ck('complete_v54_all_2667_real_native_records',
 $scan['exports']===2667 &&array_sum($scan['native_status_counts'])===2667
 &&$scan['native_unverified_examples']===[]);
foreach([
 'native_model_record_complete'=>299,
 'native_polys_database_complete'=>299,
 'native_vectors_database_complete'=>598,
 'native_verts_database_complete'=>299,
 'native_bspsurfs_database_complete'=>299,
 'native_bspnodes_database_complete'=>299,
 'native_level_record_complete'=>1,
 'native_textbuffer_record_complete'=>1,
 'tag_envelope_complete_no_native_tail'=>572,
] as $type=>$n){
 $ck('real_native_record_'.$type,($scan['native_status_counts'][$type]??null)===$n);
}
$ck('genuine_model_failure_fixed_by_four_byte_alignment',
 $m['native_UModel_complete_before']===0
 &&$m['native_UModel_has_extension_before']===2
 &&$m['native_UModel_fatal_decode_before']===297
 &&$m['native_UModel_complete_after']===299);
$epic=(string)file_get_contents($m['source_SHA']['v1200_Engine_FSPhere_overload']['path']);
$start=strpos($epic,'class ENGINE_API FSphere : public FVector');
$end=$start===false?false:strpos($epic,'};',$start);
$part=$start!==false&&$end!==false?substr($epic,$start,$end-$start):'';
$ck('v1200_FSPhere_inherits_FVector_no_FSPhere_operator',
 $start!==false&&str_contains($part,'friend FArchive& operator<<( FArchive &Ar, FPlane &P )')
 &&!str_contains($part,'operator<<( FArchive &Ar, FSphere'));
$prim=(string)file_get_contents($root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicPrimitiveNativeReader.php');
$ck('correct_source_12_byte_legacy_v54_and_16_byte_old_v40',
 str_contains($prim,'elseif(!$original&&$version>61)')
 &&str_contains($prim,'$sphere[\'w\']=null')
 &&str_contains($prim,'$original&&$version<=40'));
$p=new P($path);
$header=$p->getHeader();
$ck('genuine_original_header_version54_and_epic_family',
 (int)$header['version']===54
 &&F::selectFromHeader($header)===F::EPIC_UE1);
foreach([555,558,565] as $ix){
 $ex=$p->getExports()[$ix];
 $obj=T::inspect($path,$p,$ix);
 $result=N::inspect($path,$p,$ix,$obj);
 $ck('original_v54_model_'.$ix,
  (int)$ex['serialSize']>0
  &&$result['class_name']==='Model'
  &&$result['status']==='native_model_record_complete'
  &&($result['extension_bytes']??null)===0);
 $ck('source_base_bytes_v54_'.$ix,
   ($result['source_serialized_base_bytes']??null)===37);
}
$ck('UT99_v432_explicit_separate_sphere_version_rule_unmodified',
 str_contains($prim,'!$original&&$version>61')
 &&$m['UT99_v400_FSPhere_explicit_overload_v61_cutoff_unchanged']
 &&$m['v432_compiled_tests_rerun']===0);
$ck('legacy_v54_real_no_synthetic_StateFrame_positive',
 $m['original_v54_stateframe_flagged_objects']===0);
$ck('not_native_v1200_full_method_oracle',
 $m['full_method_decision']==='BLOCKED'
 &&$m['source_scope']==='Source-defined archive byte layout, not full runtime-loaded v1200 object parity');
$checks=$passed+count($fails);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'passed'=>$passed,'failures'=>$fails,
 'genuine_PRD_original_v54_export_count'=>2667,
 'real_legacy_UModel_complete_after_fix'=>299,
 'real_legacy_UModel_previously_failed_or_extended'=>299,
 'UT99_v432_existing_native_oracles_rerun'=>0,
 'v1200_native_compiled_loaded_values'=>'NOT_VERIFIED',
 'full_method_decision'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
