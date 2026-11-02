#!/usr/bin/env php
<?php
/** Fixed T066: native v432 physical FArchive before-field vs mid-field EOF.
 * One original compiled native count50 probe, earlier mid-field run reused.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$sys='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$old=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-UOBJECT-DIRECT-STR-PHYSICAL-EOF.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];
$ck=static function(bool $pass,string $label)use(&$checks,&$fails):void {++$checks;if(!$pass)$fails[]=$label;};
foreach(['core_dll','engine_dll','native_generated_original','native_original_loaded_log','native_mutated_error_log','malformed_one_byte_copy'] as $k){
 $f=$old['originals'][$k];
 $ck(is_file($f['path'])&&hash_file('sha256',$f['path'])===$f['sha256'],'old pinned original '.$k);
}
$mut=$ev.'20261029-G3-T066-v432-original-native-before-FName-EOF.u';
$log=$ev.'20261029-G3-T066-v432-original-native-before-FName-EOF.log';
$excluded=$ev.'20261029-G3-T066-v432-preload-early-rejection.log';
foreach([
 [$mut,'d13f25c1167f0a4d807dde51e5602c9d27b2a38a682c179ad9aa57ade800b4b2'],
 [$log,'89158262860cabe7fd253c2b65d2639d906a6fd94bc4a80840009715fca7d8f8'],
 [$excluded,'bc0c69c0dfeeee93089e91f377e0e2692aa49c238cff738a6e87664632d938c9']
] as [$p,$sha])$ck(is_file($p)&&hash_file('sha256',$p)===$sha,'native fixed evidence '.basename($p));
$origin=(string)file_get_contents($old['originals']['native_generated_original']['path']);
$m=(string)file_get_contents($mut);
$ck(strlen($origin)===283&&strlen($m)===283&&ord($origin[232])===21&&ord($m[232])===50,
    'native generated physical file unchanged length, one altered compact string count');
$m[232]=$origin[232];
$ck(hash('sha256',$origin)===hash('sha256',$m),'all other bytes original');
$runtime=$sys.'G3T066BeforeField.u';
$ck(is_file($runtime)&&hash_file('sha256',$runtime)===hash_file('sha256',$mut),
    'same source provider/install directory for original and UnrealDB');
$decode=static function(string $path):string {
 $raw=(string)file_get_contents($path);
 return str_starts_with($raw,"\xFF\xFE")
  ?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
};
$before=$decode($log);
$mid=$decode($old['originals']['native_mutated_error_log']['path']);
$early=$decode($excluded);
$ck(str_contains($before,'ReadFile beyond EOF 283+1/283')
    &&str_contains($before,'FCompactIndex<<')
    &&str_contains($before,'ULinkerLoad<<FName')
    &&str_contains($before,'TagName')
    &&str_contains($before,'283==283/283')
    &&str_contains($before,'native_error=1'),
    'original compiled FArchive before NAME_None next FName at Tell283');
$ck(!str_contains($before,'G5_NATIVE_OBJECT_COUNT 1')&&!str_contains($before,'Bad name index'),
    'original before-field native cannot be mistaken for loaded value or invalid name');
$ck(str_contains($mid,'ReadFile beyond EOF 283+1/283')
    &&str_contains($mid,'FString<<')&&str_contains($mid,'native_error=1'),
    'previous sealed original MID FString FArchive EOF, not rerun');
$ck(str_contains($early,'FArchiveFileReader::Seek')
    &&str_contains($early,'LoadImportMap')&&!str_contains($early,'TagName'),
    'excluded malformed truncation fails in import map before tagged fields');
$getExport=static function(P $pkg):int {
 foreach($pkg->getExports() as $i=>$e)
  if(($e['objectNameText']??null)==='G5DirectStringActor')return $i;
 throw new RuntimeException('Missing original actor');
};
try{
 $pkg=new P($runtime);
 T::inspect($runtime,$pkg,$getExport($pkg),'ut99-v432');
 $beforeErr='UNEXPECTED_LOADED';
}catch(\RuntimeException $e){$beforeErr=$e->getMessage();}
$ck($beforeErr==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 283 during FPropertyTag TagName<<FName',
    'original v432 reader before-tag native same physical EOF and stage');
try{
 $prior=$old['originals']['malformed_one_byte_copy']['path'];
 $pkg=new P($prior);
 T::inspect($prior,$pkg,$getExport($pkg),'ut99-v432');
 $midErr='UNEXPECTED_LOADED';
}catch(\RuntimeException $e){$midErr=$e->getMessage();}
$ck($midErr==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset 283 during direct FString<<',
    'original v432 reader mid-string same original FArchive EOF and stage');
$unmut=$old['originals']['native_generated_original']['path'];
$pkg=new P($unmut);$good=T::inspect($unmut,$pkg,$getExport($pkg),'ut99-v432');
$ck(($good['status']??null)==='tag_envelope_decoded'&&($good['tags'][0]['value']['ascii_text']??null)==='Alpha-direct-FString',
    'unchanged genuinely native saved valid FString accepted');
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'failures'=>$fails,
 'test'=>'T066','build'=>'stock UT99 v432','native_compiled_new_execution_count'=>1,
 'previous_original_compiled_mid_field_EOF_reused'=>true,
 'before_field'=>'original FCompactIndex<< next TagName at FArchive Tell283',
 'mid_field'=>'original FString<< physical EOF at Tell283',
 'excluded_early_import_seek'=>true,'G3_full_accepted'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
