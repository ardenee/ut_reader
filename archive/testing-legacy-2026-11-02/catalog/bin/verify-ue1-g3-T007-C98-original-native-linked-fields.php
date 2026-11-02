#!/usr/bin/env php
<?php
/** C98 May17 1998 original compiled Core.dll: class Object and direct Vector
 * UStruct live Children/ScriptText/Next resolved to SAME native package linker.
 * Separate x86 original stock Unreal.exe -make, original Core.u unchanged.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$e=dirname(__DIR__,2).'/catalog/compliance/evidence/o06/';
$files=[
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.dll'=>'9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92',
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.u'=>'7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c',
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Unreal.exe'=>'d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30',
 $e.'20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.cmd'=>'2f1f34461c1787b7adb4cf550bdfa91fa4c2467752d16e85166e552c6c8a14fa',
 $e.'20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.log'=>'412b5c33a6a68901bcacaf3e3ac2b56cc48178882715cf4f2072c631595a57ce'
];
$count=0;$fail=[];$check=static function(bool $yes,string $label)use(&$count,&$fail):void{
 ++$count;if(!$yes)$fail[]=$label;};
foreach($files as $f=>$pin)$check(is_file($f)&&hash_file('sha256',$f)===$pin,'May17 genuine original binary or exact native log SHA '.basename($f));
$cmd=(string)file_get_contents($e.'20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.cmd');
$log=str_replace(chr(13),'',(string)file_get_contents($e.'20261028-G3-T007-C98-ORIGINAL-LINKER-POINTERS.log'));
$check(str_contains($cmd,'bp 10110c88')&&str_contains($log,'Core.dll')
 &&str_contains($log,'G3_T007_C98_LINK_TARGET_CAPTURE_FINISHED'),
 'Authentic C98 original compiled UStruct method at address 0x10110c88 actually reached');
$lookup=static function(string $label)use($log):?array{
 $m=[];
 $pat='/^'.preg_quote($label,'/').'\n[a-fA-F0-9]+\s+([a-fA-F0-9]{8})\s+([a-fA-F0-9]{8})/m';
 if(!preg_match($pat,$log,$m))return null;
 return [hexdec($m[1]),hexdec($m[2])];
};
$vector=$lookup('G3_T007_C98_VECTOR_LINKS');
$object=$lookup('G3_T007_C98_CLASS_OBJECT_LINKS');
$childV=$lookup('G3_T007_C98_VECTOR_CHILD_ID');
$nextV=$lookup('G3_T007_C98_VECTOR_NEXT_ID');
$childC=$lookup('G3_T007_C98_CLASS_CHILD_ID');
$textC=$lookup('G3_T007_C98_CLASS_SCRIPTTEXT_ID');
foreach(compact('vector','object','childV','nextV','childC','textC') as $n=>$v)$check($v!==null,'Actual original native x86 linker target capture '.$n);
$expected=['vector'=>[1,223,422],'class'=>[0,457,231]];
if($vector&&$object&&$childV&&$nextV&&$childC&&$textC){
 $check($vector[0]===$object[0]&&$vector[1]===1&&$object[1]===0,
  'Genuine Class Object and direct Struct Vector are same original Core.u linker');
 foreach(['vector_child'=>$childV,'vector_next'=>$nextV,'class_child'=>$childC,'class_scripttext'=>$textC] as $n=>$v)
   $check($v[0]===$vector[0],'Real '.$n.' address belongs to original Core.u linker');
 $check($childV[1]===222 && $nextV[1]===421,'C98 original native Vector Children/Next target export indices 222/421');
 $check($childC[1]===456 && $textC[1]===230,'C98 original native Object Class Children/ScriptText target exports 456/230');
}
$path='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.u';
$pkg=new P($path);$ex=$pkg->getExports();
$check(($pkg->getHeader()['version']??null)===61&&count($ex)===458,'Authentic retail May17 1998 Core.u v61, 458 exports');
$check(($ex[0]['objectNameText']??null)==='Object'&&($ex[0]['classIndex']??1)===0,
 'Original compiled native Class Object is serialized root Class export0');
$check(($ex[1]['objectNameText']??null)==='Vector'&&($ex[1]['classIndex']??0)<0,
 'Original compiled native Vector is direct UStruct export1');
$cls=C::inspect($path,$pkg,0,false,'epic-ue1');
$vec=N::inspect($path,$pkg,1,T::inspect($path,$pkg,1,'epic-ue1'),'epic-ue1');
$check(($cls['status']??null)==='class_metadata_no_script_no_defaults_complete'
  &&($cls['friendly_name']['text']??null)==='Object','UnrealDB Class Object full physical native metadata read');
$check(($vec['status']??null)==='native_struct_record_complete'
  &&($vec['friendly_name']['text']??null)==='Vector','UnrealDB direct Vector original UStruct file boundary read');
foreach(['super_field_reference'=>0,'next_field_reference'=>0,
   'script_text_reference'=>231,'children_reference'=>457,'script_size'=>0] as $key=>$value)
  $check(($cls[$key]??null)===$value,'Class Object native to archived '.$key);
foreach(['super_field_reference'=>0,'next_field_reference'=>422,
   'script_text_reference'=>0,'children_reference'=>223,'script_size'=>0] as $key=>$value)
  $check(($vec[$key]??null)===$value,'UStruct Vector native to archived '.$key);
echo json_encode(['ok'=>$fail===[],'checks'=>$count,'failed'=>$fail,
  'T007'=>'genuine original May17 C98 Core.dll/UClass Object and UStruct Vector Children, ScriptText, SuperField, Next actual same-linker native identity',
  'native_Core_u_objects'=>['Object'=>['export'=>0,'children_ref'=>457,'script_text_ref'=>231],
      'Vector'=>['export'=>1,'children_ref'=>223,'next_ref'=>422]],
  'already_sealed_100_C98_script_tests_rerun'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
