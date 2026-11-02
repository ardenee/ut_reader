<?php
declare(strict_types=1);
/**
 * Full real Satisfactory published-V5/Pass-2 validation, isolated DEV DB only.
 * Never connects to PRD or imports source fixtures into production.
 */
require dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;

$root = getenv('UE583_ISOLATED_IOSTORE_STORAGE') ?: 'C:/Temp/uedb5-iostore-retention-test';
$expected = (int)(getenv('UE583_ISOLATED_PACKAGE_COUNT') ?: 27240);
$db = new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4',
    'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT @@port port, @@datadir datadir')->fetch();
if((int)$identity['port']!==3339 ||
    !str_contains(strtolower(str_replace(chr(92),'/',(string)$identity['datadir'])),'uedb5-isolated-mysql/data')) {
    throw new RuntimeException('Refusing any non-isolated MySQL target.');
}
$summary=$db->query('SELECT COUNT(*) total, SUM(f.scan_status="verified") verified,'
    . ' SUM(v.file_id IS NOT NULL) v5, SUM(s.dependency_policy="'
    . Uedb5GameDependencyPassService::DEPENDENCY_POLICY
    . '" AND s.dependency_payload_sha256=v.payload_sha256) pass2 '
    . 'FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
    . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
    . 'WHERE f.game_id=2')->fetch();
echo json_encode(['stage'=>'inventory','counts'=>$summary]),PHP_EOL;
if((int)$summary['total']!==$expected || (int)$summary['verified']!==$expected ||
    (int)$summary['v5']!==$expected || (int)$summary['pass2']!==$expected) {
    fwrite(STDERR, 'Pass 2 is not complete; full validation has not started.' . PHP_EOL);
    exit(4);
}
$reader=new Uedb5MetadataReader($root);
$validator=new Uedb5MigrationValidator($db,['storage_path'=>$root]);
$files=$db->query('SELECT id FROM ue_files WHERE game_id=2 ORDER BY id');
$checked=$ready=$failed=0;$failures=[];
while($row=$files->fetch()){
 $id=(int)$row['id'];$checked++;
 try{
  $v5=$reader->snapshot(2,$id);
  $out=$validator->validate($id,$v5);
  if(($out['ready']??false)===true){$ready++;}else{
   $failed++;if(count($failures)<30)$failures[]=['file_id'=>$id,'error'=>'not ready','result'=>$out];
  }
 }catch(Throwable $e){$failed++;if(count($failures)<30)$failures[]=['file_id'=>$id,'error'=>$e->getMessage()];}
 if(($checked%1000)===0){echo json_encode(['stage'=>'progress','checked'=>$checked,'ready'=>$ready,'failed'=>$failed]),PHP_EOL;flush();}
}
$ok=$checked===$expected && $ready===$expected && $failed===0;
echo json_encode(['stage'=>'final','ok'=>$ok,'checked'=>$checked,'ready'=>$ready,'failed'=>$failed,'failures'=>$failures],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
