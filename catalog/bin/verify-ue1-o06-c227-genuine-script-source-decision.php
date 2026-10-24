#!/usr/bin/env php
<?php
/** Unreal v227 real package ScriptArchive: source-selected modern token
 * interpretation. Original PRD .u (v69/licensee227) SHA-copied to DEV.
 * No v227 compiled engine body available; class defaults values unverified.
 * Previous compiled UT99 source-equivalent paths SHA reused, never rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$root=dirname(__DIR__,2);$checks=0;$errors=[];
$assert=static function(string $id,bool $condition)use(&$checks,&$errors):void{
 $checks++;if(!$condition)$errors[]=$id;
};
$decode=static fn(string $path):array=>json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$m=$decode($root.'/catalog/compliance/UE1-O06-C227-GENUINE-227-SCRIPT-SOURCE-FIX.json');
foreach($m['input_source_headers_sha256'] as $name=>$file){
 $assert('source_original_SHA_'.$name,
   is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256']);
}
foreach(['inventory_SHA','file_decode_scan_SHA'] as $key){
 $f=$m[$key];$assert('scratch_input_SHA_'.$key,
   is_file($f['path'])&&hash_file('sha256',$f['path'])===$f['sha256']);
}
$v227=(string)file_get_contents($m['input_source_headers_sha256']['v227_opcode_header']['path']);
$v432=(string)file_get_contents($m['input_source_headers_sha256']['v432_opcode_header']['path']);
$v1200=(string)file_get_contents($m['input_source_headers_sha256']['v1200_opcode_header']['path']);
foreach(['EX_Return'=>'04','EX_DynArrayElement'=>'10','EX_New'=>'11',
  'EX_LetBool'=>'14','EX_UnicodeStringConst'=>'34'] as $name=>$hex){
 $re='/\\b'.preg_quote($name,'/').'\\s*=\\s*0x'.$hex.'\\b/';
 $assert('modern_common_opcode_'.$name,
   (bool)preg_match($re,$v227)&&(bool)preg_match($re,$v432));
}
$assert('v1200_distinct_begin_function_opcode',
 str_contains($v1200,'EX_BeginFunction')
 &&str_contains($v227,'EX_LetBool')
 &&str_contains($v432,'EX_LetBool'));
$assert('v227_unique_opcodes_not_mislabelled_as_UT99',
 str_contains($v227,'EX_MapKeyElement')
 &&str_contains($v227,'EX_EatCtrExpr')
 &&str_contains($v227,'EX_StructConstruct')
 &&!str_contains($v432,'EX_MapKeyElement'));
$script=(string)file_get_contents(
 $root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php');
$assert('current_reader_source_selects_both_common_modern_families',
 str_contains($script,'CatalogUE1ClassicSourceFamily::UNREAL_V227')
 &&str_contains($script,'CatalogUE1ClassicSourceFamily::UT99_V432')
 &&str_contains($script,'$maxConversion = $modern ? 0x60 : 0x5a'));
$assert('v227_only_new_vm_operations_not_silently_v432',
 str_contains($script,'EX_MapKeyElement archive traversal unverified')
 &&str_contains($script,'Source token 0x%02X not decoded'));
$stats=['files'=>0,'exports'=>0,'ordinary'=>0,'classes'=>0,'errors'=>0];
foreach($m['genuine_licensee_227_files'] as $fixture){
 $path=$fixture['staged_DEV'];
 $assert('original_SHA_'.basename($path),
   is_file($path)&&hash_file('sha256',$path)===$fixture['sha256']);
 $pkg=new P($path);
 $h=$pkg->getHeader();
 $assert('source_selected_by_original_licensee_227_'.basename($path),
   ($h['version']??null)===69
   &&($h['licenseeVersion']??null)===227
   &&F::selectFromHeader($h,null)===F::UNREAL_V227);
 $stats['files']++;
 foreach($pkg->getExports() as $ix=>$ex){
  $stats['exports']++;
  try{
   $r=T::inspect($path,$pkg,$ix,null);
   if((int)$ex['classIndex']===0){
    $stats['classes']++;
    if(!str_starts_with((string)($r['status']??''),'class_'))$stats['errors']++;
   }else{
    $stats['ordinary']++;
    if(($r['status']??'')!=='tag_envelope_decoded')$stats['errors']++;
   }
   if(($r['source_sets_rf_debug_serialize']??false)!==false
      ||($r['loaded_object_flags_after_uobject_serialize']??null)!==null)
      $stats['errors']++;
  }catch(Throwable $e){$stats['errors']++;}
 }
}
$assert('all_426_genuine_exports_and_399_tagged_and_27_class_streams',
 $stats===['files'=>3,'exports'=>426,'ordinary'=>399,'classes'=>27,'errors'=>0]);
$assert('distinct_real_v227_before_11_fail_after_zero_errors',
 $m['before_error_count']===11&&$m['after_error_count']===0
 &&$m['ordinary_tag_archives_decoded']===399
 &&$m['class_exports_decoded_without_exception']===27);
$assert('v227_native_methods_missing_no_false_full',
 $m['source_engine_v227_Cxx_implementation_available']===false
 &&$m['full_method_decision']==='BLOCKED');
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
  'failures'=>$errors,'method'=>'C227:UClass::Serialize ScriptArchive',
  'genuine_original_licensee_227_packages'=>3,
  'real_original_exports'=>426,
  'original_object_tagged_streams_decoded'=>399,
  'original_UClass_export_streams_decoded'=>27,
  'source_opcode_mismatch_earlier_failed_class_exports'=>11,
  'current_original_class_export_parser_errors'=>$stats['errors'],
  'compiled_engine_reruns_in_verifier'=>0,
  'native_v227_UClass_value_parity'=>'NOT_VERIFIED',
  'full_method_decision'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
