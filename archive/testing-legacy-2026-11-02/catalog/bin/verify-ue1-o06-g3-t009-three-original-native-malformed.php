#!/usr/bin/env php
<?php
/** Fixed G3 T009: compiled original C98/v432/227k UStruct malformed LOAD.
 * Immutable x86 native traces from new, isolated one-byte original Core.u
 * mutations; this focus verifier NEVER executes an original compiled engine.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
$repo=dirname(__DIR__,2);
$e=$repo.'/catalog/compliance/evidence/o06/';
$temp='C:/Temp/UnrealDB/O06/';
$c98='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/';
$errors=[];$checks=0;$sealed=[];
$check=static function(bool $passed,string $why)use(&$checks,&$errors):void{
 ++$checks;if(!$passed)$errors[]=$why;
};
$pins=[
 [$c98.'Core.u','7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c'],
 [$c98.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$c98.'Unreal.exe','d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30'],
 [$temp.'Native-UCC/System/Core.u','ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70'],
 [$temp.'Native-UCC/System/Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'],
 [$temp.'Native-UCC/System/UCC.exe','90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f'],
 [$temp.'Native-227k/System/Core.u','28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c'],
 [$temp.'Native-227k/System/Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$temp.'Native-227k/System/UCC.exe','8377f0e1a8adcfb087a4ada317ff5dedbab406a7888226af904aca84908d907a'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php',
  '2b87b97b2c6c0d830205623cce6cf2594792952ef6ebf5314e88f0a2511f27b1'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php',
  'afca90b65cfb192d23e5d0aaa0f449acb651c85548fb81ccac25bce3a9963714'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php',
  'b309c89b21c88233a4de459b183b23dcdd2c4e4deb1fc2ba56a61f2ad34330bd'],
 [$e.'20261028-G3-T009-C98-Children-original-compiled-Tell.log',
  'c88cd8a413167d33e40168dacc3082faa0ca51d2af6bf61f2d3912f67f65df54'],
 [$e.'20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.log',
  'a4d88f4b4c7baadee6af3d743c76b354e375044f06b6330f12eaba815c4efe86'],
];
$nativeFiles=[
 '20261028-G3-T009-v432-original-native-Children-Tell.log'=>'5dfa15d8beb8afd9808776cc3976116b47806b6b2d5c6391e10d27c9431a520d',
 '20261028-G3-T009-v432-original-native-Children-Tell.cmd'=>'092bd598b09550a99d2b659c6ecbe4e147b2474008478a1567ee3687be9fdcba',
 '20261028-G3-T009-v432-original-native-ScriptSize-Tell.log'=>'2267d0ab6db79beb335315b195cd6afbfff0159a5d5d4df81187a5a8dd9f0a70',
 '20261028-G3-T009-v432-original-native-ScriptSize-Tell.cmd'=>'9e92ee167c7aeb65a5c077ecd7c4a5daf9cce95e6a89286d0b43758b535ab611',
 '20261028-G3-T009-v432-original-native-Tell-impl.log'=>'eddde0fa7996348455d5d034036008c554c19ee342254515cc21cced2cd390a6',
 '20261028-G3-T009-227k-original-native-Children-Tell.log'=>'5f1fb38123da601dee3a2785bc4cdbae04edb340fd39869afa66501c7ef8d95d',
 '20261028-G3-T009-227k-original-native-Children-Tell.cmd'=>'584fbec7d8f0da692afec954b5555cb549b83a167b3f0c387650f547cc81ddf4',
 '20261028-G3-T009-227k-original-native-ScriptSize-Tell.log'=>'1d7522447f3f590f68de07f629c8c301a9cdd1f68730b4c553f78e23466a1b8d',
 '20261028-G3-T009-227k-original-native-ScriptSize-Tell.cmd'=>'7e6ecd96753be7c1f37e191c6ae77868dd10e13eac08815465204f13e0700ed1',
 '20261028-G3-T009-227k-original-native-Children-console.log'=>'4fcc8622ea7ea9c81485378a6f16b9004b7f4c5e56f2b3fdc9eab92fde48125e',
 '20261028-G3-T009-227k-original-native-ScriptSize-console.log'=>'703053b17f40a77a91464a181e4fa8d934adfd7e2702ea1e498398e87c549c83',
];
foreach($nativeFiles as $name=>$hash){$pins[]=[$e.$name,$hash];}
foreach($pins as [$file,$sha]){
 $ok=is_file($file)&&hash_file('sha256',$file)===$sha;
 $check($ok,'immutable original x86 binary/command/log/current reader SHA: '.basename($file));
 if($ok)$sealed[basename($file)]=$sha;
}
$nativeEvidence=[
 'C98'=>[
    ['20261028-G3-T009-C98-Children-original-compiled-Tell.log',
      ['Bad import index %i/%i','00005ea0','1014cb88']],
    ['20261028-G3-T009-C98-ScriptSize-original-compiled-Tell.log',
      ['Script serialization mismatch','00005eb3','1014cb88']],
 ],
 'v432'=>[
    ['20261028-G3-T009-v432-original-native-Children-Tell.log',
      ['NATIVE_V432_CHILDREN_BADIMPORT_LOGF','0000a92b','Core!FOutputDevice::Logf']],
    ['20261028-G3-T009-v432-original-native-ScriptSize-Tell.log',
      ['T009_V432_ORIGINAL_COMPILED_UStruct_SCRIPT_MISMATCH','0000a93c','ORIGINAL_UNDERLYING_FILE_ARCHIVE','004010cd']],
    ['20261028-G3-T009-v432-original-native-Tell-impl.log',
      ['UCC+0x27c0','004027c0 8b413c','mov     eax,dword ptr [ecx+3Ch]']],
 ],
 '227k'=>[
    ['20261028-G3-T009-227k-original-native-Children-Tell.log',
      ['T009_227K_BADIMPORT_ORIGINAL_LOGF_ENAME','Core!ULinkerLoad::IndexToObject+0xd8','00010827']],
    ['20261028-G3-T009-227k-original-native-ScriptSize-Tell.log',
      ['T009_227K_UStruct_MISMATCH_UNDERLYING_ARCHIVE_TELL','00010838','UCC+0x7d30','mov     eax,dword ptr [ecx+20h]']],
    ['20261028-G3-T009-227k-original-native-Children-console.log',
      ['Failed to load object Function Core.Object.EndState: <?int?Core.Errors.ImportIndex?>']],
    ['20261028-G3-T009-227k-original-native-ScriptSize-console.log',
      ['Script (EndState) serialization failed: Size mismatch: Got 7, expected 3']],
 ]
];
foreach($nativeEvidence as $label=>$entries){
 foreach($entries as [$file,$needles]){
  $log=(string)file_get_contents($e.$file);
  foreach($needles as $str)$check(str_contains($log,$str),$label.' original compiled source+cursor: '.$file.' / '.$str);
 }
}
$cases=[
 ['C98',$c98.'Core.u',$temp.'T009-C98','epic-ue1',456,24218,35,16,4,
  ['invalid-Children-index',24223,0,0xbf,'a3df488cd43c8168900401fe386a8ac50932b157f7b48c0c1bcc6710c62143b5',
   'UE1 Bad import index 62/16 at archive cursor 24224'],
  ['invalid-ScriptSize-five',24233,4,5,'930e76d7befc0767cb3bdff95fc02f9408c8eff73a737bf09c271c9a5cadd0ea',
   'UE1 Script serialization mismatch: Got 9, expected 5 at archive cursor 24243']],
 ['v432',$temp.'Native-UCC/System/Core.u',$temp.'T009-v432','ut99-v432',634,43300,29,16,2,
  ['invalid-Children-index',43306,0,0xbf,'9b2c51e1894e4820118d00fc728e354422318404f6214920c7da513b2f19e7aa',
   'UE1 Bad import index 62/16 at archive cursor 43307'],
  ['invalid-ScriptSize-three',43316,2,3,'8012f3089b8dcf45f726562577f212499c1e2910094f8eaa7cad4f61b7f60980',
   'UE1 Script serialization mismatch: Got 7, expected 3 at archive cursor 43324']],
 ['227k',$temp.'Native-227k/System/Core.u',$temp.'T009-227k','unreal-v227k',633,67616,29,19,2,
  ['invalid-Children-index',67622,0,0xbf,'2a5101a00bd61f61e57f41602cf6b506cd44ea8750a3fe3e58d12d2de0f73f6a',
   'UE1 227k <?int?Core.Errors.ImportIndex?> at archive cursor 67623'],
  ['invalid-ScriptSize-three',67632,2,3,'e82e6993d686714582cb19dbd3f12f42303621d663b43f0fd58b72280da11020',
   'Script (EndState) serialization failed: Size mismatch: Got 7, expected 3 at archive cursor 67640']],
];
$observations=[];
foreach($cases as [$build,$stock,$scratch,$profile,$index,$start,$serialSize,$imports,$scriptNum,$reference,$size]){
 $original=(string)file_get_contents($stock);
 $pkg=new Package($stock);$ex=$pkg->getExports()[$index]??[];
 $check(($pkg->getHeader()['version']??0)===($build==='C98'?61:69)
   &&count($pkg->getImports())===$imports
   &&($ex['objectNameText']??null)==='EndState'
   &&($ex['serialOffset']??-1)===$start&&($ex['serialSize']??-1)===$serialSize,
   $build.' genuine original source package and named EndState exact export');
 $check($pkg->validatePackage()===[],$build.' original genuine package envelope valid');
 $positive=Native::inspect($stock,$pkg,$index,
   Tagged::inspect($stock,$pkg,$index,$profile),$profile);
 $check(($positive['status']??null)==='native_function_record_complete'
   &&($positive['script_size']??null)===$scriptNum,
   $build.' unchanged genuine original positive UFunction native script');
 foreach([$reference,$size] as [$label,$offset,$oldByte,$newByte,$hash,$message]){
  $mutant=$scratch.'/'.$label.'/System/Core.u';
  $raw=(string)file_get_contents($mutant);
  $check(hash('sha256',$raw)===$hash&&strlen($raw)===strlen($original),
   $build.' one-byte-only mutated genuine package SHA and file size '.$label);
  $check(ord($original[$offset])===$oldByte&&ord($raw[$offset])===$newByte,
   $build.' source-defined Children vs ScriptSize at exact physical position '.$offset);
  $restored=$raw;$restored[$offset]=$original[$offset];
  $check($restored===$original,
    $build.' identical original package except ONE designated changed serialized byte '.$label);
  $reader=new Package($mutant);
  $check($reader->validatePackage()===[],
    $build.' malformed payload retains genuine valid envelope '.$label);
  $got=null;$loaded=false;
  try{
   $success=Native::inspect($mutant,$reader,$index,
     Tagged::inspect($mutant,$reader,$index,$profile),$profile);
   $loaded=is_array($success);
  }catch(Throwable $failure){$got=$failure->getMessage();}
  $check(!$loaded&&$got===$message,
    $build.' exact original compiled method error identity and actual FArchive Tell '.$label);
  $observations[]=['profile'=>$build,'original_export_index'=>$index,
    'mutated_archive_byte_offset'=>$offset,'one_byte_change'=>sprintf('%02X->%02X',$oldByte,$newByte),
    'current_reader_error'=>$got,'original_native_error_and_Tell_match'=>!$loaded&&$got===$message,
    'mutated_real_package_sha256'=>$hash];
 }
}
$check(count($observations)===6,'fixed T009 exactly two distinct original negative source branches per three original engine implementations');
$check(array_sum(array_map(static fn(array $v):int=>($v['original_native_error_and_Tell_match']??false)?1:0,$observations))===6,
  'all SIX genuine original compiled negative method error IDs, physical cursor and rejected load agree');
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'errors'=>$errors,
  'T009_original_compiled_distinct_engines'=>['C98-1998-retail','UT99-v432-stock','Unreal-v227k-2026-stock'],
  'original_native_malformed_scenarios'=>6,'observations'=>$observations,
  'compiled_engine_native_runs_reexecuted_by_verifier'=>0,
  'previous_completed_positive_native_tests_replayed'=>0,
  'pinned_original_source_binary_and_native_logs'=>$sealed,
  'overall_G3_03'=>'OPEN_OTHER_FIXED_TEST_IDS'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
