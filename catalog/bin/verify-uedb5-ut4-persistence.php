#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';
require_once dirname($root).'/UE4/UnrealPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$checks=[];$failures=[];
$check=static function(string $n,bool $ok)use(&$checks,&$failures):void{$checks[$n]=$ok;if(!$ok)$failures[]=$n;};
$u16=static fn(int $v):string=>pack('v',$v&0xFFFF);
$u32=static fn(int $v):string=>pack('V',$v&0xFFFFFFFF);
$i32=$u32;
$i64=static fn(int $v):string=>pack('V2',$v&0xFFFFFFFF,($v>>32)&0xFFFFFFFF);
$fstring=static fn(string $v):string=>$v===''?$i32(0):$i32(strlen($v)+1).$v."\0";
$fname=static fn(int $index,int $number=0):string=>pack('V2',$index,$number);
$nameEntry=static fn(string $name):string=>$fstring($name).$u16(0x11).$u16(0x22);
$engineVersion=static fn():string=>$u16(4).$u16(15).$u16(0).$u32(12345).$fstring('UT4');
$buildFixture=static function(int $version,?int $serializedVersion=null)use($u32,$i32,$i64,$fstring,$fname,$nameEntry,$engineVersion):string{
    $serializedVersion??=$version;
    $names=['CoreUObject','Class','MyObject','MyExport'];
    $nameBytes='';foreach($names as $name)$nameBytes.=$nameEntry($name);
    $importBytes=$fname(0).$fname(1).$i32(0).$fname(2);
    if($version>=519)$importBytes.=$fname(0);
    $serial=$version>=510?$i64(0).$i64(0):$i32(0).$i32(0);
    $exportBytes=$i32(0).$i32(0).$i32(0).$i32(0).$fname(3).$u32(0x4).$serial;
    $exportBytes.=$i32(0).$i32(0).$i32(0).str_repeat("\0",16).$u32(0);
    $exportBytes.=$i32(0).$i32(1);
    $exportBytes.=$i32(0).$i32(1).$i32(0).$i32(0).$i32(0);
    $stringRefBytes=$version>=513?$fname(2).$fstring(''):$fstring('/Game/SoftPkg');
    $preloadBytes=$i32(1);

    $buildSummary=static function(int $headerSize,int $nameOffset,int $importOffset,int $exportOffset,int $stringOffset,int $preloadOffset)
        use($version,$serializedVersion,$u32,$i32,$i64,$fstring,$engineVersion):string{
        $b=$u32(0x9E2A83C1).$i32(-7).$i32(864).$i32($serializedVersion).$i32(0).$i32(0);
        $b.=$i32($headerSize).$fstring('/Game/Test').$u32(0);
        $b.=$i32(4).$i32($nameOffset);
        if($version>=515)$b.=$fstring('');
        $b.=$i32(0).$i32(0);
        $b.=$i32(1).$i32($exportOffset).$i32(1).$i32($importOffset).$i32(0);
        $b.=$i32(1).$i32($stringOffset).$i32(0).$i32(0);
        $b.=str_repeat("\0",16);
        if($version>=517)$b.=str_repeat("\0",16);
        $b.=$i32(0);
        $b.=$engineVersion().$engineVersion();
        $b.=$u32(0).$i32(0).$u32(0).$i32(0);
        $b.=$i32(0).$i64(0).$i32(0).$i32(0);
        $b.=$i32(1).$i32($preloadOffset);
        return $b;
    };
    $summary0=$buildSummary(0,0,0,0,0,0);
    $nameOffset=strlen($summary0);
    $importOffset=$nameOffset+strlen($nameBytes);
    $exportOffset=$importOffset+strlen($importBytes);
    $stringOffset=$exportOffset+strlen($exportBytes);
    $preloadOffset=$stringOffset+strlen($stringRefBytes);
    $headerSize=$preloadOffset+strlen($preloadBytes);
    $summary=$buildSummary($headerSize,$nameOffset,$importOffset,$exportOffset,$stringOffset,$preloadOffset);
    if(strlen($summary)!==strlen($summary0))throw new RuntimeException('UT4 fixture summary size changed.');
    return $summary.$nameBytes.$importBytes.$exportBytes.$stringRefBytes.$preloadBytes;
};

$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-uedb5-ut4-'.bin2hex(random_bytes(5));
if(!mkdir($temp,0775,true)&&!is_dir($temp))throw new RuntimeException('Could not create UT4 verifier temp.');
$options=['parser_profile'=>[
    'profile_key'=>'ut4-alpha','label'=>'Unreal Tournament 4 clean-master UE4 parser',
    'assumed_unversioned_parser_version'=>510,
    'source_reference'=>'UT4 clean-master cc3df76429 / GPackageFileUE4Version',
]];
try{
    $path=$temp.DIRECTORY_SEPARATOR.'fixture.uasset';file_put_contents($path,$buildFixture(510));
    $package=new \UnrealPackageReader4($path,$options);
    $check('ut4_v510_reader_has_no_issues',$package->validatePackage()===[]);
    $snapshot=Uedb5Ut4SnapshotBuilder::build($package,[
        'id'=>7511,'game_id'=>7,'package_name'=>'/Game/Test','original_name'=>'Test.uasset'
    ]);
    $summary=(array)$snapshot['sections']['summary'][0];
    $name=(array)$snapshot['sections']['names'][0];
    $import=(array)$snapshot['sections']['imports'][0];
    $export=(array)$snapshot['sections']['exports'][0];
    $check('ut4_v510_source_policy',($snapshot['source_policy']??'')===Uedb5Ut4SnapshotBuilder::SOURCE_POLICY);
    $check('ut4_v510_profile_retained',($summary['parser_profile']['key']??'')==='ut4-alpha'
        && (int)($summary['parser_profile']['assumed_unversioned_parser_version']??0)===510
        && ($summary['parser_profile']['source_reference']??'')==='UT4 clean-master cc3df76429 / GPackageFileUE4Version');
    $check('ut4_v510_name_hashes_retained',(int)($name['non_case_hash']??0)===0x11 && (int)($name['case_hash']??0)===0x22);
    $check('ut4_v510_import_fname_identity',($import['object_name']['text']??'')==='MyObject');
    $check('ut4_v510_export_serial_width',(int)($export['serial_range_serialized_width_bits']??0)===64);
    $check('ut4_v510_export_source_fields',($export['object_name']['text']??'')==='MyExport'
        && ($export['object_flags']??'')==='00000004' && !empty($export['is_asset']));
    $check('ut4_v510_string_reference_retained',($snapshot['sections']['string_asset_references'][0]['path']??'')==='/Game/SoftPkg');
    $check('ut4_v510_preload_dependency_retained',(int)($snapshot['sections']['preload_dependencies'][0]['ref']??0)===1);
    $writer=new Uedb5MetadataSnapshotWriter($temp);$writer->write($snapshot,1024);
    $reader=new Uedb5MetadataReader($temp);$round=$reader->snapshot(7,7511);
    $check('ut4_v510_uedb5_roundtrip',($round['sections']['exports'][0]['object_name']['text']??'')==='MyExport'
        && ($round['sections']['summary'][0]['parser_profile']['key']??'')==='ut4-alpha');

    $older=$temp.DIRECTORY_SEPARATOR.'fixture509.uasset';file_put_contents($older,$buildFixture(509));
    $p509=new \UnrealPackageReader4($older,$options);
    $s509=Uedb5Ut4SnapshotBuilder::build($p509,['id'=>7509,'game_id'=>7,'package_name'=>'Old','original_name'=>'Old.uasset']);
    $check('ut4_v509_is_source_loadable',$p509->validatePackage()===[]
        && (int)($s509['sections']['summary'][0]['package_version']??0)===509
        && (int)($s509['sections']['exports'][0]['serial_range_serialized_width_bits']??0)===32);

    $v511Path=$temp.DIRECTORY_SEPARATOR.'fixture511.uasset';file_put_contents($v511Path,$buildFixture(511));
    $p511=new \UnrealPackageReader4($v511Path,$options);
    $s511=Uedb5Ut4SnapshotBuilder::build($p511,[
        'id'=>7513,'game_id'=>7,'package_name'=>'Structural511','original_name'=>'Structural511.uasset'
    ]);
    $v511Summary=(array)$s511['sections']['summary'][0];
    $check('ut4_v511_structural_reader_has_no_issues',$p511->validatePackage()===[]);
    $check('ut4_v511_uses_separate_structural_source_policy',
        ($s511['source_policy']??'')===Uedb5Ut4SnapshotBuilder::SOURCE_POLICY_V511
        && (int)($v511Summary['serialized_package_version']??0)===511
        && (int)($v511Summary['package_version']??0)===511);
    $check('ut4_v511_package_tables_retain_v510_layout',
        (int)($s511['sections']['exports'][0]['serial_range_serialized_width_bits']??0)===64
        && ($s511['sections']['imports'][0]['object_name']['text']??'')==='MyObject'
        && (int)($s511['sections']['preload_dependencies'][0]['ref']??0)===1);

    $unversionedPath=$temp.DIRECTORY_SEPARATOR.'fixture-unversioned.uasset';file_put_contents($unversionedPath,$buildFixture(510,0));
    $pu=new \UnrealPackageReader4($unversionedPath,$options);
    $unversionedIssues=$pu->validatePackage();
    $su=Uedb5Ut4SnapshotBuilder::build($pu,['id'=>7512,'game_id'=>7,'package_name'=>'Unversioned','original_name'=>'Unversioned.uasset']);
    $us=(array)$su['sections']['summary'][0];
    $check('ut4_unversioned_notice_is_informational',count($unversionedIssues)===1
        && str_starts_with((string)$unversionedIssues[0],'Package is unversioned; using assumed UE4 parser version 510'));
    $check('ut4_unversioned_source_identity_retained',(int)($us['serialized_package_version']??-1)===0
        && (int)($us['serialized_licensee_version']??-1)===0
        && (int)($us['package_version']??0)===510
        && !empty($us['unversioned'])
        && (int)($us['parser_profile']['assumed_unversioned_parser_version']??0)===510
        && ($us['parser_profile']['key']??'')==='ut4-alpha');

    $finalPath=$temp.DIRECTORY_SEPARATOR.'fixture-final-ue4.uasset';file_put_contents($finalPath,$buildFixture(521));
    $standardOptions=['parser_profile'=>[
        'profile_key'=>'standard-ue4','label'=>'Standard UE4 package parser',
        'assumed_unversioned_parser_version'=>521,'source_reference'=>'UE4 final-release package summary layout',
    ]];
    $finalReader=new \UnrealPackageReader4($finalPath,$standardOptions);
    $rejectedFinal=false;
    try {
        Uedb5Ut4SnapshotBuilder::build(
            $finalReader,
            ['id'=>7521,'game_id'=>7,'package_name'=>'FinalUE4','original_name'=>'FinalUE4.uasset']
        );
    } catch (RuntimeException $e) {
        $rejectedFinal=str_contains($e->getMessage(),'requires explicit UE4 version 214-511');
    }
    $check('ut4_builder_rejects_final_ue4_source_attribution',$rejectedFinal);
    $builderSource=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php');
    $readerSource=(string)file_get_contents(dirname($root).'/UE4/UnrealPackageReader.php');
    $check('ut4_builder_separates_clean_master_and_v511_structural_source',
        str_contains($builderSource,'$effectiveVersion >= 214 && $effectiveVersion <= 510')
        && str_contains($builderSource,'$effectiveVersion === 511 && $serializedVersion === 511')
        && str_contains($builderSource,'SOURCE_POLICY_V511')
        && str_contains($builderSource,'$licenseeVersion !== 0')
    );
    $check('ue4_reader_retains_source_format_capability_checks',str_contains($readerSource,'VER_OLDEST_LOADABLE_PACKAGE')
        &&str_contains($readerSource,'Package summary format is newer than UE4.27.2'));
}finally{
    if(is_dir($temp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());}
        @rmdir($temp);
    }
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
