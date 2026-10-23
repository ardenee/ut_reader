#!/usr/bin/env php
<?php
/** UT99 nested UStructProperty -> local UStruct::SerializeBin field order. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
$base='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/';
$propSrc=@file_get_contents($base.'UnProp.cpp');
$classSrc=@file_get_contents($base.'UnClass.cpp');
$headerSrc=@file_get_contents(dirname($base).'/Inc/UnType.h');
$checks=[];$fail=[];$examples=[];
$check=static function(string $label,bool $ok)use(&$checks,&$fail):void{
 $checks[$label]=$ok;if(!$ok)$fail[]=$label;
};
foreach([
 'StructProperty_uses_SerializeBin'=>'Struct->SerializeBin( Ar, (BYTE*)Value );',
 'StructProperty_preloads_struct'=>'Ar.Preload( Struct );',
] as $name=>$needle){
 $check('Epic_'.$name,is_string($propSrc)&&str_contains($propSrc,$needle));
}
foreach([
 'SerializeBin_uses_TFieldIterator'=>'for( TFieldIterator<UProperty> It(this); It; ++It )',
 'SerializeBin_uses_ShouldSerializeValue'=>'if( It->ShouldSerializeValue(Ar) )',
 'SerializeBin_expands_ArrayDim'=>'for( Index=0; Index<It->ArrayDim; Index++ )',
 'SerializeBin_invokes_field_SerializeItem'=>'It->SerializeItem( Ar, Data + It->Offset + Index*It->ElementSize );',
] as $name=>$needle){
 $check('Epic_'.$name,is_string($classSrc)&&str_contains($classSrc,$needle));
}
$check('Epic_UT99_persistent_skip_flags',is_string($headerSrc)
 &&str_contains($headerSrc,'((PropertyFlags & CPF_Native')
 &&str_contains($headerSrc,'((PropertyFlags & CPF_Transient) && Ar.IsPersistent()'));
$compact=static function(int $n):string{
 $sign=$n<0;$n=abs($n);$b=($n&63)|($sign?128:0);$n>>=6;
 if(!$n)return chr($b);
 $s=chr($b|64);
 do{$v=$n&127;$n>>=7;$s.=chr($v|($n?128:0));}while($n);
 return $s;
};
$build=static function(bool $fixed,string $value,
 int $superRef=0,int $structRef=3,int $nextInt=5,int $floatFlags=0,
 int $memberOuter=3,int $intDim=2)
 use($compact):string{
 $names=['None',$fixed?'FixedArrayProperty':'ArrayProperty','TestArray',
 'Core','Class','StructProperty','Inner','Struct','LocalStruct',
 'IntProperty','First','FloatProperty','Second','Friendly','Category',
 'StrProperty','Third'];
 $nbytes='';
 foreach($names as $name){$nbytes.=$compact(strlen($name)+1).$name."\0".pack('V',0);}
 $imports='';
 foreach([1,5,7,9,11,15] as $nameId){
  $imports.=$compact(3).$compact(4).pack('V',0).$compact($nameId);
 }
 $none=$compact(0);
 $property=static function(int $next,int $dim,int $flags)
   use($none,$compact):string{
  return $none.$compact($next).pack('V2',$dim,$flags).$compact(14);
 };
 $tagSize=strlen($value);
 if($tagSize>65535)throw new RuntimeException('Synthetic value too large');
 $tagInfo=$tagSize<=255?chr($fixed?0x5f:0x59).chr($tagSize):
   chr($fixed?0x6f:0x69).pack('v',$tagSize);
 $arrayBody=$compact(2).$tagInfo.$value.$none
    .$none.$compact(2).pack('V2',1,0).$compact(14).$compact(2);
 if($fixed){$arrayBody.=pack('V',2);}
 $body=[
  $arrayBody,
  $none.$property(0,1,0).$compact($structRef),
  $none.$compact($superRef).$none.$none.$compact(4)
    .$compact(13).pack('V3',0,0,0),
  $none.$property($nextInt,$intDim,0),
  $none.$property(6,1,$floatFlags),
  $none.$property(0,1,0),
 ];
 $nameOff=56;$importOff=$nameOff+strlen($nbytes);
 $exportOff=$importOff+strlen($imports);
 $exp='';$offset=512;
 foreach($body as $i=>$bytes){
  $nameId=[2,6,8,10,12,16][$i];
  $exp.=$compact(-($i+1)).$none
      .pack('V',$i>=3?$memberOuter:($i===1?1:0)).$compact($nameId)
      .pack('V',0).$compact(strlen($bytes)).$compact($offset);
  $offset+=strlen($bytes);
 }
 $head=pack('V9',0x9e2a83c1,69,0,count($names),$nameOff,
   count($body),$exportOff,6,$importOff)
    .str_repeat("\0",16).pack('V',0);
 $tables=$head.$nbytes.$imports.$exp;
 if(strlen($tables)>512)throw new RuntimeException('Fixture table overflow');
 return $tables.str_repeat("\0",512-strlen($tables)).implode('',$body);
};
$record=static function(int $a,int $b,float $f,string $text)
 use($compact):string{
 return pack('V2',$a&0xffffffff,$b&0xffffffff)
   .pack('g',$f).$compact(strlen($text)+1).$text."\0";
};
$recordNoFloat=static function(int $a,int $b,string $text)
 use($compact):string{
 return pack('V2',$a&0xffffffff,$b&0xffffffff)
    .$compact(strlen($text)+1).$text."\0";
};
$run=static function(string $label,bool $fixed,string $value,
 string $expected,int $super=0,int $struct=3,int $next=5,int $flags=0,
 ?array $want=null,int $memberOuter=3,int $intDim=2)
 use($build,$check,&$examples):void{
 $path=tempnam(sys_get_temp_dir(),'o06Struct');
 if($path===false)throw new RuntimeException('Temp file creation failed');
 try{
  file_put_contents($path,$build($fixed,$value,$super,$struct,$next,$flags,
    $memberOuter,$intDim));
  $pkg=new P($path);
  $check($label.'_package_valid',$pkg->validatePackage()===[]);
  $meta=T::inspect($path,$pkg,0);
  $native=N::inspect($path,$pkg,1,T::inspect($path,$pkg,1));
  $actualStruct=N::inspect($path,$pkg,2,T::inspect($path,$pkg,2));
  $check($label.'_native_UStructProperty_with_real_UStruct',
    $native['property_kind']==='structproperty'
    &&$native['subclass_fields']['struct_reference']===$struct
    &&$actualStruct['status']==='native_struct_record_complete'
    &&$actualStruct['children_reference']===4);
  $fake=['export_index'=>0,'children_reference'=>1,
    'super_field_reference'=>0];
  $result=S::inspect($path,$pkg,$fake,$meta['tags']);
  $sample=$result['array_value_samples'][0]??[];
  $check($label.'_source_matched_status',
    ($sample['status']??'')===$expected
    &&$result['array_value_unverified']===($want===null?1:0)
    &&$result['primitive_array_values_decoded']===($want===null?0:1));
  if($want!==null){
   $check($label.'_member_order_arraydim_and_values',
     ($sample['element_count']??null)===count($want)
     &&$sample['preview_first_32']===$want
     &&$sample['serialized_sha256']===hash('sha256',$value)
     &&$sample['count_compact_bytes']===($fixed?0:1)
     &&$result['value_payload_semantics_verified']===false);
  }
  $examples[]=['test'=>$label,'status'=>$sample['status']??null,
    'reason'=>$sample['reason']??null,'element_count'=>$sample['element_count']??null];
 }finally{unlink($path);}
};
$member=static function(int $a,int $b,float $f,string $s,bool $skipFloat=false)
 use($compact):array {
 $parts=[['name'=>'First','kind'=>'intproperty','array_dim'=>2,
  'values'=>[$a,$b],'all_values_previewed'=>true]];
 if(!$skipFloat){
  $parts[]=['name'=>'Second','kind'=>'floatproperty','array_dim'=>1,
    'values'=>[$f],'all_values_previewed'=>true];
 }
 $parts[]=['name'=>'Third','kind'=>'strproperty','array_dim'=>1,
   'values'=>[[
    'signed_character_count'=>strlen($s)+1,
    'archive_encoding'=>'ansi_8bit',
    'serialized_character_bytes_hex'=>bin2hex($s."\0"),
    'ascii_text'=>$s,'nul_terminated'=>true,
    'empty_on_native_load'=>strlen($s)===0,
    'runtime_locale_conversion_verified'=>false,
    'archive_value_kind'=>'fstring_ansi_archive']],
   'all_values_previewed'=>true];
 $size=8+($skipFloat?0:4)+strlen($compact(strlen($s)+1))+strlen($s)+1;
 return ['status'=>'struct_field_values_decoded',
   'source_struct_reference'=>3,'serialized_bytes'=>$size,
   'members'=>$parts,'native_struct_memory_verified'=>false];
};
$one=$record(10,-2,1.5,'A');
$two=$record(-17,42,-3.25,'B');
$run('dynamic_two_native_structs',false,$compact(2).$one.$two,
 'array_inner_values_decoded',0,3,5,0,[
 $member(10,-2,1.5,'A'),$member(-17,42,-3.25,'B')]);
$run('fixed_two_native_structs',true,$one.$two,
 'array_inner_values_decoded',0,3,5,0,[
 $member(10,-2,1.5,'A'),$member(-17,42,-3.25,'B')]);
$noFloat=$recordNoFloat(10,-2,'A').$recordNoFloat(-17,42,'B');
$run('persistent_transient_float_skipped',true,$noFloat,
 'array_inner_values_decoded',0,3,5,0x2000,[
 $member(10,-2,0.0,'A',true),$member(-17,42,0.0,'B',true)]);
$int40=range(1,40);
$longStruct=$member(1,2,1.5,'A');
$longStruct['members'][0]['array_dim']=40;
$longStruct['members'][0]['values']=range(1,32);
$longStruct['members'][0]['all_values_previewed']=false;
$longStruct['serialized_bytes']=40*4+4+3;
$run('dynamic_struct_member_array_40_preview_bounded',false,
 $compact(1).pack('V*',...$int40).pack('g',1.5).$compact(2)."A\0",
 'array_inner_values_decoded',0,3,5,0,[$longStruct],3,40);
$run('struct_child_field_wrong_owner',false,$compact(0),
 'inner_struct_layout_unverified',0,3,5,0,null,1);
$run('member_value_truncated',false,
 $compact(1).pack('V2',1,2).pack('g',1.5).$compact(4)."X",
 'array_inner_data_unverified');
$run('unresolved_struct_import',false,$compact(0),
 'inner_struct_layout_unverified',0,-1);
$run('unresolved_struct_inheritance',false,$compact(0),
 'inner_struct_layout_unverified',1,3);
$run('field_next_cycle',false,$compact(0),
 'inner_struct_layout_unverified',0,3,4);
echo json_encode(['ok'=>$fail===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($fail),'failed'=>$fail,'examples'=>$examples,
 'epic_sha256'=>[
 'UnProp.cpp'=>is_string($propSrc)?hash('sha256',$propSrc):null,
 'UnClass.cpp'=>is_string($classSrc)?hash('sha256',$classSrc):null,
 'UnType.h'=>is_string($headerSrc)?hash('sha256',$headerSrc):null],
 'remaining'=>'Only local non-inherited UStruct with full source-resolved primitive/FString member declarations. Inherited or imported UStruct, nested struct member, arbitrary container and compiled UE1 runtime memory are unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
