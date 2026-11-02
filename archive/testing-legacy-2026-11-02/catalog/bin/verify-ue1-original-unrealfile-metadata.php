#!/usr/bin/env php
<?php
/** Source-defined v0.867 FUnrealfileSummary + FNameEntry metadata acceptance.
 * UnLinker.h:258-310, UnArc.h:56-69, UnName.h:19,39-55.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileMetadataReader;
$checks=[];$failures=[];
$check=static function(string $name, bool $value)use(&$checks,&$failures):void{
    $checks[$name]=$value;if(!$value){$failures[]=$name;}
};
$mk=static function(int $version=23,int $count=2,int $deps=2,bool $long=true):array{
    $d=['Core.u','Textures.u'];
    if($deps>2){$d=array_merge($d,array_fill(2,$deps-2,'more.u'));}
    $depbytes=implode('',array_map(static fn(string $s)=>$s."\0",array_slice($d,0,$deps)));
    $names=['Actors','A'.str_repeat('B',30)];
    $nameBytes=$names[0]."\0".pack('V',0x12345678);
    $nameBytes.=($long?$names[1]:"ShortName\0").pack('V',0xABCDEF10);
    $offset=56+strlen($depbytes)+4;
    $header=pack('a32V6',"Unrealfile\x1A",$version,0,$count,$offset,0,$deps);
    return ['bytes'=>$header.$depbytes.str_repeat("\0",4).$nameBytes,
       'nameOffset'=>$offset,'deps'=>$deps];
};
$path=tempnam(sys_get_temp_dir(),'ue1_unrealfile_meta_');
if(!is_string($path)){throw new RuntimeException('Could not create temp fixture');}
try {
    foreach([22,23] as $v) {
        $f=$mk($v);
        file_put_contents($path,$f['bytes']);
        $data=CatalogUE1OriginalUnrealfileMetadataReader::read($path);
        $check("v{$v}_family_and_status",($data['format']??null)==='early_unrealfile'
            && ($data['version']??null)===$v
            && ($data['metadata_decoded']??false)===true
            && ($data['object_bases_decoded']??true)===false);
        $check("v{$v}_native_header_fields",($data['summary']['name_count']??null)===2
            && ($data['summary']['object_count']??null)===0
            && ($data['summary']['names_offset']??null)===$f['nameOffset']
            && ($data['summary']['dependency_count']??null)===2);
        $check("v{$v}_serialized_dependencies",($data['dependencies']??null)===['Core.u','Textures.u']
            && ($data['summary']['serialized_summary_end']??null)===56+strlen("Core.u\0Textures.u\0"));
        $check("v{$v}_names_exact",($data['names'][0]['name']??null)==='Actors'
            && ($data['names'][0]['flags']??null)===0x12345678
            && ($data['names'][1]['flags']??null)===0xABCDEF10
            && strlen($data['names'][1]['name']??'')===31);
        $check("v{$v}_offsets_exact",($data['names'][0]['serialized_offset']??null)===$f['nameOffset']
            && ($data['names'][1]['serialized_offset']??null)===$f['nameOffset']+7+4);
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
            .' --json --full '.escapeshellarg($path).' 2>&1';
        $out=[];$exit=0;exec($cmd,$out,$exit);
        $cli=json_decode(implode("\n",$out),true);
        $check("v{$v}_real_cli_decodes_without_fake_full_validity",$exit===1
            && is_array($cli) && ($cli['full_validation']??null)==='FAILED'
            && ($cli['original_unrealfile_metadata_status']??null)==='DECODED'
            && ($cli['original_unrealfile_metadata']['dependencies'][0]??null)==='Core.u'
            && ($cli['original_unrealfile_metadata']['names'][0]['name']??null)==='Actors');
    }
    $f=$mk(23,2,2,true);
    file_put_contents($path,$f['bytes']);
    $d=CatalogUE1OriginalUnrealfileMetadataReader::read($path);
    $check('full_31_byte_name_has_no_serialized_nul',($d['names'][1]['name']??null)==='A'.str_repeat('B',30));
    file_put_contents($path,$mk(23,2,2,false)['bytes']);
    $short=CatalogUE1OriginalUnrealfileMetadataReader::read($path);
    $check('short_name_nul_and_flags',($short['names'][1]['name']??null)==='ShortName'
        && ($short['names'][1]['flags']??null)===0xABCDEF10);

    $f=$mk(23);
    file_put_contents($path,substr_replace($f['bytes'],pack('V',17),52,4));
    try{CatalogUE1OriginalUnrealfileMetadataReader::read($path);$r=false;}catch(RuntimeException){$r=true;}
    $check('over_16_dependencies_rejected',$r);

    file_put_contents($path,substr_replace($f['bytes'],pack('V',0x7FFFFFFF),44,4));
    try{CatalogUE1OriginalUnrealfileMetadataReader::read($path);$r=false;}catch(RuntimeException){$r=true;}
    $check('name_offset_outside_file_rejected',$r);

    file_put_contents($path,substr($f['bytes'],0,strlen($f['bytes'])-2));
    try{CatalogUE1OriginalUnrealfileMetadataReader::read($path);$r=false;}catch(RuntimeException){$r=true;}
    $check('truncated_last_flags_rejected',$r);

    file_put_contents($path,$mk(21)['bytes']);
    try{CatalogUE1OriginalUnrealfileMetadataReader::read($path);$r=false;}catch(RuntimeException){$r=true;}
    $check('below_source_minimum_version_not_promoted',$r);

    file_put_contents($path,str_repeat("\0",56));
    try{CatalogUE1OriginalUnrealfileMetadataReader::read($path);$r=false;}catch(RuntimeException){$r=true;}
    $check('wrong_format_tag_rejected',$r);
} finally {@unlink($path);}
echo json_encode(['ok'=>$failures===[],'passed'=>count($checks)-count($failures),
    'total'=>count($checks),'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
