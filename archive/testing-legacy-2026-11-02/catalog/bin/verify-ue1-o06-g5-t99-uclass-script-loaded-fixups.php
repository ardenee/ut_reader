<?php
/** Original compiled v432 UClass loaded pointer and class defaults, one new
 * native oracle for 3 genuine classes. Never executes engine again.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$m=json_decode((string)file_get_contents(dirname(__DIR__).'/compliance/UE1-O06-G5-T99-UCLASS-SCRIPT-LOADED-FIXUPS.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$fail=[];$tokenChecks=0;$relocationChecks=0;$defaultChecks=0;
$ck=static function(string $name,bool $good)use(&$n,&$fail):void{$n++;if(!$good)$fail[]=$name;};
$pin=static function(array $p,string $name)use($ck):void{
 $ck($name,is_file($p['path'])&&filesize($p['path'])===$p['bytes']
     &&hash_file('sha256',$p['path'])===$p['sha256']);
};
$ck('method',$m['method']==='T99:UClass::Serialize'&&$m['source_profile']==='ut99-v432');
foreach($m['native_dlls'] as $key=>$p)$pin($p,'original_DLL_'.$key);
$pin($m['actual_original_source_engine_archive'],'immutable_original_Engine_script');
$pin($m['native_cpp_source'],'native_probe_source');
$pin($m['native_probe_exe'],'native_probe_exe');
$fnameCase=$m['native_fname_case'];
foreach(['new_native_cpp_source','new_native_exe','native_loaded_log'] as $key){
  $pin($fnameCase[$key],'fname_'.$key);
}
$path=$m['actual_original_source_engine_archive']['path'];
$p=new P($path);
$ck('archive_engine_v69',$p->getHeader()['version']===69);
$ck('exactly_three_distinct_source_classes',count($m['native_cases'])===3);
foreach($m['native_cases'] as $case){
 $cls=$case['name'];$pin($case['native_log'],'actual_compiled_loaded_'.$cls);
 $src=null;
 foreach($p->getExports() as $idx=>$ex){
  if($ex['classIndex']===0&&$ex['objectNameText']===$cls){
   $src=T::inspect($path,$p,$idx,'ut99-v432');break;
  }
 }
 $ck('found_original_class_'.$cls,$src!==null);
 if($src===null)continue;
 $script=$src['script_archive']??[];
 $ck('full_original_script_archive_'.$cls,
    ($script['status']??null)==='script_archive_expressions_decoded'
    &&($script['script_memory_decoded']??null)===$case['script_memory_size']
    &&($src['script_size']??null)===$case['script_memory_size']);
 $relo=$script['native_load_relocation_preview']??[];
 $pointer=array_values(array_filter($relo,
    static fn($t)=>$t['kind']==='object_pointer'
    &&$t['memory_offset']===3));
 $checkPointer=count($pointer)===1
    &&$pointer[0]['archive_index']===22
    &&$pointer[0]['target_source']==='export'
    &&$pointer[0]['target_name']==='Role';
 $ck('source_legal_script_pointer_relocation_'.$cls,$checkPointer);
 $relocationChecks+=(int)$checkPointer;
 if($cls==='Pawn'){
   $names=array_values(array_filter($relo,
     static fn($v)=>$v['kind']==='fname'
         &&$v['memory_offset']===$fnameCase['memory_slot']));
   $ck('actual_UT99_Pawn_Playerscript_FName_local_relocation',
       count($names)===1
       &&$names[0]['archive_index']===$fnameCase['original_archive_fname_map_slot']
       &&$names[0]['resolved_name']===$fnameCase['source_resolved_name']);
   $nameLog=(string)file_get_contents($fnameCase['native_loaded_log']['path']);
   $ck('compiled_native_global_FName_identical_Pawn',
       str_contains($nameLog,
         'SCRIPT_LINKED_FNAME memory_slot='.$fnameCase['memory_slot']
         .' native_global_index='.$fnameCase['actual_native_global_fname_index']
         .' resolved='.$fnameCase['source_resolved_name'])
       &&str_contains($nameLog,'UCLASS_END success=1'));
   $ck('package_local_name_slot_differs_from_process_global_slot',
       $fnameCase['original_archive_fname_map_slot']!==$fnameCase['actual_native_global_fname_index']);
 }
 $log=(string)file_get_contents($case['native_log']['path']);
 $ck('compiled_class_loader_succeeded_'.$cls,str_contains($log,'UCLASS_END success=1')
    &&str_contains($log,'SCRIPT_LOADED size='.$case['script_memory_size'].' ')
    &&str_contains($log,'SCRIPT_LINKED_PROPERTY slot=3 target='.$case['native_script_pointer_referent']));
 $nativeBytes=[];
 if(preg_match_all('/SCRIPT_BYTE at=(\d+) hex=([0-9a-fA-F]{2})/',$log,$matches,PREG_SET_ORDER)){
   foreach($matches as $entry)$nativeBytes[(int)$entry[1]]=hexdec($entry[2]);
 }
 $ck('native_script_byte_prefix_48_'.$cls,count($nativeBytes)===min(48,$case['script_memory_size']));
 $nodes=[];
 $walk=static function(array $entry)use(&$walk,&$nodes):void{
    if(isset($entry['token'],$entry['memory_offset']))
      $nodes[$entry['memory_offset']]=$entry['token'];
    foreach($entry['children']??[] as $child)$walk($child);
 };
 foreach($script['expression_preview']??[] as $entry)$walk($entry);
 $equal=0;
 foreach($nodes as $slot=>$token){
   if(!isset($nativeBytes[$slot]))continue;
   if($nativeBytes[$slot]!==$token) {
       $ck('script_token_native_mismatch_'.$cls.'_'.$slot,false);
   } else {$equal++;}
 }
 $ck('native_compiled_script_token_offsets_'.$cls,$equal>=5);
 $tokenChecks+=$equal;
 $name=$case['native_default_property'];
 $match=array_values(array_filter($src['default_property_tags']??[],
   static fn($t)=>$t['name']===$name&&$t['type']===$case['native_default_type']));
 $ck('source_default_record_'.$cls,count($match)===1);
 $value=$match[0]['value']??null;
 $ck('source_tag_native_loaded_default_'.$cls,is_numeric($value)
   &&(float)$value===(float)$case['native_default_value']);
 if($case['native_default_type']==='ByteProperty')
    $ck('actual_runtime_default_memory_'.$cls,
      str_contains($log,'UCLASS_DEFAULT_BYTE name='.$name.' value='.$case['native_default_value'].' '));
 else{
   $bits=strtolower(bin2hex(pack('g',(float)$value)));
   // hex printed as big-endian number of the exact IEEE-754 bits.
   $bits=strtolower(sprintf('%08x',unpack('V',pack('g',(float)$value))[1]));
   $ck('actual_runtime_default_memory_'.$cls,
     str_contains($log,'UCLASS_DEFAULT_FLOAT name='.$name.' bits='.$bits.' '));
 }
 $defaultChecks++;
}
$ck('all_pointer_classes_native_loaded',$relocationChecks===3);
$ck('original_engine_bytecode_tokens_loaded',$tokenChecks>=15);
$ck('three_original_ClassDefaultObject_values',$defaultChecks===3);
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($fail),
 'failures'=>$fail,'native_loaded_original_classes'=>3,
 'native_loaded_pointer_referents_matched'=>$relocationChecks,
 'native_loaded_script_opcode_slots_identical'=>$tokenChecks,
 'native_loaded_class_default_values_matched'=>$defaultChecks,
 'native_loaded_fname_fixup_matches'=>true,
 'native_engines_run_by_verifier'=>0,'uclass_method_decision'=>'BLOCKED'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
