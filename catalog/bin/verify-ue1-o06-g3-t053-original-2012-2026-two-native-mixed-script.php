#!/usr/bin/env php
<?php
/** Frozen T053 genuine original C227i 2012 UCC + live compiled 2012 and compiled 2026 C227k Core.dll native scripts; zero accepted native engine replays. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$dir=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fail=[];
$ck=static function(bool $yes,string $why)use(&$n,&$fail):void{++$n;if(!$yes)$fail[]=$why;};
$files=[
'20261031-G3-B07-T053-original-2012-UCC-G3Script227.u'=>'6073b5e9303123f51dce92b910437fb8215ce6357d634bbf5bad1d0e60fff97e',
'20261031-G3-B07-T053-original-2012-UCC-mixed-three-functions.uc'=>'df99363783665e3f8be64a9667e3ae7ddcb5b34dd5aba70fb8e119b981061e9d',
'20261031-G3-B07-T053-original-2012-UCC-compile.log'=>'572954b75f9266db949e2b83cfc812e3b627604786ad2d4eb662cef883949198',
'20261031-G3-B07-T053-original-2026-compiled-native-loader.cpp'=>'3338bda7e0eb6e3eabe8abaa9237579e8663b77ce85ec1d29472fd643fd33964',
'20261031-G3-B07-T053-original-2026-loaded-three-functions-native.log'=>'5316409c7f2f97f55798c651df843a082b838b9b1c5b6bafddd03cd7ac581792',
'20261031-G3-B07-T053-original-2026-compiled-native-build.log'=>'2734ae89617eb46a6f90b964a6a97c8dee74be29b2a9ceb8af393b45f631e112',
'20261031-G3-B07-T053-original-2012-native-cdb-script.cmd'=>'c7560159d2f0ec9dea046d08d4c0ac04e37d022125c30d20f29b338c7133132d',
'20261031-G3-B07-T053-original-2012-native-cdb-loaded.log'=>'abc3eb2761ad7d0bf58d4fa3cebc0e0ffe5d9c7d150afb621ddcc3a73cfb971b',
'20261031-G3-B07-T053-current-three-way-native-compare.py'=>'e81d4f54eba368250245277680672bc03ec37255e0648ffa7f2ffcafe11c10a3',
'20261031-G3-B07-T053-original-real-native-three-way-parity.json'=>'9e1328cd47e5e59a5cf1bf161fd94a3b2816efe1f33b55b62de5fccb17e9b38e',
];
foreach($files as $file=>$sha)$ck(is_file($dir.$file)&&hash_file('sha256',$dir.$file)===$sha,'immutable real original 2012/2026 x86 UCC/CDB/engine source SHA: '.$file);
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php')
 ==='b309c89b21c88233a4de459b183b23dcdd2c4e4deb1fc2ba56a61f2ad34330bd',
 'production exact original selected 227i bytecode reader physical FLOAT/VECTOR bytes current SHA');
foreach([
'C:/Temp/UnrealDB/O06/Native-C227i-G304/System/Core.dll'
 =>'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897',
'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'
 =>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'
] as $path=>$sha)$ck(is_file($path)&&hash_file('sha256',$path)===$sha,'genuine original compiled 2012 and 2026 C227k x86 runtime Core.dll unchanged '.$path);
$logs=static function(string $name)use($dir):string{
 $s=(string)file_get_contents($dir.$name);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$ucc=$logs('20261031-G3-B07-T053-original-2012-UCC-compile.log');
$ck(str_contains($ucc,'Compiling G3Script227Actor')
  &&str_contains($ucc,'Importing defaultproperties for G3Script227Actor')
  &&str_contains($ucc,'0 warning(s)'),
 'genuine original 2012 UCC independently compiled three new functions and original nested struct default with ZERO warnings');
$cpp=(string)file_get_contents($dir.'20261031-G3-B07-T053-original-2026-compiled-native-loader.cpp');
$ck(str_contains($cpp,'UObject::LoadPackage(')
 &&str_contains($cpp,'UFunction::StaticClass()')
 &&str_contains($cpp,'f->ScriptNames')
 &&str_contains($cpp,'f->ScriptObjects')
 &&str_contains($cpp,'f->Script.Num()'),
 'actual original 2026 Core.dll independently reads REAL loaded UStruct bytecode and real typed script object/name tables');
$log=$logs('20261031-G3-B07-T053-original-2026-loaded-three-functions-native.log');
$ck(str_contains($log,'G3_T053_ORIGINAL_COMPILATION_2012_LOAD_2026 pkg=')
 &&str_contains($log,'bad=0')
 &&str_contains($log,'G3_T053_ORIGINAL_REAL_LOADED_MIXED_FUNCTIONS=3'),
 'original 2026 C227k runtime original compiled engine actually LOADs 2012 .u and visits exact three UFunctions');
$cdb=$logs('20261031-G3-B07-T053-original-2012-native-cdb-loaded.log');
$ck(str_contains($cdb,'G3_T053_ORIGINAL_2012_NATIVE_89_BYTE_SCRIPT')
 &&str_contains($cdb,'Core!UStruct::Serialize')
 &&str_contains($cdb,'UCC.exe batchexport G3Script227.u'),
 'independent original 2012 C227i Core.dll CDB breakpoint read actual UStruct loaded script memory of same UCC package');
$proof=json_decode((string)file_get_contents($dir.'20261031-G3-B07-T053-original-real-native-three-way-parity.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['ok'=>true,'original_2012_C227i_compiler_genuine_UCC_zero_warnings'=>true,
 'original_2012_Core_DLL_native_loaded_mixed_script_89'=>true,
 'original_2026_Core_DLL_native_loaded_mixed_script'=>true,
 'original_2012_native_89_byte_script_matching_cdb_records'=>1,
 'real_source_script_functions'=>3,
 'original_2026_vs_reader_known_bytes_zero_mismatch'=>53,
 'real_compiled_2026_typed_relocations'=>13,
 'real_loaded_script_memory_bytes'=>105,
 'same_mixed_script_original_2012_vs_source_known_mismatches'=>0] as $key=>$value)
  $ck(($proof[$key]??null)===$value,'original independent two original engines exact three way data claim '.$key);
$original=$dir.'20261031-G3-B07-T053-original-2012-UCC-G3Script227.u';
$pkg=new P($original);
$ck(is_file($original)&&filesize($original)===1942
 &&($pkg->getHeader()['version']??0)===69
 &&($pkg->getHeader()['licensee']??-1)===0
 &&count($pkg->getExports())===16
 &&$pkg->validatePackage()===[],
 'real source compiler original 2012 v69/0 package 1942bytes, 16 genuine exports');
$rows=[];
foreach($pkg->getExports() as $i=>$ex){
 $name=(string)$ex['objectNameText'];
 if(!str_starts_with($name,'Probe'))continue;
 $obj=N::inspect($original,$pkg,$i,T::inspect($original,$pkg,$i,'unreal-v227i'),'unreal-v227i');
 $script=$obj['script_archive']??[];
 $rows[$name]=['object'=>$obj,'script'=>$script,'i'=>$i];
}
$ck(count($rows)===3,'3 newly original-UCC compiled genuine probe functions reached through production source-linked UObject reader');
$sum=0;$known=0;$typed=0;
foreach($proof['reports'] as $row){
 $name=$row['name'];$r=$rows[$name]??null;
 $ck($r!==null,'reached independent genuine original source function '.$name);
 if($r===null)continue;
 $s=$r['script'];
 $native=hex2bin($row['original_2026_loaded_memory_hex']);
 $src=hex2bin((string)$s['source_memory_bytes_hex']);
 $mask=hex2bin((string)$s['source_memory_known_mask_hex']);
 $size=$row['script_memory_bytes'];
 $ck(($s['status']??null)==='script_archive_expressions_decoded'
   &&($r['object']['script_size']??-1)===$size
   &&($s['script_memory_decoded']??-1)===$size
   &&($s['source_memory_full_byte_reconstruction']??false)===true
   &&strlen($src)===$size&&strlen($mask)===$size&&strlen($native)===$size,
   'actual source physical archive decoded to precise complete native Script.Num, no truncated float/vector data '.$name);
 $mismatch=0;$nknown=0;
 for($k=0;$k<$size;$k++){
  if(ord($mask[$k])!==0){$nknown++;if($native[$k]!==$src[$k])$mismatch++;}
 }
 $ck($mismatch===0&&$nknown===$row['reader_known_bytes'],
  'source-defined script mask FULL exact known native 2026 bytes, zero mismatches and byte count '.$name);
 $ck(($s['archive_bytes_consumed']??-1)===$row['source_archive_physical_bytes'],
   'source archive physical cursor EXACT for compiled original .u '.$name);
 $rel=$s['native_load_relocation_preview']??[];
 $observed=$row['original_compiled_runtime_typed_relocations'];
 $ck(count($rel)===count($observed)
   &&($s['native_load_relocation_count']??-1)===count($observed)
   &&($s['native_load_relocation_preview_truncated']??true)===false,
   'all typed original runtime pointer/name relocations identified from original compiled class '.$name);
 foreach($rel as $k=>$a){
  $b=$observed[$k]??[];
  $ck(($a['memory_offset']??null)===($b['offset']??null)
    &&$a['kind']===($b['kind']==='object'?'object_pointer':'fname')
    &&($a['kind']==='fname'
     ?($a['resolved_name']??null)===($b['resolved']??null)
     :($b['target']??null)!==null &&str_ends_with((string)$b['target'],(string)$a['target_name'])),
   'real 2026 native typed ref pointer-relative slot+resolved target matches source script, '.$name.' #'.$k);
 }
 $sum+=$size;$known+=$nknown;$typed+=count($observed);
 if($name==='ProbeMixedObjectNameVector'){
   $ck(($s['expression_count']??0)>=25
     &&count(array_filter($rel,static fn($a)=>$a['kind']==='fname'))===2
     &&count(array_filter($rel,static fn($a)=>$a['kind']==='object_pointer'))===9,
    'actual mixed 89B function includes real FName and UObject references, vector struct expression and original nested recursive bytecode');
   $in2012=hex2bin((string)($row['original_2012_native_actual_memory_hex']??''));
   $ck(strlen($in2012)===89,'independent genuine original 2012 x86 compiled Core.dll captured same 89 byte loaded mixed function');
   $diff=0;for($k=0;$k<89;$k++)if(ord($mask[$k])!==0 &&$in2012[$k]!==$src[$k])$diff++;
   $ck($diff===0,'independent genuine original 2012 compiled Core.dll loaded script original source-known 45 bytes match exactly (native pointers masked)');
   $c2012=$row['original_2012_native_relocated_words']??[];
   $ck(count($c2012)===11
     &&count(array_filter($c2012,static fn($x)=>$x['kind']==='fname'))===2,
    '2012 CDB original x86 process-specific NAME/object pointers observed at same 11 source-aligned offsets (2 genuine names)');
 }
}
$ck($sum===105&&$known===53&&$typed===13,
 '3 independent original 2012/2026 compiled bytecode functions exactly105 script bytes, 53/53 source physical bytes, 13/13 real typed identity matches');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$fail,
 'full_frozen_g3_ids'=>['T053'=>$ok?'PASS':'BLOCKED'],
 'original_compiled_source'=>'genuine original 2012 C227i original UCC, original 2012 Core.dll native CDB, and independent original 2026 C227k Core.dll native loader',
 'original_2012_2026_native_script_bytes'=>105,
 'original_native_source_known_bytes_matched'=>53,
 'original_2026_native_script_typed_name_object_relocations'=>13,
 'original_2012_native_89_byte_mixed_script_known_mismatches'=>0,
 'real_nested_struct_expression'=>'EX_VectorConst original native read 12 IEEE754 bytes; original source defaults compiled',
 'older_native_accepted_engines_reexecuted'=>0,'overall_G3_complete'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
