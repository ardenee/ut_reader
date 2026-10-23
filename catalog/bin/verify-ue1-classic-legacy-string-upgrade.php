#!/usr/bin/env php
<?php
/** UT99 v400 old fixed StringProperty tag -> modern StrProperty load branch. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as Schema;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$source=@file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp');
$oldSource=@file_get_contents('L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp');
$checks=[];$failed=[];$samples=[];
$check=static function(string $k,bool $ok)use(&$checks,&$failed):void{
 $checks[$k]=$ok;if(!$ok)$failed[]=$k;
};
$check('T99_source_old_fixed_to_dynamic_branch',is_string($source)
 &&str_contains($source,'Tag.Type==NAME_StringProperty && Property->GetID()==NAME_StrProperty')
 &&str_contains($source,'//oldver: Upgrade fixed-length strings to dynamic strings')
 &&str_contains($source,'INT Count, MaxCount=Tag.Size ? Tag.Size+1 : ARRAY_COUNT(Temp);')
 &&str_contains($source,'Temp[Count] = FromAnsi(ACh);'));
$check('C98_source_has_no_dynamic_upgrade',is_string($oldSource)
 &&!str_contains($oldSource,'Tag.Type==NAME_StringProperty && Property->GetID()==NAME_StrProperty'));
$compact=static function(int $value):string{
 $negative=$value<0;$n=abs($value);
 $head=($n&63)|($negative?128:0);$n>>=6;
 if(!$n)return chr($head);
 $result=chr($head|64);
 do{$byte=$n&127;$n>>=7;$result.=chr($byte|($n?128:0));}while($n);
 return $result;
};
$make=static function(string $value,int $flags=0,bool $includeNul=true)
 use($compact):string{
 $ver=69;
 $names=['None','StrProperty','TestProperty','Core','Class','Gameplay'];
 $nameBytes='';
 foreach($names as $name){$nameBytes.=$compact(strlen($name)+1).$name."\0".pack('V',0);}
 $import=$compact(3).$compact(4).pack('V',0).$compact(1);
 $tagValue=$value.($includeNul?"\0":'');
 $tag=$compact(2).chr(0x57).chr(strlen($tagValue)).$tagValue.$compact(0);
 // Full valid UProperty export with UObject tagged prefix, UField and
 // UProperty persisted native fields. Class is imported StrProperty.
 $payload=$tag.$compact(0).$compact(0).pack('V2',1,$flags).$compact(5);
 $nameOff=56;$importOff=$nameOff+strlen($nameBytes);
 $exportOff=$importOff+strlen($import);
 $export=$compact(-1).$compact(0).pack('V',0).$compact(2)
    .pack('V',0).$compact(strlen($payload)).$compact(512);
 $summary=pack('V9',0x9e2a83c1,$ver,0,count($names),$nameOff,1,$exportOff,1,$importOff);
 $summary.=str_repeat("\0",16).pack('V',0);
 $table=$summary.$nameBytes.$import.$export;
 if(strlen($table)>512)throw new RuntimeException('Synthetic tables overflow');
 return $table.str_repeat("\0",512-strlen($table)).$payload;
};
$inspect=static function(string $value,int $flags=0,bool $nul=true)
 use($make,$check,&$samples):array{
 $p=tempnam(sys_get_temp_dir(),'u1strup');
 if($p===false)throw new RuntimeException('Temp file creation failed');
 try{
  file_put_contents($p,$make($value,$flags,$nul));
  $pkg=new Package($p);
  $check('valid_v69_package_'.count($samples),$pkg->validatePackage()===[]);
  $tagEnvelope=Tags::inspect($p,$pkg,0);
  $native=Native::inspect($p,$pkg,0,$tagEnvelope);
  $check('source_registered_StrProperty_'.count($samples),
    $native['status']==='native_property_record_complete'
    &&$native['property_kind']==='strproperty'
    &&$native['array_dim']===1);
  $tag=$tagEnvelope['tags'][0];
  $class=['export_index'=>0,'children_reference'=>1,'super_field_reference'=>0];
  $result=Schema::inspect($p,$pkg,$class,$tagEnvelope['tags']);
  $samples[]=['value_hex'=>bin2hex($value),'flags'=>$flags,
    'tag_kind'=>$tag['value_kind'],'status'=>$result['status'],
    'upgrade_count'=>$result['legacy_fixed_string_to_dynamic_upgrades']];
  return ['pkg'=>$pkg,'tag'=>$tag,'report'=>$result,
    'path'=>$p,'class'=>$class];
 }finally{unlink($p);}
};
$valid=$inspect('Legacy');
$check('v69_legacy_tag_ansi_value_retained',
 $valid['tag']['value_kind']==='legacy_ansi_cstring_bytes'
 &&$valid['tag']['value']['raw_ansi_bytes_hex']===bin2hex('Legacy')
 &&$valid['tag']['value']['ascii_text']==='Legacy');
$check('v69_legacy_upgrade_not_type_mismatch',
 $valid['report']['legacy_fixed_string_to_dynamic_upgrades']===1
 &&$valid['report']['declaration_type_and_array_index_matches']===1
 &&$valid['report']['known_incompatible_tags_skipped']===0
 &&$valid['report']['value_payload_semantics_verified']===false
 &&$valid['report']['legacy_string_codepage_verified']===false);
$nonAscii=$inspect("\xE9");
$check('v69_nonascii_ansi_remains_raw_without_codepage_guess',
 $nonAscii['tag']['value']['ascii_text']===null
 &&$nonAscii['tag']['value']['raw_ansi_bytes_hex']==='e9'
 &&$nonAscii['report']['legacy_fixed_string_to_dynamic_upgrades']===1);
$transient=$inspect('Archived',0x2000);
$check('v69_upgrade_precedes_ShouldSerialize_flag_branch',
 $transient['report']['legacy_fixed_string_to_dynamic_upgrades']===1
 &&$transient['report']['known_incompatible_tags_skipped']===0);
$missingNul=$inspect('Broken',0,false);
$check('v69_missing_nul_legacy_value_explicitly_unverified',
 $missingNul['tag']['value_kind']==='opaque'
 &&$missingNul['report']['status']==='legacy_fixed_string_upgrade_unverified'
 &&$missingNul['report']['legacy_fixed_string_upgrade_unverified']===1
 &&$missingNul['report']['known_incompatible_tags_skipped']===0);
// Mutated boundary: UT99 upgrade occurs before the usual Tag.ArrayIndex check.
// Guard it as unverified, never lie about the generic skip/warning path.
$path=tempnam(sys_get_temp_dir(),'u1strm');
try{
 file_put_contents($path,$make('Bounds'));
 $pkg=new Package($path);$envelope=Tags::inspect($path,$pkg,0);
 $class=['export_index'=>0,'children_reference'=>1,'super_field_reference'=>0];
 $mutated=$envelope['tags'];$mutated[0]['array_index']=10;
 $report=Schema::inspect($path,$pkg,$class,$mutated);
 $check('v69_upgrade_out_of_array_source_does_not_warn_skip',
   $report['status']==='legacy_fixed_string_upgrade_unverified'
   &&$report['known_incompatible_tags_skipped']===0
   &&$report['legacy_fixed_string_upgrade_unverified']===1);
 $mutated=$envelope['tags'];$mutated[0]['type']='IntProperty';
 $report=Schema::inspect($path,$pkg,$class,$mutated);
 $check('v69_other_incompatible_tag_recoverably_skipped',
   $report['status']==='known_incompatible_tags_skipped'
   &&$report['known_incompatible_tags_skipped']===1);
}finally{unlink($path);}
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'samples'=>$samples,'source_sha256'=>[
  'T99'=>is_string($source)?hash('sha256',$source):null,
  'C98'=>is_string($oldSource)?hash('sha256',$oldSource):null],
 'remaining'=>'This detects the actual archived ANSI legacy upgrade and source branch ordering. Does not simulate TCHAR locale conversion, FString heap storage, or compiled engine execution.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
