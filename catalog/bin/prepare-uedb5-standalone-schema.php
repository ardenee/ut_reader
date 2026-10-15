<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
$target=new PDO('mysql:host=127.0.0.1;port=3339','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=$target->query('SELECT @@port port,@@datadir datadir')->fetch(PDO::FETCH_ASSOC);
if((int)$id['port']!==3339||strtolower(str_replace('\\','/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Target is not standalone');
$target->exec('CREATE DATABASE IF NOT EXISTS uedb5_isolated_integration');
$target->exec('USE uedb5_isolated_integration');
$prod=catalog_bootstrap(false)->db;
$rows=$prod->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
$created=0;$fail=[];
foreach($rows as $r){
 $name=(string)$r[0];if(strtoupper((string)$r[1])!=='BASE TABLE'||!preg_match('/^[a-zA-Z0-9_]+$/',$name))continue;
 $exists=$target->query("SHOW TABLES LIKE '".$name."'")->fetchColumn();if($exists!==false)continue;
 try{$ddl=$prod->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
 $ddl=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$ddl,1);
 $ddl=preg_replace('/^\\s*,?\\s*CONSTRAINT[^\\n]*(?:\\n|$)/mi','',$ddl);
 $ddl=preg_replace('/,\\s*\\) ENGINE=/',') ENGINE=',$ddl);
 $ddl=preg_replace('/ AUTO_INCREMENT=\\d+/',' AUTO_INCREMENT=1',$ddl);
 $target->exec($ddl);$created++;
 }catch(Throwable $e){$fail[]=['table'=>$name,'error'=>$e->getMessage()];}
}
echo json_encode(['created'=>$created,'failures'=>$fail],JSON_PRETTY_PRINT),PHP_EOL;
exit($fail===[]?0:2);
