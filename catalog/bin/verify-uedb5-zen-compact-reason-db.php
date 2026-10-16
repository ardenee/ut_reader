<?php
/**
 * DB-backed D001 regression: source-shaped UEDB5 dependency reasons survive
 * the public compact read facade. Uses only disposable SQLite + temp files.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactDependencyReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$checks=[]; $failures=[];
$check=static function(string $name,bool $ok) use (&$checks,&$failures):void {
    $checks[$name]=$ok;
    if(!$ok){$failures[]=$name;}
};
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-zen-reason-'.bin2hex(random_bytes(6));
if(!mkdir($storage,0770,true)){throw new RuntimeException('Could not create isolated fixture directory.');}
$cleanup=static function(string $path) use (&$cleanup):void {
    if(!is_dir($path)){return;}
    foreach(scandir($path)?:[] as $entry){
        if($entry==='.'||$entry==='..'){continue;}
        $child=$path.DIRECTORY_SEPARATOR.$entry;
        if(is_dir($child)){$cleanup($child);}else{@unlink($child);}
    }
    @rmdir($path);
};
try {
    $db=class_exists(\Pdo\Sqlite::class)
        ? \Pdo\Sqlite::connect('sqlite::memory:')
        : new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    if($db instanceof \Pdo\Sqlite){
        $db->createFunction('DATABASE',static fn():string=>'audit_fixture',0);
    }else{
        $db->sqliteCreateFunction('DATABASE',static fn():string=>'audit_fixture',0);
    }
    // Match the production availability check without contacting MySQL.
    $db->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $db->exec('CREATE TABLE information_schema.TABLES(TABLE_SCHEMA TEXT,TABLE_NAME TEXT)');
    $db->exec("INSERT INTO information_schema.TABLES VALUES
        ('audit_fixture','ue_uedb5_files'),('audit_fixture','ue_uedb5_dependency_edges')");
    $db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
    $db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER,game_id INTEGER,format_version INTEGER)');
    $db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER)');

    $reasons=['export_filter_runtime_state_required','export_filtered_for_runtime_build'];
    $deps=[];
    foreach($reasons as $index=>$reason){
        $deps[]=[
            'dependency_kind'=>'PackageImport',
            'source_section'=>'imports','source_index'=>$index,
            'dependency_class'=>'hard','hard'=>true,'outcome'=>'unresolved',
            'reason_code'=>$reason,
            'required_package_id'=>'1020304050607080',
            'required_object_identity'=>$index===0?'1122334455667788':'9988776655443322',
        ];
    }
    $gameId=8; $fileId=9900081;
    $snapshot=[
        'file'=>['id'=>$fileId,'game_id'=>$gameId,'package_name'=>'/Game/AuditConsumer'],
        'package_family'=>Uedb5ZenPackageReader::PACKAGE_FAMILY,
        'source_policy'=>Uedb5ZenPackageReader::SOURCE_POLICY,
        'sections'=>['dependency_results'=>$deps],
    ];
    (new Uedb5MetadataSnapshotWriter($storage))->write($snapshot);
    $db->prepare('INSERT INTO ue_files VALUES(?, ?, ?)')->execute([$fileId,$gameId,'verified']);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?, ?, ?)')->execute([
        $fileId,$gameId,Uedb5MetadataContainer::FORMAT_VERSION
    ]);
    $parity=(new Uedb5ParityV5ReadService($db,['storage_path'=>$storage]))
        ->dependencies($gameId,$fileId);
    $facade=new CatalogCompactDependencyReadService($db,['storage_path'=>$storage]);
    $compact=$facade->compactRows($fileId);
    $alias=$facade->rows($fileId);
    $check('source_shaped_reader_retains_two_reasons',
        array_column($parity,'reason_code')===$reasons);
    $check('compact_facade_preserves_distinct_reasons',
        array_column($compact,'reason_code')===$reasons);
    $check('compact_alias_preserves_distinct_reasons',
        array_column($alias,'reason_code')===$reasons);
    $check('both_appear_as_unresolved_without_collapsing',
        array_column($compact,'status')===['unresolved','unresolved']
        && array_column($compact,'import_index')===[0,1]);
    $check('compact_and_parity_use_same_file_identity',
        count($compact)===2 && count($parity)===2
        && array_column($compact,'file_id')===[$fileId,$fileId]);
} finally {
    $cleanup($storage);
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
