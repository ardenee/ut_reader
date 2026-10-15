<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogIoStoreMemberReimportService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||stripos($id['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Unsafe MySQL');
$game=$db->query('SELECT profile_id FROM ue_games WHERE id=2')->fetchColumn();
$q=$db->query('SELECT relative_path,md5 FROM ue_files WHERE id=111239')->fetch();
$root='C:/Temp/uedb5-iostore-retention-test';
$source=$root.'/'.substr($q['relative_path'],strlen('storage/'));
$away=$source.'.isolated-test-hold';
if(!is_file($source)||is_file($away))throw new RuntimeException('Unsafe missing-file fixture');
if(!rename($source,$away))throw new RuntimeException('Cannot stage missing-file simulation');
try{
 $db->exec('UPDATE ue_games SET profile_id=9001 WHERE id=2');
 $result=(new CatalogIoStoreMemberReimportService($db,$root))->reimport(111239);
 $ok=is_file($source)&&md5_file($source)===$q['md5']&&(int)$result['file_id']===111239;
}finally{
 $db->prepare('UPDATE ue_games SET profile_id=? WHERE id=2')->execute([$game]);
 if(is_file($away)&&!is_file($source))rename($away,$source);
 if(is_file($away)&&is_file($source))unlink($away);
}
echo json_encode(['ok'=>$ok,'isolated'=>true,'source_repaired_from_retained_iostore'=>true],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
