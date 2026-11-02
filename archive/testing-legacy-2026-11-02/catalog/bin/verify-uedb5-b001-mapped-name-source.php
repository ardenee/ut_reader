<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader as Reader;
$root=dirname(__DIR__,2);$base=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\').'/Engine/Source/Runtime/';
$paths=[
 'mapped'=>$base.'Core/Public/Serialization/MappedName.h',
 'map'=>$base.'Core/Public/Containers/Map.h.inl',
 'set'=>$base.'Core/Public/Containers/SparseSet.h.inl',
 'async'=>$base.'CoreUObject/Private/Serialization/AsyncLoading2.cpp'
];
$hashes=[
 'mapped'=>'5221b85d9a40d300607e13d7146900e88a23d0894650296497014ec4f0f2787a',
 'map'=>'8abbac8d11e3988601a7e226e3ed3a082cda2ceef783a90d0434fad32b1153d0',
 'set'=>'946e30739b523bc7a20a3c78c95c9a9216d6a99fd5839b82b3d318cf66c88408',
 'async'=>'43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9'
];
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
 $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$src=[];
foreach($paths as $key=>$path){
 $src[$key]=is_file($path)?(string)file_get_contents($path):'';
 $check('b001_mapped_epic_'.$key.'_source_pinned',hash('sha256',$src[$key])===$hashes[$key]);
}
$check('b001_mapped_epic_all_three_name_types',
 str_contains($src['mapped'],"Package,")&&str_contains($src['mapped'],"Container,")
 &&str_contains($src['mapped'],"Global")&&str_contains($src['mapped'],"IndexBits = 30u")
 &&str_contains($src['mapped'],"return Index & IndexMask;"));
$start=strpos($src['async'],'void FCookedScriptObjectsDebug::LoadDebugData()');
$end=strpos($src['async'],'void FCookedScriptObjectsDebug::TrimDebugData(',$start===false?0:$start);
$load=($start!==false&&$end!==false)?substr($src['async'],$start,$end-$start):'';
$check('b001_mapped_epic_index_only_with_no_type_gate',
 str_contains($load,'NameMap[Entry.Mapped.GetIndex()].ToName(Entry.Mapped.GetNumber())')
 &&!str_contains($load,'Entry.Mapped.GetType()')
 &&!str_contains($load,'Entry.Mapped.IsGlobal()'));
$check('b001_mapped_epic_map_emplace_replaces_duplicate',
 str_contains($load,'Objects.Emplace(Entry.GlobalIndex, { FMinimalName(Name), Entry.OuterIndex });')
 &&str_contains($src['map'],'Pairs.Emplace(TPairInitializer')
 &&str_contains($src['set'],"If there's an existing element with the same key as the new element, replace the existing element with the new element.")
 &&str_contains($src['set'],'MoveByRelocate(Elements[ExistingIndex].Value, Element.Value);'));
$cmd='git -c '.escapeshellarg('safe.directory='.$root)
 .' -C '.escapeshellarg($root)
 .' show '.escapeshellarg('a3d159a3:catalog/src/Infrastructure/Metadata/Uedb5ZenScriptObjectsReader.php');
$p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$old='';
if(is_resource($p)){
 $old=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $status=proc_close($p);
}else{$status=127;}
$check('b001_mapped_historical_b001_reader_pinned',
 $status===0 &&str_contains($old,"(int)$"."mapped['type'] !== 1")
 &&str_contains($old,"Duplicate ScriptObjects global index."));
if($status===0){
 $old=str_replace('final class Uedb5ZenScriptObjectsReader','final class Uedb5ZenScriptObjectsReaderBeforeD025',$old);
 $temp=sys_get_temp_dir().'/b001-pre-map-'.bin2hex(random_bytes(5)).'.php';
 file_put_contents($temp,$old);try{require $temp;}finally{@unlink($temp);}
}
$oldClass='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5ZenScriptObjectsReaderBeforeD025';
$pack=static fn(int $n):string=>pack('V',$n);
$batch=$pack(1).$pack(4).str_repeat("\0",16)."\0\x04".'Test';
$key=hex2bin('0100000000000040');
$make=static fn(int $type,int $number=0):string=>$pack(($type<<30)|0).$pack($number).$key.str_repeat("\xFF",16);
foreach([0,1,2] as $type){
 $bytes=$batch.$pack(1).$make($type);
 $r=Reader::parse($bytes);
 $check('b001_mapped_type_'.$type.'_uses_index_not_type',
  $r['count']===1 &&($r['objects']['4000000000000001']['name']??null)==='Test'
  &&$r['runtime_authoritative']===false);
 if($type!==1&&class_exists($oldClass)){
  $reject=false;
  try{$oldClass::parse($bytes);}catch(Throwable){$reject=true;}
  $check('b001_mapped_historical_rejected_valid_type_'.$type,$reject);
 }
}
$dupe=$batch.$pack(2).$make(2).$make(2,2);
$fixed=Reader::parse($dupe);
$rejected=false;
if(class_exists($oldClass)){try{$oldClass::parse($dupe);}catch(Throwable){$rejected=true;}}
$check('b001_mapped_duplicate_last_emplace_wins',
 $fixed['count']===2&&count($fixed['objects'])===1
 &&($fixed['objects']['4000000000000001']['name']??null)==='Test_1');
$check('b001_mapped_original_rejected_replaceable_duplicate',$rejected);
$outOfRange=false;
try{Reader::parse($batch.$pack(1).$pack(0x80000001).substr($make(2),4));}
catch(Throwable){$outOfRange=true;}
$check('b001_mapped_out_of_range_index_still_rejected',$outOfRange);
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/catalog/src',FilesystemIterator::SKIP_DOTS));
$count=0;$callers=[];
foreach($files as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 $count++;
 $php=(string)file_get_contents($file->getPathname());
 if(preg_match('/\bUedb5ZenScriptObjectsReader\s*::\s*(?:parse|fromToc)\s*\(/',$php))
  $callers[]=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
}
// Scan every live PHP source on this host; DEV and PRD can have different
// valid checked-out inventories. Prove nontrivial coverage, not a fixed total.
$check('b001_mapped_complete_production_php_inventory',$count>=500);
$check('b001_mapped_no_production_runtime_consumer_is_fabricated',$callers===[]);
$check('b001_mapped_cooked_debug_is_optional_not_live_registration',
 str_contains($src['async'],'#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING')
 &&str_contains($src['async'],'CookedScriptObjectsDebug.TrimDebugData(ScriptObjects)')
 &&str_contains($src['async'],'GlobalImportStore.AddScriptObject(')
 &&!str_contains($load,'ScriptObjects.Add('));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures,
 'production_php_count'=>$count,'production_direct_callers'=>$callers,
 'runtime_ue_loader_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
