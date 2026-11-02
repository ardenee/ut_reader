#!/usr/bin/env php
<?php
/** New genuine 2012 shipped Unreal 227i UCC.exe native UnrealScript SAVE.
 * The actual original UCC make commandlet generated G3042012Probe.u with
 * a UState and two non-native UFunction objects; an INDEPENDENT original
 * UCC process reloaded it and Microsoft CDB captured both linked function
 * parameter layouts directly from the actual compiled 2012 Core.dll.
 *
 * This does not claim an original C98 SAVE or general malformed acceptance.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$e=dirname(__DIR__).'/compliance/evidence/o06/20261028-G3-04-C227I-';
$s='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/';
$hashes=[
 $e.'original-compiled-2012-SAVE.u'=>'998c7e46d2a642e7eec3806a98089a847f416d778deed21414b504683ff81d47',
 $e.'original-script-input.uc'=>'c53ca731d9cc54b129896fd7e04f7b4461cdfcc68134e5005074bf2d8853f6e9',
 $e.'compiled-method-postload.cmd'=>'ac42677291f417d46838c6ced0408abc878efc5e347e8ed80faf758100df55c5',
 $e.'compiled-method-postload.log'=>'b90727c08ebca501265c81dcd39f24c0553e82df09dd701fbbf62a755a5f3f6c',
 $e.'real-compiled-2012-fresh-load.log'=>'fc6426b0e18b1273e455821998410a72a6b3656ad000802661c1be904a9691d3',
 $s.'Core.dll'=>'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897',
 $s.'Engine.dll'=>'96c53e7a109fefb2166955e3f83f92a0ad87250a191758782199f84f56d0b929',
 $s.'Editor.dll'=>'5bd6981fa90c9384150749e8e434bb52e43695e458cecba0d1ccdafdc5bca7a1',
 $s.'UCC.exe'=>'69813336c9fd278f30939abde10477a37c5da951b2be8ca0c394b5bfd5cd6672',
 $s.'Core.u'=>'bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b',
 $s.'Engine.u'=>'931ffb4ddf3b9b608cbfd49bc6d5f86ddbcbe9e9148ac8c7769d6ac3b13d4ac7',
 $s.'Unreal.ini'=>'d6a022b779318ff59818dfaa60fb84b1f4dc078c9e4f504e7074d76f51c40962',
];
$checks=0;$errors=[];
$ok=static function(bool $valid,string $name)use(&$checks,&$errors):void{++$checks;if(!$valid)$errors[]=$name;};
foreach($hashes as $file=>$hash)$ok(is_file($file)&&hash_file('sha256',$file)===$hash,'original binary or immutable evidence SHA: '.basename($file));
$source=(string)file_get_contents($e.'original-script-input.uc');
$ok(str_contains($source,'function int G304AddOne(int X)')&&str_contains($source,'state G304NativeState')
 &&str_contains($source,'function bool G304CanJump()'),'new compiled 2012 script defines distinct Function/State/Function');
$cmd=(string)file_get_contents($e.'compiled-method-postload.cmd');
$native=(string)file_get_contents($e.'compiled-method-postload.log');
$ucc=(string)file_get_contents($e.'real-compiled-2012-fresh-load.log');
$ok(str_contains($cmd,'Core!UFunction::Serialize+0x110'),'native 2012 actual Core.dll UFunction post-load breakpoint');
$ok(str_contains($ucc,'Loading: Package G3042012Probe'),'fresh ORIGINAL 2012 UCC.exe actually loaded compiled test package');
$ok(str_contains($native,'G304_2012_NEW_METHOD_NATIVE_LOAD_DONE'),'new 2012 debugger completed actual binary method load');
$pattern='/^G304_2012_NEW_FUNCTION_LOADED linker=([0-9a-f]{8}) index=(\d+) object=([0-9a-f]{8})\s+[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})/mi';
preg_match_all($pattern,$native,$matches,PREG_SET_ORDER);
$ok(count($matches)===2,'exactly two original 2012 newly compiled UFunction LOAD events');
$expected=[
 3=>['name'=>'G304AddOne','fnflags'=>2,'native_index'=>0,'num_parms'=>2,'parms_size'=>8,'return_offset'=>4],
 7=>['name'=>'G304CanJump','fnflags'=>2,'native_index'=>0,'num_parms'=>1,'parms_size'=>4,'return_offset'=>0],
];
$seen=[];$nativeFields=0;
$p=new P($e.'original-compiled-2012-SAVE.u');$exports=$p->getExports();
$ok(filesize($e.'original-compiled-2012-SAVE.u')===1110 && count($exports)===9
 &&($p->getHeader()['version']??null)===69&&($p->getHeader()['licenseeVersion']??null)===0,
 'original 2012 UCC.exe make writer emitted actual v69/0 1110-byte 9-export package');
$ok(($exports[4]['objectNameText']??null)==='G3042012Actor'&&($exports[6]['objectNameText']??null)==='G304NativeState',
 'genuine 2012 compiler emitted UClass and real UState export hierarchy');
foreach($matches as $event){
 $idx=(int)$event[2];$old=$expected[$idx]??null;
 $ok($old!==null&&!isset($seen[$idx]),'new distinct original CDB function export index '.$idx);
 if($old===null||isset($seen[$idx]))continue;
 $seen[$idx]=$event[1];
 $w=array_map(static fn($i)=>hexdec($event[$i]),[4,5,6,7]);
 $nf=['fnflags'=>$w[0],'native_index'=>$w[1]&0xffff,
   'num_parms'=>($w[2]>>8)&255,'parms_size'=>($w[2]>>16)&65535,
   'return_offset'=>$w[3]&65535];
 $value=N::inspect($e.'original-compiled-2012-SAVE.u',$p,$idx,
    T::inspect($e.'original-compiled-2012-SAVE.u',$p,$idx,S::UNREAL_V227),S::UNREAL_V227);
 $ok(($exports[$idx]['objectNameText']??null)===$old['name']
    &&($value['status']??null)==='native_function_record_complete'
    &&($value['source_function_link_status']??null)==='source_property_layout_computed',
    'genuine original 2012 SAVE function identity and source-linked parameters '.$idx);
 $map=['fnflags'=>'function_flags','native_index'=>'native_index','num_parms'=>'loaded_num_parms',
    'parms_size'=>'loaded_parms_size','return_offset'=>'loaded_return_value_offset'];
 foreach($map as $name=>$reader){
    ++$nativeFields;
    $ok($nf[$name]===$old[$name]&&($value[$reader]??null)===$old[$name],
      'original 2012 x86 post-LOAD matches reader and source '.$old['name'].' '.$name);
 }
}
$ok(count($seen)===2&&count(array_unique(array_values($seen)))===1,
 'genuinely loaded new generated file functions share one actual native linker');
$state=N::inspect($e.'original-compiled-2012-SAVE.u',$p,6,
  T::inspect($e.'original-compiled-2012-SAVE.u',$p,6,S::UNREAL_V227),S::UNREAL_V227);
foreach(['status'=>'native_state_record_complete','state_flags'=>0,'probe_mask_hex'=>'0000000000000000',
 'ignore_mask_hex'=>'ffffffffffffffff','label_table_offset'=>12,'children_reference'=>8] as $name=>$value)
   $ok(($state[$name]??null)===$value,'true 2012 compiler saved new state '.$name);
$ok(($exports[3]['serialOffset']??null)===673&&($exports[3]['serialSize']??null)===33
 &&($exports[6]['serialOffset']??null)===809&&($exports[6]['serialSize']??null)===62
 &&($exports[7]['serialOffset']??null)===871&&($exports[7]['serialSize']??null)===29,
 'real original 2012 writer function/state/function exact physical export positions');
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'failures'=>$errors,
 'new_original_2012_native_SAVED_package_bytes'=>1110,'new_2012_compiled_state_exports'=>1,
 'new_2012_native_compiled_function_POSTLOAD_events'=>count($matches),
 'new_2012_x86_native_field_comparisons'=>$nativeFields,
 'existing_sealed_native_runs_reexecuted'=>false,
 'C98_1998_original_SAVE_covered'=>false,'g3_04_FULL'=>'OPEN'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
