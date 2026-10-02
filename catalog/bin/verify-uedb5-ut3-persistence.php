#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';
require_once $root.'/parsers/EpicUE3PackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;

$checks=[];$failures=[];
$check=static function(string $n,bool $ok)use(&$checks,&$failures):void{$checks[$n]=$ok;if(!$ok)$failures[]=$n;};
$u32=static fn(int $v):string=>pack('V',$v&0xFFFFFFFF);
$i32=$u32;
$fixture=static function(int $version)use($u32,$i32):string{
    $summarySize=100;$nameOffset=100;$exportOffset=116;$fileSize=188;
    $b=$u32(0x9E2A83C1).$u32($version).$i32($fileSize).$i32(0).$u32(0);
    $b.=$i32(1).$i32($nameOffset).$i32(1).$i32($exportOffset).$i32(0).$i32($exportOffset).$i32($fileSize);
    $b.=$u32(1).$u32(2).$u32(3).$u32(4);
    $b.=$i32(1).$i32(1).$i32(1).$i32(0);
    $b.=$i32(3800).$i32(0).$u32(0).$i32(0).$u32(0x12345678);
    if(strlen($b)!==$summarySize)throw new RuntimeException('Bad UT3 fixture summary size '.strlen($b));
    $b.=$i32(4)."Obj\0".$u32(0x3).$u32(0);
    $b.=$i32(0).$i32(0).$i32(0).$i32(0).$i32(0).$i32(0);
    $b.=$u32(0).$u32(4); // RF_Public = 0x0000000400000000
    $b.=$i32(0).$i32(0).$i32(0).$u32(0).$i32(0);
    $b.=$u32(5).$u32(6).$u32(7).$u32(8).$u32(0x9);
    if(strlen($b)!==$fileSize)throw new RuntimeException('Bad UT3 fixture size '.strlen($b));
    return $b;
};
$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-uedb5-ut3-'.bin2hex(random_bytes(5));
if(!mkdir($temp,0775,true)&&!is_dir($temp))throw new RuntimeException('Could not create UT3 verifier temp.');
try{
    $path=$temp.DIRECTORY_SEPARATOR.'fixture.upk';file_put_contents($path,$fixture(512));
    $package=new CatalogUE3PackageReader($path);
    $check('ut3_v512_reader_has_no_issues',$package->validatePackage()===[]);
    $snapshot=Uedb5Ut3SnapshotBuilder::build($package,[
        'id'=>6512,'game_id'=>6,'package_name'=>'Fixture','original_name'=>'Fixture.upk'
    ]);
    $summary=(array)$snapshot['sections']['summary'][0];
    $name=(array)$snapshot['sections']['names'][0];
    $export=(array)$snapshot['sections']['exports'][0];
    $check('ut3_v512_source_policy',($snapshot['source_policy']??'')===Uedb5Ut3SnapshotBuilder::SOURCE_POLICY);
    $check('ut3_v512_name_row_offset_preserved',(int)($name['serialized_offset']??-1)==100);
    $check('ut3_v512_export_row_offset_preserved',(int)($export['serialized_offset']??-1)==116);
    $check('ut3_v512_name_flags_qword',($name['flags']??'')==='0000000000000003');
    $check('ut3_v512_public_flag_high32_preserved',($export['object_flags']??'')==='0000000400000000');
    $check('ut3_v512_object_flags_width',($export['object_flags_serialized_width_bits']??0)===64);
    $check('ut3_v512_fname_number_preserved',(int)($export['object_name']['number']??-1)===0);
    $check('ut3_v512_component_map_preserved',is_array($export['component_map']??null));
    $check('ut3_v512_summary_source_fields',($summary['package_source']??'')==='12345678' && ($summary['additional_packages_to_cook_present']??true)===false);
    $writer=new Uedb5MetadataSnapshotWriter($temp);$writer->write($snapshot,1024);
    $reader=new Uedb5MetadataReader($temp);$round=$reader->snapshot(6,6512);
    $check('ut3_v512_uedb5_roundtrip_flags',($round['sections']['exports'][0]['object_flags']??'')==='0000000400000000');

    $next=$temp.DIRECTORY_SEPARATOR.'fixture513.upk';file_put_contents($next,$fixture(513));
    $p513=new CatalogUE3PackageReader($next);
    $s513=Uedb5Ut3SnapshotBuilder::build($p513,['id'=>6513,'game_id'=>6,'package_name'=>'Next','original_name'=>'Next.upk']);
    $check('ut3_v513_builder_defers_admission_to_game_profile',($s513['source_policy']??'')===Uedb5Ut3SnapshotBuilder::SOURCE_POLICY);
}finally{
    if(is_dir($temp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){$item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());}
        @rmdir($temp);
    }
}
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
