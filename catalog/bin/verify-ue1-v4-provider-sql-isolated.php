#!/usr/bin/env php
<?php
/**
 * End-to-end V4 SQL provider resolution using an isolated real UEDB5 binary.
 * Real MySQL session-temporary table shadowing; no durable production writes.
 * Native basis: UT99 v400 Core/Src/UnLinker.h VerifyImport and RF_Public.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
$app=catalog_bootstrap(false);$db=$app->db;
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){throw new RuntimeException('MySQL needed');}
$root=sys_get_temp_dir().'/uedb-ue1-o05-sql-'.bin2hex(random_bytes(6));
if(!mkdir($root,0775,true)){throw new RuntimeException('Cannot allocate isolated UEDB5 storage');}
$checks=[];$failed=[];
$check=static function(string $n,bool $pass)use(&$checks,&$failed):void{
    $checks[$n]=$pass;if(!$pass){$failed[]=$n;}
};
$providerId=981902;$consumerId=981901;$gameId=3;
$importRows=[
 ['id'=>2900,'import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'O05Provider','outer_index'=>0],
 ['id'=>2901,'import_index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'Parent','outer_index'=>-1],
 ['id'=>2902,'import_index'=>2,'class_package'=>'Core','class_name'=>'Class','object_name'=>'Child','outer_index'=>-2],
 ['id'=>2903,'import_index'=>3,'class_package'=>'Core','class_name'=>'Class','object_name'=>'Accepted','outer_index'=>-1],
 ['id'=>2904,'import_index'=>4,'class_package'=>'Core','class_name'=>'Class','object_name'=>'NotFound','outer_index'=>-1],
];
$fname=static fn(string $s):array=>['name_index'=>0,'number'=>0,'text'=>$s];
$export=static function(int $index,string $name,string $flags)use($fname):array {
    return ['index'=>$index,'class_index'=>0,'super_index'=>0,'outer_index'=>0,
        'object_name'=>$fname($name),'object_flags'=>$flags];
};
$snapshot=[
 'file'=>['id'=>$providerId,'game_id'=>$gameId,'package_name'=>'O05Provider',
          'original_name'=>'O05Provider.u','package_version'=>68,'licensee_version'=>0],
 'package_family'=>'classic-linkerload',
 'source_policy'=>'ue1-ut99-retail-v1400-1999-11-30',
 'section_schemas'=>['imports'=>'ue1.ut99.object-import.v1','exports'=>'ue1.ut99.object-export.v1',
                     'summary'=>'ue1.ut99.package-summary.v1'],
 'sections'=>['summary'=>[['package_version'=>68,'licensee_version'=>0]],
              'imports'=>[],'exports'=>[
                  $export(0,'Parent','00000000'),
                  $export(1,'Child','00000004'),
                  $export(2,'Accepted','00000004'),
              ]],
];
$db->exec('CREATE TEMPORARY TABLE ue_files (id BIGINT PRIMARY KEY, game_id INT, package_name VARCHAR(255), package_version INT, licensee_version INT, scan_status VARCHAR(32), uploaded_at DATETIME, file_size BIGINT, md5 VARCHAR(40), sha1 VARCHAR(64))');
$db->exec('CREATE TEMPORARY TABLE ue_uedb5_files (file_id BIGINT PRIMARY KEY,game_id INT,format_version INT)');
try{
    $put=$db->prepare('INSERT INTO ue_files(id,game_id,package_name,package_version,licensee_version,scan_status,uploaded_at,file_size,md5,sha1) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach([[$consumerId,'O05Consumer','aaa'],[$providerId,'O05Provider','bbb']] as [$id,$name,$fingerprint]){
        $put->execute([$id,$gameId,$name,68,0,'verified','2026-10-21 09:00:00',123,$fingerprint,$fingerprint]);
    }
    $db->prepare('INSERT INTO ue_uedb5_files(file_id,game_id,format_version) VALUES (?,?,5)')
        ->execute([$providerId,$gameId]);
    $writer=new Uedb5MetadataSnapshotWriter($root);
    $written=$writer->write($snapshot,1024);
    $check('isolated_v5_binary_written',is_file($written['path'])
        && str_starts_with(str_replace('\\','/',$written['path']),str_replace('\\','/',$root)));
    $results=Resolver::resolve($db,$gameId,$consumerId,$importRows,$root);
    $check('root_only_when_provider_exists',($results[2900]['status']??null)==='package_only');
    $check('private_export_source_rejected',
        ($results[2901]['status']??null)==='missing'
        && ($results[2901]['source']??null)==='ue1_verify_import_private_export');
    $check('private_parent_descendant_unresolved',
        ($results[2902]['status']??null)!=='resolved'
        && ($results[2902]['resolved_export_index']??null)===null);
    $check('genuine_v4_sql_resolved_public_export',
        ($results[2903]['status']??null)==='resolved'
        && ($results[2903]['resolved_file_id']??null)===$providerId
        && ($results[2903]['resolved_export_index']??null)===2);
    $check('missing_runtime_cannot_be_falsely_failed',
        ($results[2904]['status']??null)==='unresolved'
        && ($results[2904]['confidence']??null)==='runtime_unavailable');
    $check('valid_sql_class_name_mismatch_runtime_only',
        (static function()use($db,$gameId,$consumerId,$root,$importRows):bool{
            $wrong=$importRows;
            $wrong[3]['class_name']='DefinitelyNotEpicClass';
            $result=Resolver::resolve($db,$gameId,$consumerId,$wrong,$root);
            return ($result[2903]['status']??null)==='unresolved';
        })());
    $check('temporary_table_registry_isolated',
        (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_files')->fetchColumn()===1
        && (int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===2);
}finally{
    $db->exec('DROP TEMPORARY TABLE IF EXISTS ue_uedb5_files');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS ue_files');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
    @rmdir($root);
}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
