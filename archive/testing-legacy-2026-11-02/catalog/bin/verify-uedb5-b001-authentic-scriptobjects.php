<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5BinaryReader as Binary;
$base='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/';
$path=(string)(getenv('UNREALDB_B001_REAL_GLOBAL_TOC')?:$base.'global.utoc');
$negative=(string)(getenv('UNREALDB_B001_REAL_PACKAGE_TOC')?:$base.'FactoryGame-WindowsServer.utoc');
$checks=[];$failures=[];
$check=static function(string $id,bool $pass)use(&$checks,&$failures):void{
 $checks[$id]=$pass;if(!$pass)$failures[]=$id;
};
$check('b001_real_original_installed_global_toc_present',is_file($path));
$check('b001_real_original_installed_package_toc_present',is_file($negative));
try{
 $global=new Toc($path);
 $check('b001_real_global_toc_pinned',$global->tocSha256()==='0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6');
 $indices=$global->chunkIndexesByType(5);
 $check('b001_real_single_global_script_chunk_key',
  count($indices)===1&&($global->chunks()[$indices[0]]['raw']??'')==='000000000000000000000005');
 $raw=count($indices)===1?$global->readChunk($indices[0]):'';
 $check('b001_real_script_chunk_sha_and_length',
  hash('sha256',$raw)==='46d8ca9171563208e1b1a83f853274f63a507bf9922cc6b9d4b9116f0d4c4e76'
  &&strlen($raw)===3540748);
 $decoded=Reader::fromToc($global);
 $check('b001_real_reader_exact_cooked_counts',
  $decoded['present']===true&&$decoded['count']===54968
  &&count($decoded['objects'])===54968);
 $check('b001_real_reader_first_names_and_source_identity',
  ($decoded['objects']['567E29465232A0A9']['name']??'')==='GetRecipes'
  &&($decoded['objects']['5E2F3730B5C2A376']['name']??'')==='AbstractInstanceDataObject');
 $check('b001_real_reader_never_claims_live_registration',
  $decoded['runtime_authoritative']===false
  &&($decoded['objects']['567E29465232A0A9']['runtime_resolved']??true)===false);
 $reader=new Binary($raw,'B001 real global cooked ScriptObjects');
 $names=Zen::readNameBatchForScriptObjects($reader);$count=$reader->u32le();
 $types=[];$invalid=0;$unique=[];$duplicate=0;
 for($i=0;$i<$count;$i++){
  $mapped=Binary::decodeMappedNameIndex($reader->u32le());$reader->u32le();
  $type=(int)$mapped['type'];$types[$type]=($types[$type]??0)+1;
  if(!isset($names[$mapped['index']]))$invalid++;
  $key=$reader->u64HexLe();$reader->u64HexLe();$reader->u64HexLe();
  if(isset($unique[$key]))$duplicate++;
  $unique[$key]=true;
 }
 $check('b001_real_global_name_map_exact_size',count($names)===50640);
 $check('b001_real_all_54968_names_are_global_type_2',$types===[2=>54968]);
 $check('b001_real_name_indices_in_range_no_duplicate', $invalid===0&&$duplicate===0);
 $check('b001_real_chunk_reader_consumed_exactly_source_records',
  $reader->remaining()===0 && $count===54968);
 $root=dirname(__DIR__,2);
 $cmd='git -c '.escapeshellarg('safe.directory='.$root).' -C '.escapeshellarg($root)
  .' show '.escapeshellarg('a3d159a3:catalog/src/Infrastructure/Metadata/Uedb5ZenScriptObjectsReader.php');
 $pipe=[];$proc=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipe);
 $code='';
 if(is_resource($proc)){
  $code=stream_get_contents($pipe[1]);fclose($pipe[1]);
  $stderr=stream_get_contents($pipe[2]);fclose($pipe[2]);$status=proc_close($proc);
 }else{$status=127;}
 $check('b001_real_historical_reader_pinned',$status===0&&str_contains($code,"(int)$"."mapped['type'] !== 1"));
 if($status===0){
  $code=str_replace('final class Uedb5ZenScriptObjectsReader','final class Uedb5ZenScriptObjectsReaderBeforeRealB001',$code);
  $temp=sys_get_temp_dir().'/b001-authentic-baseline-'.bin2hex(random_bytes(5)).'.php';
  file_put_contents($temp,$code);try{require $temp;}finally{@unlink($temp);}
 }
 $oldName='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5ZenScriptObjectsReaderBeforeRealB001';
 $originalRejected=false;
 if(class_exists($oldName)){
  try{$oldName::parse($raw);}catch(Throwable $e){$originalRejected=str_contains($e->getMessage(),'global mapped-name index');}
 }
 $check('b001_real_historical_pre_fix_fails_on_actual_type_2',$originalRejected);
 $ordinary=new Toc($negative);$noScript=Reader::fromToc($ordinary);
 $check('b001_real_package_toc_without_script_chunk_is_absent',
  $noScript['present']===false && $noScript['count']===0
  &&$noScript['runtime_authoritative']===false);
}catch(Throwable $e){
 $failures[]='b001_real_fixture_exception';
 $error=$e->getMessage();
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures,
 'authentic_source'=>'Installed Satisfactory dedicated server UE5 cooked global.utoc + global.ucas',
 'fixture_path'=>$path,'types'=>$types??[],'error'=>$error??null,
 'live_epic_engine_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
