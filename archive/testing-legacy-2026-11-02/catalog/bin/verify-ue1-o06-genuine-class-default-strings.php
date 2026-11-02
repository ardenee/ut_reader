#!/usr/bin/env php
<?php
/**
 * Genuine UE1 v61 fixed UStringProperty defaults: source byte identity.
 * FArchive::String persists one byte at a time to NUL or StringSize-1.
 * Does not infer non-ASCII codepage or memory allocation semantics.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as Schema;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
 $repo.'/catalog/compliance/inventory/ue1-o06-genuine-compiled-nontexture.json'),
 true,512,JSON_THROW_ON_ERROR);
$epicRoot='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/';
$archive=@file_get_contents($epicRoot.'Src/UnFile.cpp');
$type=@file_get_contents($epicRoot.'Inc/UnType.h');
$prop=@file_get_contents($epicRoot.'Src/UnProp.cpp');
$checks=[];$failed=[];$samples=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{
 $checks[$name]=$pass;if(!$pass){$failed[]=$name;}
};
$check('epic_UStringProperty_archive_item',is_string($type)
 &&str_contains($type,'Ar.String( (char*)Value, StringSize );'));
$check('epic_FArchive_String_byte_termination',is_string($archive)
 &&str_contains($archive,'for( INT Count=0; Count<MaxLength-1; Count++ )')
 &&str_contains($archive,'Serialize( &S[Count], 1 );')
 &&str_contains($archive,'if( S[Count] == 0 )'));
$check('epic_UStringProperty_native_StringSize',is_string($prop)
 &&str_contains($prop,'Ar << StringSize;'));
$stringCount=0;$roundTrip=0;$nullTerminated=0;$invalidValueKind=0;
$capacityNegative=false;
foreach($manifest['files'] as $spec){
 if(strtolower(pathinfo($spec['path'],PATHINFO_EXTENSION))!=='u')continue;
 $path=$spec['path'];$label=basename($path);
 $check('pinned_package_bytes_'.$label,is_file($path)
  &&strtolower(hash_file('sha256',$path))===strtolower($spec['sha256']));
 $pkg=new P($path);
 foreach($pkg->getExports() as $ex){
  if((int)$ex['classIndex']!==0)continue;
  $class=T::inspect($path,$pkg,(int)$ex['index']);
  if(($class['status']??'')!=='class_default_tags_structurally_decoded_schema_unverified')continue;
  $schema=$class['default_property_declaration_validation'];
  foreach($class['default_property_tags'] as $tag){
   if($tag['type']!=='StringProperty')continue;
   $stringCount++;$value=$tag['value'];
   if($tag['value_kind']!=='legacy_ansi_cstring_bytes'||!is_array($value)){
    $invalidValueKind++;continue;
   }
   $bytes=hex2bin($value['raw_ansi_bytes_hex']);
   $reconstructed=$bytes."\0";
   $actual=file_get_contents($path,false,null,
     (int)$tag['value_offset'],(int)$tag['on_disk_value_bytes']);
   $valid=is_string($bytes)&&$reconstructed===$actual
     &&strlen($actual)===(int)$tag['serialized_size']
     &&hash('sha256',$actual)===$tag['value_sha256']
     &&$value['byte_length']===strlen($bytes)
     &&($value['nul_terminated']??false)===true;
   if($valid){$roundTrip++;$nullTerminated++;}
   $check('genuine_string_'.$label.'_'.(int)$ex['index'].'_'.
       $tag['name'].'_'.(int)$tag['array_index'],$valid);
   if(count($samples)<5){$samples[]=[
     'package'=>$label,'class'=>$ex['objectNameText'],
     'property'=>$tag['name'],'value'=>$value];}
  }
  if(!$capacityNegative&&$schema['declaration_type_and_array_index_matches']>0){
   $mutated=$class['default_property_tags'];
   foreach($mutated as &$tag){
    if($tag['type']==='StringProperty'){
     $tag['serialized_size']=1000000;
     break;
    }
   }unset($tag);
   if($mutated!==$class['default_property_tags']){
    $r=Schema::inspect($path,$pkg,$class,$mutated);
    $capacityNegative=$r['status']==='fixed_string_archive_size_unverified'
      &&$r['fixed_string_archive_bounds_unverified']>0
      &&$r['known_incompatible_tags_skipped']===0
      &&count($r['fixed_string_archive_bound_samples'])>0;
   }
  }
 }
}
$check('all_576_genuine_v61_string_tags',$stringCount===576);
$check('all_576_are_typed_ansi_bytes',$invalidValueKind===0);
$check('all_576_c_string_payloads_exact_byte_roundtrip',
 $roundTrip===576&&$nullTerminated===576);
$check('oversized_string_archive_vs_declared_capacity_explicitly_unverified',
 $capacityNegative);
echo json_encode(['ok'=>$failed===[],
 'checks'=>count($checks),'passed'=>count($checks)-count($failed),
 'failed'=>$failed,'strings'=>$stringCount,'raw_byte_roundtrips'=>$roundTrip,
 'source_sha256'=>[
  'UnFile.cpp'=>is_string($archive)?hash('sha256',$archive):null,
  'UnType.h'=>is_string($type)?hash('sha256',$type):null,
  'UnProp.cpp'=>is_string($prop)?hash('sha256',$prop):null
 ],'samples'=>$samples,
 'remaining'=>'No guessed ANSI codepage; property slot allocation, native FArchive::String on malformations, and compiled Epic oracle still open.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
