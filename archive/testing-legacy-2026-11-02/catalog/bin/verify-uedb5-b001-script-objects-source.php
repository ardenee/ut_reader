<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader as Reader;
$root=dirname(__DIR__,2);
$srcRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$cpp=$srcRoot.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$header=$srcRoot.'/Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h';
$ioc=$srcRoot.'/Engine/Source/Runtime/Core/Public/IO/IoChunkId.h';
$source=is_file($cpp)?(string)file_get_contents($cpp):'';
$head=is_file($header)?(string)file_get_contents($header):'';
$io=is_file($ioc)?(string)file_get_contents($ioc):'';
$checks=[];$failed=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{
 $checks[$name]=$pass;if(!$pass)$failed[]=$name;
};
$check('b001_epic_cpp_pinned',hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$check('b001_epic_struct_header_pinned',hash('sha256',$head)==='088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2');
$check('b001_epic_chunk_enum_pinned',hash('sha256',$io)==='64ebf25a5cf436e1cc353a8fefbd16bbb231ef28ff914a4ef6296e27b20b30b1');
$start=strpos($source,'void FCookedScriptObjectsDebug::LoadDebugData()');
$end=$start===false?false:strpos($source,'void FCookedScriptObjectsDebug::TrimDebugData(',$start+1);
$load=$end===false?'':substr($source,$start,$end-$start);
$check('b001_epic_global_script_chunk_exists_gate',
 str_contains($load,'CreateIoChunkId(0, 0, EIoChunkType::ScriptObjects)')
 &&str_contains($load,'if (!IoDispatcher.DoesChunkExist(ChunkId))')
 &&str_contains($io,'ScriptObjects = 5'));
$check('b001_epic_name_batch_then_int32_count_then_contiguous_view',
 str_contains($load,'LoadNameBatch(Ar)')
 &&str_contains($load,'int32 NumObjects = 0;')
 &&str_contains($load,'Ar << NumObjects;')
 &&str_contains($load,'MakeConstArrayView(reinterpret_cast<const FScriptObjectEntry*>(Buffer->Data() + Ar.Tell()), NumObjects)'));
$check('b001_epic_record_fields_and_map_key',
 str_contains($head,'FMappedName Mapped;')
 &&str_contains($head,'FPackageObjectIndex GlobalIndex;')
 &&str_contains($head,'FPackageObjectIndex OuterIndex;')
 &&str_contains($head,'FPackageObjectIndex CDOClassIndex;')
 &&str_contains($load,'Objects.Emplace(Entry.GlobalIndex'));
$check('b001_epic_no_end_of_chunk_exact_gate',
 !str_contains($load,'Buffer->DataSize() - Ar.Tell() ==')
 &&!str_contains($load,'Ar.AtEnd()')
 &&str_contains($load,'NumObjects);'));
$cmd='git -c '.escapeshellarg('safe.directory='.$root)
 .' -C '.escapeshellarg($root)
 .' show '.escapeshellarg('2b4377b0:catalog/src/Infrastructure/Metadata/Uedb5ZenScriptObjectsReader.php');
$proc=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$old='';
if(is_resource($proc)){
 $old=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $exit=proc_close($proc);
}else{$exit=127;}
$check('b001_historical_original_checked_out',$exit===0 &&str_contains($old,'remaining() !== $count * 32'));
if($exit===0) {
 $old=str_replace('final class Uedb5ZenScriptObjectsReader','final class Uedb5ZenScriptObjectsReaderBeforeD024',$old);
 $temp=sys_get_temp_dir().'/b001-old-'.bin2hex(random_bytes(5)).'.php';
 file_put_contents($temp,$old);
 try{require $temp;}finally{@unlink($temp);}
}
$pack=static fn(int $v):string=>pack('V',$v);
$batch=$pack(1).$pack(4).str_repeat("\0",16)."\0\x04".'Test';
$entry=$pack(0x40000000).$pack(0).hex2bin('0100000000000040').str_repeat("\xFF",16);
$fixture=$batch.$pack(1).$entry;
$oldReader='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5ZenScriptObjectsReaderBeforeD024';
$rejectedBefore=false;
if(class_exists($oldReader)){
 try{$oldReader::parse($fixture."\x99");}catch(Throwable){$rejectedBefore=true;}
}
$fixed=Reader::parse($fixture."\x99");
$zero=Reader::parse($batch.$pack(0)."\x01");
$check('b001_negative_before_positive_after_trailing_bytes',
 $rejectedBefore&&$fixed['count']===1&&$fixed['runtime_authoritative']===false);
$check('b001_zero_records_trailing_bytes_allowed',$zero['count']===0);
$truncated=false;
try{Reader::parse(substr($fixture,0,-1));}catch(Throwable){$truncated=true;}
$check('b001_truncated_record_still_rejected',$truncated);
$check('b001_cooked_chunk_never_proves_live_runtime',
 $fixed['runtime_authoritative']===false
 &&($fixed['objects']['4000000000000001']['runtime_resolved']??null)===false);
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed,
 'original_operation'=>'UE5-583-SCRIPT-001','runtime_script_registration_executed'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
