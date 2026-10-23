#!/usr/bin/env php
<?php
/** T99 v400 UArrayProperty::SerializeItem primitive Inner contracts. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$src='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp';
$source=@file_get_contents($src);
$stringSource=@file_get_contents(dirname($src).'/UnMisc.cpp');
$checks=[];$failed=[];$samples=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void{
 $checks[$label]=$ok;if(!$ok)$failed[]=$label;
};
foreach([
 'UArrayProperty::SerializeItem'=>'void UArrayProperty::SerializeItem( FArchive& Ar, void* Value ) const',
 'UArrayProperty_count'=>'Ar << AR_INDEX(n);',
 'UArrayProperty_inner'=>'Inner->SerializeItem( Ar, p+i*c );',
 'UFixedArrayProperty::SerializeItem'=>'void UFixedArrayProperty::SerializeItem( FArchive& Ar, void* Value ) const',
 'UFixedArrayProperty_no_prefix'=>'for( INT i=0; i<Count; i++ )',
 'UFixedArrayProperty_inner'=>'Inner->SerializeItem( Ar, (BYTE*)Value + i*Inner->ElementSize );',
 'UFixedArrayProperty_stored_count'=>'Ar << Inner << Count;',
 'UByteProperty'=>'Ar << *(BYTE*)Value;',
 'UIntProperty'=>'Ar << *(INT*)Value;',
 'UFloatProperty'=>'Ar << *(FLOAT*)Value;',
 'UBoolProperty'=>'if( B ) *(BITFIELD*)Value |=  BitMask;',
 'UNameProperty'=>'Ar << *(FName*)Value;',
 'UObjectProperty'=>'Ar << *(UObject**)Value;',
 'UStrProperty'=>'Ar << *(FString*)Value;',
] as $what=>$expr){
 $check('Epic_'.$what,is_string($source)&&str_contains($source,$expr));
}
foreach([
 'signed_AR_INDEX_FString_count'=>'INT SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num();',
 'FString_compact_count'=>'Ar << AR_INDEX(SaveNum);',
 'FString_ANSI_character_read'=>'A(i)=FromAnsi(ACh);',
 'FString_Unicode_character_read'=>'A(i)=FromUnicode(UCh);',
] as $symbol=>$expected){
 $check('Epic_UnMisc_'.$symbol,is_string($stringSource)
     &&str_contains($stringSource,$expected));
}
$compact=static function(int $n):string{
 $negative=$n<0;$n=abs($n);$first=($n&63)|($negative?128:0);$n>>=6;
 if(!$n)return chr($first);
 $s=chr($first|64);do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$build=static function(int $version,string $innerClass,string $value,
 bool $fixed=false,int $fixedCount=3)
 use($compact):string{
 $names=['None',$fixed?'FixedArrayProperty':'ArrayProperty','TestArray','Core','Class',
         $innerClass,'Inner'];
 $namesBin='';
 foreach($names as $n){$namesBin.=$compact(strlen($n)+1).$n."\0".pack('V',0);}
 $import1=$compact(3).$compact(4).pack('V',0).$compact(1);
 $import2=$compact(3).$compact(4).pack('V',0).$compact(5);
 $imports=$import1.$import2;
 $size=strlen($value);
 $tag=$compact(2).($size<=255?chr($fixed?0x5f:0x59).chr($size):
   chr($fixed?0x6f:0x69).pack('v',$size)).$value.$compact(0);
 $arrayNative=$compact(0).$compact(2).pack('V2',1,0).$compact(3).$compact(2);
 if($fixed){$arrayNative.=pack('V',$fixedCount);}
 $arrayBody=$tag.$arrayNative;
 $innerNative=$compact(0).$compact(0).pack('V2',1,0).$compact(3);
 if(in_array($innerClass,['ByteProperty','ObjectProperty','StructProperty'],true)){
  $innerNative.=$compact(0);
 }
 $innerBody=$compact(0).$innerNative;
 $headerSize=56;$nameOffset=$headerSize;
 $impOffset=$nameOffset+strlen($namesBin);
 $exportOffset=$impOffset+strlen($imports);
 $export1=$compact(-1).$compact(0).pack('V',0).$compact(2)
   .pack('V',0).$compact(strlen($arrayBody)).$compact(512);
 $export2=$compact(-2).$compact(0).pack('V',0).$compact(6)
   .pack('V',0).$compact(strlen($innerBody))
   .$compact(512+strlen($arrayBody));
 $header=pack('V9',0x9e2a83c1,$version,0,count($names),
   $nameOffset,2,$exportOffset,2,$impOffset);
 $header.=str_repeat("\0",16).pack('V',0);
 $tables=$header.$namesBin.$imports.$export1.$export2;
 if(strlen($tables)>512)throw new RuntimeException('Synthetic export tables too large');
 return $tables.str_repeat("\0",512-strlen($tables)).$arrayBody.$innerBody;
};
$run=static function(string $label,string $innerClass,string $bytes,
 string $expectStatus,?array $want=null,bool $fixed=false,int $fixedCount=3)
 use($build,$check,&$samples):void{
 $file=tempnam(sys_get_temp_dir(),'O06Array');
 if($file===false)throw new RuntimeException('Failed to create test file');
 try{
  file_put_contents($file,$build(69,$innerClass,$bytes,$fixed,$fixedCount));
  $pkg=new P($file);
  $check($label.'_valid_UE1_package',$pkg->validatePackage()===[]);
  $tag=T::inspect($file,$pkg,0);
  $check($label.'_source_tag_record',
    $tag['status']==='tag_envelope_decoded'
    &&$tag['tags'][0]['type']===($fixed?'FixedArrayProperty':'ArrayProperty')
    &&$tag['tags'][0]['serialized_size']===strlen($bytes));
  $outer=N::inspect($file,$pkg,0,$tag);
  $innerTag=T::inspect($file,$pkg,1);
  $inner=N::inspect($file,$pkg,1,$innerTag);
  $check($label.'_source_native_parent_inner',
    $outer['status']==='native_property_record_complete'
    &&$outer['property_kind']===($fixed?'fixedarrayproperty':'arrayproperty')
    &&(!$fixed||$outer['subclass_fields']['fixed_count']===$fixedCount)
    &&$outer['subclass_fields']['inner_reference']===2
    &&$inner['status']==='native_property_record_complete');
  $fakeClass=['export_index'=>0,'children_reference'=>1,'super_field_reference'=>0];
  $report=S::inspect($file,$pkg,$fakeClass,$tag['tags']);
  $sample=$report['array_value_samples'][0]??[];
  $status=$sample['status']??'';
  $check($label.'_source_array_result',
    $status===$expectStatus
    &&$report['primitive_array_values_decoded']===($want===null?0:1)
    &&$report['array_value_unverified']===($want===null?1:0)
    &&($want===null
      ?$report['status']==='array_property_value_unverified'
      :$report['status']==='all_available_declarations_match'));
  if($want!==null){
   $check($label.'_exact_inner_values',
     $sample['preview_first_32']===array_slice($want,0,32)
     &&$sample['element_count']===count($want)
     &&$sample['serialized_sha256']===hash('sha256',$bytes)
     &&$sample['all_values_previewed']===(count($want)<=32)
    &&$sample['array_serialization_family']===
        ($fixed?'fixed_declared_count':'dynamic_counted')
    &&$sample['count_compact_bytes']===($fixed?0:1));
  }
  $samples[]=['test'=>$label,'class'=>$innerClass,'status'=>$status,
    'count'=>$sample['element_count']??null,
    'reason'=>$sample['reason']??null];
 }finally{unlink($file);}
};
$run('byte_three','ByteProperty',$compact(3).chr(3).chr(254).chr(0),
  'array_inner_values_decoded',[3,254,0]);
$run('int_signed','IntProperty',$compact(3).pack('V3',100,0xfffffffe,0),
  'array_inner_values_decoded',[100,-2,0]);
$run('float_three','FloatProperty',$compact(3).pack('g3',1.5,-2.25,0),
  'array_inner_values_decoded',[1.5,-2.25,0.0]);
$run('bool_noncanonical','BoolProperty',$compact(3).chr(1).chr(0).chr(255),
  'array_inner_values_decoded',[true,false,true]);
$run('name_map_refs','NameProperty',$compact(3).$compact(2).$compact(6).$compact(0),
  'array_inner_values_decoded',[2,6,0]);
$run('object_import_export_refs','ObjectProperty',
  $compact(3).$compact(0).$compact(-2).$compact(1),
  'array_inner_values_decoded',[0,-2,1]);
$run('empty_int_array','IntProperty',$compact(0),
  'array_inner_values_decoded',[]);
$run('fixed_byte_three','ByteProperty',chr(3).chr(254).chr(0),
  'array_inner_values_decoded',[3,254,0],true);
$run('fixed_int_signed','IntProperty',pack('V3',100,0xfffffffe,0),
  'array_inner_values_decoded',[100,-2,0],true);
$run('fixed_bool_noncanonical','BoolProperty',chr(1).chr(0).chr(255),
  'array_inner_values_decoded',[true,false,true],true);
$run('fixed_name_refs','NameProperty',$compact(2).$compact(6).$compact(0),
  'array_inner_values_decoded',[2,6,0],true);
$run('fixed_object_refs','ObjectProperty',
  $compact(0).$compact(-2).$compact(1),
  'array_inner_values_decoded',[0,-2,1],true);
$run('fixed_truncated','ByteProperty',chr(3),
  'array_inner_data_unverified',null,true);
$run('fixed_trailing','ByteProperty',chr(3).chr(2).chr(1).chr(0),
  'array_inner_data_unverified',null,true);
$run('fixed_unsupported_nested','StructProperty','',
  'inner_struct_layout_unverified',null,true);
$strVal=static function(int $count,string $payload,?string $ascii):array{
 return ['signed_character_count'=>$count,
  'archive_encoding'=>$count<0?'unicode_16bit_le':'ansi_8bit',
  'serialized_character_bytes_hex'=>bin2hex($payload),
  'ascii_text'=>$ascii,'nul_terminated'=>$count!==0,
  'empty_on_native_load'=>abs($count)<=1,
  'runtime_locale_conversion_verified'=>false,
  'archive_value_kind'=>$count<0?'fstring_utf16_archive':'fstring_ansi_archive'];
};
$run('dynamic_nested_fstring_ansi_unicode_empty','StrProperty',
 $compact(3).$compact(3)."Hi\0"
 .$compact(-2).pack('v2',0x03a9,0).$compact(0),
 'array_inner_values_decoded',[
  $strVal(3,"Hi\0",'Hi'),
  $strVal(-2,pack('v2',0x03a9,0),null),
  $strVal(0,'','')]);
$run('fixed_nested_fstring_unicode_ansi_nonascii_empty','StrProperty',
 $compact(-3).pack('v3',65,66,0)
 .$compact(2)."\xe9\0".$compact(1)."\0",
 'array_inner_values_decoded',[
  $strVal(-3,pack('v3',65,66,0),'AB'),
  $strVal(2,"\xe9\0",null),
  $strVal(1,"\0",'')],true);
$run('dynamic_fstring_long_300_ansi','StrProperty',
 $compact(1).$compact(301).str_repeat('A',300)."\0",
 'array_inner_values_decoded',[
  $strVal(301,str_repeat('A',300)."\0",str_repeat('A',300))]);
$run('dynamic_fstring_40_items_preview_bounded','StrProperty',
 $compact(40).str_repeat($compact(2)."A\0",40),
 'array_inner_values_decoded',
 array_fill(0,40,$strVal(2,"A\0",'A')));
$run('fixed_fstring_40_items_preview_bounded','StrProperty',
 str_repeat($compact(2)."A\0",40),
 'array_inner_values_decoded',
 array_fill(0,40,$strVal(2,"A\0",'A')),true,40);
$run('dynamic_fstring_bad_unicode_length','StrProperty',
 $compact(2).$compact(2)."A\0".$compact(-2).pack('v',65),
 'array_inner_data_unverified');
$run('dynamic_fstring_bad_missing_terminator','StrProperty',
 $compact(1).$compact(2)."AB",'array_inner_data_unverified');
$run('fixed_fstring_bad_missing_terminator','StrProperty',
 $compact(2)."AB",'array_inner_data_unverified',null,true);
$run('fixed_fstring_extra_trailing','StrProperty',
 $compact(1)."\0".chr(0xFE),'array_inner_data_unverified',null,true);
$run('bad_count_truncated','ByteProperty',$compact(5).chr(4),
  'array_inner_data_unverified');
$run('bad_count_negative','IntProperty',$compact(-1),
  'array_inner_data_unverified');
$run('bad_trailing_bytes','ByteProperty',$compact(1).chr(9).chr(10),
  'array_inner_data_unverified');
$run('truncated_compact_reference','NameProperty',$compact(1).chr(0x40),
  'array_inner_data_unverified');
$run('unsupported_nested','StructProperty',$compact(0),
  'inner_struct_layout_unverified');
foreach([
 'invalid_name_reference'=>['NameProperty',$compact(1).$compact(7),
   'UE1 array inner FName linker index invalid'],
 'invalid_export_reference'=>['ObjectProperty',$compact(1).$compact(3),
   'UE1 array inner UObject linker index invalid'],
 'invalid_import_reference'=>['ObjectProperty',$compact(1).$compact(-3),
   'UE1 array inner UObject linker index invalid'],
] as $label=>[$kind,$bytes,$reason]){
 $file=tempnam(sys_get_temp_dir(),'O06ArrayBad');
 if($file===false)throw new RuntimeException('Temp creation failed');
 try{
  file_put_contents($file,$build(69,$kind,$bytes));
  $pkg=new P($file);
  $meta=T::inspect($file,$pkg,0);
  $fake=['export_index'=>0,'children_reference'=>1,
    'super_field_reference'=>0];
  $caught=false;
  try{S::inspect($file,$pkg,$fake,$meta['tags']);}
  catch(RuntimeException $e){$caught=str_contains($e->getMessage(),$reason);}
  $check($label.'_epic_linker_fatal',$caught);
 }finally{unlink($file);}
}
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'samples'=>$samples,'epic_source_sha256'=>is_string($source)?hash('sha256',$source):null,
 'epic_fstring_source_sha256'=>is_string($stringSource)?hash('sha256',$stringSource):null,
 'remaining'=>'Source-typed dynamic and fixed array values now include numeric, Bool, FName, UObject and independently counted ANSI/Unicode FString inner serializers. StructProperty, recursive arrays, native heap and TCHAR runtime conversion remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
