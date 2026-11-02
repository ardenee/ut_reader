<?php
declare(strict_types=1);
/** Bounded real V5 package/provider fixture in separate standalone MySQL + storage. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
$iso=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$iso->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||strtolower(str_replace('\\','/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe MySQL target');
$source=catalog_bootstrap(false)->db;
$config=catalog_config();$inputRoot=(string)$config['storage_path'];
$outRoot='C:/Temp/uedb5-integration-storage';
if(!is_dir($outRoot))mkdir($outRoot,0700,true);
$copy=static function(string $table,string $where,array $params=[])use($source,$iso):int{
 $q=$source->prepare('SELECT * FROM `'.$table.'` WHERE '.$where);
 $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $generated=$iso->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND EXTRA LIKE ?');
 $generated->execute([$table,'%GENERATED%']);
 $skip=array_fill_keys($generated->fetchAll(PDO::FETCH_COLUMN),true);
 foreach($rows as $row){
  $row=array_diff_key($row,$skip);
  $fields=array_keys($row);
  $cols=implode(',',array_map(static fn($c)=>'`'.$c.'`',$fields));
  $sql='REPLACE INTO `'.$table.'` ('.$cols.') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')';
  $iso->prepare($sql)->execute(array_values($row));
 }
 return count($rows);
};
$gameId=2;$fileIds=[111139,111194,111142,111141];
$game=$source->query('SELECT * FROM ue_games WHERE id=2')->fetch(PDO::FETCH_ASSOC);
if(!$game)throw new RuntimeException('Missing sample game');
if((int)($game['profile_id']??0)>0)$copy('ue_game_profiles','id=?',[(int)$game['profile_id']]);
$copy('ue_games','id=?',[$gameId]);
$counts=[];$copied=[];
foreach($fileIds as $fileId){
 $counts[$fileId]=[];
 foreach(['ue_files','ue_file_locations','ue_uedb5_files','ue_uedb5_provider_keys','ue_uedb5_migration_status','ue_file_package_aliases'] as $table){
  $where= $table==='ue_files'?'id=?':'file_id=?';
  $counts[$fileId][$table]=$copy($table,$where,[$fileId]);
 }
 $from=Uedb5MetadataContainer::path($inputRoot,$gameId,$fileId);
 $to=Uedb5MetadataContainer::path($outRoot,$gameId,$fileId);
 if(!is_file($from))throw new RuntimeException('Source V5 missing '.$fileId);
 if(!is_dir(dirname($to)))mkdir(dirname($to),0700,true);
 if(!copy($from,$to)||hash_file('sha256',$from)!==hash_file('sha256',$to))
   throw new RuntimeException('Disposable V5 copy failed '.$fileId);
 $copied[]=$fileId;
}
echo json_encode(['ok'=>count($copied)===count($fileIds),'isolated_storage'=>$outRoot,'files'=>$copied,'rows'=>$counts],JSON_PRETTY_PRINT),PHP_EOL;
