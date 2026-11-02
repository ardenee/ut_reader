#!/usr/bin/env php
<?php
/**
 * Epic v0.84a UnResTyp.h:413-429 native 32-bit UResource header prefix,
 * and UnRes.cpp:1027-1078/1452-1493 per-export buffer and file data.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceHeaderReader;

$checks=[];$failed=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void {
    $checks[$label]=$ok;if(!$ok){$failed[]=$label;}
};
$fixture=static function(int $version):array {
    $prefix="Unreal Resource\x1A\0";
    $nameOffset=strlen($prefix);
    $names=pack('a32V','HeaderName',0x800);
    $dataOffset=$nameOffset+strlen($names);
    $payload='DATA'.'MORE';
    $exportOffset=$dataOffset+strlen($payload);
    $exports=pack('a32VVV','TextureOne',11,3,76)
        .pack('a32VVV','TextureTwo',12,4,80);
    $headerOffset=$exportOffset+strlen($exports);
    // Native 32-bit virtual pointer + resource type, Name[32],
    // then nine DWORD members starting with Data* and ending with Unused1.
    $header1=pack('V2a32V9',0xDEADBEEF,3,'TextureOne',0,0,11,0xFFFFFFFF,0,$dataOffset,4,0x11223344,0);
    $header2=pack('V2a32V9',0xCAFEBABE,4,'TextureTwo',0,1,12,0xFFFFFFFF,0,$dataOffset+4,4,0x55667788,0)
        .pack('V',0x99AABBCC);
    if(strlen($header1)!==76 || strlen($header2)!==80) {
        throw new RuntimeException('Native 32-bit UResource layout mismatch.');
    }
    $h=$header1.$header2;
    $trailer=pack('V9',$version,2,0,1,$nameOffset,0,$exportOffset,$headerOffset,strlen($h))
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$payload.$exports.$h.'[SUMMARY]'.$trailer,
        'exportOffset'=>$exportOffset,
        'headerOffset'=>$headerOffset,
        'dataOffset'=>$dataOffset,
        'trailerOffset'=>$headerOffset+strlen($h)+strlen('[SUMMARY]')];
};
$p=tempnam(sys_get_temp_dir(),'ue1_resource_header_');
if(!is_string($p)){throw new RuntimeException('Cannot create test fixture');}
try {
    foreach([9,10] as $version) {
        $f=$fixture($version);
        file_put_contents($p,$f['bytes']);
        $serialized=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalSerializedObjectReader::read($p,'early_unreal_resource');
        $segments=$serialized['object_ranges'];
        $check("v{$version}_source_payload_ranges",
            count($segments)===2
            && $segments[0]['header']['offset']===$f['headerOffset']
            && $segments[0]['header']['size']===76
            && $segments[0]['data']['offset']===$f['dataOffset']
            && $segments[0]['data']['sha256']===hash('sha256','DATA')
            && $segments[1]['data']['sha256']===hash('sha256','MORE')
            && $serialized['class_property_schema_decoded']===false);
        $data=CatalogUE1OriginalResourceHeaderReader::read($p);
        $entries=$data['resource_headers']??[];
        $check("v{$version}_two_native_headers",($data['resource_headers_decoded']??false)===true
            && ($data['resource_data_decoded']??true)===false
            && count($entries)===2 && ($data['unconsumed_header_bytes']??null)===0);
        $check("v{$version}_source_offsets",$entries[0]['serialized_offset']===$f['headerOffset']
            && $entries[1]['serialized_offset']===$f['headerOffset']+76
            && $entries[1]['serialized_length']===80);
        $check("v{$version}_shared_fields",$entries[0]['resource_name']==='TextureOne'
            && $entries[0]['resource_version']===11
            && $entries[0]['resource_type']===3
            && $entries[0]['type_matches_table']
            && $entries[0]['name_matches_table']
            && $entries[0]['file_crc']===0x11223344);
        $check("v{$version}_data_boundaries",$entries[1]['file_data_offset']===$f['dataOffset']+4
            && $entries[1]['file_data_size']===4
            && $entries[1]['derived_header_bytes']===4
            && $entries[1]['resource_index']===1);
        $check("v{$version}_pointers_not_exposed",!array_key_exists('vtable_pointer',$entries[0])
            && !array_key_exists('data_pointer',$entries[0])
            && !array_key_exists('resource_data',$entries[0]));
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
            .' --json --full '.escapeshellarg($p).' 2>&1';
        $out=[];$exit=0;exec($cmd,$out,$exit);
        $cli=json_decode(implode("\n",$out),true);
        $check("v{$version}_cli_surfaces_headers_without_fake_payload",$exit===1
            && is_array($cli) && ($cli['full_validation']??null)==='FAILED'
            && ($cli['original_resource_metadata_status']??null)==='DECODED'
            && ($cli['original_resource_headers_status']??null)==='DECODED'
            && ($cli['original_resource_headers'][1]['resource_name']??null)==='TextureTwo');
        $broken=substr_replace($f['bytes'],pack('V',0x7FFFFFFF),$f['exportOffset']+40,4);
        file_put_contents($p,$broken);
        try {CatalogUE1OriginalResourceHeaderReader::read($p);$bad=false;}
        catch(RuntimeException){$bad=true;}
        $check("v{$version}_bad_header_stride_rejected",$bad);
        $broken=substr_replace($f['bytes'],pack('V',0x7FFFFFFF),$f['headerOffset']+60,4);
        file_put_contents($p,$broken);
        try {CatalogUE1OriginalResourceHeaderReader::read($p);$bad=false;}
        catch(RuntimeException){$bad=true;}
        $check("v{$version}_bad_data_offset_rejected",$bad);
        $broken=substr_replace($f['bytes'],'DifferentName', $f['headerOffset']+8,13);
        file_put_contents($p,$broken);
        $mismatch=CatalogUE1OriginalResourceHeaderReader::read($p);
        $check("v{$version}_mismatched_embedded_name_not_falsely_equated",
            ($mismatch['resource_headers'][0]['name_matches_table']??true)===false);
    }
} finally {@unlink($p);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
