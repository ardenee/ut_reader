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
$checks=[];$failed=[];$samples=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void{
 $checks[$label]=$ok;if(!$ok)$failed[]=$label;
};
foreach([
 'UArrayProperty::SerializeItem'=>'void UArrayProperty::SerializeItem( FArchive& Ar, void* Value ) const',
 'UArrayProperty_count'=>'Ar << AR_INDEX(n);',
 'UArrayProperty_inner'=>'Inner->SerializeItem( Ar, p+i*c );',
 'UByteProperty'=>'Ar << *(BYTE*)Value;',
 'UIntProperty'=>'Ar << *(INT*)Value;',
 'UFloatProperty'=>'Ar << *(FLOAT*)Value;',
 'UBoolProperty'=>'if( B ) *(BITFIELD*)Value |=  BitMask;',
 'UNameProperty'=>'Ar << *(FName*)Value;',
 'UObjectProperty'=>'Ar << *(UObject**)Value;',
] as $what=>$expr){
 $check('Epic_'.$what,is_string($source)&&str_contains($source,$expr));
}
$compact=static function(int $n):string{
 $negative=$n<0;$n=abs($n);$first=($n&63)|($negative?128:0);$n>>=6;
 if(!$n)return chr($first);
 $s=chr($first|64);do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$build=static function(int $version,string $innerClass,string $value)
 use($compact):string{
 $names=['None','ArrayProperty','TestArray','Core','Class',
         $innerClass,'Inner'];
 $namesBin='';
 foreach($names as $n){$namesBin.=$compact(strlen($n)+1).$n."\0".pack('V',0);}
 $import1=$compact(3).$compact(4).pack('V',0).$compact(1);
 $import2=$compact(3).$compact(4).pack('V',0).$compact(5);
 $imports=$import1.$import2;
 $size=strlen($value);
 $tag=$compact(2).($size<=255?chr(0x59).chr($size):
   chr(0x69).pack('v',$size)).$value.$compact(0);
 $arrayNative=$compact(0).$compact(2).pack('V2',1,0).$compact(3).$compact(2);
 $arrayBody=$tag.$arrayNative;
 $innerNative=$compact(0).$compact(0).pack('V2',1,0).$compact(3);
 if(in_array($innerClass,['ByteProperty','ObjectProperty'],true)){
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
 string $expectStatus,?array $want=null)
 use($build,$check,&$samples):void{
 $file=tempnam(sys_get_temp_dir(),'O06Array');
 if($file===false)throw new RuntimeException('Failed to create test file');
 try{
  file_put_contents($file,$build(69,$innerClass,$bytes));
  $pkg=new P($file);
  $check($label.'_valid_UE1_package',$pkg->validatePackage()===[]);
  $tag=T::inspect($file,$pkg,0);
  $check($label.'_source_tag_record',
    $tag['status']==='tag_envelope_decoded'
    &&$tag['tags'][0]['type']==='ArrayProperty'
    &&$tag['tags'][0]['serialized_size']===strlen($bytes));
  $outer=N::inspect($file,$pkg,0,$tag);
  $innerTag=T::inspect($file,$pkg,1);
  $inner=N::inspect($file,$pkg,1,$innerTag);
  $check($label.'_source_native_parent_inner',
    $outer['status']==='native_property_record_complete'
    &&$outer['property_kind']==='arrayproperty'
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
     $sample['preview_first_32']===$want
     &&$sample['element_count']===count($want)
     &&$sample['serialized_sha256']===hash('sha256',$bytes)
     &&$sample['all_values_previewed']===true);
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
$run('bad_count_truncated','ByteProperty',$compact(5).chr(4),
  'array_inner_data_unverified');
$run('bad_count_negative','IntProperty',$compact(-1),
  'array_inner_data_unverified');
$run('bad_trailing_bytes','ByteProperty',$compact(1).chr(9).chr(10),
  'array_inner_data_unverified');
$run('truncated_compact_reference','NameProperty',$compact(1).chr(0x40),
  'array_inner_data_unverified');
$run('unsupported_nested','StrProperty',$compact(0),
  'inner_serializer_not_yet_implemented');
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
 'remaining'=>'Primitive byte/int/float/bool UArrayProperty inner serializers only. Object/Name/Str/Struct/nested arrays and native heap effects remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
