#!/usr/bin/env php
<?php
/** UE1 v1.200/UT99 v400 UConst::Serialize: UField references plus FString Value. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $signed):string {
 $negative=$signed<0;$val=abs($signed);
 $head=($val&63)|($negative?128:0);$val>>=6;
 if(!$val){return chr($head);}
 $out=chr($head|64);
 do{$byte=$val&127;$val>>=7;$out.=chr($byte|($val?128:0));}while($val);
 return $out;
};
$build=static function(int $ver,string $tail)use($compact):string {
 $names=['None','Const','TEST_CONST','Core','Class','Package'];
 $sumSize=$ver>=68?56:44;
 $nameMap='';
 foreach($names as $name){
  $nameMap.=($ver>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);
 }
 $import=$compact(3).$compact(4).pack('V',0).$compact(1);
 $data="\x00".($ver<57?"\x00":"").($ver<58?"\x00":"").$tail;
 $no=$sumSize;$io=$no+strlen($nameMap);$eo=$io+strlen($import);
 $export=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0).$compact(strlen($data)).$compact(512);
 $header=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io);
 $header.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $tables=$header.$nameMap.$import.$export;
 if(strlen($tables)>512){throw new RuntimeException('Fixture offset overlap');}
 return $tables.str_repeat("\0",512-strlen($tables)).$data;
};
$paths=[];$cases=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$cases,&$fail):void{
 $cases[$name]=$ok;if(!$ok)$fail[]=$name;
};
$inspect=static function(int $ver,string $tail,string $physicalTail='')use(&$paths,$build):array {
 $path=tempnam(sys_get_temp_dir(),'ue1const');
 if($path===false)throw new RuntimeException('No fixture file');
 $paths[]=$path;file_put_contents($path,$build($ver,$tail).$physicalTail);
 $pkg=new Package($path);
 if($pkg->validatePackage()!==[])throw new RuntimeException('Package fixture invalid');
 $family=$ver>=62?'ut99-v432':'epic-ue1'; // Explicit source of this fixture, not version-based dispatch.
 $props=Tags::inspect($path,$pkg,0,$family);
 if(($props['status']??'')!=='tag_envelope_decoded')throw new RuntimeException('Inherited UObject envelope unavailable');
 return [Native::inspect($path,$pkg,0,$props,$family),$props];
};
$bad=static function(int $ver,string $tail)use($inspect):bool{
 try{$inspect($ver,$tail);return false;}catch(RuntimeException){return true;}
};
try{
 $basic=$compact(0).$compact(0);
 foreach([50,69] as $v){
  $whole=$basic.$compact(5)."Test\0";
  [$cross]=$inspect($v,substr($whole,0,-1),substr($whole,-1));
  $check('v'.$v.'_UConst_physical_read_then_serial_size_mismatch',
   $cross['status']==='native_export_serial_size_mismatch'
   &&$cross['serial_size_overrun_bytes']===1
   &&$cross['source_serial_size_check_failed']===true);
  $check('v'.$v.'_UConst_physical_EOF',
   $bad($v,substr($whole,0,-1)));
 }

 [$a,$p]=$inspect(50,$basic.$compact(7)."TRUE42\0");
 $check('v50_ansi_and_refs',($a['status']??'')==='native_const_record_complete'
  && $a['value']==='TRUE42' && $a['value_count']===7
  && $a['super_field_reference']===0 && $a['next_field_reference']===0);
 $check('v50_legacy_uobject_names',($p['legacy_state_name']['text']??null)==='None'
  && ($p['legacy_group_name']['text']??null)==='None');
 [$b]=$inspect(69,$basic.$compact(5)."Caf\xe9\0");
 $check('v69_source_ansi_EpicFromAnsi',($b['value']??null)==="Caf\u{00e9}"
  && $b['value_encoding']==='Epic-FromAnsi-bytewise');
 $wide=iconv('UTF-8','UTF-16LE',"\u{03a9}K\0");
 if(!is_string($wide))throw new RuntimeException('No UTF16 fixture');
 [$c]=$inspect(69,$basic.$compact(-3).$wide);
 $check('v69_wide_UTF16',($c['status']??'')==='native_const_record_complete'
  &&$c['value']==="\u{03a9}K"&&$c['value_encoding']==='UTF-16LE');
 [$e]=$inspect(69,$compact(-1).$compact(1).$compact(0));
 $check('UField_refs_inherited_and_retained',$e['super_field_reference']===-1
    &&$e['next_field_reference']===1&&$e['value']==='');
 [$ext]=$inspect(69,$basic.$compact(2)."X\0"."\xff");
 $check('extension_not_accepted_complete',($ext['status']??'')==='native_const_has_extension'
  &&$ext['extension_bytes']===1);
 $check('wide_not_allowed_old_ver',$bad(50,$basic.$compact(-2)."X\0\0\0"));
 $check('positive_ref_oob',$bad(69,$compact(2).$compact(0).$compact(0)));
 $check('negative_ref_oob',$bad(69,$compact(-2).$compact(0).$compact(0)));
 $check('positive_count_exceeds_bounds',$bad(69,$basic.$compact(18)."A\0"));
 $check('negative_count_exceeds_bounds',$bad(69,$basic.$compact(-18)."\0\0"));
 // Original archive accepts these source-legal byte streams even
 // though treating the result as a NUL-terminated C string is unsafe.
 [$notTerminated]=$inspect(50,$basic.$compact(2)."AB");
 $check('c98_unterminated_char_array_source_archive_not_fatal',
    $notTerminated['status']==='native_const_archive_complete_text_unverified'
    &&$notTerminated['string_terminated']===false
    &&$notTerminated['source_text_value_verified']===false
    &&$notTerminated['value']===null
    &&$notTerminated['unverified_value_bytes_preview_hex']==='4142');
 [$notTerminatedWide]=$inspect(69,$basic.$compact(-2)."\x41\0\x42\0");
 $check('t99_unterminated_utf16_units_source_archive_not_fatal',
    $notTerminatedWide['status']==='native_const_archive_complete_text_unverified'
    &&$notTerminatedWide['string_terminated']===false
    &&$notTerminatedWide['unverified_value_bytes_preview_hex']==='41004200');
 [$invalidUnit]=$inspect(69,$basic.$compact(-2)."\0\xd8\0\0");
 $check('t99_lone_surrogate_code_unit_source_archive_not_fatal',
    $invalidUnit['status']==='native_const_archive_complete_text_unverified'
    &&$invalidUnit['string_terminated']===true
    &&$invalidUnit['source_text_value_verified']===false
    &&$invalidUnit['unverified_value_bytes_preview_hex']==='00d80000');
 [$single]=$inspect(69,$basic.$compact(1)."X");
 $check('t99_single_ansi_unit_cleared_after_load_even_without_nul',
    $single['status']==='native_const_record_complete'
    &&$single['string_terminated']===false
    &&$single['ut99_single_unit_cleared_on_load']===true
    &&$single['value']==='');
 [$sourceAnsi]=$inspect(69,$basic.$compact(3)."ABZ");
 $check('t99_unterminated_ansi_does_not_drop_last_archive_byte',
    $sourceAnsi['unverified_value_bytes_preview_hex']==='41425a'
    &&$sourceAnsi['value_count']===3
    &&$sourceAnsi['value_sha256']===hash('sha256',"ABZ"));
 $check('truncated_UField_ref',$bad(69,$compact(0)));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'passed'=>count($cases)-count($fail),'total'=>count($cases),
  'failed'=>$fail,'cases'=>$cases,
  'epic_source'=>['v1.200 Core/Src/UnClass.cpp:1463-1469',
    'v1.200 Core/Inc/UnClass.h:470-476','UT99 v400 Core/Src/UnClass.cpp:1438-1444',
    'UT99 v400 Core/Inc/UnClass.h:506-513']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
