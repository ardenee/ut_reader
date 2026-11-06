#!/usr/bin/env php
<?php
/**
 * READ ONLY: exact original-file preflight for Unreal II's v60..69
 * December-2000 source loader; no V5 backups or writes.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(2); }
require_once dirname(__DIR__).'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue2VerifyImportSourceProfile;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader;

$options=getopt('',['db-host::','storage-root::','require-current','help']);
if(isset($options['help'])){echo "Usage: php catalog/bin/diagnose-ue2-unreal2-v69-legacy-source.php [--db-host=HOST] [--storage-root=PATH] [--require-current]\n";exit(0);}
$config=catalog_config();
if(isset($options['db-host']))$config['db']['host']=(string)$options['db-host'];
if(isset($options['storage-root']))$config['storage_path']=(string)$options['storage-root'];
$files=[
    149492=>['name'=>'Detail.utx','version'=>61,'licensee'=>0,'sha256'=>'42c79438778910108f46a40db91f0d1cb177555075da516031d2e46f00a7d837'],
    1295446=>['name'=>'ShaneChurch.utx','version'=>68,'licensee'=>0,'sha256'=>'315a191cad1ec6a2f747bd8f1be390f806804d65c2987e842e8ba5d129d9ef98'],
];
$db=catalog_db($config);$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
$query=$db->prepare('SELECT f.*,v.source_policy current_metadata_policy FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id WHERE f.id=?');
$out=['ok'=>true,'mode'=>'READ_ONLY','game_id'=>2,'files'=>[],'needs_resnapshot'=>0,'current'=>0];
try{
    foreach($files as $id=>$expected){
        $query->execute([$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||(int)$row['game_id']!==2||(string)$row['scan_status']!=='verified'
            ||(int)$row['package_version']!==$expected['version']||(int)$row['licensee_version']!==0
            ||(string)$row['original_name']!==$expected['name']){
            throw new RuntimeException('Verified source identity changed for file #'.$id);
        }
        $path=CatalogFileMaintenanceSupport::storagePath($config,$row);
        // DEV read-only source audit: the PRD original is mounted via UNC,
        // not under the DEV checkout's catalogue root. Never write to it.
        if(!is_file($path??'') && isset($options['storage-root'])){
            $storageRoot=realpath((string)$config['storage_path']);
            $candidate=$storageRoot!==false
                ? realpath(dirname($storageRoot).DIRECTORY_SEPARATOR.str_replace('/','\\',(string)$row['relative_path']))
                : false;
            $path=$candidate!==false && str_starts_with($candidate,rtrim((string)$storageRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                ? $candidate : null;
        }
        if(!is_file($path??'')){
            throw new RuntimeException('Original physical file is not accessible for file #'.$id);
        }
        $sha=(string)hash_file('sha256',$path);
        if(!hash_equals($expected['sha256'],$sha)){
            throw new RuntimeException('Original SHA-256 mismatch for file #'.$id);
        }
        $reader=new CatalogUE2PackageReader($path);
        $issues=$reader->validatePackage();
        if($issues!==[])throw new RuntimeException('Reader rejected original file #'.$id.': '.implode('; ',$issues));
        $new=Uedb5Unreal2SnapshotBuilder::build($reader,$row);
        if((string)$new['source_policy']!==Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000
            ||Uedb5Ue2VerifyImportSourceProfile::fromSnapshot($new)!=='ue2-unreal2-2000-v69'){
            throw new RuntimeException('New source profile is not the December 2000 loader for file #'.$id);
        }
        $isCurrent=hash_equals((string)$new['source_policy'],(string)($row['current_metadata_policy']??''));
        $out['files'][]=['id'=>$id,'name'=>$expected['name'],'source_package_version'=>$expected['version'],
            'source_licensee_version'=>0,'file_sha256'=>$sha,
            'stored_source_policy'=>(string)($row['current_metadata_policy']??''),
            'expected_source_policy'=>(string)$new['source_policy'],
            'source_specific_verify_import'=>'ue2-unreal2-2000-v69',
            'names'=>count((array)$new['sections']['names']),
            'imports'=>count((array)$new['sections']['imports']),
            'exports'=>count((array)$new['sections']['exports']),
            'status'=>$isCurrent?'current':'needs_resnapshot'];
        $out[$isCurrent?'current':'needs_resnapshot']++;
    }
    $out['dependency_published_current']=\UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::isGameCurrent($db,2,'UE2');
    $out['dashboard_stats_current']=\UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::isGameStatsCurrent($db,2,'UE2');
    $db->rollBack();
    if(isset($options['require-current']) && ($out['needs_resnapshot']!==0
        || !$out['dependency_published_current'] || !$out['dashboard_stats_current']))$out['ok']=false;
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($out['ok']?0:2);
}catch(Throwable $error){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
