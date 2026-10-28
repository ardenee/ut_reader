#!/usr/bin/env php
<?php
/** Fixed G3 T009: original May17 1998 C98 direct UStruct bad reference and ScriptSize.
 * This proves only the C98 distinct compiled branch, not UT99 v432 or 227k.
 * Native CDB evidence was already captured once; this verifier never launches it.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$sys='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/';
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';
$original=$sys.'Core.u';
$checks=0;$errors=[];
$ck=static function(bool $yes,string $name)use(&$checks,&$errors):void{
  ++$checks;if(!$yes)$errors[]=$name;
};
$pins=[
  [$source,'ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291'],
  [$sys.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
  [$original,'7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c'],
  [$ev.'20261028-G3-T009-C98-Children-original-compiled-Tell.cmd',
    'd09b1e54a11c7c3d26487f747dd88d97485792a495b3fff094027544bb8e796d'],
  [$ev.'20261028-G3-T009-C98-Children-original-compiled-Tell.log',
    'c88cd8a413167d33e40168dacc3082faa0ca51d2af6bf61f2d3912f67f65df54'],
  [$ev.'20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.cmd',
    '7ef6a9fee56b74275522eb3c21de59bbb88a0ebb977b3a84e0cba0833ba5781f'],
  [$ev.'20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.log',
    'a4d88f4b4c7baadee6af3d743c76b354e375044f06b6330f12eaba815c4efe86'],
];
foreach($pins as [$file,$sha]){
  $ck(is_file($file)&&hash_file('sha256',$file)===$sha,'immutable original SHA '.basename($file));
}
$cpp=(string)file_get_contents($source);
$ck(str_contains($cpp,'Ar << ScriptText << Children;')&&str_contains($cpp,'Ar << ScriptSize;')
  &&str_contains($cpp,'if( iCode != ScriptSize )')
  &&str_contains($cpp,'Script serialization mismatch: Got %i, expected %i'),
 'selected original C98 UStruct source parse/rejection order');
$raw=(string)file_get_contents($original);
$start=24218;$stock='0000480700001e0a010000082c00000400000014000415000000000000ffff00080000';
$ck(strlen($raw)===31472&&bin2hex(substr($raw,$start,35))===$stock,
  'unchanged genuine original export456 EndState UFunction payload');
$good=new P($original);
$ck($good->validatePackage()===[]&&($good->getExports()[456]['objectNameText']??null)==='EndState',
  'original stock package EndState exported object');
$positive=N::inspect($original,$good,456,T::inspect($original,$good,456,'epic-ue1'),'epic-ue1');
$ck(($positive['status']??'')==='native_function_record_complete'
  &&($positive['script_size']??0)===4&&($positive['script_archive']['script_memory_decoded']??0)===4,
  'genuine positive original EndState source profile still decodes 4/4');
$cases=[
 ['invalid-Children-index',24223,'00','bf',
  'a3df488cd43c8168900401fe386a8ac50932b157f7b48c0c1bcc6710c62143b5',
  'UE1 Bad import index 62/16 at archive cursor 24224',
  '20261028-G3-T009-C98-Children-original-compiled-Tell.log',
  ['Bad import index %i/%i','0000003e 00000010','00005ea0','1014cb88','T009_C98_ORIGINAL_CURSOR_ONLY']],
 ['invalid-ScriptSize-five',24233,'04','05',
  '930e76d7befc0767cb3bdff95fc02f9408c8eff73a737bf09c271c9a5cadd0ea',
  'UE1 Script serialization mismatch: Got 9, expected 5 at archive cursor 24243',
  '20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.log',
  ['Script serialization mismatch: G','00000009 00000005','00005eb3','1014cb88','T009_C98_SCRIPT5_ACTUAL_ARCHIVE']],
];
$results=[];
foreach($cases as [$name,$offset,$old,$new,$sha,$want,$nativeRel,$needles]){
  $mutant='C:/Temp/UnrealDB/O06/T009-C98/'.$name.'/System/Core.u';
  $buffer=(string)file_get_contents($mutant);
  $ck(hash('sha256',$buffer)===$sha&&strlen($buffer)===strlen($raw),
    $name.' exactly pinned one-byte isolated original mutant');
  $ck(ord($raw[$offset])===hexdec($old)&&ord($buffer[$offset])===hexdec($new),
    $name.' source-byte mutation at correct physical offset');
  $check=$buffer;$check[$offset]=$raw[$offset];
  $ck($check===$raw,$name.' no other original core package bytes altered');
  $log=(string)file_get_contents($ev.$nativeRel);
  foreach($needles as $needle){
    $ck(str_contains($log,$needle),$name.' actual compiled x86 native: '.$needle);
  }
  $p=new P($mutant);
  $ck($p->validatePackage()===[],$name.' valid genuine envelope');
  $failure=null;
  try{
    N::inspect($mutant,$p,456,T::inspect($mutant,$p,456,'epic-ue1'),'epic-ue1');
  }catch(Throwable $e){$failure=$e->getMessage();}
  $ck($failure===$want,$name.' current production rejects at exact native error and FArchive Tell');
  $results[]=['branch'=>$name,'mutated_core_sha256'=>$sha,
    'native_error'=>$want,'reader_error'=>$failure,
    'loaded'=>false,'native_compiled_oracle_reexecuted'=>false];
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'errors'=>$errors,
 'selected_source'=>'Unreal v1.200 May19 snapshot; original executable is May17 build, not claimed identical',
 'original_native_1998_cases'=>2,'results'=>$results,
 'C98_T009_branch'=>'NATIVE_PARITY_PROVEN',
 'T009_FIXED_TEST'=>'BLOCKED_OTHER_DISTINCT_V432_AND_C227K',
 'original_compiled_engine_reexecuted_by_this_verifier'=>0,
 'prior_positive_native_test_replay'=>0,
 'current_struct_reader_sha256'=>hash_file('sha256',
   $root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php')
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
