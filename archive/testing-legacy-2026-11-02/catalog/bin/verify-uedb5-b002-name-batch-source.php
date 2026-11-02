<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5BinaryReader as Binary;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
$root=dirname(__DIR__,2);
$base=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\').'/Engine/Source/Runtime/';
$paths=[
 'async'=>$base.'CoreUObject/Private/Serialization/AsyncLoading2.cpp',
 'names'=>$base.'Core/Private/UObject/UnrealNames.cpp',
 'batch'=>$base.'Core/Public/UObject/NameBatchSerialization.h',
 'mapped'=>$base.'Core/Public/Serialization/MappedName.h'
];
$hashes=[
 'async'=>'43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9',
 'names'=>'1a4f7f23564afebc8c18d080a4757b16958c15fcab513c9e3a1f0a67e565f67c',
 'batch'=>'63fa10b5509a03cd9c55e836b5ab2eaa1522bd7a43e4564b96a5de80a9c7c979',
 'mapped'=>'5221b85d9a40d300607e13d7146900e88a23d0894650296497014ec4f0f2787a'
];
$checks=[];$fail=[];
$check=static function(string $k,bool $v)use(&$checks,&$fail):void{$checks[$k]=$v;if(!$v)$fail[]=$k;};
$src=[];
foreach($paths as $key=>$path){
 $src[$key]=is_file($path)?(string)file_get_contents($path):'';
 $check('b002_epic_'.$key.'_source_sha256',hash('sha256',$src[$key])===$hashes[$key]);
}
$start=strpos($src['names'],'struct FNameBatchLoader');
$end=strpos($src['names'],'struct FNameBatchAsyncLoader',$start===false?0:$start);
$loader=($start!==false&&$end!==false)?substr($src['names'],$start,$end-$start):'';
$check('b002_epic_zero_names_returns_without_header',
 str_contains($loader,'Ar << Num;')
 &&str_contains($loader,'if (Num == 0)')
 &&str_contains($loader,'return false;'));
$check('b002_epic_wire_count_string_bytes_hash_and_arrays',
 str_contains($loader,'Ar << NumStringBytes;')
 &&str_contains($loader,'Ar << HashVersion;')
 &&str_contains($loader,'sizeof(uint64) * Num')
 &&str_contains($loader,'sizeof(FSerializedNameHeader) * Num')
 &&str_contains($loader,'Ar.Serialize(Data.GetData(), Data.Num());'));
$check('b002_epic_saved_hash_rehash_or_input_shard_branch',
 str_contains($loader,'CanUseSavedHashes(HashVersion)')
 &&str_contains($loader,'LoadSeparatedNameBatchInInputOrder')
 &&str_contains($loader,'LoadSeparatedNameBatchInShardOrder')
 &&str_contains($loader,'Out.SetNumUninitialized(Headers.Num())'));
$check('b002_epic_header_utf16_and_15bit_length',
 str_contains($src['names'],'Data[0] = uint8(bIsUtf16) << 7')
 &&str_contains($src['names'],'return Data[0] & 0x80u;')
 &&str_contains($src['names'],'((Data[0] & 0x7Fu) << 8) + Data[1]')
 &&str_contains($src['names'],'sizeof(UTF16CHAR) * Len()'));
$check('b002_epic_cooked_loader_preserves_name_map_order',
 str_contains($src['async'],'TArray<FDisplayNameEntryId> NameMap = LoadNameBatch(Ar);')
 &&str_contains($src['async'],'NameMap[Entry.Mapped.GetIndex()].ToName(Entry.Mapped.GetNumber())')
 &&str_contains($src['mapped'],'return Index & IndexMask;'));
$php=(string)file_get_contents($root.'/catalog/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php');
$check('b002_unrealdb_name_batch_wire_decoder_maps_field_order',
 str_contains($php,'public static function readNameBatchForScriptObjects(')
 &&str_contains($php,'return self::readNameBatch(')
 &&str_contains($php,'$reader->u32le();')
 &&str_contains($php,'$reader->u64HexLe();')
 &&str_contains($php,'(($first & 0x7f) << 8) | $second'));
$check('b002_unrealdb_name_batch_preserves_index_and_original_hash',
 str_contains($php,"'index' => $".'index')
 &&str_contains($php,"'hash_version' => $".'hashVersion')
 &&str_contains($php,"'hash' => $".'hashes[$index]')
 &&str_contains($php,"'raw' => strtoupper(bin2hex($".'raw))'));
$pack=static fn(int $n):string=>pack('V',$n);
$nameRaw='Name'."\xC5\x00";
$bytes=$pack(2).$pack(strlen($nameRaw)).str_repeat("\x00",8)
 .str_repeat("\x00",16)."\x00\x04\x80\x01".$nameRaw;
$reader=new Binary($bytes,'B002 independent names');
$names=Zen::readNameBatchForScriptObjects($reader);
$check('b002_utf8_utf16_names_are_ordered_and_roundtrip',
 count($names)===2
 &&$names[0]['text']==='Name'
 &&$names[1]['text']==='Å'
 &&$names[0]['index']===0&&$names[1]['index']===1
 &&$names[1]['is_utf16']===true&&$reader->remaining()===0);
$reader=new Binary($pack(0)."\x99\x88",'B002 zero count');
$empty=Zen::readNameBatchForScriptObjects($reader);
$check('b002_zero_names_consume_only_count',
 $empty===[]&&$reader->remaining()===2);
$bad=false;
try{
 $truncated=new Binary(substr($bytes,0,-1),'B002 bad UTF16');
 Zen::readNameBatchForScriptObjects($truncated);
}catch(Throwable){$bad=true;}
$check('b002_truncated_utf16_still_rejected',$bad);
$bad=false;
try{
 $wrong=new Binary(substr_replace($bytes,pack('V',8),4,4),'B002 bad length');
 Zen::readNameBatchForScriptObjects($wrong);
}catch(Throwable){$bad=true;}
$check('b002_declared_name_bytes_outside_available_rejected',$bad);
$fixture=(string)(getenv('UNREALDB_B001_REAL_GLOBAL_TOC')?:
 '//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc');
try{
 $toc=new Toc($fixture);
 $chunks=$toc->chunkIndexesByType(5);
 $blob=$toc->readChunk($chunks[0]);
 $r=new Binary($blob,'B002 authentic name map');
 $cooked=Zen::readNameBatchForScriptObjects($r);
 $num=$r->u32le();
 $check('b002_real_50640_name_map_has_54968_serialized_consumers',
  $toc->tocSha256()==='0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6'
  &&count($cooked)===50640&&$num===54968
  &&$cooked[0]['text']==='GetRecipes');
 $check('b002_real_first_record_mapped_name_index_and_display',
  $r->u32le()===0x80000000 && $r->u32le()===0
  &&$cooked[0]['text']==='GetRecipes');
}catch(Throwable $e){
 $fail[]='b002_authentic_fixture_exception';$error=$e->getMessage();
}
$phpRoot=$root.'/catalog/src';$total=0;$external=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($phpRoot,FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 $total++;$body=(string)file_get_contents($file->getPathname());
 if(preg_match('/\b(?:ZenPackageReader|Uedb5ZenPackageReader)::readNameBatchForScriptObjects\s*\(/',$body))
  $external[]=basename($file->getPathname());
}
$check('b002_complete_source_files_and_one_script_diagnostic_caller',
 $total>=500&&$external===['Uedb5ZenScriptObjectsReader.php']);
echo json_encode(['ok'=>$fail===[],'operation'=>'UE5-583-SCRIPT-002',
 'checks'=>$checks,'failures'=>$fail,
 'caller_count'=>count($external),'source_files_scanned'=>$total,
 'epic_runtime_FNamePool_executed'=>false,'error'=>$error??null],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
