#!/usr/bin/env php
<?php
/** Full v69 source-typed consumer + imported parent .u fixture. DEV only. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as Struct;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructImportResolver as Imported;

$sourceRoot='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/';
$class=@file_get_contents($sourceRoot.'Inc/UnClass.h');
$linker=@file_get_contents($sourceRoot.'Src/UnLinker.h');
$checks=[];$failed=[];$cases=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$check('Epic_TFieldIterator_visits_inherited_definitions',
 is_string($class)&&str_contains($class,'Struct = Struct->GetInheritanceSuper();')
 &&str_contains($class,'Field = Struct->Children;'));
$check('Epic_ULinkerLoad_uses_VerifyImport',
 is_string($linker)&&str_contains($linker,'VerifyImport('));
$check('Epic_ULinkerLoad_uses_ImportFullName',
 is_string($linker)&&str_contains($linker,'GetImportFullName('));

$c=static function(int $n):string{
 $sign=$n<0;$n=abs($n);$first=($n&63)|($sign?128:0);$n>>=6;
 if(!$n)return chr($first);
 $s=chr($first|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$names=['None','ArrayProperty','TestArray','Core','Class',
 'StructProperty','Inner','Struct','LocalStruct','IntProperty','First',
 'FloatProperty','Second','Friendly','Category','StrProperty','Third',
 'ParentStruct','AncestorMember','NameProperty','LinkName',
 'ObjectProperty','LinkObject','ParentCore','ParentType','Package'];
$build=static function(bool $provider,string $value='',int $importKind=7,
 int $providerVersion=69,int $providerOwner=1,int $parentName=24,
 int $superRef=-8,int $innerStructRef=3,int $importClassPackage=3)
 use($names,$c):string{
 $ns='';
 foreach($names as $name){$ns.=$c(strlen($name)+1).$name."\0".pack('V',0);}
 $imports='';
 $classIds=$provider?[7,9]:[1,5,7,9,11,15];
 foreach($classIds as $nameId){
  $imports.=$c(3).$c(4).pack('V',0).$c($nameId);
 }
 if(!$provider){
  // Import[7] root Package ParentCore, Import[8] its child Struct.
  $imports.=$c(3).$c(25).pack('V',0).$c(23);
  $imports.=$c($importClassPackage).$c($importKind).pack('V',-7).$c($parentName);
 }
 $none=$c(0);
 $property=static function(int $next,int $dim)use($none,$c):string{
  return $none.$c($next).pack('V2',$dim,0).$c(14);
 };
 if($provider){
  $body=[
   $none.$none.$none.$none.$c(2).$c(13).pack('V3',0,0,0),
   $none.$property(0,1)
  ];
  $exportNames=[24,18];$exportClasses=[-1,-2];
  $owners=[0,$providerOwner];
 }else{
  if(strlen($value)>255){throw new RuntimeException('Test input too large');}
  $tag=$c(2).chr(0x59).chr(strlen($value)).$value.$none;
  $body=[
   $tag.$none.$c(2).pack('V2',1,0).$c(14).$c(2),
   $none.$property(0,1).$c($innerStructRef),
   $none.$c($superRef).$none.$none.$c(4).$c(13).pack('V3',0,0,0),
   $none.$property(5,2),
   $none.$property(6,1),
   $none.$property(0,1),
  ];
  $exportNames=[2,6,8,10,12,16];$exportClasses=[-1,-2,-3,-4,-5,-6];
  $owners=[0,1,0,3,3,3];
 }
 $nameOff=56;$importOff=$nameOff+strlen($ns);
 $exportOff=$importOff+strlen($imports);
 $offset=1024;$exports='';
 foreach($body as $i=>$bytes){
  $exports.=$c($exportClasses[$i]).$none.pack('V',$owners[$i])
    .$c($exportNames[$i]).pack('V',0).$c(strlen($bytes)).$c($offset);
  $offset+=strlen($bytes);
 }
 $header=pack('V9',0x9e2a83c1,$provider?$providerVersion:69,0,
   count($names),$nameOff,count($body),$exportOff,
   count($classIds)+($provider?0:2),$importOff)
  .str_repeat("\0",16).pack('V',0);
 $tabs=$header.$ns.$imports.$exports;
 if(strlen($tabs)>1024){throw new RuntimeException('Package table overflow');}
 return $tabs.str_repeat("\0",1024-strlen($tabs)).implode('',$body);
};
$record=static function(int $x,int $y,float $z,string $text,int $parent)
 use($c):string{
 return pack('V2',$x&0xffffffff,$y&0xffffffff)
   .pack('g',$z).$c(strlen($text)+1).$text."\0"
   .pack('V',$parent&0xffffffff);
};
$data=$c(2).$record(10,-2,1.5,'A',123)
 .$record(-17,42,-3.25,'B',-4);
$root='C:/Temp/UnrealDB/O06/imported-struct-'.bin2hex(random_bytes(5));
if(!is_dir($root)&&!mkdir($root,0777,true)){throw new RuntimeException('Cannot create C:\Temp fixture directory');}
$consumer=$root.'/ChildArray.u';$parent=$root.'/ParentCore.u';
$make=static function(string $parentData,string $childData)use(&$parent,&$consumer):void{
 file_put_contents($parent,$parentData);
 file_put_contents($consumer,$childData);
};
$read=static function()use(&$consumer):array{
 $pkg=new P($consumer);
 $meta=T::inspect($consumer,$pkg,0);
 $fake=['export_index'=>0,'children_reference'=>1,
        'super_field_reference'=>0];
 return [$pkg,S::inspect($consumer,$pkg,$fake,$meta['tags'])];
};
try{
 $provider=$build(true);
 $child=$build(false,$data);
 $make($provider,$child);
 $pkg=new P($consumer);
 $check('genuine_shaped_consumer_valid',$pkg->validatePackage()===[]);
 $providerPkg=new P($parent);
 $check('genuine_shaped_provider_valid',$providerPkg->validatePackage()===[]);
 $parentImport=Imported::resolve($consumer,$pkg,-8);
 $check('strict_import_package_class_outer_resolves',
   is_array($parentImport)
   &&$parentImport['ref']===1
   &&strcasecmp(str_replace('\\','/',$parentImport['path']),$parent)===0);
 $layout=Struct::resolve($consumer,$pkg,3);
 $check('own_then_imported_parent_field_order',
   $layout['status']==='struct_local_field_layout_resolved'
   &&$layout['source_imported_struct_hops']===1
   &&array_column($layout['fields'],'name')===
     ['First','Second','Third','AncestorMember']
   &&count($layout['source_definition_package_paths'])===2);
 [$pkg,$report]=$read();
 $sample=$report['array_value_samples'][0]??[];
 $check('complete_true_two_package_nested_binary_stream',
   $report['primitive_array_values_decoded']===1
   &&$report['array_value_unverified']===0
   &&$sample['status']==='array_inner_values_decoded'
   &&$sample['element_count']===2
   &&$sample['serialized_sha256']===hash('sha256',$data));
 $values=$sample['preview_first_32']??[];
 $check('source_inherited_values_order_and_signedness',
   count($values)===2
   &&$values[0]['members'][3]['values']===[123]
   &&$values[1]['members'][3]['values']===[-4]
   &&$values[0]['members'][0]['values']===[10,-2]
   &&$values[1]['members'][0]['values']===[-17,42]);
 $cases[]=['case'=>'valid_two_package',
    'status'=>$sample['status']??null,
    'parent_field'=>$values[0]['members'][3]['values'][0]??null];
 // The property itself can reference a Struct import, without a
 // locally-exported derived Struct. Preserve imported field definitions.
 $directData=$c(2).pack('V2',42,0xfffffffa);
 $make($provider,$build(false,$directData,7,69,1,24,0,-8));
 $directPkg=new P($consumer);
 $directLayout=Struct::resolve($consumer,$directPkg,-8);
 $check('source_direct_imported_struct_field_layout',
   $directLayout['status']==='struct_local_field_layout_resolved'
   &&$directLayout['source_imported_struct_hops']===1
   &&array_column($directLayout['fields'],'name')===['AncestorMember']);
 [$directPkg,$directReport]=$read();
 $direct=$directReport['array_value_samples'][0]??[];
 $check('source_direct_imported_struct_values',
   ($direct['status']??'')==='array_inner_values_decoded'
   &&($direct['element_count']??0)===2
   &&($direct['preview_first_32'][0]['members'][0]['values']??null)===[42]
   &&($direct['preview_first_32'][1]['members'][0]['values']??null)===[-6]);
 $cases[]=['case'=>'direct_imported_UStructProperty','status'=>$direct['status']??null];
 $make($provider,$child);
 // Without the exact imported package, never accept a reconstructed struct.
 rename($parent,$root.'/MissingCore.u');
 [$pkg,$report]=$read();
 $check('missing_sibling_source_not_guessed',
   $report['array_value_unverified']===1
   &&($report['array_value_samples'][0]['status']??'')===
      'inner_struct_layout_unverified'
   &&($report['array_value_samples'][0]['reason']??'')===
      'struct_inherited_import_unverified');
 rename($root.'/MissingCore.u',$parent);
 // Spoofing source class identity or imported name must fail closed.
 foreach([
   ['wrong_import_class',$build(false,$data,9),$provider],
   ['wrong_imported_name',$build(false,$data,7,69,1,8),$provider],
   ['forged_import_class_package',$build(false,$data,7,69,1,24,-8,3,23),$provider],
   ['provider_wrong_owner',$child,$build(true,'',7,69,0)],
   ['provider_version_unsupported',$child,$build(true,'',7,61)],
 ] as [$case,$consumerData,$providerData]){
  // Source packages are immutable for each fixture scenario, just as
  // cooked packages are during a single engine load. Do not reuse a
  // static source-file package cache after overwriting its bytes.
  $caseDir=$root.'/'.$case;
  if(!mkdir($caseDir)){throw new RuntimeException('Cannot make scenario');}
  $parent=$caseDir.'/ParentCore.u';$consumer=$caseDir.'/ChildArray.u';
  try{
   $make($providerData,$consumerData);
   $pkt=new P($consumer);$layout=Struct::resolve($consumer,$pkt,3);
   $expected=$case==='provider_wrong_owner'
     ?'struct_child_owner_unverified'
     :'struct_inherited_import_unverified';
   $check($case.'_fail_closed',
     $layout['status']===$expected);
   $cases[]=['case'=>$case,'status'=>$layout['status']];
  }finally{
   foreach([$parent,$consumer] as $f){if(is_file($f)){unlink($f);}}
   rmdir($caseDir);
  }
 }
 $parent=$root.'/ParentCore.u';$consumer=$root.'/ChildArray.u';
 $make($provider,$child);
 $check('restored_two_package_resolves',
   Struct::resolve($consumer,new P($consumer),3)['status']===
      'struct_local_field_layout_resolved');
}finally{
 foreach([$consumer,$parent,$root.'/MissingCore.u'] as $file){
  if(is_file($file)){unlink($file);}
 }
 if(is_dir($root)){rmdir($root);}
}
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'cases'=>$cases,'source_hashes'=>[
  'UnClass.h'=>is_string($class)?hash('sha256',$class):null,
  'UnLinker.h'=>is_string($linker)?hash('sha256',$linker):null],
 'remaining'=>'Only exact sibling ParentCore.u imported native Struct identity / child UStruct on-disk fields. Runtime GetPackageLinker, package manager, native object linker repair, global search and arbitrary engine family remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
