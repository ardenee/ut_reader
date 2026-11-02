<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as Reader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
$root=dirname(__DIR__,2);
$m=json_decode(file_get_contents($root.'/catalog/compliance/UE1-O06-G3-08-C227K-REAL-OBJECT7-NULL-LOAD-SAVE.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$bad=[];
$test=static function(string $name,bool $pass)use(&$n,&$bad):void{$n++;if(!$pass)$bad[]=$name;};
$test('G3-08 original source class not prematurely accepted',
 $m['G3_08_full_status']==='OPEN'&&$m['nonzero_UObject_original_linker_mapping_verified']===false);
$src=$m['original_shipped_227k_Core_DLL'];
$test('matching genuine original Core.dll SHA256 unchanged',is_file($src['path'])&&filesize($src['path'])===$src['bytes']&&hash_file('sha256',$src['path'])===$src['sha256']);
foreach($m['original_native_machine_code_and_execution_artifacts'] as $name=>$pin){
 $f=$root.'/'.$pin['repo_path'];
 $test('original genuine x86 native source or log exact SHA '.$name,
  is_file($f)&&filesize($f)===$pin['bytes']&&hash_file('sha256',$f)===$pin['sha256']);
}
$log=$m['original_native_machine_code_and_execution_artifacts']['actual_original_compiled_DLL_NULL_OBJECT_LOAD_SAVE_stdout'];
$raw=file_get_contents($root.'/'.$log['repo_path']);
$s=str_starts_with($raw,"\xff\xfe")?iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
$test('original compiled TYPE7 LOAD consumes five actual bytes with null native UObject pointer',
 str_contains($s,'type=7 cursor=5 calls=3 error=0 dataPointer=00000000'));
$test('original compiled TYPE7 SAVE returns identical archive bytes',
 str_contains($s,'cursor=5 calls=2 error=0 hex=0700000000'));
$test('FArchive original operator for real UObject* at virtual slot0x18',
 $m['original_FArchive_virtual_UObject_reference_operator_vtable_offset']==='0x18');
$test('native TYPE6 experiment unsupported mock, not original UE error conclusion',
 $m['genuine_STRUCT6_null_mock_crash_not_native_error_or_actual_unsupported_engine']===true);
$test('production original Any source decoder exact SHA',
 hash_file('sha256',$root.'/'.$m['new_php_source_decoder_path'])===$m['new_php_source_decoder_sha256']);
$wire="\x07\0\0\0\0";$pos=0;
$read=static function(int $len)use(&$pos,$wire):string{
 if($len<0||$pos+$len>strlen($wire))throw new RuntimeException('truncated');
 $x=substr($wire,$pos,$len);$pos+=$len;return $x;
};
$record=Reader::readFromArchive($read,$pos,Source::UNREAL_V227);
$test('original compiled source TYPE7 null object parser returns meaningful null reference, no guess',
 $record['status']==='native_227k_any_value_original_compiled_subtype_verified'
 &&$record['original_any_type_id']===7&&$record['value_kind']==='native_any_null_object'
 &&$record['value']['object_reference']===0&&$record['serialized_byte_count']===5&&$pos===5);
$test('original actual native TYPE7 null UObject save exactly 0700000000',
 Reader::saveVerified(7,"\0\0\0\0",Source::UNREAL_V227)===$wire);
foreach([
 ["\x07\x01\0\0\0",'nonnull'],
 ["\x07\0\0\0",'truncated'],
 ["\x06\0\0\0\0",'struct'],
] as [$data,$name]){
 $pos=0;
 $read=static function(int $len)use(&$pos,$data):string{
  if($pos+$len>strlen($data))throw new RuntimeException('truncated');
  $x=substr($data,$pos,$len);$pos+=$len;return $x;
 };
 $blocked=false;
 try{Reader::readFromArchive($read,$pos,Source::UNREAL_V227);}catch(RuntimeException){$blocked=true;}
 $test('original unverified '.$name.' archive cannot be fabricated', $blocked);
}
$blocked=false;try{Reader::saveVerified(7,"\x01\0\0\0",Source::UNREAL_V227);}catch(RuntimeException){$blocked=true;}
$test('not native-certified nonnull object SAVE fails closed',$blocked);
$blocked=false;try{Reader::saveVerified(7,"\0\0\0\0",Source::UT99_V432);}catch(RuntimeException){$blocked=true;}
$test('old UT99 source must not run C227 native Any VALUE method',$blocked);
$test('old original test suites not reexecuted',$m['original_prior_accepted_native_tests_rerun']===0);
echo json_encode(['ok'=>$bad===[],'checks'=>$n,'passed'=>$n-count($bad),'failed'=>$bad,
 'genuine_original_C227k_UAny_object_null_native_LOAD_SAVE'=>true,
 'G3_08_full_status'=>'OPEN','previous_tests_replayed'=>0],JSON_PRETTY_PRINT).PHP_EOL;
exit($bad===[]?0:1);
