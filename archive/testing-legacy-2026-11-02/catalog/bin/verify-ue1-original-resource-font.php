#!/usr/bin/env php
<?php
/** 1996 UFont native 88-byte header and FFontCharacter 16-byte glyphs. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceFontReader as Font;

$checks=[];$bad=[];
$check=static function(string $label,bool $okay)use(&$checks,&$bad):void{
    $checks[$label]=$okay;if(!$okay)$bad[]=$label;
};
$fixture=static function(int $version):array{
    $prefix="Unreal Resource\x1A\0";
    $names=pack('a32V','FontFamily',0);
    $nameOff=strlen($prefix);$dataOff=$nameOff+strlen($names);
    $glyphs=pack('V4',1,2,3,4).pack('V4',8,9,10,12).pack('V4',0,0,0,0);
    $exOff=$dataOff+strlen($glyphs);
    $ex=pack('a32VVV','NativeFont',1,6,88);
    $hOff=$exOff+strlen($ex);
    $header=pack('V2a32V9',0,6,'NativeFont',0,0,1,0xffffffff,0,$dataOff,strlen($glyphs),0,0)
        .pack('V3',3,3,0xfeedbabe);
    if(strlen($header)!==88)throw new RuntimeException('Bad native font fixture size');
    $end=pack('V9',$version,1,0,1,$nameOff,0,$exOff,$hOff,88)
        .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
    return ['bytes'=>$prefix.$names.$glyphs.$ex.$header.'[SUMMARY]'.$end,
        'header'=>$hOff,'export'=>$exOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1font96');
if(!is_string($tmp))throw new RuntimeException('No fixture temp path');
try{
    foreach([9,10] as $v){
        $f=$fixture($v);file_put_contents($tmp,$f['bytes']);
        $parsed=Font::read($tmp,true)['font_records'][0]??[];
        $check("v{$v}_native_font_header",
            ($parsed['status']??'')==='native_font_records_decoded'
            && ($parsed['glyph_count']??null)===3
            && ($parsed['glyph_capacity']??null)===3
            && ($parsed['record_bytes']??null)===16
            && ($parsed['serialized_data_bytes']??null)===48);
        $check("v{$v}_native_glyph_values",
            ($parsed['glyphs'][0]['start_u']??null)===1
            && ($parsed['glyphs'][0]['start_v']??null)===2
            && ($parsed['glyphs'][0]['u_size']??null)===3
            && ($parsed['glyphs'][1]['v_size']??null)===12);
        $check("v{$v}_texture_pointer_is_not_resolved",
            ($parsed['texture_pointer_raw_word']??null)===0xfeedbabe
            && $parsed['texture_pointer_not_portable']===true
            && $parsed['runtime_texture_reference_resolved']===false);
        $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unreal_resource','version'=>$v]
        );
        $stage=$staged['native_metadata']['resource_font_records']['font_records'][0]??[];
        $check("v{$v}_staging_keeps_unverified_native_font",
            ($stage['glyph_count']??null)===3
            && ($staged['parse_complete']??true)===false);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',4),$f['header']+76,4));
        try{Font::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_impossible_glyph_count_rejected",$reject);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',47),$f['header']+64,4));
        try{Font::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_incorrect_data_length_rejected",$reject);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',84),$f['export']+40,4));
        $relaxed=Font::read($tmp,false)['font_records'][0]??[];
        try{Font::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_short_header_not_falsely_decoded",
            ($relaxed['status']??null)==='incomplete_native_font_header'&&$reject);
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),'total'=>count($checks),
    'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
