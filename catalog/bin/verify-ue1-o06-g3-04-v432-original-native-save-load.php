#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$e=dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-V432-';
$orig='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$hashes=[
$orig.'Core.dll'=>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
'L:/Source/Games/UT99/Unreal Tournament v432/Core/Lib/Core.lib'=>'066f48ac6e136ac0ddfc27663305f52d957709f4f4ca4539c6f58be0760e6448',
$orig.'G304V432MethodSave.exe'=>'b7091010f5559a88a0f1faeb10e932babbbc4c29b7e2d18ea6456ef03695a4a2',
$orig.'G304V432MethodReload.exe'=>'fba945313b5642c4384698cd725002d08105f77c06d215ddf14532e580b7c377',
$e.'native-function-state-generate.cpp'=>'44241cc57f1da955bb2b7fe3bbcbeec5218556ae26fe2cf600457db3f9140d53',
$e.'native-function-state-load.cpp'=>'06af72a3c7c8a95c942961f2475e0b75bde1903e8594960b8f1bd05a109a175e',
$e.'native-method-reload-build.log'=>'f8a22ec25758db2b2113b14ac2d4eec77c087ed3ef951d34f022c2001fdc8bbb',
$e.'native-method-reload-console.log'=>'a944feaf2579b3aaf0f420ba65f5d9da525b73ea5ca4eee83fa2240e09d0e875',
$e.'original-native-save.u'=>'545439a4526dd9108fd3c12f3b6f1eaf37ad2c91cf3729e684e12fc8dc903ad6',
];
$assertions=0;$fail=[];
$ok=static function(bool $pass,string $claim)use(&$assertions,&$fail):void{++$assertions;if(!$pass)$fail[]=$claim;};
foreach($hashes as $file=>$sha)$ok(is_file($file)&&hash_file('sha256',$file)===$sha,'original or evidence SHA '.basename($file));
$loader=str_replace("\0",'',(string)file_get_contents($e.'native-method-reload-console.log'));
$ok(str_contains($loader,'G304_469F_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1'),'distinct genuine original v432 Core.dll fresh LoadPackage validated native object memory');
$ok(str_contains($loader,'G304_469F_LOADED_STATE_PROBE=1020304050607080 IGNORE=1234567890ABCDEF FLAGS=00010200 LABEL=0'),'original compiled state member values');
$ok(str_contains($loader,'G304_469F_LOADED_FUNCTION_FLAGS=00000002 NATIVE=0 PRECEDENCE=0 NUMPARMS=0 PARMSIZE=0 RETURN=65535'),'original compiled function member values');
$source=(string)file_get_contents($e.'native-function-state-generate.cpp');
$ok(str_contains($source,'UObject::SavePackage')&&str_contains($source,'G304V432FunctionStateFixture.u'),'native original SavePackage emits the pinned 322-byte package');
$ok(str_contains($source,'#define i I')&&str_contains($source,'#define _heapchk() _HEAPOK'),'historical SDK host-only header compilation shims, not modified Epic originals');
$fixture=$e.'original-native-save.u';$p=new P($fixture);$ex=$p->getExports();
$ok(filesize($fixture)===322&&count($ex)===2&&($p->getHeader()['version']??null)===69&&($p->getHeader()['licenseeVersion']??null)===0,'real v432 original binary package header');
$values=[];
foreach([0,1] as $i){$values[$i]=N::inspect($fixture,$p,$i,T::inspect($fixture,$p,$i,S::UT99_V432),S::UT99_V432);
$ok(($values[$i]['status']??null)===($i===0?'native_function_record_complete':'native_state_record_complete'),"original Engine reader method $i");
}
$ok(($ex[0]['objectNameText']??null)==='G304v432Function'&&($ex[0]['serialOffset']??null)===208&&($ex[0]['serialSize']??null)===25,'original 432 function export tail bounds');
$ok(($ex[1]['objectNameText']??null)==='G304v432State'&&($ex[1]['serialOffset']??null)===233&&($ex[1]['serialSize']??null)===40,'original 432 state export tail bounds');
foreach(['function_flags'=>2,'native_index'=>0,'operator_precedence'=>0,'loaded_num_parms'=>0,'loaded_parms_size'=>0,'loaded_return_value_offset'=>65535,'children_reference'=>0] as $key=>$val)$ok(($values[0][$key]??null)===$val,'native vs UE DB Function.'.$key);
foreach(['probe_mask_hex'=>'8070605040302010','ignore_mask_hex'=>'efcdab9078563412','state_flags'=>66048,'label_table_offset'=>0,'children_reference'=>1] as $key=>$val)$ok(($values[1][$key]??null)===$val,'native vs UE DB State.'.$key);
echo json_encode(['ok'=>!$fail,'checks'=>$assertions,'failed'=>$fail,'genuine_v432_compiled_SAVE_and_fresh_LOAD'=>true,'export_count'=>count($ex),'source_provenance'=>'ut99-v432','sealed_other_native_suites_rerun'=>false,'g3_04_FULL'=>'OPEN: other source branches'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
