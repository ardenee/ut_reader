#!/usr/bin/env php
<?php
/** Correct v69 Engine.u UClass G5 denominator using actual package and
 * original compiled v432 method-entry object identities. A ClassIndex==0
 * zero-byte NAME_None export placeholder is NOT a serialized UClass.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$original='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u';
$logfile='C:/Temp/UnrealDB/O06/g5-v432-uclass-native-nested-values.log';
$checks=0;$bad=[];
$ck=static function(string $label,bool $ok)use(&$checks,&$bad):void{$checks++;if(!$ok)$bad[]=$label;};
$ck('pinned_genuine_v69_Engine_archive',
  is_file($original)&&hash_file('sha256',$original)==='d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695');
$ck('pinned_genuine_v432_compiled_native_UClass_events',
  is_file($logfile)&&hash_file('sha256',$logfile)==='38be93aa859b2b8e1c60150c2c4b215d536362be0e6b182502cada61f484e1f7');
$pkg=new P($original);$exps=$pkg->getExports();
$ck('genuine_UT99_v69_package',($pkg->getHeader()['version']??null)===69&&$pkg->validatePackage()===[]);
$rows=[];$real=[];$placeholder=[];$other=[];
foreach($exps as $row){
 if((int)$row['classIndex']!==0)continue;
 $rows[]=$row;$id=(int)$row['index'];
 $name=(string)$row['objectNameText'];$size=(int)$row['serialSize'];
 if($size===0&&strcasecmp($name,'None')===0)$placeholder[]=$id;
 elseif($size>0&&strcasecmp($name,'None')!==0)$real[$id]=$name;
 else $other[]=['index'=>$id,'name'=>$name,'size'=>$size];
}
$ck('all_ClassIndex_zero_rows_131',count($rows)===131);
$ck('none_zero_byte_rows_are_empty_placeholders_43',
  count($placeholder)===43&&$other===[]);
$ck('real_named_serialized_UClass_exports_88',count($real)===88);
$raw=file_get_contents($logfile);
$log=str_starts_with($raw,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
preg_match_all('/G5_UCLASS_NEST idx=(\d+) name=([A-Za-z_][A-Za-z0-9_]*)/',$log,$events,PREG_SET_ORDER);
$ck('actual_compiled_UClass_serializations_90',count($events)===90);
$matched=[];$foreign=[];
foreach($events as $e){
 $id=(int)$e[1];$name=$e[2];
 if(isset($real[$id])&&strcasecmp($real[$id],$name)===0){
  $ck('compiled_genuine_class_once_'.$id,!isset($matched[$id]));
  $matched[$id]=true;
 }else{
  $foreign[]=$id.':'.$name;
 }
}
sort($foreign);
$ck('two_compiled_foreign_Core_classes',
  $foreign===['2:Object','328:Subsystem']);
$ck('all_88_real_Engine_UClass_loaded_native',
  count($matched)===count($real));
$ck('no_real_Engine_UClass_unobserved',
  array_diff_key($real,$matched)===[]);
$ck('no_dummy_NAME_None_in_real_UClass_set',
  !in_array('None',$real,true));
echo json_encode(['ok'=>$bad===[],'checks'=>$checks,'passed'=>$checks-count($bad),
 'failures'=>$bad,'original_classIndex_zero_rows'=>count($rows),
 'zero_size_NAME_None_export_placeholders'=>count($placeholder),
 'genuine_serialized_UClass_exports'=>count($real),
 'compiled_genuine_UClass_coverage'=>count($matched),
 'remaining_real_UClass_unloaded'=>count(array_diff_key($real,$matched)),
 'foreign_Core_UClass_loaded'=>$foreign,
 'full_method_accepted'=>false,
 'remaining'=>'Inherited serializer, malformed/errors, source branch variants and full method review'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
