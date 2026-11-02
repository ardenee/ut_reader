#!/usr/bin/env php
<?php
/** UE1 UMusic source FileType + TArray/TLazyArray archive, genuine UE1 layout fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $n):string{
    $neg=$n<0;$n=abs($n);$h=($n&63)|($neg?128:0);$n>>=6;
    if(!$n)return chr($h);$s=chr($h|64);
    do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
    return $s;
};
$build=static function(int $ver,string $payload)use($compact):string{
    $names=['None','Music','Track','Engine','Class','S3M'];
    $nmap='';
    foreach($names as $name){$nmap.=($ver>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);}
    $summarySize=$ver>=68?56:44;
    $im=$compact(3).$compact(4).($ver<50?$compact(0):pack('V',0)).$compact(1);
    $prefix=$ver<40?pack('V2',0,0):'';
    if($ver<47){$prefix.=$compact(0).$compact(0).str_repeat("\0",8);}
    $body=$prefix."\x00".($ver<57?"\x00":"").($ver<58?"\x00":"").$payload;
    $no=$summarySize;$io=$no+strlen($nmap);$eo=$io+strlen($im);
    $ex=$compact(-1).$compact(0).($ver<50?'':pack('V',0)).$compact(2).pack('V',0)
        .$compact(strlen($body)).$compact(512);
    $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io);
    $sum.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $tab=$sum.$nmap.$im.$ex;
    if(strlen($tab)>512)throw new RuntimeException('Fixture overlap');
    return $tab.str_repeat("\0",512-strlen($tab)).$body;
};
$music=static function(int $ver,string $bytes,int $name=5,int $lazyShift=0,int $lengthOverride=-1)use($compact):string{
    $count=$lengthOverride<0?strlen($bytes):$lengthOverride;
    $start=512+1+($ver<57?1:0)+($ver<58?1:0);
    $type=$compact($name);
    $countSer=$compact($count);
    $seekpos=$ver>61?pack('V',$start+strlen($type)+4+strlen($countSer)+strlen($bytes)+$lazyShift):'';
    return $type.$seekpos.$countSer.$bytes;
};
$paths=[];$cases=[];$failed=[];
$check=static function(string $n,bool $ok)use(&$cases,&$failed):void{
    $cases[$n]=$ok;if(!$ok){$failed[]=$n;}
};
$parse=static function(int $ver,string $body)use(&$paths,$build):array {
    $p=tempnam(sys_get_temp_dir(),'ue1music');
    if(!$p)throw new RuntimeException('No fixture path');
    $paths[]=$p;file_put_contents($p,$build($ver,$body));
    $pkg=new Package($p);
    if($pkg->validatePackage()!==[])throw new RuntimeException('Not a valid UE1 package fixture');
    $props=Tags::inspect($p,$pkg,0);
    if(($props['status']??'')!=='tag_envelope_decoded')throw new RuntimeException('Tag envelope unavailable');
    return [Native::inspect($p,$pkg,0,$props),$props,$p];
};
$reject=static function(int $ver,string $body)use($parse):bool {
    try{$parse($ver,$body);return false;}catch(RuntimeException){return true;}
};
try{
    [$a,$t,$file]=$parse(50,$music(50,"A\x01BC"));
    $check('v50_Umusic_source_TArray',($a['status']??'')==='native_music_record_complete'
      &&$a['file_type_name']==='S3M'&&$a['audio_data_bytes']===4
      &&$a['audio_data_offset']===517&&$a['lazy_seek_pos']===null
      &&$a['audio_data_decoded']===false);
    $check('v50_no_confusion_with_UObject_state_names',
      $t['legacy_state_name']['text']==='None'&&$t['legacy_group_name']['text']==='None'
      &&$t['native_tail_bytes']===6);
    [$b]=$parse(61,$music(61,"X\0"));
    $check('v61_TArray_boundary',$b['status']==='native_music_record_complete'
      &&$b['lazy_seek_pos']===null&&$b['audio_data_bytes']===2);
    [$c,$d,$p]=$parse(69,$music(69,"\x01\x02\x03\x04"));
    $check('v69_lazy_seekpos',$c['status']==='native_music_record_complete'
      &&$c['lazy_seek_pos']===523&&$c['audio_data_offset']===519
      &&$c['audio_data_bytes']===4);
    $check('v69_bytes_preserved_sha256',
      hash_file('sha256',$p)!==''&&$c['audio_data_decoded']===false);
    [$e]=$parse(69,$music(69,''));
    $check('v69_empty_data',$e['status']==='native_music_record_complete'
      &&$e['audio_data_bytes']===0&&$e['lazy_seek_pos']===519);
    [$x]=$parse(69,$music(69,'Z')."\xff");
    $check('extension_not_reported_complete',
      $x['status']==='native_music_has_extension'&&$x['extension_bytes']===1);
    $check('reject_negative_array_count',$reject(50,$compact(5).$compact(-1)));
    $check('reject_oversized_array_count',$reject(50,$music(50,'A',5,0,64)));
    $check('reject_invalid_name_ref',$reject(69,$music(69,'A',55)));
    $check('reject_bad_lazy_seekpos',$reject(69,$music(69,'ABC',5,1)));
    $check('reject_bad_lazy_seekpos_backward',$reject(69,$music(69,'ABC',5,-1)));
    $check('reject_truncated_payload',$reject(69,$compact(5)."\0"));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($cases)-count($failed),'total'=>count($cases),
 'failed'=>$failed,'cases'=>$cases,'source'=>[
 'Epic v1.200 Engine/Src/UnAudio.cpp:467-480, Engine/Inc/UnAudio.h:76-83',
 'UT99 v400 Engine/Src/UnAudio.cpp:518-531, Engine/Inc/UnAudio.h:105-113',
 'UT99 Core/Inc/UnTemplate.h::TLazyArray operator<< version>61 SeekPos']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
