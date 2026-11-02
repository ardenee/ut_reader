#!/usr/bin/env php
<?php
/** C98 oldver struct aliases do not exist in UT99 v400 load source. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$c98=@file_get_contents('L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp');
$t99=@file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp');
$checks=[];$fail=[];$samples=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$check('C98_source_oldver_Rotation_Rotator_conversion',
 is_string($c98)
 &&str_contains($c98,'appStricmp(*Tag.ItemName,"Rotation")==0')
 &&str_contains($c98,'Tag.ItemName = "Rotator";'));
$check('C98_source_oldver_Region_PointRegion_conversion',
 is_string($c98)
 &&str_contains($c98,'appStricmp(*Tag.ItemName,"Region")==0')
 &&str_contains($c98,'Tag.ItemName = "PointRegion";'));
$check('UT99_source_requires_exact_struct_names',
 is_string($t99)
 &&str_contains($t99,'Tag.ItemName!=CastChecked<UStructProperty>(Property)->Struct->GetFName()')
 &&!str_contains($t99,'Tag.ItemName = "Rotator";')
 &&!str_contains($t99,'Tag.ItemName = "PointRegion";'));
$compact=static function(int $v):string{
 $n=abs($v);$first=($n&63)|($v<0?128:0);$n>>=6;
 if(!$n)return chr($first);
 $out=chr($first|64);
 do{$b=$n&127;$n>>=7;$out.=chr($b|($n?128:0));}while($n);
 return $out;
};
$build=static function(int $version,bool $oldAlias)
 use($compact):string{
 $names=['None','StructProperty','TestProperty','Core','Class',
  'Gameplay','Rotator','Rotation','ScriptStruct'];
 $nameBytes='';
 foreach($names as $name){
  $nameBytes.=($version>=64?$compact(strlen($name)+1):'')
   .$name."\0".pack('V',0);
 }
 $imp1=$compact(3).$compact(4).pack('V',0).$compact(1);
 $imp2=$compact(3).$compact(8).pack('V',0).$compact(6);
 $imports=$imp1.$imp2;
 $tag=$compact(2).chr(0x3a).$compact($oldAlias?7:6)
    .pack('V3',4096,5,0).$compact(0);
 $native=$compact(0).$compact(0).pack('V2',1,0)
    .$compact(5).$compact(-2);
 $payload=$tag.$native;
 $headSize=$version>=68?56:44;
 $nOff=$headSize;$iOff=$nOff+strlen($nameBytes);
 $eOff=$iOff+strlen($imports);
 $export=$compact(-1).$compact(0).pack('V',0)
    .$compact(2).pack('V',0).$compact(strlen($payload)).$compact(512);
 $head=pack('V9',0x9e2a83c1,$version,0,count($names),$nOff,1,
    $eOff,2,$iOff);
 $head.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $table=$head.$nameBytes.$imports.$export;
 if(strlen($table)>512)throw new RuntimeException('Synthetic .u tables exceed position');
 return $table.str_repeat("\0",512-strlen($table)).$payload;
};
foreach([61,69] as $version){
 foreach([true,false] as $oldAlias){
  $id='v'.$version.($oldAlias?'_old':'_current');
  $tmp=tempnam(sys_get_temp_dir(),'u1stalias');
  if($tmp===false)throw new RuntimeException('Unable to create fixture');
  try{
   file_put_contents($tmp,$build($version,$oldAlias));
   $pkg=new P($tmp);
   $check('source_package_'.$id,$pkg->validatePackage()===[]);
   $meta=T::inspect($tmp,$pkg,0);
   $native=N::inspect($tmp,$pkg,0,$meta);
   $check('native_struct_ref_source_identity_'.$id,
     $native['status']==='native_property_record_complete'
     &&$native['property_kind']==='structproperty'
     &&$native['subclass_fields']['struct_reference']===-2);
   $check('source_struct_tag_decoded_'.$id,
     ($meta['tags'][0]['struct_name']['text']??'')===
         ($oldAlias?'Rotation':'Rotator')
     &&$meta['tags'][0]['serialized_size']===12
     &&$meta['tags'][0]['value_kind']===
        ($version===69&&$oldAlias?'opaque':'frotator_int32_pitch_yaw_roll'));
   $syntheticClass=['export_index'=>0,'children_reference'=>1,
     'super_field_reference'=>0];
   $result=S::inspect($tmp,$pkg,$syntheticClass,$meta['tags']);
   $shouldMatch=$version===61||!$oldAlias;
   $check('source_struct_alias_gate_'.$id,
     $shouldMatch?
       ($result['declaration_type_and_array_index_matches']===1
         &&$result['known_incompatible_tags_skipped']===0):
       ($result['status']==='known_incompatible_tags_skipped'
         &&$result['declaration_type_and_array_index_matches']===0
         &&$result['known_incompatible_tags_skipped']===1
         &&($result['incompatible_tag_warning_samples'][0]['reason']??'')
           ==='struct_type_mismatch'));
   $samples[]=['version'=>$version,'tag'=>$meta['tags'][0]['struct_name']['text'],
     'declared'=>'Rotator','schema_status'=>$result['status'],
     'recognized'=>$result['declaration_type_and_array_index_matches']];
  }finally{unlink($tmp);}
 }
}
echo json_encode(['ok'=>$fail===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($fail),'failed'=>$fail,
 'samples'=>$samples,
 'source_sha256'=>['C98'=>is_string($c98)?hash('sha256',$c98):null,
 'T99'=>is_string($t99)?hash('sha256',$t99):null],
 'remaining'=>'This proves source-version-specific native struct-name compatibility; cross-overlapping source lineage v60/61 and compiled loader parity remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
