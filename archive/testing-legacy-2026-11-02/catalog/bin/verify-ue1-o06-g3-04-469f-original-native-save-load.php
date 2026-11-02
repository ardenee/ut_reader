#!/usr/bin/env php
<?php
/**
 * Original, unmodified UT99 OldUnreal v469f Core.dll performs native
 * UState/UFunction SAVE to a new package and independent fresh LOAD.
 * Unlike the 227k fixture, this is a distinct original compiled UT99
 * generation with version69/licensee0 and v400/v432 inherited source.
 * One positive method pair only. No C98/v432/227 suites repeated.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/20261028-G3-04-469f-';
$sys='C:/Temp/UnrealDB/O06/Native-469f/System/';
$sdk='L:/Source/Games/UT99/OldUnreal-UTPatch469f-SDK-Windows/Core/Lib/x86/Core.lib';
$hashes=[
 $sys.'Core.dll'=>'80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2',
 $sdk=>'55ae95a080e56015af5e28aeb99637e6afa81063f2ff924b0e7fab6e9b24668a',
 $sys.'G304469fMethodSave.exe'=>'e68ab3cc19e918e9ca9e7b175631428ae566b056e62aeb9bb236ed96029e96a4',
 $sys.'G304469fMethodReload.exe'=>'eabefc8082e56bd79db608dd26b62c4a161e1c25a705b8b2027f8ed08aa6c23b',
 $ev.'function-state-original-saved.u'=>'f389aeba411529cbbc9b52d0c814925db3d39b324629ac293090a5e81d88a9c3',
 $ev.'native-function-state-generate.cpp'=>'eede64038c9372b542c6042a8a81663e650c7bb01d98f07e81218536bc29103b',
 $ev.'native-function-state-load.cpp'=>'675401277ca18efc5ea1d38ed2c1f1117e89302eabb308e4c11e3b67a5ecb203',
 $ev.'method-save-build.log'=>'96a2c89cd07c472e1c50bb0b07af4aeef8da4d1551c5123528a5a50c9ed521e8',
 $ev.'method-reload-build.log'=>'22ad2140d3a51f31b8f32b714810d4930734667c99d1363251412b320761858f',
 $ev.'method-save-console.log'=>'8c516a11eb8df2432ec17a55bf3ce619b32ab443babec9189f358fbdeb9bbb73',
 $ev.'method-reload-console.log'=>'412984ad3ffba423d66a35bd8fa15fac9ecb8b03a3a70c0d7c8495cb8c35d08c',
 $ev.'method-save-build.ps1'=>'c52a7c4b72ded258f70db44b07eb7421ff78f4aa3d3209403dcc9c6515391055',
 $ev.'method-reload-build.ps1'=>'3169c524be7ab8ff018913f9a99dadd65817208277d0c9522680056368ff97f6',
];
$checks=0;$fails=[];
$check=static function(bool $yes,string $what)use(&$checks,&$fails):void{
 ++$checks;if(!$yes)$fails[]=$what;
};
foreach($hashes as $file=>$sha){$check(is_file($file)&&hash_file('sha256',$file)===$sha,'SHA '.basename($file));}
$save=file_get_contents($ev.'method-save-console.log');
$load=file_get_contents($ev.'method-reload-console.log');
$save=str_replace("\0",'',$save);$load=str_replace("\0",'',$load);
$check(str_contains($save,'G304_469F_NATIVE_FUNCTION_STATE_SAVED=1')
    &&str_contains($save,'G304_469F_NATIVE_NEW_STATE ')
    &&str_contains($save,'G304_469F_NATIVE_NEW_FUNCTION '),
    'actual original 469f compiled SavePackage saved exactly the new real State and Function');
$check(str_contains($load,'G304_469F_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1')
    &&str_contains($load,'G304_469F_ORIGINAL_REAL_LOADER_PACKAGE='),
    'independent new process actual original compiled v469f LoadPackage validated all fields and pointers');
$check(str_contains($load,'G304_469F_LOADED_STATE_PROBE=1020304050607080 IGNORE=1234567890ABCDEF FLAGS=00010200 LABEL=0'),
    'real original native SAVE + LOAD state masks, flags and label');
$check(str_contains($load,'G304_469F_LOADED_FUNCTION_FLAGS=00000002 NATIVE=0 PRECEDENCE=0 NUMPARMS=0 PARMSIZE=0 RETURN=65535'),
    'real original native SAVE + LOAD function flags and linked calculated parameter fields');
$check(str_contains(file_get_contents($ev.'method-save-build.ps1'),'/Zc:wchar_t-')
    &&str_contains(file_get_contents($ev.'method-save-build.ps1'),'Core.lib'),
    'actual original 469f x86 ABI and original SDK Core.lib used for true SAVEr');
$check(str_contains(file_get_contents($ev.'method-reload-build.ps1'),'/Zc:wchar_t-')
    &&str_contains(file_get_contents($ev.'method-reload-build.ps1'),'Core.lib'),
    'original shipped 469f x86 ABI and Core.lib used for independent actual compiled loader');
$file=$ev.'function-state-original-saved.u';$p=new P($file);
$x=$p->getExports();
$check(filesize($file)===322&&count($x)===2
    &&($p->getHeader()['version']??null)===69
    &&($p->getHeader()['licenseeVersion']??null)===0,
    'genuine original compiled SAVE created 322-byte v69/0 package with two exports');
$check(($x[0]['objectNameText']??null)==='G304469fFunction'
    &&($x[0]['serialOffset']??null)===208
    &&($x[0]['serialSize']??null)===25
    &&($x[1]['objectNameText']??null)==='G304469fState'
    &&($x[1]['serialOffset']??null)===233
    &&($x[1]['serialSize']??null)===40,
    'exact 469f original method offsets and 25/40-byte serialized native tails');
$values=[];
foreach([0,1] as $i){
 $values[$i]=N::inspect($file,$p,$i,
   T::inspect($file,$p,$i,S::UT99_V469F),S::UT99_V469F);
 $check(($values[$i]['status']??null)===($i===0?'native_function_record_complete':'native_state_record_complete'),
   'original saved member native export complete #'.$i);
}
$f=$values[0];$s=$values[1];
foreach(['function_flags'=>2,'native_index'=>0,'operator_precedence'=>0,
  'loaded_num_parms'=>0,'loaded_parms_size'=>0,'loaded_return_value_offset'=>65535,
  'children_reference'=>0,'rep_offset'=>null] as $key=>$v){
 $check(($f[$key]??null)===$v,"original saved then reloaded UFunction $key");
}
foreach(['probe_mask_hex'=>'8070605040302010',
  'ignore_mask_hex'=>'efcdab9078563412','state_flags'=>66048,
  'label_table_offset'=>0,'children_reference'=>1] as $key=>$v){
 $check(($s[$key]??null)===$v,"original saved then reloaded UState $key");
}
$check(($f['runtime_function_param_chain_linked']??null)===false,
    'reader does not fabricate original genuine in-process pointers');
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'failed'=>$fails,
    'original_compiled_469f_save_load_methods'=>2,
    'original_writer_generated_package_bytes'=>322,
    'original_compiled_CDB_Engine_load_rerun'=>false,
    'other_original_native_suites_rerun'=>false,
    'g3_04_FULL'=>'OPEN: other source build SAVE/version/error branches'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
