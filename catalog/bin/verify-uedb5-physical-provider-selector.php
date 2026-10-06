#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5PhysicalProviderSelector;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Unreal2SnapshotBuilder;
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
$snapshot=static function(
    int $fileId,string $packageName,array $imports,array $exports,
    string $sourcePolicy='test-ue1-provider-selection',int $packageVersion=69,
    int $gameId=3,string $schemaPrefix='ue1.ut99'
)use($fname):array{
    foreach($imports as &$row){
        foreach(['class_package','class_name','object_name'] as $field){
            if(isset($row[$field])&&!is_array($row[$field]))$row[$field]=$fname((string)$row[$field]);
        }
    } unset($row);
    foreach($exports as &$row){
        if(isset($row['object_name'])&&!is_array($row['object_name']))$row['object_name']=$fname((string)$row['object_name']);
    } unset($row);
    return [
        'file'=>['id'=>$fileId,'game_id'=>$gameId,'package_name'=>$packageName,'original_name'=>$packageName.'.u'],
        'package_family'=>'classic-linkerload','source_policy'=>$sourcePolicy,
        'section_schemas'=>[
            'summary'=>$schemaPrefix.'.package-summary.v1','names'=>$schemaPrefix.'.name-entry.v1',
            'imports'=>$schemaPrefix.'.object-import.v1','exports'=>$schemaPrefix.'.object-export.v1',
        ],
        'sections'=>[
            'summary'=>[['package_version'=>$packageVersion]],'names'=>[],
            'imports'=>array_values($imports),'exports'=>array_values($exports),
        ],
    ];
};
$consumerImports=[
    ['index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Pkg','outer_index'=>0],
    ['index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'A','outer_index'=>-1],
    ['index'=>2,'class_package'=>'Core','class_name'=>'Class','object_name'=>'B','outer_index'=>-1],
    ['index'=>3,'class_package'=>'Core','class_name'=>'Class','object_name'=>'None','outer_index'=>-1],
];
$public=0x00000004;
$export=static fn(int $index,string $name):array=>[
    'index'=>$index,'class_index'=>0,'super_index'=>0,'outer_index'=>0,
    'object_name'=>$name,'object_flags'=>$public,
];
$writer->write($snapshot(10,'Consumer',$consumerImports,[]));
$writer->write($snapshot(20,'Pkg',[],[$export(0,'A')]));
$writer->write($snapshot(21,'Pkg',[],[$export(0,'B'),$export(1,'None')]));
$writer->write($snapshot(22,'PkgVariant',[],[$export(0,'A'),$export(1,'B')]));
foreach([
    [10,'Consumer','2026-10-01 10:00:00'],[20,'Pkg','2026-10-02 12:00:00'],
    [21,'Pkg','2026-10-01 12:00:00'],[22,'PkgVariant','2026-09-30 12:00:00'],
] as [$id,$name,$uploaded]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,3,'verified',$uploaded,$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,3,$name]);
}
$classic=Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME;
$pkgKey=md5('pkg',true);
foreach([
    [1,20,20],[1,21,21],[2,500,22],
] as [$sourceKind,$sourceId,$fileId]){
    $db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')
        ->execute([$sourceKind,$sourceId,3,$classic,$pkgKey,$fileId]);
}
$selector=new PdoUedb5PhysicalProviderSelector($db,$tmp);
$selected=$selector->select(3,10);
$check('classic_duplicate_provider_environment_is_ambiguous',
    count($selected)===1
    && ($selected[0]['selection_status']??'')==='ambiguous'
    && ($selected[0]['file_id']??null)===null
    && ($selected[0]['package_name']??'')==='Pkg'
    && (array)($selected[0]['candidate_file_ids']??[])===[20,21,22]);
$check('later_complete_alias_is_not_content_scored_into_selection',
    !in_array((int)($selected[0]['file_id']??0),[20,21,22],true));

$db->exec('DELETE FROM ue_uedb5_provider_keys WHERE file_id=22');
$selected=$selector->select(3,10);
$check('two_physical_candidates_remain_ambiguous',
    count($selected)===1
    && ($selected[0]['selection_status']??'')==='ambiguous'
    && (array)($selected[0]['candidate_file_ids']??[])===[20,21]);
$db->exec('DELETE FROM ue_uedb5_provider_keys WHERE file_id=21');
$selected=$selector->select(3,10);
$check('single_physical_provider_is_selected',
    count($selected)===1
    && ($selected[0]['selection_status']??'')==='selected'
    && (int)$selected[0]['file_id']===20);

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

$ut99Retail='ue1-ut99-retail-v1400-1999-11-30';
$unrealIFallbackImports=[
    ['index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'UnrealI','outer_index'=>0],
    ['index'=>1,'class_package'=>'UnrealI','class_name'=>'Texture','object_name'=>'LegacyTex','outer_index'=>-1],
];
$writer->write($snapshot(24,'FallbackConsumer',$unrealIFallbackImports,[],$ut99Retail,68));
$writer->write($snapshot(25,'UnrealShare',[],[$export(0,'LegacyTex')],$ut99Retail,68));
foreach([[24,'FallbackConsumer'],[25,'UnrealShare']] as [$id,$name]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,3,'verified','2026-10-02 13:45:00',$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,3,$name]);
}
$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')
    ->execute([1,25,3,$classic,md5('unrealshare',true),25]);
$ut99Fallback=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(3,24);
$check('ut99_v1400_unreali_package_load_retries_unrealshare',
    count($ut99Fallback)===1
    && ($ut99Fallback[0]['selection_status']??'')==='selected'
    && (int)($ut99Fallback[0]['file_id']??0)===25
    && ($ut99Fallback[0]['package_name']??'')==='UnrealI'
    && ($ut99Fallback[0]['source_fallback_package_name']??'')==='UnrealShare');

$writer->write($snapshot(26,'UnverifiedFallbackConsumer',$unrealIFallbackImports,[],'ue1-ut99-v430-public-source-partial',69));
$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([26,3,'verified','2026-10-02 13:46:00',26,md5('26'),sha1('26')]);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([26,3,'UnverifiedFallbackConsumer']);
$unverifiedFallback=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(3,26);
$check('ut99_unverified_later_source_does_not_inherit_v1400_package_fallback',$unverifiedFallback===[]);

$writer->write($snapshot(27,'Unreal2FallbackConsumer',$unrealIFallbackImports,[],Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,69,2,'ue2.unreal2'));
$writer->write($snapshot(28,'UnrealShare',[],[$export(0,'LegacyTex')],Uedb5Unreal2SnapshotBuilder::POLICY_V69_2000,69,2,'ue2.unreal2'));
foreach([[27,'Unreal2FallbackConsumer'],[28,'UnrealShare']] as [$id,$name]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,2,'verified','2026-10-02 13:47:00',$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,2,$name]);
}
$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')
    ->execute([1,28,2,$classic,md5('unrealshare',true),28]);
$unreal2Fallback=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(2,27);
$check('unreal2_v69_unreali_package_load_retries_unrealshare',
    count($unreal2Fallback)===1
    &&($unreal2Fallback[0]['selection_status']??'')==='selected'
    &&(int)($unreal2Fallback[0]['file_id']??0)===28
    &&($unreal2Fallback[0]['source_fallback_package_name']??'')==='UnrealShare');

$writer->write($snapshot(29,'Unreal2V126Consumer',$unrealIFallbackImports,[],Uedb5Unreal2SnapshotBuilder::SOURCE_POLICY,126,2,'ue2.unreal2'));
$db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([29,2,'verified','2026-10-02 13:48:00',29,md5('29'),sha1('29')]);
$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([29,2,'Unreal2V126Consumer']);
$unreal2UnverifiedFallback=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(2,29);
$check('unreal2_v126_does_not_inherit_v69_package_fallback',$unreal2UnverifiedFallback===[]);

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

$zen=static function(int $fileId,string $packageId,array $imports,array $exports,array $redirects=[]):array{
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
            'package_redirects'=>array_values($redirects),'localized_packages'=>[],
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
$check('zen_duplicate_package_id_environment_is_ambiguous',
    count($zenSelected)===1
    && ($zenSelected[0]['selection_status']??'')==='ambiguous'
    && ($zenSelected[0]['file_id']??null)===null
    && ($zenSelected[0]['package_id']??'')===$zenPackageId
    && (array)($zenSelected[0]['candidate_file_ids']??[])===[41,42,43]);
$check('zen_public_export_coverage_does_not_select_a_duplicate_package_file',
    (int)($zenSelected[0]['file_id']??0)===0);

$redirectSourceId='ABCDEF0011223344';
$redirectTargetId='1020304050607080';
$redirectHash='3333333333333333';
$redirectImport=[[
    'type'=>'PackageImport','provider_package_id'=>$redirectSourceId,
    'provider_public_export_hash'=>$redirectHash,'dependency_class'=>'hard',
]];
$redirectRows=[[
    'container_index'=>0,'source_package_id'=>$redirectSourceId,
    'target_package_id'=>$redirectTargetId,
    'source_package_name'=>['text'=>'/Game/RedirectSource'],
]];
$writer->write($zen(44,'9988776655443322',$redirectImport,[],$redirectRows));
$writer->write($zen(45,$redirectTargetId,[],[$zenExport($redirectHash,'RedirectedObject')]));
foreach([[44,'2026-10-02 14:00:00'],[45,'2026-10-02 14:01:00']] as [$id,$uploaded]){
    $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,?,?,?)')->execute([$id,8,'verified',$uploaded,$id,md5((string)$id),sha1((string)$id)]);
    $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,?)')->execute([$id,8,'/Game/RedirectTest']);
}
$db->prepare('INSERT INTO ue_uedb5_provider_keys VALUES(?,?,?,?,?,?)')->execute([
    1,45,8,$zenKind,hex2bin($redirectTargetId),45,
]);
$redirectSelected=(new PdoUedb5PhysicalProviderSelector($db,$tmp))->select(8,44);
$check('zen_selector_does_not_guess_single_container_redirect_target',
    $redirectSelected===[]);

$source=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php');
$check('selector_uses_v5_provider_keys',str_contains($source,'ue_uedb5_provider_keys'));
$check('selector_reads_v5_snapshots',str_contains($source,'Uedb5MetadataReader'));
$check('selector_excludes_known_invalid_identities',str_contains($source,'ue_invalid_file_identities'));
$check('selector_never_reads_v4_provider_projection',!str_contains($source,'ue_package_providers'));
$check('selector_never_reads_uedb4_container',!str_contains($source,'BlockedCompressedMetadata'));
$check('selector_never_reads_v4_metadata_registration',!str_contains($source,'ue_file_metadata'));
$check('selector_has_no_content_scoring',
    !str_contains($source,'bestClassicCandidate')
    && !str_contains($source,'bestZenCandidate')
    && !str_contains($source,'matchCount')
    && !str_contains($source,'redirectorCount'));
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
