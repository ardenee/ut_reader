#!/usr/bin/env php
<?php
/** Native 1996 USound (type 12) original v9/v10 byte fixture. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSoundReader as Sound;
$checks=[];$bad=[];
$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{$checks[$n]=$ok;if(!$ok)$bad[]=$n;};
$make=static function(int $version):array{
    $prefix="Unreal Resource\x1A\0";
    $names=pack('a32V','SoundFamily',0);
    $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
    $audio=str_repeat("\xA5",128);
    $expOff=$dataOff+strlen($audio);
    $export=pack('a32VVV','NativeSound',1,12,344);
    $hOff=$expOff+strlen($export);
    $derived=pack('Vv',128,1)."\0\0".pack('V',0xffffffff).str_repeat("\0",256);
    $header=pack('V2a32V9',0,12,'NativeSound',0,0,1,0xffffffff,0,$dataOff,128,0,0).$derived;
    if(strlen($header)!==344)throw new RuntimeException('USound fixture has incorrect source layout');
    $trailer=pack('V9',$version,1,0,1,$nameOff,0,$expOff,$hOff,344)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$audio.$export.$header.'[SUMMARY]'.$trailer,
        'header'=>$hOff,'export'=>$expOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1snd96');
if(!is_string($tmp))throw new RuntimeException('No temporary path');
try{
 foreach([9,10] as $ver){
    $f=$make($ver);file_put_contents($tmp,$f['bytes']);
    $row=Sound::read($tmp,true)['sound_headers'][0]??[];
    $check("v{$ver}_native_sound_fields",
        ($row['status']??null)==='native_sound_header_decoded'
        && ($row['source_native_header_bytes']??null)===344
        && ($row['sound_data_size']??null)===128
        && ($row['sound_driver_id']??null)===-1
        && ($row['family_name_raw_word']??null)===1
        && ($row['data_size_matches_source']??false)===true);
    $check("v{$ver}_runtime_name_and_audio_not_fabricated",
        $row['runtime_name_fixups_evaluated']===false
        && $row['audio_bytes_decoded']===false);
    $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
        $tmp,['format'=>'early_unreal_resource','version'=>$ver]
    );
    $value=$stage['native_metadata']['resource_sound_headers']['sound_headers'][0]??[];
    $check("v{$ver}_staging_preserves_sound_fields_unverified",
        ($value['sound_data_size']??null)===128
        && $stage['parse_complete']===false);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',129),$f['header']+76,4));
    try{Sound::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$ver}_bad_source_data_length_rejected",$reject);
    file_put_contents($tmp,substr_replace($f['bytes'],pack('V',340),$f['export']+40,4));
    $status=Sound::read($tmp,false)['sound_headers'][0]['status']??null;
    try{Sound::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
    $check("v{$ver}_short_derived_sound_header_rejected",
        $status==='incomplete_native_sound_header'&&$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT),PHP_EOL;
exit($bad===[]?0:1);
