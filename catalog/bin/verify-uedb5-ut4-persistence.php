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
$buildFixture=static function(int $version)use($u32,$i32,$i64,$fstring,$fname,$nameEntry,$engineVersion):string{
    $names=['CoreUObject','Class','MyObject','MyExport'];
    $nameBytes='';foreach($names as $name)$nameBytes.=$nameEntry($name);
    $importBytes=$fname(0).$fname(1).$i32(0).$fname(2);
    $serial=$version>=511?$i64(0).$i64(0):$i32(0).$i32(0);
    $exportBytes=$i32(0).$i32(0).$i32(0).$i32(0).$fname(3).$u32(0x4).$serial;
    $exportBytes.=$i32(0).$i32(0).$i32(0).str_repeat("\0",16).$u32(0);
    $exportBytes.=$i32(0).$i32(1);
    $exportBytes.=$i32(0).$i32(1).$i32(0).$i32(0).$i32(0);
    $stringRefBytes=$fstring('/Game/SoftPkg');
    $preloadBytes=$i32(1);

    $buildSummary=static function(int $headerSize,int $nameOffset,int $importOffset,int $exportOffset,int $stringOffset,int $preloadOffset)
        use($version,$u32,$i32,$i64,$fstring,$engineVersion):string{
        $b=$u32(0x9E2A83C1).$i32(-7).$i32(864).$i32($version).$i32(0).$i32(0);
        $b.=$i32($headerSize).$fstring('/Game/Test').$u32(0);
        $b.=$i32(4).$i32($nameOffset);
        $b.=$i32(0).$i32(0);
        $b.=$i32(1).$i32($exportOffset).$i32(1).$i32($importOffset).$i32(0);
        $b.=$i32(1).$i32($stringOffset).$i32(0).$i32(0);
        $b.=str_repeat("\0",16).$i32(0);
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
    'profile_key'=>'ut4-alpha','label'=>'Unreal Tournament 4 Alpha UE4 parser',
    'assumed_unversioned_parser_version'=>522,'source_reference'=>'ardenee/UnrealTournament clean-master',
]];
try{
    $path=$temp.DIRECTORY_SEPARATOR.'fixture.uasset';file_put_contents($path,$buildFixture(511));
    $package=new \UnrealPackageReader4($path,$options);
    $check('ut4_v511_reader_has_no_issues',$package->validatePackage()===[]);
    $snapshot=Uedb5Ut4SnapshotBuilder::build($package,[
        'id'=>7511,'game_id'=>7,'package_name'=>'/Game/Test','original_name'=>'Test.uasset'
    ]);
    $summary=(array)$snapshot['sections']['summary'][0];
    $name=(array)$snapshot['sections']['names'][0];
    $import=(array)$snapshot['sections']['imports'][0];
    $export=(array)$snapshot['sections']['exports'][0];
    $check('ut4_v511_source_policy',($snapshot['source_policy']??'')===Uedb5Ut4SnapshotBuilder::SOURCE_POLICY);
    $check('ut4_v511_profile_retained',($summary['parser_profile']['key']??'')==='ut4-alpha'
        && ($summary['parser_profile']['source_reference']??'')==='ardenee/UnrealTournament clean-master');
    $check('ut4_v511_name_hashes_retained',(int)($name['non_case_hash']??0)===0x11 && (int)($name['case_hash']??0)===0x22);
    $check('ut4_v511_import_fname_identity',($import['object_name']['text']??'')==='MyObject');
    $check('ut4_v511_export_serial_width',(int)($export['serial_range_serialized_width_bits']??0)===64);
    $check('ut4_v511_export_source_fields',($export['object_name']['text']??'')==='MyExport'
        && ($export['object_flags']??'')==='00000004' && !empty($export['is_asset']));
    $check('ut4_v511_string_reference_retained',($snapshot['sections']['string_asset_references'][0]['path']??'')==='/Game/SoftPkg');
    $check('ut4_v511_preload_dependency_retained',(int)($snapshot['sections']['preload_dependencies'][0]['ref']??0)===1);
    $writer=new Uedb5MetadataSnapshotWriter($temp);$writer->write($snapshot,1024);
    $reader=new Uedb5MetadataReader($temp);$round=$reader->snapshot(7,7511);
    $check('ut4_v511_uedb5_roundtrip',($round['sections']['exports'][0]['object_name']['text']??'')==='MyExport'
        && ($round['sections']['summary'][0]['parser_profile']['key']??'')==='ut4-alpha');

    $older=$temp.DIRECTORY_SEPARATOR.'fixture510.uasset';file_put_contents($older,$buildFixture(510));
    $p510=new \UnrealPackageReader4($older,$options);
    $s510=Uedb5Ut4SnapshotBuilder::build($p510,['id'=>7510,'game_id'=>7,'package_name'=>'Old','original_name'=>'Old.uasset']);
    $check('ue4_v510_is_source_loadable',$p510->validatePackage()===[]
        && (int)($s510['sections']['summary'][0]['package_version']??0)===510
        && (int)($s510['sections']['exports'][0]['serial_range_serialized_width_bits']??0)===32);
    $check('ue4_policy_uses_4272_loadable_range',Uedb5Ut4SnapshotBuilder::MIN_VERSION===214
        && Uedb5Ut4SnapshotBuilder::MAX_VERSION===522);
}finally{
    if(is_dir($temp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());}
        @rmdir($temp);
    }
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
