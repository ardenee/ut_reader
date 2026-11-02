<?php
/** New and unique G3-08 actual original 227k compiled FAnyProperty STRING LOAD/SAVE oracle.
 * Previous accepted C227k Any NULL/INT/FLOAT/BOOL 65, 469f pointer46, Button, etc.
 * are SHA reused ONLY; never execute their previous verifier again.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as Any;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-08-C227K-REAL-NATIVE-ANY-STRING-LOAD-SAVE-ROUNDTRIP.json'),true,512,JSON_THROW_ON_ERROR);
$c=0;$fail=[];
$check=static function(string $what,bool $ok)use(&$c,&$fail):void{
 $c++;if(!$ok&&count($fail)<25)$fail[]=$what;
};
$check('actual 227k native STRING dynamic acceptance is a G3-08 partial not whole',
 $m['G3_08_FULL_ACCEPTED']===false&&$m['other_native_ANY_type_NAME_STRUCT_OBJECT_and_NET_still_open']===true);
$binary=$m['original_DLL'];
$check('actual original 227k shipped DLL SHA immutable',
 is_file($binary['path'])&&filesize($binary['path'])===$binary['bytes']
 &&hash_file('sha256',$binary['path'])===$binary['sha256']);
foreach($m['native_27_oct_output_and_reproducible_Cplusplus_source'] as $name=>$pin){
 $f=$root.'/'.$pin['repo_path'];
 $check('original shipped native compiled witness and C++ script SHA '.$name,
  is_file($f)&&filesize($f)===$pin['bytes']
  &&hash_file('sha256',$f)===$pin['sha256']);
}
$pin=$m['native_27_oct_output_and_reproducible_Cplusplus_source']['actual_compiled_native_STRING_LOAD_SAVE_and_SAVE_THEN_LOAD_log'];
$f=$root.'/'.$pin['repo_path'];
$raw=(string)file_get_contents($f);
$native=str_starts_with($raw,"\xff\xfe")
  ?(string)iconv('UTF-16LE','UTF-8',substr($raw,2))
  :$raw;
$check('native C++ 227k actual STRING source after FArchive load has true UTF-16 Test',
 str_contains($native,'chars=5 wchar0=0054 wchar1=0065 wchar2=0073 wchar3=0074 wchar4=0000'));
$check('native C++ original save uses exactly compact type4 and ASCII byte payload',
 str_contains($native,'STRING_NATIVE_ORIGINAL_SAVE cursor=7 calls=7 error=0 HEX=04055465737400'));
$check('actual native compiled own SAVE then own LOAD same five UTF-16 characters',
 str_contains($native,'STRING_NATIVE_SAVE_THEN_LOAD cursor=7 type=4 charCount=5 name0=0054 name1=0065 err=0')
 &&str_contains($native,'NATIVE_227K_UAny_STRING_TYPE4_ORIGINAL_COMPILED_LOAD_SAVE_ROUNDTRIP_PASS=1'));
$check('actual source-specific native CountBytes and ArIsSaving harness correction proven',
 $m['original_required_FArchive_virtual_CountBytes_offset_hex']==='0x14'
 &&$m['genuine_compiled_FArchive_Saving_flag']===2
 &&$m['genuine_compiled_FArchive_Loading_flag']===1);
$reader=$root.'/'.$m['new_production_value_reader'];
$check('actual production runtime Any reader source SHA corresponds to pinned native evidence',
 hash_file('sha256',$reader)===$m['new_production_value_reader_sha256']);
$bytes=hex2bin($m['native_TYPE4_STRING_original_load_wire_hex']);
$check('native canonical original full STRING save hexadecimal has exactly 7 bytes',
 $bytes==="\x04\x05Test\0"&&strlen($bytes)===7);
$directRead=static function(string $bytes,string $family=Family::UNREAL_V227):array{
 $pos=0;
 $read=static function(int $n)use(&$pos,$bytes):string{
  if($n<0||$pos+$n>strlen($bytes))
   throw new RuntimeException('real archive physical read bound');
  $got=substr($bytes,$pos,$n);$pos+=$n;return $got;
 };
 $result=Any::readFromArchive($read,$pos,$family);
 return [$result,$pos];
};
[$record,$cursor]=$directRead($bytes);
$check('new PHP actual compiled native source STRING value is Test',
 $record['value']==='Test'&&$record['value_kind']==='native_any_ansi_string'
 &&$record['original_any_type_id']===4);
$check('new PHP actual compiled native count, raw archive bytes and physical cursor',
 $cursor===7&&$record['serialized_byte_count']===7
 &&$record['raw_archive_bytes_hex']==='04055465737400');
$check('new PHP SAVE bytewise equals actual original compiled engine 7-byte output',
 Any::saveVerified(4,"\x05Test\0",Family::UNREAL_V227)===$bytes);
$method=new ReflectionMethod(Tag::class,'parseTags');
$parse=static function(string $wire,?array $declarations,string $family)use($method):array{
 $data="\x01\x2b".$wire."\x00";$pos=0;$state=null;
 $read=static function(int $n)use(&$pos,$data):string{
  if($n<0||$pos+$n>strlen($data))throw new RuntimeException('real tagged archive EOF');
  $got=substr($data,$pos,$n);$pos+=$n;return $got;
 };
 $u8=static fn():int=>ord($read(1));
 $idx=static function()use($u8,&$pos):int{
  $n=$u8();if($n&0xc0)throw new RuntimeException('small archived indices only');
  return $n;
 };
 $readName=static function()use($idx):array{
  $id=$idx();return ['index'=>$id,'text'=>['None','Data'][$id]??'Unknown'];
 };
 return $method->invokeArgs(null,[
  $read,$u8,$readName,$idx,69,0,&$pos,strlen($data),$family,
  $declarations,null,null,&$state
 ]);
};
$tags=$parse(substr($bytes,0),['data'=>['kind'=>'anyproperty','array_dim'=>1]],Family::UNREAL_V227);
$check('same Vector tag ID11 authentic v227 source-proven Any decodes actual STRING',
 count($tags)===1 &&($tags[0]['type_id']??null)===11
 &&($tags[0]['native_load_policy']??null)==='native_227k_resolved_AnyProperty_SerializeItem');
$check('source-tagged actual original STRING loaded value, physical byte consumption',
 ($tags[0]['value']??null)==='Test'
 &&($tags[0]['value_kind']??null)==='native_any_ansi_string'
 &&($tags[0]['on_disk_value_bytes']??null)===7
 &&($tags[0]['native_227k_source_resolved_Any_decoded']['raw_archive_bytes_hex']??null)
  ==='04055465737400');
$tags=$parse("\x11\x22\x33\x44",['data'=>['kind'=>'vectorproperty','array_dim'=>1]],Family::UNREAL_V227);
$check('same source name but ordinary FVector NEVER automatically interpreted as Any STRING',
 count($tags)===1&&($tags[0]['native_load_policy']??null)
   ==='archive_bytes_only_schema_not_linked'
 &&($tags[0]['value_kind']??null)==='opaque');
$tags=$parse("\x11\x22\x33\x44",null,Family::UNREAL_V227);
$check('Unreal source provenance ambiguous stays ordinary tagged raw not Any decoded',
 count($tags)===1&&($tags[0]['value_kind']??null)==='opaque');
$negative=[
 ['short compact count','04', 'real archive physical read bound'],
 ['zero count','0400','not yet verified'],
 ['too many compact chars','0440','not yet verified'],
 ['truncated ASCII','04055465','real archive physical read bound'],
 ['missing null terminator','04055465737421','payload invalid'],
 ['non-ASCII value','04028000','payload invalid'],
 ['unknown subtype NAME','05','not yet implemented'],
 ['unknown subtype STRUCT','06','not yet implemented'],
 ['unknown subtype OBJECT','07','not yet implemented'],
];
foreach($negative as [$name,$hex,$fragment]){
 $error='';
 try{$directRead((string)hex2bin($hex));}
 catch(RuntimeException $e){$error=$e->getMessage();}
 $check('unsupported/malformed source typed original Any STRING fails closed '.$name,
  str_contains($error,$fragment));
}
foreach([
 ['unverified unicode save',"\x02\x80\x00"],
 ['missing terminator',"\x05TestX"],
 ['invalid compact count',"\x40".str_repeat('A',64)],
 ['interior nul',"\x05Te\0t\0"],
] as [$name,$value]){
 $error='';try{Any::saveVerified(4,$value,Family::UNREAL_V227);}
 catch(RuntimeException $e){$error=$e->getMessage();}
 $check('unverified original dynamic Any STRING SAVE fails closed '.$name,
  str_contains($error,'not verified')
  ||str_contains($error,'unsupported compact'));
}
$error='';try{Any::saveVerified(4,"\x05Test\0",Family::UT99_V432);}
catch(RuntimeException $e){$error=$e->getMessage();}
$check('actual 227k engine exact source-only STRING not applied to v432',str_contains($error,'not Unreal v227'));
$check('native full G3-08 not claimed due to unverified name struct object network',
 $m['G3_08_FULL_ACCEPTED']===false&&$m['prior_accepted_native_tests_reexecuted']===0);
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$c,'passed'=>$c-count($fail),
 'failed'=>$fail,'actual_original_compiled_native_String4_load_save_roundtrip'=>true,
 'source_227k_Any_supported_dynamic_types'=>[0,1,2,3,4],
 'G3_08_FULL_ACCEPTED'=>false,'old_native_tests_replayed'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
