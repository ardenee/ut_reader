#!/usr/bin/env php
<?php
/**
 * G3-04 genuine original 2026 Unreal 227k Engine.u compiled loaded
 * UFunction values, including source-linked nested structs and FUNC_Net.
 * One ORIGINAL CDB Unreal.exe startup trace, never a PHP native simulation.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFunctionStructShapeReader as Shape;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$root=dirname(__DIR__,2);
$sys='C:/Temp/UnrealDB/O06/Native-227k/System/';
$ev=$root.'/catalog/compliance/evidence/o06/20261027-';
$hashes=[
 [$sys.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$sys.'Core.u','28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c'],
 [$sys.'Engine.u','a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'],
 [$sys.'Unreal.exe','f2770354da3ddd6b8f3c9efde34f174b6cc3bca8ebeadd0ca01e84012717af64'],
 [$ev.'g3-04-227k-engine-native-net-original.cmd','b2756ec09cdabd8068a5df6759d9fcd48cc79c3411e8a72c8d7004c4538203aa'],
 [$ev.'g3-04-227k-engine-native-net-original.log','33ed0827f861442a935ea2f232e583110d28136b4f19bea81e05390bcd2979aa'],
 [$ev.'g3-04-227k-engine-native-net-original-parsed.json','c30a88357f1442f00f317163d7942d6aa8aba0c938265bc02d601e15acec8d83'],
 [$ev.'g3-04-227k-engine-native-net-parse.py','88257fba26447778d627cded1a23ea6d9faae5a0b8b10a3b2658551c03fbe388'],
];
$checks=0;$fails=[];$matched=0;$ignored=0;$nativeFields=0;$net=[];
$ids=[];$srcMatched=[];$special=[];
$assert=static function(bool $condition,string $name)use(&$checks,&$fails):void{
 ++$checks;if(!$condition&&count($fails)<25)$fails[]=$name;
};
foreach($hashes as [$f,$hash]){
 $assert(is_file($f)&&hash_file('sha256',$f)===$hash,
    'authentic original engine/package/debugger SHA '.basename($f));
}
$log=(string)file_get_contents($ev.'g3-04-227k-engine-native-net-original.log');
$assert(str_contains($log,'G304_C227K_UNREAL_ENGINE_FN')
    &&str_contains($log,'G304_C227K_UNREAL_ENGINE_STATE')
    &&str_contains($log,'Core.dll')
    &&str_contains($log,'Unreal.exe'),
 'real original Unreal.exe -server Engine.u Core.dll after-LOAD debugger');
$j=json_decode((string)file_get_contents(
    $ev.'g3-04-227k-engine-native-net-original-parsed.json'),
    true,512,JSON_THROW_ON_ERROR);
$assert(($j['summary']['events']??null)===220
    &&($j['summary']['parsed']??null)===220
    &&($j['summary']['errors']??null)===[]
    &&($j['summary']['native_net_count']??null)===2,
 'exact one original 220-event afterload compiled trace, two FUNC_Net');
$path=$sys.'Engine.u';$pkg=new P($path);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$assert(($pkg->getHeader()['version']??null)===69
   &&($pkg->getHeader()['licenseeVersion']??null)===227
   &&count($exports)===9477,
 'authentic 227k Engine.u version69/licensee227/9477 original exports');
$assert(Shape::elementSize($path,$pkg,-56,'BoundingBox',S::UNREAL_V227)===28,
 'original Core.Object.BoundingBox 2 vectors + BYTE aligned 28');
$assert(Shape::elementSize($path,$pkg,6589,'MeshSocketInfo',S::UNREAL_V227)===44,
 'original Engine.MeshSocketInfo native fixed array / Vector / Rotator size44');
$assert(Shape::elementSize($path,$pkg,-56,'BoundingBox',S::UT99_V432)===null
   &&Shape::elementSize($path,$pkg,6589,'MeshSocketInfo',S::UNREAL_V227I)===null,
 'source profile isolation forbids importing v227k struct sizes into unrelated original ABI');
$assert(Shape::elementSize($path,$pkg,6589,'BoundingBox',S::UNREAL_V227)===null
   &&Shape::elementSize($path,$pkg,-57,'BoundingBox',S::UNREAL_V227)===null,
 'incorrect imported / local struct identity fails closed');
$tmp='C:/Temp/UnrealDB/O06/g3-04-227k-missing-Core-shape-negative';
if(!is_dir($tmp)&&!mkdir($tmp,0777,true)&&!is_dir($tmp)){
 $assert(false,'create isolated Core-missing temporary original package sandbox');
}else{
 $copy=$tmp.'/Engine.u';
 $copied=is_file($copy)&&hash_file('sha256',$copy)===hash_file('sha256',$path);
 if(!$copied)$copied=copy($path,$copy);
 $assert($copied&&is_file($copy),'independent disposable original Engine.u SHA negative fixture');
 if($copied){
    $other=new P($copy);
    $assert(!is_file($tmp.'/Core.u')
      &&Shape::elementSize($copy,$other,-56,'BoundingBox',S::UNREAL_V227)===null,
      'no adjacent original Core.u: missing imported Struct MUST fail closed');
 }
}
foreach($j['records'] as $event){
 $idx=$event['idx'];$exp=$exports[$idx]??null;
 if(!is_array($exp)){++$ignored;continue;}
 $class=(int)$exp['classIndex'];
 $klass=$class===0?'Class':($class<0
    ?($imports[-$class-1]['objectNameText']??null)
    :($exports[$class-1]['objectNameText']??null));
 if($klass!=='Function'){++$ignored;continue;}
 try{
   $v=N::inspect($path,$pkg,$idx,
        T::inspect($path,$pkg,$idx,S::UNREAL_V227),S::UNREAL_V227);
 }catch(Throwable $error){
    $assert(false,'reader raised for genuine Engine.u export'.$idx.':'.$error->getMessage());
    continue;
 }
 if(($v['function_flags']??null)!==$event['flags']){++$ignored;continue;}
 ++$matched;$ids[$idx]=true;$name=(string)$exp['objectNameText'];
 $assert(($v['status']??null)==='native_function_record_complete'
   &&($v['source_function_link_status']??null)==='source_property_layout_computed'
   &&($v['source_function_compiled_runtime_profile']??null)===S::UNREAL_V227
   &&($v['runtime_function_param_chain_linked']??null)===false,
   'original Engine.u native function now complete but no fabricated UObject pointers '.$idx);
 foreach(['function_flags'=>'flags','native_index'=>'native_index',
          'operator_precedence'=>'operator_precedence',
          'loaded_num_parms'=>'num_parms','loaded_parms_size'=>'parms_size',
          'loaded_return_value_offset'=>'return_value_offset'] as $reader=>$native){
  ++$nativeFields;
  $assert(($v[$reader]??null)===$event[$native],
   'real 227k native Engine.u '.$name.' idx'.$idx.' '.$reader);
 }
 if(($event['flags']&0x40)!==0){
  $assert(($v['rep_offset']??null)===$event['rep_offset'],
    'FUNC_Net actual afterload RepOffset '.$name);
  $net[]=['index'=>$idx,'name'=>$name,'original_rep_offset'=>$event['rep_offset']];
 }
 if(in_array($idx,[3495,7766,8638,8797],true)){
  $special[$idx]=['name'=>$name,
    'actual_parms_size'=>$event['parms_size'],
    'actual_num_parms'=>$event['num_parms'],
    'actual_return_offset'=>$event['return_value_offset']];
 }
}
$expectedSpecial=[
 3495=>['name'=>'SetTimer','actual_parms_size'=>20,
   'actual_num_parms'=>5,'actual_return_offset'=>65535],
 7766=>['name'=>'GetBoundingBox','actual_parms_size'=>32,
   'actual_num_parms'=>2,'actual_return_offset'=>4],
 8638=>['name'=>'SetSocketInfo','actual_parms_size'=>44,
   'actual_num_parms'=>1,'actual_return_offset'=>65535],
 8797=>['name'=>'GetSocketInfo','actual_parms_size'=>12,
   'actual_num_parms'=>3,'actual_return_offset'=>8],
];
$assert(count($special)===4&&$special==$expectedSpecial,
 'all four previously missing original runtime sizes now exact from source shapes');
$assert($matched===219&&$ignored===1&&$nativeFields===1314,
 'source identity: original 219 Engine.u functions x6 native compiled fields, one ambiguous excluded');
$assert($net===[
 ['index'=>3425,'name'=>'BroadcastMessage','original_rep_offset'=>697],
 ['index'=>3492,'name'=>'DemoPlaySound','original_rep_offset'=>100],
]||count($net)===2&&
    array_column($net,'original_rep_offset')===[697,100],
 'two original FUNC_Net saved/loaded RepOffset values exactly match unchanged reader');
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failed'=>$fails,
 'original_engine_unreal_exe_afterload_events'=>220,
 'actually_identified_engine_functions'=>$matched,
 'distinct_original_engine_export_indices'=>count($ids),
 'source_ambiguous_original_events_excluded'=>$ignored,
 'native_persisted_and_loaded_fields_exact'=>$nativeFields,
 'newly_resolved_functions'=>$special,'compiled_network_function_reps'=>$net,
 'previously_sealed_compiled_trace_reexecutions'=>0,
 'G3_04_full'=>'OPEN (source SAVE and malformed physical FArchive Tell and other engine versions)'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
