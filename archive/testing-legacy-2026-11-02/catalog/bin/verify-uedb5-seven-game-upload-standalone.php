<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/CatalogScanner.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry;
$iso=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$iso->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$identity['port']!==3339||strtolower(str_replace(chr(92),'/',$identity['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe database target');
$prod=catalog_bootstrap(false)->db;
$config=catalog_config();
$originalStorage=(string)$config['storage_path'];
$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['queue']['name']='uedb5-seven-game-'.bin2hex(random_bytes(4));
$copy=static function(string $table,int $id)use($prod,$iso):void{
 $stmt=$prod->prepare('SELECT * FROM `'.$table.'` WHERE id=?');
 $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('Missing '.$table.' #'.$id);
 $cols=$iso->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND EXTRA LIKE ?');
 $cols->execute([$table,'%GENERATED%']);$skip=array_fill_keys($cols->fetchAll(PDO::FETCH_COLUMN),true);
 $row=array_diff_key($row,$skip);
 $keys=array_keys($row);$sql='INSERT INTO `'.$table.'` ('.implode(',',array_map(static fn($k)=>'`'.$k.'`',$keys)).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).') ON DUPLICATE KEY UPDATE id=id';
 $iso->prepare($sql)->execute(array_values($row));
};
$out=[];$failed=[];
foreach([2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902] as $game=>$sourceId){
 try{
  $q=$prod->prepare('SELECT f.*,g.slug,g.profile_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=? AND f.game_id=? AND f.scan_status="verified"');
  $q->execute([$sourceId,$game]);$row=$q->fetch();
  if(!$row)throw new RuntimeException('Missing verified fixture');
  if((int)$row['profile_id']>0)$copy('ue_game_profiles',(int)$row['profile_id']);
  $copy('ue_games',$game);
  $src=rtrim($originalStorage,'\\/').'/games/'.Uedb5GameSourceRegistry::storageKey($game).'/verified/'.$row['stored_name'];
  if(!is_file($src)||filesize($src)!==(int)$row['file_size']||md5_file($src)!==strtolower((string)$row['md5']))throw new RuntimeException('Source mismatch');
  $input=$config['storage_path'].'/incoming';
  if(!is_dir($input))mkdir($input,0700,true);
  $temp=$input.'/seven-'.bin2hex(random_bytes(8)).'.'.pathinfo((string)$row['stored_name'],PATHINFO_EXTENSION);
  if(!copy($src,$temp))throw new RuntimeException('Cannot copy source');
  try{
   $r=scanner_scan_uploaded_file($iso,$config,$game,$temp,(string)$row['original_name'],null,true,null,false,['source_relative_path'=>'integration-seven/'.basename((string)$row['original_name']),'defer_dependency_rebuild'=>true]);
  }finally{if(is_file($temp))@unlink($temp);}
  $id=(int)($r[1]??0);
  if($id<1||!in_array((string)($r[0]??''),['verified','duplicate'],true))throw new RuntimeException('Scanner: '.json_encode($r));
  $s=$iso->prepare('SELECT scan_status,relative_path FROM ue_files WHERE id=?');$s->execute([$id]);$stored=$s->fetch();
  $v=(new Uedb5MetadataReader($config['storage_path']))->verify($game,$id);
  if((string)$stored['scan_status']!=='verified'||!str_starts_with((string)$stored['relative_path'],'storage/uedb5-isolated-reimport-20261015/'))throw new RuntimeException('Not isolated verified');
  $out[]=['game_id'=>$game,'source_file_id'=>$sourceId,'imported_file_id'=>$id,'status'=>$r[0],'v5_blocks'=>$v['block_count']??null];
 }catch(Throwable $e){$failed[]=['game_id'=>$game,'error'=>$e->getMessage()];}
}
$ok=count($out)===7&&$failed===[];
echo json_encode(['ok'=>$ok,'scope'=>'isolated actual scanner verified upload seven game source samples','passed'=>$out,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
