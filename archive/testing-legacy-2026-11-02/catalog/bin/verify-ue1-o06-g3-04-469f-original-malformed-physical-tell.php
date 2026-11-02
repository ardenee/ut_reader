#!/usr/bin/env php
<?php
/** UT99 OldUnreal 469f physical FArchive::Tell from original compiled x86 Core.dll.
 * This new malformed negative demonstrates a source-profile-specific behavioral
 * distinction from the already sealed 227k check-before-read case. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$e=dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-469f-';
$local='C:/Temp/UnrealDB/O06/Native-469f/System/';
$files=[
 $local.'Core.dll'=>'80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2',
 $local.'G304469fBadNetReload.exe'=>'ba98ef9942629039c861203d279dbe044f32ed8a54d2f79f3f85746384402a74',
 $e.'native-badnet-original-mutant.u'=>'bc940c6aa9a3cfc38bed3e428445b2929ad30c1dbe6fd9154cbca48b8cfdf711',
 $e.'native-badnet-original-runtime.log'=>'7e02d4774e3b47389484b21fe98be172f719f593e8dd08d9546ee3f7a9c2c964',
 $e.'native-function-badnet-load.cpp'=>'326877d95d7392ef0bba76de346b38f9aa9677209074be30d3ecf8cc653d0663',
 $e.'badnet-load-build.log'=>'c6f94f4367c9c46704f55522f6f89dab577a82705c4314f4b93aaa2709562e9a',
 $e.'malformed-preload-disasm.log'=>'63da32cf2094332e7416f5c79f94cbb179e4404d7b58227826d13fa256d75f26',
 $e.'badnet-real-native-farchive-tell.cmd'=>'6e35d33b0be78aedf9c325d3e006748c68f17f53a804fa22da1230afd9eae6e3',
 $e.'badnet-real-native-farchive-tell.log'=>'e4891e18c06d68f9852651c7c9c3e93e1439f264c6a489e039cca4a4407f5f32',
 dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-469f-function-state-original-saved.u'=>'f389aeba411529cbbc9b52d0c814925db3d39b324629ac293090a5e81d88a9c3',
];
$checks=0;$fail=[];
$check=static function(bool $pass,string $label)use(&$checks,&$fail):void{++$checks;if(!$pass)$fail[]=$label;};
foreach($files as $f=>$sha)$check(is_file($f)&&hash_file('sha256',$f)===$sha,'original compiled/evidence SHA '.basename($f));
$good=(string)file_get_contents(dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-469f-function-state-original-saved.u');
$bad=(string)file_get_contents($e.'native-badnet-original-mutant.u');
$check(strlen($good)===322&&strlen($bad)===322,'original 322-byte writer fixture and one-byte mutant');
$mut=[];
for($i=0;$i<min(strlen($good),strlen($bad));++$i)if($good[$i]!==$bad[$i])$mut[]=$i;
$check($mut===[229]&&ord($good[229])===2&&ord($bad[229])===66,
    'only original 469f writer FunctionFlags bit FUNC_Net mutated at byte229');
$log=(string)file_get_contents($e.'badnet-real-native-farchive-tell.log');
$dis=(string)file_get_contents($e.'malformed-preload-disasm.log');
$cmd=(string)file_get_contents($e.'badnet-real-native-farchive-tell.cmd');
$native=(string)file_get_contents($e.'native-badnet-original-runtime.log');
$check(str_contains($dis,'5ef45666 ff5028')
    &&str_contains($dis,'call    dword ptr [eax+28h]')
    &&str_contains($dis,'sub     eax,esi'),
    'true 469f compiled Preload disassembly verifies Tell then consumed-size subtraction');
$check(str_contains($cmd,'Core!ULinkerLoad::Preload+0x139'),
    'CDB breakpoint is after actual 469f compiled virtual FArchive::Tell return');
$check(preg_match_all('/^G304_469F_REAL_COMPILED_FARCHIVE_TELL event=1 native_tell=235 serial_start=208 actual_bytes=27 declared_size=25/m',$log)===1,
    'ORIGINAL native actual Tell235, serial start208, physically consumed27, declared25');
$check(str_contains($native,'Core.Errors.SerialSize')
    &&str_contains($native,'Core.Errors.FailedLoadPackage'),
    'original compiled 469f loader reports serial size failure then rejects bad package');
$pkg=new P($e.'native-badnet-original-mutant.u');$x=$pkg->getExports();$check(count($x)===2&&($x[0]['serialOffset']??null)===208
    &&($x[0]['serialSize']??null)===25&&($x[1]['serialOffset']??null)===233,
    'old 469f original malformed export ends at 233, next State starts at 233');
$n=N::inspect($e.'native-badnet-original-mutant.u',$pkg,0,
    T::inspect($e.'native-badnet-original-mutant.u',$pkg,0,S::UT99_V469F),
    S::UT99_V469F);
foreach(['status'=>'native_export_serial_size_mismatch','source_serial_size_check_failed'=>true,
    'archive_cursor'=>235,'expected_export_end'=>233,'serial_size_overrun_bytes'=>2,
    'function_flags'=>66] as $key=>$val)$check(($n[$key]??null)===$val,'UT99 469f true physical native cursor parity '.$key);
foreach(['loaded_num_parms','loaded_parms_size','loaded_return_value_offset'] as $key)
    $check(($n[$key]??null)===null,'invalid native function must not expose loaded '.$key);
$s=N::inspect($e.'native-badnet-original-mutant.u',$pkg,1,
    T::inspect($e.'native-badnet-original-mutant.u',$pkg,1,S::UT99_V469F),S::UT99_V469F);
$check(($s['status']??null)==='native_state_record_complete',
    'only the modified original UFunction is rejected; independent UState intact');
echo json_encode(['ok'=>!$fail,'checks'=>$checks,'failures'=>$fail,
    'actual_original_469f_native_FArchive_Tell'=>235,'original_function_export_end'=>233,
    'original_compiled_overrun_bytes'=>2,'reader_physical_cursor'=>$n['archive_cursor']??null,
    'source_specific_227k_precheck_oracle_repeated'=>false,
    'g3_04_FULL'=>'OPEN'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
