#!/usr/bin/env php
<?php
/** UT99 UMapProperty::SerializeItem has no archived element bytes. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$source=@file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp');
$checks=[];$failed=[];$examples=[];
$check=static function(string $label,bool $good)use(&$checks,&$failed):void{
 $checks[$label]=$good;if(!$good)$failed[]=$label;
};
$function='';
if(is_string($source)&&preg_match(
 '/void UMapProperty::SerializeItem\s*\(\s*FArchive& Ar,\s*void\* Value\s*\)\s*const\s*\{(.*?)\n\}/s',
 $source,$m)){$function=$m[1];}
$sourceArchive=preg_replace('~/\*.*?\*/~s','',$function);
$check('source_map_item_body_located',$function!=='');
$check('source_map_serializer_checks_Key_and_Value',
 str_contains($function,'checkSlow(Key);')
 &&str_contains($function,'checkSlow(Value);'));
$check('source_map_element_archive_loop_commented_out',
 str_contains($function,'/*')&&str_contains($function,'*/')
 &&!str_contains($sourceArchive,'Ar <<')
 &&!str_contains($sourceArchive,'SerializeItem('));
$check('source_map_definition_saves_Key_and_Value',
 is_string($source)&&str_contains($source,'Ar << Key << Value;'));
$compact=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$first=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($first);
 $s=chr($first|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$build=static function(string $value,int $keyRef=2,int $valueRef=3)
 use($compact):string {
 $names=['None','MapProperty','TestMap','Core','Class',
         'ByteProperty','Key','IntProperty','Value'];
 $nameData='';
 foreach($names as $name){$nameData.=$compact(strlen($name)+1).$name."\0".pack('V',0);}
 $imports='';
 foreach([1,5,7] as $nameId){$imports.=$compact(3).$compact(4).pack('V',0).$compact($nameId);}
 $tag=$compact(2).chr(0x5e).chr(strlen($value)).$value.$compact(0);
 $mapNative=$compact(0).$compact(0).pack('V2',1,0).$compact(3)
   .$compact($keyRef).$compact($valueRef);
 $mapData=$tag.$mapNative;
 $keyData=$compact(0).$compact(0).$compact(0).pack('V2',1,0)
   .$compact(3).$compact(0);
 $valueData=$compact(0).$compact(0).$compact(0).pack('V2',1,0)
   .$compact(3);
 $headerSize=56;$nameOff=$headerSize;$importOff=$nameOff+strlen($nameData);
 $exportOff=$importOff+strlen($imports);
 $exp=static function(int $classIndex,int $nameIndex,int $size,int $pos)
   use($compact):string{
  return $compact($classIndex).$compact(0).pack('V',0).$compact($nameIndex)
    .pack('V',0).$compact($size).$compact($pos);
 };
 $exports=$exp(-1,2,strlen($mapData),512)
   .$exp(-2,6,strlen($keyData),512+strlen($mapData))
   .$exp(-3,8,strlen($valueData),512+strlen($mapData)+strlen($keyData));
 $head=pack('V9',0x9e2a83c1,69,0,count($names),$nameOff,
   3,$exportOff,3,$importOff).str_repeat("\0",16).pack('V',0);
 $tables=$head.$nameData.$imports.$exports;
 if(strlen($tables)>512)throw new RuntimeException('Map fixture source tables too large');
 return $tables.str_repeat("\0",512-strlen($tables)).$mapData.$keyData.$valueData;
};
$run=static function(string $label,string $value,int $keyRef,int $valueRef,
 string $expected)
 use($build,$check,&$examples):void{
 $file=tempnam(sys_get_temp_dir(),'u1map');
 if($file===false)throw new RuntimeException('Cannot create map fixture');
 try{
  file_put_contents($file,$build($value,$keyRef,$valueRef));
  $pkg=new P($file);
  $check($label.'_valid_package',$pkg->validatePackage()===[]);
  $meta=T::inspect($file,$pkg,0);
  $native=N::inspect($file,$pkg,0,$meta);
  $check($label.'_source_native_map_metadata',
    $meta['tags'][0]['type']==='MapProperty'
    &&$meta['tags'][0]['serialized_size']===strlen($value)
    &&$native['property_kind']==='mapproperty'
    &&$native['subclass_fields']['key_reference']===$keyRef
    &&$native['subclass_fields']['value_reference']===$valueRef
    &&$native['status']==='native_property_record_complete');
  $fake=['export_index'=>0,'children_reference'=>1,'super_field_reference'=>0];
  $report=S::inspect($file,$pkg,$fake,$meta['tags']);
  $sample=$report['map_property_samples'][0]??[];
  $positive=$expected==='map_source_no_persisted_entries';
  $check($label.'_source_map_load_status',
    ($sample['status']??'')===$expected
    &&$report['map_property_source_no_archive_bytes']===($positive?1:0)
    &&$report['map_property_value_unverified']===($positive?0:1)
    &&$report['status']===($positive?'all_available_declarations_match':
       'map_property_value_unverified')
    &&$report['value_payload_semantics_verified']===false);
  $examples[]=['test'=>$label,'native_key'=>$keyRef,
    'native_value'=>$valueRef,'tag_bytes'=>strlen($value),
    'map_source_status'=>$sample['status']??null];
 }finally{unlink($file);}
};
$run('source_zero_bytes','',2,3,'map_source_no_persisted_entries');
$run('unexpected_value_byte',"\xF0",2,3,'map_source_payload_unverified');
$run('unexpected_four_bytes',pack('V',0x01020304),2,3,'map_source_payload_unverified');
$run('missing_key_property','',0,3,'map_source_payload_unverified');
$run('missing_value_property','',2,0,'map_source_payload_unverified');
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'examples'=>$examples,
 'epic_source_sha256'=>is_string($source)?hash('sha256',$source):null,
 'remaining'=>'UT99 map serializer encodes no map entries. Runtime in-memory map contents and other version families are not verified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
