#!/usr/bin/env php
<?php
/** Fixed T070: one extra physical-read case and an existing original true EOF.
 * Compare the ORIGINAL UT99 v432 compiled runtime to the current source reader.
 * Previously accepted original true-EOF native run is REUSED, never rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$system='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$prior=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-UOBJECT-DIRECT-STR-PHYSICAL-EOF.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];
$ck=static function(bool $condition,string $name)use(&$checks,&$failed):void{
  ++$checks;if(!$condition)$failed[]=$name;
};
foreach(['core_dll','engine_dll','compiled_script_package','native_generated_original',
          'native_original_loaded_log','native_mutated_error_log','malformed_one_byte_copy'] as $id) {
  $f=$prior['originals'][$id];
  $ck(is_file($f['path'])&&hash_file('sha256',$f['path'])===$f['sha256'],
    'existing native original hash '.$id);
}
$mutant=$ev.'20261029-G3-T070-v432-original-native-physical-readable-one-byte.u';
$log=$ev.'20261029-G3-T070-v432-original-native-physical-readable.log';
$ck(is_file($mutant)&&hash_file('sha256',$mutant)==='f814c88c487bd0707b9f7730001d4a51ef86aa739fe19ddcb4dd6d143c4a0a71',
  'one-byte archive immutable SHA');
$ck(is_file($log)&&hash_file('sha256',$log)==='edb68d238e598428e7fd2b415918dae67fc68144bdc2ac23d3c64a361fccb545',
  'new original v432 compiled log immutable SHA');
$original=(string)file_get_contents($prior['originals']['native_generated_original']['path']);
$changed=(string)file_get_contents($mutant);
$ck(strlen($original)===283&&strlen($changed)===283
  &&ord($original[232])===21&&ord($changed[232])===22,
  'same full physical archive length, genuine FString count 21 -> 22');
$runtimeMutant=$system.'G3T070Extra.u';
$ck(is_file($runtimeMutant)&&hash_file('sha256',$runtimeMutant)===hash_file('sha256',$mutant),
  'native source loader and reader use same original import-resolution directory');
$mask=$changed;$mask[232]=$original[232];
$ck(hash('sha256',$mask)===hash('sha256',$original),
  'no other archive, header, tag or import modified');
$n=(string)file_get_contents($log);
if(str_starts_with($n,"\xFF\xFE"))$n=(string)iconv('UTF-16LE','UTF-8',substr($n,2));
$priorLog=(string)file_get_contents($prior['originals']['native_mutated_error_log']['path']);
$ck(str_contains($n,'G5_NATIVE_DIRECT_PACKAGE_BEGIN')
  &&str_contains($n,'Bad name index -5242879/9')
  &&str_contains($n,'ULinkerLoad<<FName')
  &&str_contains($n,'262==262/283')
  &&str_contains($n,'native_error=1'),
  'actual original Core.dll reads past nominal export and FIRST rejects name at Tell262');
$ck(!str_contains($n,'ReadFile beyond EOF')&&!str_contains($n,'G5_NATIVE_OBJECT_COUNT 1'),
  'not confused with physical EOF and not claimed loaded');
$ck(str_contains($priorLog,'ReadFile beyond EOF 283+1/283')
  &&str_contains($priorLog,'FString<<')
  &&str_contains($priorLog,'native_error=1'),
  'previous original compiled v432 genuine FString physical EOF at 283 reused');
$nativeValid=(string)file_get_contents($prior['originals']['native_original_loaded_log']['path']);
$ck(str_contains($nativeValid,'Headline="Alpha-direct-FString"')
  &&str_contains($nativeValid,'G5_NATIVE_OBJECT_COUNT 1'),
  'unchanged native original complete positive load reused');
$findExport=static function(P $pkg):int {
 foreach($pkg->getExports() as $idx=>$e)
   if(($e['objectNameText']??null)==='G5DirectStringActor')return $idx;
 throw new RuntimeException('Source original G5DirectStringActor missing');
};
$base=new P($prior['originals']['native_generated_original']['path']);
$normal=T::inspect($prior['originals']['native_generated_original']['path'],
   $base,$findExport($base),'ut99-v432');
$ck(($normal['status']??null)==='tag_envelope_decoded'
  &&($normal['native_tail_bytes']??null)===0
  &&($normal['tags'][0]['value']['ascii_text']??null)==='Alpha-direct-FString'
  &&($normal['tags'][0]['value_offset']??null)===232,
  'existing current source normal 21 byte native-tagged FString');
try {
 $pkg=new P($runtimeMutant);
 T::inspect($runtimeMutant,$pkg,$findExport($pkg),'ut99-v432');
 $message='INCORRECT_SUCCESS';
} catch(\RuntimeException $e) {
 $message=$e->getMessage();
}
$ck($message==='UE1 v432 ULinkerLoad<<FName invalid name index -5242879 at archive offset 262',
  'source-selected reader original compiled bad name and physical cursor262');
try {
 $bad=$prior['originals']['malformed_one_byte_copy']['path'];
 $pkg=new P($bad);
 T::inspect($bad,$pkg,$findExport($pkg),'ut99-v432');
 $message='INCORRECT_SUCCESS';
} catch(\RuntimeException $e) {
 $message=$e->getMessage();
}
$ck($message==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 283 during direct FString<<',
  'source-selected reader original native FString physical EOF283, not export-size cutoff');
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed,
  'test'=>'T070','native_original'=>'stock UT99 v432 Core.dll',
  'native_new_execution_count'=>1,'previous_compiled_original_execution_reused'=>true,
  'extra_nominal_export_error'=>'Bad name index -5242879/9 at physical Tell262',
  'physical_EOF_error'=>'ReadFile beyond EOF 283+1/283','full_G3_accepted'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
