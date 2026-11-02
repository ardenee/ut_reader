#!/usr/bin/env php
<?php
/** Original UE1 v0.82/0.83 (resource v9) and v0.84a (resource v10) tables.
 * Epic: Src/Inc/UnResTyp.h, Src/Engine/UnRes.cpp::AddFile/SaveFile.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceTableReader;
$failed=[];$checks=[];
$check=static function(string $label,bool $ok)use(&$failed,&$checks):void{
    $checks[$label]=$ok;if(!$ok){$failed[]=$label;}
};
$mk=static function(int $ver):array{
    $tag="Unreal Resource\x1A";
    $prefix=$tag."\0";
    $names=pack('a32V','FirstName',0x12345678)
         .pack('a32V','SecondName',0xABCDEF10);
    $imports=pack('a32VVV','ImportOne',101,2,32);
    $exports=pack('a32VVV','ExportOne',201,3,48)
            .pack('a32VVV','ExportTwo',202,4,64);
    $header=str_repeat('H',24);
    $no=strlen($prefix);
    $io=$no+strlen($names);
    $eo=$io+strlen($imports);
    $ho=$eo+strlen($exports);
    $trailer=pack('V9',$ver,2,1,2,$no,$io,$eo,$ho,strlen($header))
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',$tag);
    return [
        'bytes'=>$prefix.$names.$imports.$exports.$header.'[SUMMARY]'.$trailer,
        'trailerOffset'=>$ho+strlen($header)+strlen('[SUMMARY]'),
        'namesOffset'=>$no,
        'importOffset'=>$io,
        'exportOffset'=>$eo,
        'headerOffset'=>$ho,
    ];
};
$path=tempnam(sys_get_temp_dir(),'ue1_original_tables_');
if(!is_string($path)){throw new RuntimeException('Cannot allocate fixture');}
try {
    foreach([9,10] as $v){
        $f=$mk($v);
        file_put_contents($path,$f['bytes']);
        $decoded=CatalogUE1OriginalResourceTableReader::read($path);
        $check("v{$v}_summary",($decoded['version']??null)===$v
            && ($decoded['metadata_decoded']??false)===true
            && ($decoded['resource_data_decoded']??true)===false);
        $check("v{$v}_table_counts",count($decoded['names']??[])===2
            && count($decoded['imports']??[])===1
            && count($decoded['exports']??[])===2);
        $check("v{$v}_names_exact",($decoded['names'][0]['name']??null)==='FirstName'
            && ($decoded['names'][0]['flags']??null)===0x12345678
            && ($decoded['names'][1]['name']??null)==='SecondName'
            && ($decoded['names'][1]['flags']??null)===0xABCDEF10);
        $check("v{$v}_imports_exact",($decoded['imports'][0]['name']??null)==='ImportOne'
            && ($decoded['imports'][0]['resource_version']??null)===101
            && ($decoded['imports'][0]['resource_type']??null)===2
            && ($decoded['imports'][0]['resource_header_size']??null)===32);
        $check("v{$v}_exports_exact",($decoded['exports'][1]['name']??null)==='ExportTwo'
            && ($decoded['exports'][1]['resource_version']??null)===202
            && ($decoded['exports'][1]['resource_type']??null)===4
            && ($decoded['exports'][1]['resource_header_size']??null)===64);
        $check("v{$v}_source_offsets",($decoded['names'][0]['serialized_offset']??null)===$f['namesOffset']
            && ($decoded['imports'][0]['serialized_offset']??null)===$f['importOffset']
            && ($decoded['exports'][1]['serialized_offset']??null)===$f['exportOffset']+44);
        // Real public CLI entry: metadata can be decoded without misreporting
        // that the original resource payload/object loader is complete.
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
            .' --json --full '.escapeshellarg($path).' 2>&1';
        $out=[];$exitCode=0;
        exec($command,$out,$exitCode);
        $cli=json_decode(implode("\n",$out),true);
        $check("v{$v}_real_cli_metadata_without_false_full_success",$exitCode===1
            && is_array($cli)
            && ($cli['original_resource_metadata_status']??null)==='DECODED'
            && ($cli['original_resource_metadata']['names'][1]['name']??null)==='SecondName'
            && ($cli['full_validation']??null)==='FAILED');
        $bytes=$f['bytes'];
        // Native AddFile ignores display markers and duplicated trailer tag.
        file_put_contents($path,substr_replace($bytes,'?????????',strlen($bytes)-164-9,9));
        $other=CatalogUE1OriginalResourceTableReader::read($path);
        $check("v{$v}_marker_is_not_required",($other['exports'][1]['name']??null)==='ExportTwo');
        file_put_contents($path,substr_replace($bytes,pack('V',0x7FFFFFFF),$f['trailerOffset']+16,4));
        try{CatalogUE1OriginalResourceTableReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$v}_invalid_name_offset_rejected",$rejected);
        file_put_contents($path,substr_replace($bytes,pack('V',0x7FFFFFFF),$f['trailerOffset']+32,4));
        try{CatalogUE1OriginalResourceTableReader::read($path);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check("v{$v}_invalid_header_length_rejected",$rejected);
    }
    file_put_contents($path,$mk(11)['bytes']);
    try{CatalogUE1OriginalResourceTableReader::read($path);$rejected=false;}
    catch(RuntimeException){$rejected=true;}
    $check('unknown_resource_version_not_misdecoded',$rejected);
} finally {@unlink($path);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
    'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
