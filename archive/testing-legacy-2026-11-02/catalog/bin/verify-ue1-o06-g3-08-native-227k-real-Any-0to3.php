<?php
/** ONE G3-08 new native original 227k FAny/UAny instance LOAD/SAVE 0..3.
 * The original C++ functions were directly invoked in an x86 executable.
 * This gate NEVER reexecutes prior 46 Pointer, C98 UString66, Button2 tests.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as Any;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
$repo=dirname(__DIR__,2);$b=$repo.'/catalog/compliance';
$m=json_decode((string)file_get_contents($b.'/UE1-O06-G3-08-C227K-ACTUAL-COMPILED-FANY-NULL-INT-FLOAT-BOOL-LOAD-SAVE.json'),
 true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $label,bool $pass)use(&$checks,&$errors):void{
 $checks++;if(!$pass&&count($errors)<30)$errors[]=$label;
};
$check('G3-08 not prematurely called full native compiled acceptance',
 $m['G3_08_full_status']==='OPEN'
 &&$m['original_native_ANYTYPE_NAME_STRUCT_OBJECT_and_NETWORK_unverified']===true);
foreach([$m['original_shipped_C227k_Core_DLL'],$m['source_authority_227k_original_EAnyPropertyType']] as $pin){
 $check('original genuine shipped C227k source or binary SHA verified',
  is_file($pin['path'])&&filesize($pin['path'])===$pin['bytes']
  &&hash_file('sha256',$pin['path'])===$pin['sha256']);
}
$header=(string)file_get_contents($m['source_authority_227k_original_EAnyPropertyType']['path']);
$check('genuine SDK original dynamic enum subtype ordering exactly 0-7',
 preg_match('/enum EAnyPropertyType\s*\{\s*ANYTYPE_NULL,\s*ANYTYPE_INT,\s*ANYTYPE_FLOAT,\s*ANYTYPE_BOOL,\s*ANYTYPE_STRING,\s*ANYTYPE_NAME,\s*ANYTYPE_STRUCT,\s*ANYTYPE_OBJECT,\s*ANYTYPE_MAX,/s',$header)===1);
$check('source selected class exposes FVector on-tag alias but not FVector value format',
 str_contains((string)file_get_contents(
 'L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Inc/UnType.h'),
 'return NAME_VectorProperty; // NOTE: Reuse VectorProperty name'));
$logs=[];
foreach($m['actual_compiled_native_harness_and_raw_stdout_CDB_input_SHA'] as $name=>$pin){
 $p=$repo.'/'.$pin['repo_path'];
 $check('original complete native x86 compiled source and output immutable '.$name,
   is_file($p)&&filesize($p)===$pin['bytes']
   &&hash_file('sha256',$p)===$pin['sha256']);
 if(str_contains($name,'native_run')){
  $contents=(string)file_get_contents($p);
  $logs[$name]=str_starts_with($contents,"\xff\xfe")
    ?(string)iconv('UTF-16LE','UTF-8',substr($contents,2))
    :$contents;
 }
}
$zero=$logs['actual_original_compiled_FAny_null_and_cursor_native_run'];
$check('original 227k compiled FAny null full native status',
 str_contains($zero,'ORIGINAL_227K_NATIVE_FANY_TYPE_ORACLE_ALL_PASS=1'));
for($i=0;$i<8;$i++){
 $check('original compiled FAny PeekIsEmpty actual type '.$i,
   str_contains($zero,'PASS original_compiled_FAny_PeekIsEmpty_type_'.$i.'='
   .($i===0?'1':'0')));
}
$check('original compiled UAnyProperty::SerializeItem NULL LOAD native archive seek',
 str_contains($zero,'PASS native_UAnyProperty_SerializeItem_LOAD_EMPTY_serializes_twice=2')
 &&str_contains($zero,'PASS native_UAnyProperty_SerializeItem_LOAD_EMPTY_Seek_back_once=1')
 &&str_contains($zero,'PASS native_UAnyProperty_SerializeItem_LOAD_EMPTY_consumed_one_type_byte=1'));
$check('original compiled native NULL SAVE emits exactly one byte',
 str_contains($zero,'PASS native_UAnyProperty_SerializeItem_SAVE_NULL_writes_type_zero=0')
 &&str_contains($zero,'PASS native_UAnyProperty_SerializeItem_SAVE_NULL_consumed_one_byte=1'));
foreach([1=>'INT',2=>'FLOAT',3=>'BOOL'] as $type=>$kind){
 $s=$logs['actual_original_compiled_FAny_'.strtolower($kind).'_native_run'];
 $check('actual original compiled '.$kind.' real x86 UAnyProperty LOAD+SAVE executed',
  str_contains($s,"NATIVE_227K_UAny_SUBTYPE_{$type}_REAL_COMPILED_LOAD_SAVE_PASS=1"));
 $check('actual original compiled '.$kind.' native cursor payload width',
  str_contains($s,'PASS native_loaded_expected_bytes='.($type===3?2:5))
  &&str_contains($s,'PASS native_SAVE_serialized_size='.($type===3?2:5)));
 $check('actual original compiled '.$kind.' native data exact bits',
   str_contains($s,$type===3?'PASS native_BOOL_value=1'
                  :'PASS native_INT_or_FLOAT_bits=305419896'));
}
$actual=[
 [0,"\0",null,'native_any_null'],
 [1,"\x01\x78\x56\x34\x12",305419896,'native_any_int32'],
 [2,"\x02\x78\x56\x34\x12",unpack('g',"\x78\x56\x34\x12")[1],'native_any_float32'],
 [3,"\x03\x01",true,'native_any_bool'],
];
$reader=$repo.'/'.$m['new_production_value_decoder_path'];
$tagReader=$repo.'/'.$m['production_source_resolved_tag_integration'];
$check('production real original FAny value decoder SHA follows proven native model',
 hash_file('sha256',$reader)===$m['new_production_value_decoder_sha256']);
$check('production property tag resolved source integration SHA pinned',
 hash_file('sha256',$tagReader)===$m['production_tag_source_sha256']);
foreach($actual as [$id,$data,$wanted,$valueKind]){
 $pos=0;$read=static function(int $n)use(&$pos,$data):string{
  if($n<0||$pos+$n>strlen($data))throw new RuntimeException('bounded real source archive');
  $v=substr($data,$pos,$n);$pos+=$n;return $v;
 };
 $record=Any::readFromArchive($read,$pos,Source::UNREAL_V227);
 $check('new UnrealDB exact source native Any subtype payload class '.$id,
  $record['status']==='native_227k_any_value_original_compiled_subtype_verified'
  &&$record['original_any_type_id']===$id
  &&$record['value_kind']===$valueKind
  &&$record['value']===$wanted
  &&$record['serialized_byte_count']===strlen($data)
  &&$pos===strlen($data));
 $check('new source runtime save matches exact original compiled x86 data '.$id,
   Any::saveVerified($id,substr($data,1),Source::UNREAL_V227)===$data
   &&$record['raw_archive_bytes_hex']===bin2hex($data));
}
$reflection=new ReflectionMethod(Tags::class,'parseTags');
$parse=static function(string $wire,?array $decls,string $family)use($reflection):array{
 $text=chr(1).chr(0x2b).$wire.chr(0);
 $pos=0;$state=null;
 $read=static function(int $n)use(&$pos,$text):string{
  if($n<0||$pos+$n>strlen($text))throw new RuntimeException('synthetic source-typed archive physical bound');
  $a=substr($text,$pos,$n);$pos+=$n;return $a;
 };
 $u8=static fn():int=>ord($read(1));
 $compact=static function()use($u8):int{
  $first=$u8();if($first&0xc0)throw new RuntimeException('fixture only small indexes');
  return $first;
 };
 $name=static function()use($compact):array{
  $id=$compact();return ['index'=>$id,'text'=>['None','Data'][$id]??'Unknown'];
 };
 $args=[$read,$u8,$name,$compact,69,0,&$pos,strlen($text),
    $family,$decls,null,null,&$state];
 return $reflection->invokeArgs(null,$args);
};
foreach($actual as [$id,$wire,$wanted,$valueKind]){
 $records=$parse($wire,['data'=>['kind'=>'anyproperty','array_dim'=>1]],Source::UNREAL_V227);
 $tag=$records[0]??[];
 $check('resolved genuine C227 UAny alias type11 unlike real vector '.$id,
  count($records)===1&&($tag['type_id']??null)===11
  &&($tag['type']??null)==='VectorProperty'
  &&($tag['native_load_policy']??'')==='native_227k_resolved_AnyProperty_SerializeItem');
 $check('source-resolved Any native item ignores wrong advisory Tag.Size and returns exact '.$id,
  ($tag['value_kind']??null)===$valueKind
  &&($tag['value']??null)===$wanted
  &&($tag['on_disk_value_bytes']??null)===strlen($wire)
  &&($tag['serialized_size']??null)===4
  &&($tag['native_227k_source_resolved_Any_decoded']['raw_archive_bytes_hex']??null)
    ===bin2hex($wire));
}
$vector=$parse("\x11\x22\x33\x44",
 ['data'=>['kind'=>'vectorproperty','array_dim'=>1]],Source::UNREAL_V227);
$check('ordinary C227 VectorProperty using same type11 is NOT decoded as dynamic Any',
 count($vector)===1
 &&array_key_exists('native_227k_source_resolved_Any_decoded',$vector[0])
 &&$vector[0]['native_227k_source_resolved_Any_decoded']===null
 &&($vector[0]['native_load_policy']??'')==='archive_bytes_only_schema_not_linked'
 &&($vector[0]['value_kind']??null)==='opaque');
$unknown=$parse("\x11\x22\x33\x44",null,Source::UNREAL_V227);
$check('Unreal C227 schema unavailable NEVER claims dynamic Any from alias alone',
 count($unknown)===1&&($unknown[0]['value_kind']??null)==='opaque');
$old=$parse("\x11\x22\x33\x44",
 ['data'=>['kind'=>'anyproperty','array_dim'=>1]],Source::UT99_V432);
$check('original UT99 v432 never erroneously selects newer C227 dynamic Any',
 count($old)===1&&str_starts_with(
 (string)($old[0]['native_load_policy']??''),'native_warning_skip'));
foreach([4,5,6,7,8,255] as $type){
 $bad='';try{$parse(chr($type)."\0\0\0",
  ['data'=>['kind'=>'anyproperty','array_dim'=>1]],Source::UNREAL_V227);}
 catch(RuntimeException $ex){$bad=$ex->getMessage();}
 $check('non-verified original Any subtype '.$type.' fails closed not guessed vector',
  str_contains($bad,'dynamic Any subtype not yet implemented'));
}
$bad='';try{Any::saveVerified(3,"\x02",Source::UNREAL_V227);}
catch(RuntimeException $ex){$bad=$ex->getMessage();}
$check('noncanonical any BOOL source-not-native-proven FAILS CLOSED',
 str_contains($bad,'noncanonical BOOL'));
$bad='';try{$pos=0;$payload="\x01\xAA";
 $read=static function(int $n)use(&$pos,$payload):string{
  if($pos+$n>strlen($payload))throw new RuntimeException('bounded');
  $str=substr($payload,$pos,$n);$pos+=$n;return $str;
 };
 Any::readFromArchive($read,$pos,Source::UNREAL_V227);
}catch(RuntimeException $ex){$bad=$ex->getMessage();}
$check('truncated valid INT cannot return success',str_contains($bad,'bounded'));
$bad='';try{Any::saveVerified(0,'',Source::UT99_V432);}
catch(RuntimeException $ex){$bad=$ex->getMessage();}
$check('wrong original engine source cannot claim C227 native loaded Any',
 str_contains($bad,'not Unreal v227'));
$engine='L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-Windows/System/Engine.u';
$pkg=new Package($engine);
$check('genuine original C227k Engine.u package readable',$pkg->validatePackage()===[]);
$im=$pkg->getImports();$found=0;
foreach($pkg->getExports() as $ex){
 $ci=(int)$ex['classIndex'];
 if($ci<0&&strtolower((string)($im[-$ci-1]['objectNameText']??''))==='anyproperty')$found++;
}
$check('real original C227k Engine.u has 5 genuine UAnyProperty declarations, no hypothetical fake class',
 $found===5);
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
 'failed'=>$errors,'new_real_shipped_original_C227k_native_type0_1_2_3_LOAD_SAVE'=>true,
 'new_source_declared_Any_runtime_value_IMPLEMENTED_subtypes'=>[0,1,2,3],
 'native_STRING_NAME_STRUCT_OBJECT_error_Net_subtypes_unverified'=>[4,5,6,7],
 'G3_08_contract_still_OPEN'=>true,'old_original_native_accepted_tests_reexecuted'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
