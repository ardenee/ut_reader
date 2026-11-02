#!/usr/bin/env php
<?php
/** Original compiled Unreal 227k Core.dll direct UField/UStruct actual
 * loaded fields + linked child/next/super referents and inherited nonzero script.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$e=$root.'/catalog/compliance/evidence/o06/';
$files=[
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'=>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.u'=>'28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c',
 'C:/Temp/UnrealDB/O06/Native-227k/System/G3T005OriginalStructs.exe'=>'de6287614a556893dfd6b06ee8768c53b3c363b369a1ad39330586c214c36472',
 'L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Lib/x86/Core.lib'=>'b46f9cab43814946c7e4ac224fdf2dfc575498dad1a4557548639bb9ff12321f',
 $e.'20261028-G3-T005-T006-227k-native-direct-structs.cpp'=>'9263998f6c69a5eb0955e83545831b84f3aeb034afc13d1039cfae06bf789a5a',
 $e.'20261028-G3-T005-T006-227k-native-direct-structs.log'=>'29afbd35450e4a969d28236a4971f75e596390d79ec8dd0c3d07abd81f18dbf1',
 $e.'20261028-G3-T005-T006-227k-native-build.log'=>'bfc038501ca57da99bff2fd33adc073a2c8cfa6f3b249f5db7381b1f5ee56ce6',
];
$checks=0;$fail=[];$ok=static function(bool $yes,string $reason)use(&$checks,&$fail):void{
 ++$checks;if(!$yes)$fail[]=$reason;};
foreach($files as $f=>$digest)$ok(is_file($f)&&hash_file('sha256',$f)===$digest,'genuine source, compiled native, host or log identity '.basename($f));
$log=str_replace(chr(13),'',(string)file_get_contents($e.'20261028-G3-T005-T006-227k-native-direct-structs.log'));
$source=(string)file_get_contents($e.'20261028-G3-T005-T006-227k-native-direct-structs.cpp');
$ok(str_contains($source,'UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail)')
 &&str_contains($source,'GetLinkerIndex()+1')
 &&str_contains($source,'s->ScriptText==NULL'),
 'Genuine original compiled Core.u LoadPackage, real native linker deref and ScriptText');
$ok(str_contains($log,'G3_T005_227K_LOADED_COUNT=6'),
 'Original compiled Core.dll loaded all 6 source-eligible direct UStructs');
$path='C:/Temp/UnrealDB/O06/Native-227k/System/Core.u';$pkg=new P($path);
$exports=$pkg->getExports();$header=$pkg->getHeader();
$ok(($header['version']??null)===69&&($header['licensee']??null)===227&&count($exports)===1142,
 'Correct original Unreal v227k 69/227 provenance and 1142 actual exports');
$cases=[
 ['Vector',0,584,313,0],
 ['Rotator',1,577,33,0],
 ['Color',547,547,559,0],
 ['Plane',32,309,935,1],
 ['Coords',571,569,1074,0],
 ['Guid',312,590,662,0],
];
$matched=[];
foreach($cases as $i=>[$name,$idx,$child,$next,$super]){
 $p='/^G3_T005_227K_NATIVE\['.$i.'\] export=(\d+) script=(\d+) line=(\d+) textpos=(\d+) name=(\w+) child_ref=(\d+) next_ref=(\d+) super_ref=(\d+) scripttext_null=(\d+) child_same_linker=(\d+)$/m';
 $m=[];$hit=preg_match($p,$log,$m)===1;
 $ok($hit,'Original 2026 227k compiled method was reached for '.$name);if(!$hit)continue;
 $ok((int)$m[1]===$idx&&(int)$m[2]===0&&(int)$m[3]===0&&(int)$m[4]===0,
   'Original real UStruct identity, Script.Num and positions '.$name);
 $ok($m[5]===$name&&(int)$m[6]===$child&&(int)$m[7]===$next&&(int)$m[8]===$super,
   'Real original 227k linked Children/Next/SuperField serialized target indices '.$name);
 $ok((int)$m[9]===1&&(int)$m[10]===1,
   'Original native actual ScriptText null and real same-linker resolved child '.$name);
 $rec=N::inspect($path,$pkg,$idx,T::inspect($path,$pkg,$idx,'unreal-v227k'),'unreal-v227k');
 $ok(($rec['status']??null)==='native_struct_record_complete'
  &&($rec['friendly_name']['text']??null)===$name
  &&($exports[$idx]['objectNameText']??null)===$name,
   'UnrealDB exact original direct 227k native archive consume and text '.$name);
 foreach(['children_reference'=>$child,'next_field_reference'=>$next,
          'super_field_reference'=>$super,'script_text_reference'=>0,
          'script_size'=>0,'source_line'=>0,'source_text_pos'=>0] as $field=>$value)
   $ok(($rec[$field]??null)===$value,'UnrealDB matches original compiled '.$name.' '.$field);
 $matched[$name]=['export_index'=>$idx,'native_children_ref'=>$child,
                    'native_next_ref'=>$next,'native_super_ref'=>$super,'native_script_length'=>0];
}
$m=[];$hit=preg_match('/^G3_T005_227K_NONZERO_FUNCTION export=(\d+) script=(\d+) present=(\d+)$/m',$log,$m)===1;
$ok($hit,'Original compiled inherited UStruct UFunction loaded nonzero Script');
$nonzero=N::inspect($path,$pkg,292,T::inspect($path,$pkg,292,'unreal-v227k'),'unreal-v227k');
if($hit){
 $ok((int)$m[1]===292&&(int)$m[2]===11&&(int)$m[3]===1,
  'Original compiled genuine Function NotEqual_FloatFloat Script.Num=11');
 $ok(($nonzero['script_size']??null)===11
    &&($nonzero['script_archive']['script_memory_decoded']??null)===11
    &&($nonzero['script_archive']['archive_bytes_consumed']??null)===7,
  'Original inherited UStruct script memory11 vs source archive7, exact typed decoded');
 $ok(($nonzero['status']??null)==='native_function_record_complete',
  'Original 227k source selected function inherited UStruct bytes consumed exactly');
}
$ok(count($matched)===6,'Six loaded native direct UStructs completed actual source match');
echo json_encode(['ok'=>!$fail,'checks'=>$checks,'failed'=>$fail,
 'T005'=>'Original compiled Unreal 227k six direct UStructs and nonzero inherited UStruct script 11/7',
 'T006'=>'Original compiled UField Next/SuperField and UStruct Children/ScriptText target identities',
 'native_loaded'=>$matched,'native_function_nonzero'=>['export'=>292,'memory'=>11,'archive'=>7],
 'already_sealed_2012_C227i_positive_native_rerun'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
