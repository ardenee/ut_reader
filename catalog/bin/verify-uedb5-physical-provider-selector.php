#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5PhysicalProviderSelector;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-provider-selector-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT,uploaded_at TEXT,file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec('CREATE TABLE ue_invalid_file_identities(file_size INTEGER,md5 TEXT,sha1 TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT)');
$db->exec('CREATE TABLE ue_uedb5_provider_keys(source_kind INTEGER,source_id INTEGER,game_id INTEGER,package_key_kind INTEGER,package_key BLOB,file_id INTEGER)');

$fname=static fn(string $text):array=>['text'=>$text];
$snapshot=static function(int $fileId,string $packageName,array $imports,array $exports)use($fname):array{
    foreach($imports as &$row){
        foreach(['class_package','class_name','object_name'] as $field){
            if(isset($row[$field])&&!is_array($row[$field]))$row[$field]=$fname((string)$row[$field]);
        }
    } unset($row);
    foreach($exports as &$row){
        if(isset($row['object_name'])&&!is_array($row['object_name']))$row['object_name']=$fname((string)$row['object_name']);
    } unset($row);
    return [
        'file'=>['id'=>$fileId,'game_id'=>3,'package_name'=>$packageName,'original_name'=>$packageName.'.u'],
        'package_family'=>'classic-linkerload','source_policy'=>'test-ue1-provider-selection',
        'section_schemas'=>[
            'summary'=>'ue1.ut99.package-summary.v1','names'=>'ue1.ut99.name-entry.v1',
            'imports'=>'ue1.ut99.object-import.v1','exports'=>'ue1.ut99.object-export.v1',
        ],
        'sections'=>[
            'summary'=>[['package_version'=>69]],'names'=>[],
            'imports'=>array_values($imports),'exports'=>array_values($exports),
        ],
    ];
};
$consumerImports=[
    ['index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Pkg','outer_index'=>0],
    ['index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'A','outer_index'=>-1],
    ['index'=>2,'class_package'=>'Core','class_name'=>'Class','object_name'=>'B','outer_index'=>-1],
];
$public=0x00000004;
$export=static fn(int $index,string $name):array=>[
    'index'=>$index,'class_index'=>0,'super_index'=>0,'outer_index'=>0,
    'object_name'=>$name,'object_flags'=>$public,
];
$writer->write($snapshot(10,'Consumer',$consumerImports,[]));
$writer->write($snapshot(20,'Pkg',[],[$export(0,'A')]));
$writer->write($snapshot(21,'Pkg',[],[$export(0,'B')]));
$writer->write($snapshot(22,'PkgVariant',[],[$export(0,'A'),$export(1,'B')]));
foreach([
    [10,'Consumer','2026-10-01 10:00:00'],[20,'Pkg','2026-10-02 12:00:00'],
    [21,'Pkg','2026-10-01 12:00:00'],[22,'PkgVariant','2026-09-30 12:00:00'],
] as [$id,$name,$uploaded]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,3,'verified',$uploaded,$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,3,$name]);
}
$classic=Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME;
$pkgKey=md5('pkg',true);
foreach([
    [1,20,20],[1,21,21],[2,500,22],
] as [$sourceKind,$sourceId,$fileId]){
    $db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')
        ->execute([$sourceKind,$sourceId,3,$classic,$pkgKey,$fileId]);
}
$selector=new PdoUedb5PhysicalProviderSelector($db,$tmp);
$selected=$selector->select(3,10);
$check('complete_alias_provider_beats_partial_primary_candidates',
    count($selected)===1 && (int)$selected[0]['file_id']===22);
$check('selected_alias_uses_required_lookup_package_identity',
    ($selected[0]['package_name']??'')==='Pkg');

$db->exec('DELETE FROM ue_uedb5_provider_keys WHERE file_id=22');
$selected=$selector->select(3,10);
$check('partial_duplicate_set_selects_one_physical_provider',
    count($selected)===1 && (int)$selected[0]['file_id']===20);
$check('candidate_order_breaks_equal_coverage_ties',
    (int)$selected[0]['file_id']===20);

$soloImports=[
    ['index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Solo','outer_index'=>0],
    ['index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'OnlyObject','outer_index'=>-1],
];
$writer->write($snapshot(11,'SoloConsumer',$soloImports,[]));
foreach([[11,'SoloConsumer'],[23,'Solo']] as [$id,$name]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,3,'verified','2026-10-02 13:30:00',$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,3,$name]);
}
$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')
    ->execute([1,23,3,$classic,md5('solo',true),23]);
$soloSelected=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(3,11);
$check('single_provider_is_selected_without_expensive_scoring',
    count($soloSelected)===1 && (int)$soloSelected[0]['file_id']===23);

$ut4=[
    'file'=>['id'=>30,'game_id'=>7,'package_name'=>'/Game/Empty','original_name'=>'Empty.uasset'],
    'package_family'=>Uedb5Ut4SnapshotBuilder::PACKAGE_FAMILY,
    'source_policy'=>Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,
    'section_schemas'=>[
        'summary'=>'ue4.ut4.package-summary.v1','names'=>'ue4.ut4.name-entry.v1',
        'imports'=>'ue4.ut4.object-import.v1','exports'=>'ue4.ut4.object-export.v1',
    ],
    'sections'=>['summary'=>[['package_version'=>511]],'names'=>[],'imports'=>[],'exports'=>[]],
];
$writer->write($ut4);
$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([30,7,'verified','2026-10-02 14:00:00',30,md5('30'),sha1('30')]);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([30,7,'/Game/Empty']);
$check('ue4_classic_package_family_is_admitted',
    (new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(7,30)===[]);

$zen=static function(int $fileId,string $packageId,array $imports,array $exports):array{
    return [
        'file'=>['id'=>$fileId,'game_id'=>8,'package_name'=>'/Game/Test','original_name'=>'Test'],
        'package_family'=>Uedb5ZenPackageReader::PACKAGE_FAMILY,
        'source_policy'=>Uedb5ZenPackageReader::SOURCE_POLICY,
        'section_schemas'=>[],
        'sections'=>[
            'package_summary'=>[['package_id'=>$packageId]],
            'imports'=>array_values($imports),'exports'=>array_values($exports),
            'cell_imports'=>[],'cell_exports'=>[],'soft_package_references'=>[],
            'dependency_bundle_entries'=>[],
        ],
    ];
};
$zenPackageId='0011223344556677';
$zenHashA='1111111111111111';$zenHashB='2222222222222222';
$zenImports=[
    ['type'=>'PackageImport','provider_package_id'=>$zenPackageId,'provider_public_export_hash'=>$zenHashA,'dependency_class'=>'hard'],
    ['type'=>'PackageImport','provider_package_id'=>$zenPackageId,'provider_public_export_hash'=>$zenHashB,'dependency_class'=>'hard'],
];
$zenExport=static fn(string $hash,string $name):array=>['public_export_hash'=>$hash,'filter_flags'=>0,'object_name'=>$name];
$writer->write($zen(40,'8899AABBCCDDEEFF',$zenImports,[]));
$writer->write($zen(41,$zenPackageId,[],[$zenExport($zenHashA,'A')]));
$writer->write($zen(42,$zenPackageId,[],[$zenExport($zenHashB,'B')]));
$writer->write($zen(43,$zenPackageId,[],[$zenExport($zenHashA,'A'),$zenExport($zenHashB,'B')]));
foreach([[40,'2026-10-02 13:00:00'],[41,'2026-10-02 12:00:00'],[42,'2026-10-01 12:00:00'],[43,'2026-09-30 12:00:00']] as [$id,$uploaded]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,8,'verified',$uploaded,$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,8,'/Game/Test']);
}
$zenKind=Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID;
$zenKey=hex2bin($zenPackageId);
foreach([[1,41,41],[1,42,42],[2,700,43]] as [$sourceKind,$sourceId,$fileId]){
    $db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')->execute([$sourceKind,$sourceId,8,$zenKind,$zenKey,$fileId]);
}
$zenSelected=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(8,40);
$check('zen_complete_provider_beats_partial_candidates',count($zenSelected)===1&&(int)$zenSelected[0]['file_id']===43);
$source=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php');
$check('selector_uses_v5_provider_keys',str_contains($source,'ue_uedb5_provider_keys'));
$check('selector_reads_v5_snapshots',str_contains($source,'Uedb5MetadataReader'));
$check('selector_excludes_known_invalid_identities',str_contains($source,'ue_invalid_file_identities'));
$check('selector_never_reads_v4_provider_projection',!str_contains($source,'ue_package_providers'));
$check('selector_never_reads_uedb4_container',!str_contains($source,'BlockedCompressedMetadata'));
$check('selector_never_reads_v4_metadata_registration',!str_contains($source,'ue_file_metadata'));
$it=new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach($it as $path){$path->isDir()?@rmdir($path->getPathname()):@unlink($path->getPathname());}
@rmdir($tmp);

echo json_encode([
    'ok'=>$failures===[],
    'checks'=>$checks,
    'failures'=>$failures,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
