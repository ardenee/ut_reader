#!/usr/bin/env php
<?php
/** Real compiled v432 UClass fields compared with the production class reader.
 * Does NOT claim full-method native defaults, inherited or malformed behavior.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-UCLASS-NATIVE-VALUES.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$matched=0;$extraneous=[];$seen=[];
$ck=static function(string $label,bool $ok)use(&$checks,&$errors):void{
 $checks++;if(!$ok)$errors[]=$label;
};
$pin=static function(array $data,string $label)use($ck):string{
 $raw=is_file($data['path'])?file_get_contents($data['path']):false;
 $ck('original_sha256_'.$label,is_string($raw)&&hash('sha256',$raw)===$data['sha256']);
 return is_string($raw)?$raw:'';
};
$pin($m['original_package'],'Engine_u_v69');
$pin($m['compiled_core'],'Core_v432');
$disasm=$pin($m['compiled_disassembly'],'compiled_UClass_native_disassembly');
if(str_starts_with($disasm,"\xff\xfe"))$disasm=(string)iconv('UTF-16LE','UTF-8',substr($disasm,2));
$ck('true_compiled_UClass_super_and_version_dispatch',
 str_contains($disasm,'Core!UClass::Serialize')
 &&str_contains($disasm,'call    Core!UState::Serialize')
 &&str_contains($disasm,'lea     eax,[esi+488h]')
 &&str_contains($disasm,'lea     ecx,[esi+490h]'));
$log=$pin($m['compiled_native_log'],'compiled_live_UClass_event_log');
if(str_starts_with($log,"\xff\xfe"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
$log=str_replace("\r",'',$log);
$ck('CDB_has_no_mem_or_expression_errors',
 !str_contains($log,'Memory access error')&&!str_contains($log,'Numeric expression missing'));
$regex='/G5_UClass idx=(\d+) name=([A-Za-z_][A-Za-z0-9_]*) flags=([0-9a-f]{8}) guid0=([0-9a-f]{8}) guid1=([0-9a-f]{8}) guid2=([0-9a-f]{8}) guid3=([0-9a-f]{8}) deps=(\d+) imports=(\d+) within=([0-9a-f]{8}) config=([A-Za-z0-9_]+)/i';
preg_match_all($regex,$log,$native,PREG_SET_ORDER);
$ck('compiled_90_UClass_seralizations',count($native)===$m['expected_compiled_invocations']);
$ck('compiled_method_finished',
 preg_match('/G5_UClass_ORACLE_COMPLETE count=(\d+)/',$log,$end)===1
 &&(int)$end[1]===count($native));
$path=$m['original_package']['path'];$p=new P($path);$exports=$p->getExports();
$original=array_filter($exports,static fn($e)=>(int)$e['classIndex']===0);
$real=array_filter($original,static fn($e)=>(int)$e['serialSize']>0
   &&strcasecmp((string)$e['objectNameText'],'None')!==0);
$placeholders=array_filter($original,static fn($e)=>(int)$e['serialSize']===0
   &&strcasecmp((string)$e['objectNameText'],'None')===0);
$ck('no_other_UClass_entry_kinds',count($real)+count($placeholders)===count($original));
$ck('43_zero_size_NAME_None_placeholders',count($placeholders)===43);
$ck('all_88_real_UClass_serialized_entries',count($real)===88);
$ck('original_Engine_131_ClassMap_entries',
 count($original)===$m['expected_original_package_class_exports']);
foreach($native as $rec){
 $idx=(int)$rec[1];$name=$rec[2];$label='native_Class_'.$name.'_idx_'.$idx;
 $ex=$real[$idx]??null;
 if(!is_array($ex)||strcasecmp((string)$ex['objectNameText'],$name)!==0){
  $extraneous[]="$idx:$name";continue;
 }
 $ck('class_called_once_'.$label,!isset($seen[$idx]));$seen[$idx]=true;
 $class=C::inspect($path,$p,$idx,true,'ut99-v432');
 $ck('production_source_class_header_and_archive_decoded_'.$label,
  str_starts_with((string)($class['status']??''),'class_')
  &&($class['source_file_archive_complete']??false)===true);
 $ck('native_classflags_'.$label,
  (int)$class['class_flags']===hexdec($rec[3]));
 $guid=strtoupper(bin2hex(pack('V4',hexdec($rec[4]),hexdec($rec[5]),hexdec($rec[6]),hexdec($rec[7]))));
 $ck('native_full_GUID_bytes_'.$label,
  $class['class_guid_raw_hex']===$guid);
 $ck('native_dependencies_TArray_count_'.$label,
  count($class['dependencies'])===(int)$rec[8]);
 $ck('native_package_imports_TArray_count_'.$label,
  count($class['package_imports'])===(int)$rec[9]);
 $ck('compiled_ClassWithin_UObject_pointer_nonnull_'.$label,
  hexdec($rec[10])!==0);
 $ck('native_FName_class_config_'.$label,
  strcasecmp((string)($class['class_config_name']['text']??''),$rec[11])===0);
 $matched++;
}
sort($extraneous);
$expected=$m['expected_non_Engine_same_linker_index_classes'];
sort($expected);
$ck('only_known_foreign_loaded_Core_classes',$extraneous===$expected);
$ck('exactly_88_untouched_Engine_class_loaded_values',
 $matched===$m['expected_compiled_loaded_original_class_records']);
$ck('all_real_Engine_UClass_entries_are_native_observed',
 count($real)-count($seen)===0);
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
 'errors'=>$errors,
 'actual_original_UClass_exports'=>count($real),
 'classIndex_zero_nonserialized_placeholders'=>count($placeholders),
 'compiled_UClass_serialize_calls'=>count($native),
 'compiled_unique_Engine_UClass_matches'=>$matched,
 'compiled_unrelated_UClass_calls'=>$extraneous,
 'unobserved_real_UClass_exports'=>count($real)-count($seen),
 'full_G5_method_accepted'=>false,
 'reason'=>'Inherited UObject->UField->UStruct->UState, default loaded values, complete nested dependency refs, malignant byte/error branches and all 88 genuine classes loaded; inheritance and malformed error branches remain open'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
