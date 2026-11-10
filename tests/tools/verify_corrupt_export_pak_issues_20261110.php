<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogCorruptSourceExportQuery;
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_background_jobs (
    id INTEGER PRIMARY KEY,queue_name TEXT,job_type TEXT,parent_job_id INTEGER,
    status TEXT,display_status TEXT,payload_json TEXT,progress_json TEXT,
    result_json TEXT,last_error TEXT)');
$db->exec('CREATE TABLE ue_system_errors (
    id INTEGER PRIMARY KEY,status TEXT,source_kind TEXT,error_type TEXT,
    message TEXT,context_json TEXT)');
$insert=$db->prepare('INSERT INTO ue_background_jobs
    (id,queue_name,job_type,parent_job_id,status,display_status,payload_json,
     progress_json,result_json,last_error)
    VALUES(?,?,?,?,?,?,?,?,?,?)');
$cases = [
    [9529799,'empty.pak','PAK file is too small.'],
    [9528900,'DM-Goop!.pak','Unsupported PAK file: no Unreal PAK footer was found.'],
    [9528811,'transient.pak','Temporary database connection timeout.'],
];
foreach($cases as [$id,$file,$reason]){
    $insert->execute([$id,'catalog','catalog.import_staged_pak',0,'completed','failed',
        json_encode(['original_name'=>$file,'source_relative_path'=>'UT3/'.$file]),
        json_encode(['message'=>$reason]),
        json_encode(['status'=>'failed','message'=>$reason]),null]);
}
$r=(new CatalogCorruptSourceExportQuery($db, ['storage_path'=>sys_get_temp_dir()]))->fetch('catalog');
$ids=array_map(static fn(array $row):int=>(int)$row['job_id'], $r['rows']);
sort($ids);
if($ids !== [9528900,9529799] || $r['scanned']!==3 || $r['exported']!==2){
    throw new RuntimeException('PAK issue export mismatch: '.json_encode($r));
}
foreach($r['rows'] as $row){
    if (!in_array($row['classification'],['invalid_pak_container','unsupported_pak_format','misrouted_unreal_package'],true))
       throw new RuntimeException('Incorrect issue classification: '.json_encode($row));
}
echo json_encode(['pass'=>true,'scanned'=>$r['scanned'],'exported'=>$r['exported'],
    'job_ids'=>$ids,'transient_failure_excluded'=>true,'db_writes'=>'in-memory only'],JSON_PRETTY_PRINT).PHP_EOL;
