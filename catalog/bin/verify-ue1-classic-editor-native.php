#!/usr/bin/env php
<?php
/** UT99 v400 Core UExporter/UFactory archive contracts; DEV-only verification. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$idx=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$head=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($head);$s=chr($head|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);return $s;
};
$prefix=static function(int $v):string{
 $raw=$v<40?pack('V2',0,0):'';
 if($v<47){$raw.="\0\0".str_repeat("\0",8);}
 return $raw."\0".($v<57?"\0":"").($v<58?"\0":"");
};
$package=static function(string $kind,int $ver,string $body)use($idx):string{
 $names=['None','Factory','Exporter','Instance','Core','Class',
   'LevelFactoryNew','ClassFactoryNew','TextureFactoryNew','TestName','TexName'];
 $nmap='';
 foreach($names as $n){$nmap.=($ver>=64?$idx(strlen($n)+1):'').$n."\0".pack('V',0);}
 $kindIds=['Factory'=>1,'Exporter'=>2,'LevelFactoryNew'=>6,'ClassFactoryNew'=>7,'TextureFactoryNew'=>8];
 $imp=$idx(4).$idx(5).($ver<50?$idx(0):pack('V',0)).$idx($kindIds[$kind]);
 $summarySize=$ver>=68?56:44;
 $no=$summarySize;$io=$no+strlen($nmap);$eo=$io+strlen($imp);
 $exp=$idx(-1).$idx(0).($ver<50?'':pack('V',0)).$idx(3).pack('V',0)
     .$idx(strlen($body)).$idx(512);
 $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io)
    .($ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0));
 $tbl=$sum.$nmap.$imp.$exp;
 if(strlen($tbl)>512){throw new RuntimeException('Fixture metadata too large');}
 return $tbl.str_repeat("\0",512-strlen($tbl)).$body;
};
$files=[];$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$parse=static function(string $kind,int $ver,string $data)use($package,$prefix,&$files):array{
 $bytes=$package($kind,$ver,$prefix($ver).$data);
 $p=tempnam(sys_get_temp_dir(),'ue1ed');
 if(!$p)throw new RuntimeException('Cannot create fixture');
 $files[]=$p;file_put_contents($p,$bytes);
 $pkg=new Package($p);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Invalid source fixture package');
 $tags=Tags::inspect($p,$pkg,0);
 if(($tags['status']??'')!=='tag_envelope_decoded'){
    throw new RuntimeException('Unrecognized property tag envelope');
 }
 return Native::inspect($p,$pkg,0,$tags);
};
$bad=static function(string $kind,int $ver,string $bytes)use($parse):bool{
 try{$parse($kind,$ver,$bytes);return false;}catch(RuntimeException){return true;}
};
$string=static fn(string $v):string=>$idx(strlen($v)+1).$v."\0";
try{
 foreach([61,68,69] as $ver){
  $factory=$parse('Factory',$ver,'');
  $check('factory_no_disk_native_v'.$ver,
    $factory['status']==='native_factory_no_file_fields'
    &&$factory['file_fields_serialized']===false
    &&$factory['extension_bytes']===0);
  $extra=$parse('Factory',$ver,"\x01");
  $check('factory_gc_fields_not_reinterpreted_v'.$ver,
    $extra['status']==='native_factory_unexpected_tail'
    &&$extra['extension_bytes']===1);
  $plain=$parse('Exporter',$ver,$idx(0).$idx(2).$string('bmp').$string('t3d'));
  $check('exporter_ansi_formats_v'.$ver,
    $plain['status']==='native_exporter_record_complete'
    &&$plain['supported_class_reference']===0
    &&$plain['format_count']===2
    &&$plain['formats_preview'][0]['format']==='bmp'
    &&$plain['formats_preview'][1]['format']==='t3d');
  $empty=$parse('Exporter',$ver,$idx(0).$idx(0));
  $check('exporter_zero_formats_v'.$ver,
    $empty['status']==='native_exporter_record_complete'
    &&$empty['format_count']===0);
  $negative=$parse('Exporter',$ver,$idx(-1).$idx(1).$string('obj'));
  $check('exporter_import_reference_v'.$ver,
    $negative['supported_class_reference']===-1
    &&$negative['formats_preview'][0]['format']==='obj');
  $positive=$parse('Exporter',$ver,$idx(1).$idx(1).$string('wav'));
  $check('exporter_export_reference_v'.$ver,
    $positive['supported_class_reference']===1
    &&$positive['format_count']===1);
  $check('reject_invalid_class_reference_v'.$ver,$bad('Exporter',$ver,$idx(-2).$idx(0)));
  $check('reject_missing_format_count_v'.$ver,$bad('Exporter',$ver,$idx(0)));
  $check('reject_negative_format_count_v'.$ver,$bad('Exporter',$ver,$idx(0).$idx(-1)));
  $check('reject_short_ansi_format_v'.$ver,$bad('Exporter',$ver,$idx(0).$idx(1).$idx(10)."x\0"));
  $check('reject_unterminated_format_v'.$ver,$bad('Exporter',$ver,$idx(0).$idx(1).$idx(3).'abc'));
  $tail=$parse('Exporter',$ver,$idx(0).$idx(0)."\x09");
  $check('unconsumed_extension_v'.$ver,
    $tail['status']==='native_exporter_has_extension'&&$tail['extension_bytes']===1);
  $many=$parse('Exporter',$ver,$idx(0).$idx(18).str_repeat($string('q'),18));
  $check('bounded_preview_v'.$ver,
    $many['format_count']===18&&count($many['formats_preview'])===16);
 }
 foreach([68,69] as $ver){
  $wide="t\0x\0t\0\0\0";
  $valid=$parse('Exporter',$ver,$idx(0).$idx(1).$idx(-4).$wide);
  $check('utf16_supported_v'.$ver,
    $valid['status']==='native_exporter_record_complete'
    &&$valid['formats_preview'][0]['format']==='txt'
    &&$valid['format_encodings']['UTF-16LE']===1);
  $check('utf16_terminator_v'.$ver,
    $bad('Exporter',$ver,$idx(0).$idx(1).$idx(-2)."a\0b\0"));
 }
 $check('v61_rejects_wide_count',$bad('Exporter',61,$idx(0).$idx(1).$idx(-1)."\0\0"));
 foreach(['Factory','Exporter','LevelFactoryNew','ClassFactoryNew','TextureFactoryNew'] as $kind){
  $out=$parse($kind,35,'');
  $check('no_ut99_layout_guess_v35_'.$kind,
   $out['status']==='source_ut99_editor_serializer_version_unverified');
 }
 foreach([61,68,69] as $ver){
  $level=$parse('LevelFactoryNew',$ver,$string('MyLevel').$string('Epic').pack('V',1));
  $check('level_factory_fields_v'.$ver,
    $level['status']==='native_levelfactorynew_record_complete'
    &&$level['level_title']==='MyLevel'
    &&$level['author']==='Epic'
    &&$level['close_existing_windows_bitfield']===1);
  $class=$parse('ClassFactoryNew',$ver,$idx(9).$idx(-1).$idx(1));
  $check('class_factory_fields_v'.$ver,
    $class['status']==='native_classfactorynew_record_complete'
    &&$class['class_name']['name']==='TestName'
    &&$class['class_package_reference']===-1
    &&$class['superclass_reference']===1);
  $texture=$parse('TextureFactoryNew',$ver,$idx(10).$idx(-1).$idx(1).pack('V2',64,128));
  $check('texture_factory_fields_v'.$ver,
    $texture['status']==='native_texturefactorynew_record_complete'
    &&$texture['texture_name']['name']==='TexName'
    &&$texture['texture_package_reference']===-1
    &&$texture['texture_class_reference']===1
    &&$texture['u_size']===64&&$texture['v_size']===128);
  $check('class_factory_bad_name_v'.$ver,
    $bad('ClassFactoryNew',$ver,$idx(99).$idx(0).$idx(0)));
  $check('class_factory_bad_object_v'.$ver,
    $bad('ClassFactoryNew',$ver,$idx(9).$idx(-2).$idx(0)));
  $check('texture_factory_bad_name_v'.$ver,
    $bad('TextureFactoryNew',$ver,$idx(-1).$idx(0).$idx(0).pack('V2',32,32)));
  $check('texture_factory_short_int_v'.$ver,
    $bad('TextureFactoryNew',$ver,$idx(10).$idx(0).$idx(0).pack('V',32)));
  $check('level_factory_truncated_bool_v'.$ver,
    $bad('LevelFactoryNew',$ver,$string('MyLevel').$string('Epic')."\0"));
  $check('level_factory_truncated_string_v'.$ver,
    $bad('LevelFactoryNew',$ver,$idx(10).'x'));
  $extended=$parse('ClassFactoryNew',$ver,$idx(9).$idx(0).$idx(0)."\xfa");
  $check('factory_extension_not_complete_v'.$ver,
    $extended['status']==='native_classfactorynew_has_extension'
    &&$extended['extension_bytes']===1);
 }
} finally {foreach($files as $p){@unlink($p);}}
echo json_encode(['ok'=>count($failed)===0,'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'source'=>'UT99 v400 Core/Src/UExporter.cpp UFactory.cpp',
 'limits'=>'direct names only; subclass inheritance not proven'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failed?1:0);
