<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-parity-search-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_name_candidates(file_id INTEGER,name_key_hash BLOB,name_key_length INTEGER,name_key_fingerprint BLOB,first_name_index INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_object_candidates(file_id INTEGER,object_kind INTEGER,object_index INTEGER,object_name_hash BLOB,object_name_length INTEGER,public_export_hash BLOB)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_kind INTEGER,source_index INTEGER,required_package_key_kind INTEGER,required_package_key BLOB,required_object_key_kind INTEGER,required_object_key BLOB)');

$snapshot=static function(int $fileId,string $name,string $nameValue,string $importValue,string $exportValue):array{
    return [
        'file'=>['id'=>$fileId,'game_id'=>3,'package_name'=>$name,'original_name'=>$name.'.u'],
        'package_family'=>'classic-linkerload','source_policy'=>'test-parity-targeted-search',
        'section_schemas'=>[
            'summary'=>'ue1.ut99.package-summary.v1','names'=>'ue1.ut99.name-entry.v1',
            'imports'=>'ue1.ut99.object-import.v1','exports'=>'ue1.ut99.object-export.v1',
        ],
        'sections'=>[
            'summary'=>[['package_version'=>69]],
            'names'=>[['index'=>0,'text'=>$nameValue]],
            'imports'=>[
                ['index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Pkg','outer_index'=>0],
                ['index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>$importValue,'outer_index'=>-1],
            ],
            'exports'=>[['index'=>0,'class_index'=>0,'super_index'=>0,'outer_index'=>0,'object_name'=>$exportValue,'object_flags'=>4]],
        ],
    ];
};
$writer->write($snapshot(1,'Good','Alpha','ImportThing','ExportThing'));
$writer->write($snapshot(2,'FalseCandidate','DifferentName','DifferentImport','DifferentExport'));
$db->exec("INSERT INTO ue_files VALUES(1,3,'verified'),(2,3,'verified')");

$key=static function(string $value):array{
    $normalized=\UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash::nameKey($value);
    return ['hash'=>md5($normalized,true),'length'=>strlen($normalized),'fingerprint'=>hash('sha256',$normalized,true)];
};
$alpha=$key('Alpha');$export=$key('ExportThing');$import=$key('ImportThing');$pkg=$key('Pkg');
foreach([1,2] as $fileId){
    $db->prepare('INSERT INTO ue_uedb5_name_candidates VALUES(?,?,?,?,?)')
        ->execute([$fileId,$alpha['hash'],$alpha['length'],$alpha['fingerprint'],0]);
    $db->prepare('INSERT INTO ue_uedb5_object_candidates VALUES(?,?,?,?,?,NULL)')
        ->execute([$fileId,Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT,0,$export['hash'],$export['length']]);
    $db->prepare('INSERT INTO ue_uedb5_dependency_edges VALUES(?,?,?,?,?,?,?)')
        ->execute([$fileId,Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,1,
            Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,$pkg['hash'],
            Uedb5SqlProjectionContract::OBJECT_KEY_NAME,$import['hash']]);
}
$service=new Uedb5ParityV5ReadService($db,['storage_path'=>$tmp]);
$check('targeted_name_hydration_accepts_only_authoritative_v5_row',
    $service->exactMetadataSearch(3,'Alpha',['names'],500)===[1]);
$check('targeted_import_hydration_accepts_only_authoritative_v5_row',
    $service->exactMetadataSearch(3,'ImportThing',['imports'],500)===[1]);
$check('targeted_export_hydration_accepts_only_authoritative_v5_row',
    $service->exactMetadataSearch(3,'ExportThing',['exports'],500)===[1]);

$remove=static function(string $path)use(&$remove):void{
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry){
        if($entry==='.'||$entry==='..')continue;
        $child=$path.DIRECTORY_SEPARATOR.$entry;
        if(is_dir($child))$remove($child);else @unlink($child);
    }
    @rmdir($path);
};
$remove($tmp);
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
