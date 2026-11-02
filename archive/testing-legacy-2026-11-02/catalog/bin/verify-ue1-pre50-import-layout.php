#!/usr/bin/env php
<?php
declare(strict_types=1);

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
require_once $root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};
$u32=static fn(int $v):string=>pack('V',$v&0xFFFFFFFF);
$compact=static function(int $value):string{$neg=$value<0;$m=abs($value);$first=$m&0x3F;$m>>=6;if($neg)$first|=0x80;if($m!==0)$first|=0x40;$out=chr($first);while($m!==0){$n=$m&0x7F;$m>>=7;if($m!==0)$n|=0x80;$out.=chr($n);}return$out;};
$names=['Core','Package','Engine','OtherClass','Provider','LegacyObj'];
$nameBytes='';foreach($names as $name){$nameBytes.=$name."\0".$u32(0);}
$importBytes=$compact(2).$compact(3).$compact(4).$compact(5);
// Unreal v1.200 pre-v50 export layout omits PackageIndex entirely.
// Use a negative non-zero SerialSize to prove SerialOffset is still consumed.
$exportBytes=$compact(0).$compact(0).$compact(5).$u32(4).$compact(-1).$compact(7);
$summarySize=44;$nameOffset=$summarySize;$importOffset=$nameOffset+strlen($nameBytes);$exportOffset=$importOffset+strlen($importBytes);
$header=$u32(0x9E2A83C1).$u32(49).$u32(0)
    .$u32(count($names)).$u32($nameOffset)
    .$u32(1).$u32($exportOffset)
    .$u32(1).$u32($importOffset)
    .$u32(0).$u32($summarySize);
$bytes=$header.$nameBytes.$importBytes.$exportBytes;
$path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-ue1-pre50-'.bin2hex(random_bytes(5)).'.u';
file_put_contents($path,$bytes);
try{
    $reader=new CatalogUE1PackageReader($path);
    $issues=$reader->validatePackage();
    $check('pre50_fixture_is_valid',$issues===[]);
    $imports=$reader->getImports();
    $row=(array)($imports[0]??[]);
    $check('pre50_third_field_is_object_package',($row['objectPackageText']??null)==='Provider');
    $check('pre50_package_index_is_not_serialized',(int)($row['outerIndex']??-1)===0);
    $check('pre50_object_name_follows_object_package',($row['objectNameText']??null)==='LegacyObj');
    $exports=$reader->getExports();
    $export=(array)($exports[0]??[]);
    $check('pre50_export_package_index_is_not_serialized',(int)($export['outerIndex']??-1)===0);
    $check('pre50_export_object_name_is_aligned',($export['objectNameText']??null)==='LegacyObj');
    $check('negative_nonzero_serial_size_still_has_offset',
        (int)($export['serialSize']??0)===-1&&(int)($export['serialOffset']??0)===7);
    $snapshot=Uedb5UnrealSnapshotBuilder::build($reader,[
        'id'=>12049,'game_id'=>12,'package_name'=>'Consumer','original_name'=>'Consumer.u',
    ]);
    $import=(array)($snapshot['sections']['imports'][0]??[]);
    $summary=(array)($snapshot['sections']['summary'][0]??[]);
    $v5Export=(array)($snapshot['sections']['exports'][0]??[]);
    $check('pre50_uses_versioned_import_schema',($snapshot['section_schemas']['imports']??'')==='ue1.unreal.object-import.v2');
    $check('pre50_uses_versioned_export_schema',($snapshot['section_schemas']['exports']??'')==='ue1.unreal.object-export.v2');
    $check('pre50_uses_versioned_summary_schema',($snapshot['section_schemas']['summary']??'')==='ue1.unreal.package-summary.v2');
    $check('pre50_v5_preserves_object_package',!empty($import['object_package_present'])&&($import['object_package']['text']??null)==='Provider');
    $check('pre50_v5_marks_export_outer_not_serialized',empty($v5Export['outer_index_serialized'])&&($v5Export['outer_index_serialized_width_bits']??null)===null);
    $check('pre50_summary_records_serialization_boundary',
        ($summary['import_outer_index_encoding']??'')==='not-serialized'
        &&($summary['import_object_package_encoding']??'')==='fname-compact-index'
        &&($summary['export_outer_index_encoding']??'')==='not-serialized');
}finally{@unlink($path);}
$ok=$failures===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);