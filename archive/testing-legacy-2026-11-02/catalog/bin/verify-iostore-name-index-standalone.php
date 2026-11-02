<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStorePackageNameIndexer;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong MySQL');
$indexer=new CatalogIoStorePackageNameIndexer($db,'C:/Temp/uedb5-iostore-retention-test','C:/php8.5/ext/oo2core_9_win64.dll');
$first=$indexer->indexBatch(1,25);
$second=$indexer->indexBatch(1,25);
$q=$db->prepare('SELECT toc_index,package_name,name_count,import_count,export_count,dependency_count,metadata_status FROM ue_iostore_packages WHERE container_id=1 ORDER BY toc_index LIMIT 5');
$q->execute();$sample=$q->fetchAll();
$ready=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=1 AND metadata_status="ready"')->fetchColumn();
$failed=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=1 AND metadata_status="failed"')->fetchColumn();
$ok=$first['processed']===25&&$second['processed']===25&&$ready>=50&&$failed===0;
foreach($sample as $row)$ok=$ok&&$row['metadata_status']==='ready'&&str_starts_with($row['package_name'],'/');
echo json_encode(['ok'=>$ok,'isolated'=>true,'first'=>$first,'second'=>$second,'ready'=>$ready,'failed'=>$failed,'sample'=>$sample],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
