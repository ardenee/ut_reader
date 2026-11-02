#!/usr/bin/env php
<?php
/** Epic v0.867 USound/UMusic native SerializeHeader + SerializeData fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileAudioReader as Audio;
$checks=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{
    $checks[$name]=$ok;if(!$ok)$bad[]=$name;
};
$fixture=static function(int $version):array{
    $names='';
    foreach(['Sound','Music','ClipA','TrackA','FamilyA'] as $name) $names.=$name."\0".pack('V',0);
    $stack=pack('V2',0,0).str_repeat("\xff",8);
    $sh=$stack.pack('Vv',4,5);$mh=$stack.pack('V',3);
    $base=56+strlen($names);
    $so=$base;$mo=$so+strlen($sh);$sd=$mo+strlen($mh);$md=$sd+4;$ob=$md+3;
    $objs=pack('vvVV',3,1,0,$so).pack('V4',0,$sd,22,4)
         .pack('vvVV',4,2,0,$mo).pack('V4',0,$md,20,3);
    $summary=pack('a32V6',"Unrealfile\x1A",$version,2,5,56,$ob,0);
    return ['bytes'=>$summary.$names.$sh.$mh.'WAVE'.'MOD'.$objs,
        'sound'=>$so,'sound_data'=>$sd,'music'=>$mo,'music_data'=>$md,'objects'=>$ob];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1audio');
if(!is_string($tmp))throw new RuntimeException('Audio fixture path unavailable');
try{
    foreach([22,23] as $v){
        $f=$fixture($v);file_put_contents($tmp,$f['bytes']);
        $items=Audio::read($tmp,true)['audio_records'];
        $sound=$items[0]??[];$music=$items[1]??[];
        $check("v{$v}_sound_members_and_exact_data",
            count($items)===2 && ($sound['native_class']??null)==='USound'
            && ($sound['status']??null)==='native_audio_serializer_decoded'
            && ($sound['family_name_ref']??null)===5
            && ($sound['family_name']??null)==='FamilyA'
            && ($sound['data_size']??null)===4
            && ($sound['data_sha256']??null)===hash('sha256','WAVE')
            && ($sound['runtime_audio_driver_loaded']??true)===false);
        $check("v{$v}_music_exact_data",
            ($music['native_class']??null)==='UMusic'
            && ($music['data_size']??null)===3
            && ($music['data_sha256']??null)===hash('sha256','MOD')
            && array_key_exists('family_name_ref',$music) && $music['family_name_ref']===null
            && ($music['audio_payload_decoded']??true)===false);
        $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
            $tmp,['format'=>'early_unrealfile','version'=>$v]
        );
        $stored=$staged['native_metadata']['unrealfile_audio_records']['audio_records'][0]??[];
        $check("v{$v}_staging_unverified",
            ($stored['family_name']??null)==='FamilyA'
            && ($staged['parse_complete']??true)===false);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',5),$f['sound']+16,4));
        try{Audio::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_wrong_datasize_rejected",$reject);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('v',6),$f['sound']+20,2));
        try{Audio::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_invalid_family_name_rejected",$reject);
        file_put_contents($tmp,substr_replace($f['bytes'],pack('V',19),$f['objects']+20,4));
        $partial=Audio::read($tmp,false)['audio_records'][0]??[];
        try{Audio::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
        $check("v{$v}_short_header_not_promoted",
            ($partial['status']??null)==='incomplete_native_audio_header' && $reject);
    }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
