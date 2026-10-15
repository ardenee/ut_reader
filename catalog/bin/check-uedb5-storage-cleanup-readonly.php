<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeMetadataReader;
$db=catalog_bootstrap(false)->db;
$jobs=(int)$db->query('SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"')->fetchColumn();
$reader=new Uedb5RuntimeMetadataReader($db,(string)catalog_config()['storage_path']);
$checked=[];
foreach([1293497,1362805] as $id){$st=$db->prepare('SELECT id,game_id,scan_status FROM ue_files WHERE id=?');$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);$verified=false;$error=null;try{$reader->verify($id);$verified=true;}catch(Throwable $e){$error=$e->getMessage();}$checked[]=['file'=>$row,'v5_verified'=>$verified,'error'=>$error];}
echo json_encode(['read_only'=>true,'running_jobs'=>$jobs,'old_container_v5_coverage'=>$checked],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
