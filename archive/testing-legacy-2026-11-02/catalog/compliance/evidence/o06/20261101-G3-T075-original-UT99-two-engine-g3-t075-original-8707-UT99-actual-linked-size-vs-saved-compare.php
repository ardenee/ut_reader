<?php
declare(strict_types=1);
require 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$folder='C:/Temp/UnrealDB/O06/';
$sizes=['IntProperty'=>4,'BoolProperty'=>4,'ObjectProperty'=>4,'FloatProperty'=>4,'ByteProperty'=>1,'NameProperty'=>4,'ClassProperty'=>4,'StrProperty'=>12,'ArrayProperty'=>12,'PointerProperty'=>4];
$checks=0;$fail=[];$count=0;$result=[];
$check=static function(bool $yes,string $message)use(&$checks,&$fail):void{$checks++;if(!$yes&&count($fail)<35)$fail[]=$message;};
foreach(['v432'=>['Native-UCC/System/','ut99-v432',4227],'f469'=>['Native-469f/System/','ut99-v469f',4480]] as $build=>$cfg){
 $lines=file($folder.'g3-t075-'.$build.'-original-compiled-native-linked-element-sizes.log');
 $cache=[];$types=[];$seen=[];$matched=0;$structSizes=[];
 foreach($lines as $ln){
  if(!str_starts_with($ln,'T075_NEW_ORIGINAL_NATIVE_PROPERTY '))continue;
  if(!preg_match('/file=(\S+) idx=(\d+) type=(\w+) element=(-?\d+) dim=(-?\d+) linked_size=(-?\d+) offset=(-?\d+)/',$ln,$m)){$fail[]='Bad native line';continue;}
  $file=basename(str_replace('\\','/',$m[1]));$i=(int)$m[2];$type=$m[3];$element=(int)$m[4];$dim=(int)$m[5];$size=(int)$m[6];$offset=(int)$m[7];
  if(!isset($cache[$file])){
   $path=$folder.$cfg[0].$file;$pkg=new P($path);
   $cache[$file]=[$path,$pkg,$pkg->getExports()];
  }
  [$path,$pkg,$ex]=$cache[$file];
  $check(isset($ex[$i]),"$build $file original export $i absent");
  $check(!isset($seen[$file][$i]),"$build $file duplicate $i");$seen[$file][$i]=1;
  try{$native=N::inspect($path,$pkg,$i,T::inspect($path,$pkg,$i,$cfg[1]),$cfg[1]);}
  catch(Throwable $e){$fail[]="$build $file $i ".$e->getMessage();continue;}
  $check(($native['class_name']??null)===$type,"$build $file $i class");
  $check(($native['array_dim']??null)===$dim,"$build $file $i native arrayDim=$dim saved=".json_encode($native['array_dim']??null));
  $check(($native['extension_bytes']??null)===0,"$build $file $i exact archive member cursor");
  $check($element>0&&$dim>0&&$size===$element*$dim&&$offset>=0,"$build $file $i original native member size/offset semantics");
  if($type==='StructProperty'){
   $check($element<=1048576,"$build $file $i linked struct element size overflow");
   $structSizes[$element]=($structSizes[$element]??0)+1;
  }else{
   $check(isset($sizes[$type])&&$sizes[$type]===$element,"$build $file $i $type linked source size $element");
  }
  $types[$type]=($types[$type]??0)+1;$matched++;
 }
 $check($matched===$cfg[2],"$build actual loaded property count $matched");
 $result[$build]=['native_source_profile'=>$cfg[1],'exact_genuine_original_properties'=>$matched,'by_type'=>$types,'struct_size_distribution'=>$structSizes,'physical_package_counts'=>array_map('count',$seen)];
 $count+=$matched;
}
$check($count===8707,'exact 8707 original compiled loaded properties');
echo json_encode(['ok'=>$fail===[],'assertions'=>$checks,'native_linked_properties'=>$count,'by_build'=>$result,'errors'=>$fail,'T075_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
