#!/usr/bin/env php
<?php
/** T003: original UT99 v432 compiled native LOAD vs exact UStruct Script.Num,
 * physical Script archive length and typed relocations. No original scripts rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$e=$root.'/catalog/compliance/evidence/o06/';
$files=[
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll'=>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.u'=>'ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll'=>'f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u'=>'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/G3T003V432ScriptLoad.exe'=>'a4fe32434e36779efe9799bef95877f380e7a41d4316f0a22843311bb11d6466',
 $e.'20261028-G3-T003-v432-original-native-script-load.cpp'=>'c24ab8cd1b2f537ace55089bd800013e4fafaee5fd691b9ea6696396ab5463a8',
 $e.'20261028-G3-T003-v432-original-native-script-load.log'=>'028d1ddc84ac2e6a97180030ccb187b53f2e7e5f31bfc07708e2eac9db11006b',
 $e.'20261028-G3-T003-v432-original-native-build.log'=>'3768474819f2ca0e78ae619c2a13a308320029d9df23fb3b931fd8f3b0e2dd1b',
];
$checkCount=0;$fail=[];
$assert=static function(bool $pass,string $name)use(&$checkCount,&$fail):void{
 ++$checkCount;if(!$pass)$fail[]=$name;
};
foreach($files as $path=>$pin)$assert(is_file($path)&&hash_file('sha256',$path)===$pin,'original source/runtime/fixture pin '.basename($path));
$log=str_replace(chr(13),'',(string)file_get_contents($e.'20261028-G3-T003-v432-original-native-script-load.log'));
$assert(str_contains($log,'G3_T003_V432_ORIGINAL_NATIVE_MEMORY_MATCH=3'),'Three genuine native UStruct Script.Num loaded');
$assert(preg_match('/^G3_T003_V432_NATIVE_PACKAGE_CORE=[0-9A-F]+ ENGINE=[0-9A-F]+$/m',$log)===1,
 'Original compiled process loaded genuine Core.u and Engine.u');
$cases=[
 ['Core.u',0,'Vector',0,'',0,0,[]],
 ['Core.u',286,'NotEqual_FloatFloat',10,'29',6,2,[279,213]],
 ['Engine.u',67,'PostBeginPlay',2,'040b',2,0,[]]
];
$results=[];
foreach($cases as $i=>[$file,$id,$name,$num,$maskPrefix,$archiveLength,$pointerCount,$indexes]){
 $m=[];
 $assert(preg_match('/^G3_T003_V432_NATIVE_SCRIPT\['.$i.'\] linker=([a-fA-F0-9]+) export=(\d+) memory_count=(\d+) bytes_hex=([a-fA-F0-9]*)$/m',$log,$m)===1,
   'Original native original loaded script '.$name);
 if(!$m)continue;
 $nativeHex=strtolower($m[4]);$nativeBytes=hex2bin($nativeHex);
 $assert((int)$m[2]===$id&&(int)$m[3]===$num &&strlen($nativeBytes)===$num,
  'Original native actual script buffer count/target '.$name);
 $path='C:/Temp/UnrealDB/O06/Native-UCC/System/'.$file;
 $package=new P($path);
 $ex=$package->getExports();
 $inspect=N::inspect($path,$package,$id,T::inspect($path,$package,$id,'ut99-v432'),'ut99-v432');
 $assert(($ex[$id]['objectNameText']??null)===$name&&($inspect['status']??null)==='native_'.($id===0?'struct':'function').'_record_complete',
  'Actual native object class, source-selected reader and physical Serialize export consumption '.$name);
 $assert(($inspect['script_size']??null)===$num,'Reader source UStruct ScriptSize vs original native Script.Num '.$name);
 if($num>0){
  $s=$inspect['script_archive']??[];
  $assert(($s['status']??null)==='script_archive_expressions_decoded'
   &&($s['script_memory_expected']??null)===$num
   &&($s['script_memory_decoded']??null)===$num
   &&($s['archive_bytes_consumed']??null)===$archiveLength,
   'Native script length vs physical archived script bytes '.$name);
  $assert(($s['native_load_relocation_count']??null)===$pointerCount
    &&($s['native_pointer_addresses_fabricated']??true)===false
    &&($s['source_memory_full_byte_reconstruction']??false)===true,
    'Exact source-known script and true relocation mask '.$name);
  if($pointerCount){
   $mask=(string)$s['source_memory_known_mask_hex'];
   $expected=(string)$s['source_memory_bytes_hex'];
   $assert($mask==='01000000000100000000'&&$expected==='29000000002900000000',
    'Reader typed two memory-sized relocated object references at token positions');
   $assert(substr($nativeHex,0,2)==='29'&&substr($nativeHex,10,2)==='29',
    'Actual original loaded native script opcodes at bytes0 and5');
   $refs=array_map(static fn(array $x)=>$x['archive_index']??null,$s['native_load_relocation_preview']??[]);
   $assert($refs===$indexes,'Actual archived two typed source ObjectProperty indices for original object targets');
   $assert(($ex[$indexes[0]-1]['objectNameText']??null)==='A'
      &&($ex[$indexes[1]-1]['objectNameText']??null)==='B',
      'Original export targets names A and B; no guessed relocated pointer values');
  }else{
   $assert($nativeHex===$maskPrefix &&($s['source_memory_bytes_hex']??null)===$maskPrefix,
    'Original source-script native bytes match non-relocated full script data');
  }
 }
 $results[$name]=['source_package'=>$file,'export_index'=>$id,'native_script_bytes'=>$num,
  'original_memory_hex'=>$nativeHex,'archived_script_bytes'=>$archiveLength,
  'object_pointer_relocations'=>$pointerCount];
}
echo json_encode(['ok'=>!$fail,'checks'=>$checkCount,'failed'=>$fail,
 'T003'=>'Genuine original compiled v432 stock Core.dll / Engine.dll native zero + nonzero UStruct Script.Num, typed object relocations and archive byte size equality',
 'native_objects'=>$results,
 'previous_native_verifiers_reexecuted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
