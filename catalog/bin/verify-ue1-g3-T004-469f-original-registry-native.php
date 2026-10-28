#!/usr/bin/env php
<?php
/** T004 one genuine compiled original 469f Core.dll LOAD for previously
 * unobserved RegistryPropertyInfo/RegistryObjectInfo/PreferencesInfo.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$e=$root.'/catalog/compliance/evidence/o06/';
$files=[
 'C:/Temp/UnrealDB/O06/Native-469f/System/Core.dll'=>'80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2',
 'C:/Temp/UnrealDB/O06/Native-469f/System/Core.u'=>'7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0',
 'C:/Temp/UnrealDB/O06/Native-469f/System/G3T004OriginalRegistry.exe'=>'aed5e6638406bc7a2f6ce531aec34b25f597236c2e5a2cfb519af189abfbe478',
 $e.'20261028-G3-T004-469f-original-native-registry.cpp'=>'d28bcd92666e693fe8047cc68b9c09c51dc3e0eeff07d31876a8448262bd3364',
 $e.'20261028-G3-T004-469f-original-native-registry.log'=>'90728ef4696f4038aa5c1bea72bb6fb9d68bbeba790153063103216fd9d43ee8',
 $e.'20261028-G3-T004-469f-original-native-registry-build.log'=>'693f89d960ad05b59d075664da7fbaf475c096eb56ac1f50446e864e0f9daa4a'
];
$checks=0;$fail=[];
$ok=static function(bool $valid,string $what)use(&$checks,&$fail):void{++$checks;if(!$valid)$fail[]=$what;};
foreach($files as $path=>$expected)$ok(is_file($path)&&hash_file('sha256',$path)===$expected,'source/runtime SHA '.basename($path));
$source=(string)file_get_contents($e.'20261028-G3-T004-469f-original-native-registry.cpp');
$log=str_replace(chr(13),'',(string)file_get_contents($e.'20261028-G3-T004-469f-original-native-registry.log'));
$ok(str_contains($source,'UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail)')
 &&str_contains($source,'StaticFindObject(UStruct::StaticClass()')
 &&str_contains($source,'GetLinkerIndex()+1'),
 'Original compiled native loader actual v469f serializable structs and linker identity');
$ok(str_contains($log,'G3_T004_469F_LOADED_COUNT=3'), 'All three genuine original compiled 469f native struct objects really loaded');
$path='C:/Temp/UnrealDB/O06/Native-469f/System/Core.u';
$p=new P($path);$exports=$p->getExports();
$ok(($p->getHeader()['version']??null)===69 && count($exports)>770,
 'Genuine 469f source Core.u original header and export table');
$cases=[
 ['RegistryPropertyInfo',727,743,735],
 ['RegistryObjectInfo',728,759,0],
 ['PreferencesInfo',734,737,729],
];
$matched=[];
foreach($cases as $i=>[$name,$idx,$child,$next]){
 $pattern='/^G3_T004_469F_NATIVE\['.$i.'\] export=(\d+) script=(\d+) line=(\d+) textpos=(\d+) name=(\w+) child_ref=(\d+) next_ref=(\d+) super_ref=(\d+) scripttext_null=(\d+) child_same_linker=(\d+)$/m';
 $m=[];
 $valid=preg_match($pattern,$log,$m)===1;
 $ok($valid,'Original native v469f loaded object '.$name);
 if(!$valid)continue;
 $ok((int)$m[1]===$idx&&(int)$m[2]===0&&(int)$m[3]===0&&(int)$m[4]===0,
  'Actual native source export index Script.Num and source positions '.$name);
 $ok($m[5]===$name&&(int)$m[6]===$child&&(int)$m[7]===$next,
  'Actual native object name Children/Next linker references '.$name);
 $ok((int)$m[8]===0&&(int)$m[9]===1&&(int)$m[10]===1,
  'Actual native superclass/ScriptText null and Children same original linker '.$name);
 $actual=N::inspect($path,$p,$idx,T::inspect($path,$p,$idx,'ut99-v469f'),'ut99-v469f');
 $ok(($actual['status']??null)==='native_struct_record_complete'
   &&($actual['friendly_name']['text']??null)===$name
   &&($exports[$idx]['objectNameText']??null)===$name,
  'Current UnrealDB direct export and complete physical archive UStruct '.$name);
 foreach(['children_reference'=>$child,'next_field_reference'=>$next,
  'super_field_reference'=>0,'script_text_reference'=>0,'script_size'=>0,
  'source_line'=>0,'source_text_pos'=>0] as $field=>$value)
   $ok(($actual[$field]??null)===$value,'UnrealDB equals original compiled native '.$name.' '.$field);
 $matched[$name]=['native_export'=>$idx,'native_child_ref'=>$child,'native_next_ref'=>$next,'script_size'=>0];
}
$ok(count($matched)===3,'All three previously unobserved 469f native structs source-verified');
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failed'=>$fail,
 'T004'=>'Original compiled genuine 469f registry direct UStruct loaded field/member/linked target parity',
 'source_selected_provenance'=>'ut99-v469f','native_loaded'=>$matched,
 'earlier_sealed_469f_direct_struct_tests_rerun'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
