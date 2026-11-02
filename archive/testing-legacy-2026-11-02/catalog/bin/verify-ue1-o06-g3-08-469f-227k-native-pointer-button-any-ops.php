<?php
/** Independent G3-08 compiled original 469f vs 227k pointer/button/any
 * runtime method DIFFERENTIAL. Do NOT rerun prior UString66 or Button38.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$repo=dirname(__DIR__,2);$root=$repo.'/catalog/compliance';
$m=json_decode((string)file_get_contents($root.'/UE1-O06-G3-08-469F-VS-227K-POINTER-BUTTON-ANY-RUNTIME-SEMANTICS.json'),true,512,JSON_THROW_ON_ERROR);
$status=json_decode((string)file_get_contents($root.'/UE1-O06-G3-LIVE-CLOSURE-STATUS.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$bad=[];
$check=static function(string $label,bool $yes)use(&$n,&$bad):void{$n++;if(!$yes)$bad[]=$label;};
$check('G3-08 stays OPEN until UAny dynamic value and button native real loaded',
 $m['G3_08_FULL_ACCEPTED']===false&&$status['full_contracts_accepted']===5
 &&current(array_values(array_filter($status['contracts'],static fn($x)=>$x['id']==='G3-08')))['full_status']==='OPEN');
foreach($m['original_matched_compiled_true_native_PE_exports_and_first24bytes'] as $name=>$dll){
 $path=$dll['original_dll'];
 $check('matching ORIGINAL compiled '.$name.' full DLL SHA256',
   is_file($path)&&filesize($path)===$dll['bytes']
   &&hash_file('sha256',$path)===$dll['sha256']);
 $resource=fopen($path,'rb');if(!$resource)throw new RuntimeException('Cannot read original native DLL');
 try{
  foreach($dll['native_exported_methods'] as $method=>$meta){
   fseek($resource,$meta['PE_file_offset']);
   $bytes=fread($resource,24);
   $check('real original shipped '.$name.' PE machine method bytes '.$method,
     is_string($bytes)&&strlen($bytes)===24
     &&bin2hex($bytes)===$meta['first_24_compiled_bytes_hex']);
  }
 }finally{fclose($resource);}
}
$t=$m['original_matched_compiled_true_native_PE_exports_and_first24bytes']['T99-469f']['native_exported_methods'];
$c=$m['original_matched_compiled_true_native_PE_exports_and_first24bytes']['C227k']['native_exported_methods'];
$check('T99-469f Pointer SerializeItem invokes archive Serialize(ptr,4)',
 str_starts_with($t['UPointerProperty::SerializeItem']['first_24_compiled_bytes_hex'],
 '558bec8b4d086a04ff750c8b01ff50045dc20800'));
$check('T99-469f Pointer NET archives four bytes and returns one',
 str_starts_with($t['UPointerProperty::NetSerializeItem']['first_24_compiled_bytes_hex'],
 '558bec8b4d086a04ff75108b01ff5004b801000000'));
$check('Original C227k Pointer and Button item exact same native method ADDRESS',
 $c['UPointerProperty::SerializeItem']['PE_RVA']===$c['UButtonProperty::SerializeItem']['PE_RVA']
 &&$c['UPointerProperty::SerializeItem']['PE_RVA']==='0x1ec40');
$check('Original C227k Pointer/Button method is literally no-op ret8',
 str_starts_with($c['UPointerProperty::SerializeItem']['first_24_compiled_bytes_hex'],'c20800'));
$check('Original C227k Pointer and Button NET same native method ADDRESS',
 $c['UPointerProperty::NetSerializeItem']['PE_RVA']===$c['UButtonProperty::NetSerializeItem']['PE_RVA']
 &&$c['UPointerProperty::NetSerializeItem']['PE_RVA']==='0x7ea00');
$check('Original C227k Pointer/Button NET returns true without archive write',
 str_starts_with($c['UButtonProperty::NetSerializeItem']['first_24_compiled_bytes_hex'],'b801000000c20c00'));
$check('Do not confuse actual C227k Any dynamic SerializeItem with pointer no-op',
 $c['UAnyProperty::SerializeItem']['PE_RVA']==='0x80b00'
 &&str_starts_with($c['UAnyProperty::SerializeItem']['first_24_compiled_bytes_hex'],'558bec6aff'));
$check('Actual AnyProperty NetSerializeItem is distinct and complex',
 $c['UAnyProperty::NetSerializeItem']['PE_RVA']==='0x7e800'
 &&$c['UAnyProperty::NetSerializeItem']['PE_RVA']!==$c['UButtonProperty::NetSerializeItem']['PE_RVA']);
foreach($m['original_real_CDB_compiled_native_instruction_logs'] as $name=>$pin){
 $path=$repo.'/'.$pin['repo_path'];
 $check('original independently compiled WinDBG transcript or debugger commands SHA '.$name,
   is_file($path)&&filesize($path)===$pin['bytes']
   &&hash_file('sha256',$path)===$pin['sha256']);
}
$reader=$repo.'/'.$m['newly_updated_Reader'];
$check('current actual production reader source since this new decision SHA',
 hash_file('sha256',$reader)===$m['newly_updated_reader_SHA256']);
$compact=static function(int $v):string {
 $neg=$v<0;$v=abs($v);$b=($v&63)|($neg?128:0);$v>>=6;
 if(!$v)return chr($b);$raw=chr($b|64);
 do{$b=$v&127;$v>>=7;$raw.=chr($b|($v?128:0));}while($v);
 return $raw;
};
$fixture=static function(string $kind,int $licensee,string $tail)use($compact):string {
 $names=['None',$kind,'Sample','Core','Class','Gameplay'];$table='';
 foreach($names as $name){$table.=$compact(strlen($name)+1).$name."\0".pack('V',0);}
 $imp=$compact(3).$compact(4).pack('V',0).$compact(1);
 $body="\0".$compact(0).$compact(0).pack('V2',1,0x20).$compact(5).pack('v',7).$tail;
 $off=56;$imOff=$off+strlen($table);$exOff=$imOff+strlen($imp);
 $exp=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
  .$compact(strlen($body)).$compact(512);
 $header=pack('V9',0x9e2a83c1,69,$licensee,count($names),$off,1,$exOff,1,$imOff)
   .str_repeat("\0",16).pack('V',0);
 $prefix=$header.$table.$imp.$exp;
 if(strlen($prefix)>=512)throw new RuntimeException('Fixture header too long');
 return $prefix.str_repeat("\0",512-strlen($prefix)).$body;
};
$source=[
 ['pointer-469f','PointerProperty',0,'',S::UT99_V469F,4,4,1,'ut99-469f-x86'],
 ['pointer-227k','PointerProperty',227,'',S::UNREAL_V227,0,0,1,'unreal-227k-x86'],
 ['button-227k','ButtonProperty',227,"\0",S::UNREAL_V227,0,0,1,'unreal-227k-x86'],
 ['any-227k','AnyProperty',227,'',S::UNREAL_V227,null,null,null,null],
];
$tmp=tempnam(sys_get_temp_dir(),'G308bin');
if(!is_string($tmp))throw new RuntimeException('Temp unavailable');
try{
 foreach($source as [$label,$kind,$licensee,$tail,$family,$loadBytes,$netBytes,$success,$profile]){
  file_put_contents($tmp,$fixture($kind,$licensee,$tail));
  $pkg=new P($tmp);
  $check($label.' valid source version 69 package summary',$pkg->validatePackage()===[]);
  $record=N::inspect($tmp,$pkg,0,T::inspect($tmp,$pkg,0,$family),$family);
  $ops=$record['compiled_scalar_runtime_method_semantics'];
  $check($label.' selected binary source runtime operator byte count',
    $ops===null?$loadBytes===null:
      $ops['serializeitem_archive_bytes']===$loadBytes
      &&$ops['netserializeitem_archive_bytes']===$netBytes
      &&$ops['netserializeitem_returns']===$success
      &&$ops['matched_original_compiled_binary_profile']===$profile);
  $check($label.' compiled source audited but UnrealDB instance codec not invented',
    $record['unrealdb_runtime_instance_method_implemented']===false
    &&$record['new_v227_property_runtime_serializeitem_verified']===false
    &&$record['compiled_scalar_runtime_methods_disassembled']===($profile!==null));
  $check($label.' original declaration still fully consumed (not runtime field injected)',
    $record['extension_bytes']===0
    &&in_array($record['status'],['native_property_record_complete',
      'native_buttonproperty_caption_record_complete'],true));
  $check($label.' dynamic-any codec explicitly NOT yet certified',
    $kind==='AnyProperty'?
      ($record['unreal_v227_anyproperty_dynamic_instance_codec_unverified']===true
       &&$ops===null):
      ($record['unreal_v227_anyproperty_dynamic_instance_codec_unverified']===false));
 }
 file_put_contents($tmp,$fixture('PointerProperty',0,''));
 $p=new P($tmp);$wrong=false;
 try{N::inspect($tmp,$p,0,T::inspect($tmp,$p,0,S::UT99_V432),S::UT99_V432);}
 catch(RuntimeException){$wrong=true;}
 $check('same archived v69/0 cannot falsely imply explicit 469f Pointer source', $wrong);
}finally{@unlink($tmp);}
$check('old original 227k button caption class serializer evidence used as reference only',
 $m['existing_native_Button_2_caption_original_loaded_values_not_rerun']===true
 &&$m['no_native_in_memory_Button_Caption_claimed']===true
 &&$m['G3_08_FULL_ACCEPTED']===false);
$ok=$bad===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($bad),
 'failed'=>$bad,'G3_08_FULL_ACCEPTED'=>false,
 'new_original_compiled_source_specific_runtime_operator_methods'=>8,
 'genuine_469f_pointer_payload_bytes'=>4,
 'genuine_C227k_pointer_button_payload_bytes'=>0,
 'genuine_C227k_Any_dynamic_codec_unsupported'=>true,
 'prior_native_positive_acceptance_tests_reexecuted'=>0,
 'full_G3_contracts_still_accepted'=>5],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
