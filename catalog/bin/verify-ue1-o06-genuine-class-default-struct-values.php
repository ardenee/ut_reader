#!/usr/bin/env php
<?php
/** True source-defined C98 typed FVector/FRotator/FScale/FColor default values. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
 $repo.'/catalog/compliance/inventory/ue1-o06-genuine-compiled-nontexture.json'),
 true,512,JSON_THROW_ON_ERROR);
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Engine/Inc/UnMath.h';
$raw=@file_get_contents($source);
$checks=[];$failed=[];$samples=[];$stats=['total'=>0,
 'vector'=>0,'rotator'=>0,'scale'=>0,'color'=>0,'raw_roundtrip'=>0];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[$name]=$ok;if(!$ok){$failed[]=$name;}
};
$check('pinned_epic_math_source_available',is_string($raw));
if(is_string($raw)){
 foreach(['return Ar << V.X << V.Y << V.Z;',
  'return Ar << R.Pitch << R.Yaw << R.Roll;',
  'return Ar << S.Scale << S.SheerRate << S.SheerAxis;'] as $sourceExpr){
  $check('epic_archive_'.substr(hash('sha256',$sourceExpr),0,10),
    str_contains($raw,$sourceExpr));
 }
}
foreach($manifest['files'] as $spec){
 if(strtolower(pathinfo($spec['path'],PATHINFO_EXTENSION))!=='u'){continue;}
 $path=$spec['path'];$name=basename($path);
 $check('source_pkg_sha256_'.$name,is_file($path)
  &&strtolower(hash_file('sha256',$path))===strtolower($spec['sha256']));
 $pkg=new P($path);
 foreach($pkg->getExports() as $ex){
  if((int)$ex['classIndex']!==0){continue;}
  $metadata=T::inspect($path,$pkg,(int)$ex['index']);
  if(($metadata['status']??'')!=='class_default_tags_structurally_decoded_schema_unverified'){
   continue;
  }
  foreach($metadata['default_property_tags'] as $tag){
   if($tag['type']!=='StructProperty'){continue;}
   $stats['total']++;$value=$tag['value'];$kind=$tag['value_kind'];
   $srcBytes=file_get_contents($path,false,null,
       (int)$tag['value_offset'],(int)$tag['on_disk_value_bytes']);
   $written=null;
   if($kind==='fvector_float32_xyz'){
    $stats['vector']++;
    $written=pack('g3',$value['x'],$value['y'],$value['z']);
   }elseif($kind==='frotator_int32_pitch_yaw_roll'){
    $stats['rotator']++;
    $written=pack('V3',$value['pitch'],$value['yaw'],$value['roll']);
   }elseif($kind==='fscale_vector_rate_axis'){
    $stats['scale']++;
    $v=$value['scale'];
    $written=pack('g4C',$v['x'],$v['y'],$v['z'],
      $value['sheer_rate'],$value['sheer_axis']);
   }elseif($kind==='fcolor_serialized_bytes'){
    $stats['color']++;
    $written=pack('C4',...$value['serialized_channel_bytes']);
   }
   $ok=is_string($written)&&$written===$srcBytes
    &&hash('sha256',$srcBytes)===$tag['value_sha256']
    &&strlen($srcBytes)===$tag['serialized_size'];
   $check('genuine_struct_'.$name.'_'.(int)$ex['index'].'_'.$tag['name'].
      '_'.(int)$tag['array_index'],$ok);
   if($ok){$stats['raw_roundtrip']++;}
   if(count($samples)<8){
    $samples[]=['file'=>$name,'class'=>$ex['objectNameText'],
     'tag'=>$tag['name'],'kind'=>$kind,'value'=>$value];
   }
  }
 }
}
$check('source_97_native_struct_tag_values',$stats['total']===97);
$check('source_42_FVector',$stats['vector']===42);
$check('source_49_FRotator',$stats['rotator']===49);
$check('source_3_FScale',$stats['scale']===3);
$check('source_3_FColor',$stats['color']===3);
$check('all_struct_values_exact_byte_roundtrip',
 $stats['raw_roundtrip']===$stats['total']);
echo json_encode(['ok'=>$failed===[],
 'passed'=>count($checks)-count($failed),'checks'=>count($checks),
 'failed'=>$failed,'stats'=>$stats,'samples'=>$samples,
 'epic_math_source_sha256'=>is_string($raw)?hash('sha256',$raw):null,
 'remaining'=>'Raw typed vector/rotator/scale/color source-value roundtrip only; 576 fixed-length strings, transient/class-specific/load-time value semantics and native engine oracle still unresolved.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
