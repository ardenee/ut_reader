#!/usr/bin/env php
<?php
/**
 * Execute V4 PdoDependencyResolver against real MySQL/PDO, using a
 * session-temp shadow of ue_files. Original Epic UT99 v400 VerifyImport
 * rejects roots other than Core.Package, regardless of is_common
 * and even when a provider exists under that package name.
 * Permanent SQL records are never changed.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;
$checks=[];$bad=[];
$check=static function(string $n,bool $pass)use(&$checks,&$bad):void {
    $checks[$n]=$pass;
    if(!$pass){$bad[]=$n;}
};
$app=catalog_bootstrap(false);
$db=$app->db;
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') {
    throw new RuntimeException('This test requires real MariaDB/MySQL PDO.');
}
$db->exec('CREATE TEMPORARY TABLE ue_files (id BIGINT PRIMARY KEY, game_id INT, package_name VARCHAR(255), package_version INT, licensee_version INT, scan_status VARCHAR(32), uploaded_at DATETIME, file_size BIGINT, md5 VARCHAR(40), sha1 VARCHAR(64))');
try {
    $consumerId=981101;
    $providerId=981102;
    $put=$db->prepare('INSERT INTO ue_files (id,game_id,package_name,package_version,licensee_version,scan_status,uploaded_at,file_size,md5,sha1) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $put->execute([$consumerId,3,'Consumer',68,0,'verified','2026-10-21 01:00:00',234,'aaa','aaa']);
    $put->execute([$providerId,3,'BadPkg',68,0,'verified','2026-10-21 01:00:00',234,'bbb','bbb']);
    $wrongRoot=[
        ['id'=>1901,'import_index'=>0,'class_package'=>'Engine','class_name'=>'Package',
            'object_name'=>'BadPkg','outer_index'=>0,'is_common'=>1],
        ['id'=>1902,'import_index'=>1,'class_package'=>'Engine','class_name'=>'Texture',
            'object_name'=>'Child','outer_index'=>-1,'is_common'=>1],
        ['id'=>1903,'import_index'=>2,'class_package'=>'Core','class_name'=>'Package',
            'object_name'=>'GoodPkg','outer_index'=>0,'is_common'=>1],
        ['id'=>1904,'import_index'=>3,'class_package'=>'Engine','class_name'=>'Texture',
            'object_name'=>'GoodChild','outer_index'=>-3,'is_common'=>1],
    ];
    $resolved=PdoDependencyResolver::resolve($db,3,$consumerId,$wrongRoot);
    $check('invalid_root_not_common',($resolved[1901]['status']??null)==='unresolved'
        && ($resolved[1901]['source']??null)==='ue1_invalid_root_package_import'
        && ($resolved[1901]['confidence']??null)==='source_invalid');
    $check('invalid_ancestor_not_common',($resolved[1902]['status']??null)==='unresolved'
        && ($resolved[1902]['source']??null)==='ue1_invalid_root_package_import');
    $check('valid_root_common_preserved',($resolved[1903]['status']??null)==='common');
    $check('valid_child_common_preserved',($resolved[1904]['status']??null)==='common');

    $wrongRoot[0]['is_common']=0;
    $noCommon=PdoDependencyResolver::resolve($db,3,$consumerId,[$wrongRoot[0]]);
    $check('provider_exists_but_root_not_package_only',
        ($noCommon[1901]['status']??null)==='unresolved'
        && ($noCommon[1901]['resolved_file_id']??null)===null
        && ($noCommon[1901]['source']??null)==='ue1_invalid_root_package_import');

    $db->prepare('UPDATE ue_files SET package_version=49 WHERE id=?')->execute([$consumerId]);
    $legacy=[
        ['id'=>1905,'import_index'=>0,'class_package'=>'Engine','class_name'=>'Texture',
            'object_name'=>'LegacyObject','outer_index'=>0,'object_package_present'=>true,
            'object_package'=>'BadPkg','is_common'=>1],
    ];
    $old=PdoDependencyResolver::resolve($db,3,$consumerId,$legacy);
    $check('pre50_old_object_package_not_marked_invalid',
        ($old[1905]['status']??null)==='common'
        && ($old[1905]['source']??null)==='common_script');

    $check('all_rows_are_session_temporary',
        (int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===2);
} finally {
    $db->exec('DROP TEMPORARY TABLE IF EXISTS ue_files');
}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
