#!/usr/bin/env php
<?php
/**
 * Source-linked UClass defaults -> real same-package and imported UProperty
 * declarations via pinned UE1 v61 script packages and source-defined links.
 * Inherited declaration types/array bounds verified; value bytes remain partial.
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
$checkResults=[];$failed=[];$examples=[];$fileRows=[];
$check=static function(string $name,bool $good)use(&$checkResults,&$failed):void{
 $checkResults[$name]=$good;if(!$good){$failed[]=$name;}
};
$root='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/';
$unclass=@file_get_contents($root.'UnClass.cpp');
$unprop=@file_get_contents($root.'UnProp.cpp');
$check('source_field_next_and_struct_children',
 is_string($unclass)
 &&str_contains($unclass,'Ar << SuperField << Next;')
 &&str_contains($unclass,'Ar << ScriptText << Children;'));
$check('source_property_arraydim_archive',
 is_string($unprop)
 &&str_contains($unprop,'Ar << ArrayDim << PropertyFlags << Category;'));
$counts=['classes'=>0,'tags'=>0,'matched'=>0,'direct'=>0,'inherited'=>0,
 'unresolved_imported_or_unknown'=>0,'declaration_complete_classes'=>0,
 'cross_package_superclass_hops'=>0,'class_schema_semantics_accepted'=>0];
$negativeType=false;$negativeArray=false;
foreach($manifest['files'] as $spec){
 if(strtolower(pathinfo($spec['path'],PATHINFO_EXTENSION))!=='u')continue;
 $path=$spec['path'];$file=basename($path);
 $check('genuine_package_hash_'.$file,is_file($path)
  &&strtolower(hash_file('sha256',$path))===strtolower($spec['sha256']));
 $pkg=new P($path);
 $localClasses=0;$localTags=0;$localMatched=0;
 foreach($pkg->getExports() as $e){
  if((int)$e['classIndex']!==0){continue;}
  $class=T::inspect($path,$pkg,(int)$e['index']);
  if(($class['status']??'')!=='class_default_tags_structurally_decoded_schema_unverified'){
   continue;
  }
  $localClasses++;$counts['classes']++;
  $validation=$class['default_property_declaration_validation']??null;
  $check('validation_exists_'.$file.'_'.$e['index'],is_array($validation));
  if(!is_array($validation)){continue;}
  $total=$class['defaults_tag_count'];
  $matched=$validation['declaration_type_and_array_index_matches'];
  $unresolved=$validation['unresolved_tag_count'];
  $check('bounded_declaration_accounting_'.$file.'_'.$e['index'],
   $total===$matched+$unresolved
   &&$matched===$validation['matches_in_this_class']
     +$validation['matches_in_local_superclasses']
   &&($validation['value_payload_semantics_verified']??true)===false
   &&($validation['known_incompatible_tags_skipped']??-1)===0
   &&($class['defaults_schema_verified']??true)===false);
  $counts['tags']+=$total;$counts['matched']+=$matched;
  $counts['direct']+=$validation['matches_in_this_class'];
  $counts['inherited']+=$validation['matches_in_local_superclasses'];
  $counts['unresolved_imported_or_unknown']+=$unresolved;
  $counts['cross_package_superclass_hops']+=
    $validation['source_imported_superclasses_resolved']??0;
  if($unresolved===0){$counts['declaration_complete_classes']++;}
  $localTags+=$total;$localMatched+=$matched;
  if(count($examples)<8&&$unresolved>0){
   $examples[]=['file'=>$file,'class'=>$e['objectNameText'],
    'unresolved'=>$unresolved,
    'sample'=>$validation['unresolved_examples'][0]??null];
  }
  if(!$negativeType&&$matched>0){
   $mutated=$class['default_property_tags'];
   foreach($mutated as &$tag){$tag['type']='NonexistentProperty';}
   unset($tag);
   $diagnostic=Schema::inspect($path,$pkg,$class,$mutated);
   $negativeType=$diagnostic['status']==='known_incompatible_tags_skipped'
    &&$diagnostic['known_incompatible_tags_skipped']>0
    &&($diagnostic['incompatible_tag_warning_samples'][0]['reason']??'')==='type_mismatch'
    &&$diagnostic['declaration_type_and_array_index_matches']<$matched;
   $mutated=$class['default_property_tags'];
   foreach($mutated as &$tag){$tag['array_index']=1048576;}
   unset($tag);
   $diagnostic=Schema::inspect($path,$pkg,$class,$mutated);
   $negativeArray=$diagnostic['status']==='known_incompatible_tags_skipped'
    &&$diagnostic['known_incompatible_tags_skipped']>0
    &&($diagnostic['incompatible_tag_warning_samples'][0]['reason']??'')==='array_index_out_of_bounds'
    &&$diagnostic['declaration_type_and_array_index_matches']<$matched;
  }
 }
 $fileRows[]=['file'=>$file,'classes_with_defaults'=>$localClasses,
   'default_property_tags'=>$localTags,'declaration_matched'=>$localMatched];
}
// Copy a genuine archive into a clean C:\Temp fixture directory, without
// its imported Engine.u parent. A missing dependency MUST remain explicitly
// unresolved; a previously decoded parent must not leak from any global
// cache merely because the child class has the same serialized identity.
$tempDirectory='C:/Temp/ue1-o06-missing-super-'.bin2hex(random_bytes(5));
$sourceUnreal=null;
foreach($manifest['files'] as $candidate){
 if(basename($candidate['path'])==='UNreal.u'){$sourceUnreal=$candidate;break;}
}
$missingDependencyNegative=false;
$check('negative_import_fixture_source_pinned',is_array($sourceUnreal)
 &&strtolower(hash_file('sha256',$sourceUnreal['path']))===
    strtolower($sourceUnreal['sha256']));
if(is_array($sourceUnreal)&&mkdir($tempDirectory,0700,true)){
 $isolated=$tempDirectory.'/UNreal.u';
 try{
  if(!copy($sourceUnreal['path'],$isolated)){
   throw new RuntimeException('Cannot copy genuine isolated dependency fixture');
  }
  $check('negative_import_fixture_bytes_unchanged',
    hash_file('sha256',$isolated)===hash_file('sha256',$sourceUnreal['path']));
  $realPackage=new P($sourceUnreal['path']);
  $isolatedPackage=new P($isolated);
  $realClass=T::inspect($sourceUnreal['path'],$realPackage,0);
  $isolatedClass=T::inspect($isolated,$isolatedPackage,0);
  $positive=$realClass['default_property_declaration_validation'];
  $negative=$isolatedClass['default_property_declaration_validation'];
  $missingDependencyNegative=
    $realClass['default_property_tags']===$isolatedClass['default_property_tags']
    &&$positive['unresolved_tag_count']===0
    &&$positive['source_imported_superclasses_resolved']>0
    &&$negative['unresolved_tag_count']>0
    &&$negative['declaration_type_and_array_index_matches']<
      $positive['declaration_type_and_array_index_matches']
    &&$negative['has_unavailable_imported_superclass']===true
    &&$negative['value_payload_semantics_verified']===false;
 }finally{
  if(is_file($isolated)){unlink($isolated);}
  rmdir($tempDirectory);
 }
}
$check('negative_missing_sibling_dependency_stays_unverified',
 $missingDependencyNegative);
$check('genuine_513_class_default_exports',$counts['classes']===513);
$check('genuine_5344_default_tags',$counts['tags']===5344);
$check('source_declared_5344_tag_types_and_indices',$counts['matched']===5344);
$check('source_local_861_matches',$counts['direct']===861);
$check('source_inherited_4483_matches',$counts['inherited']===4483);
$check('import_super_resolved_967_hops',
 $counts['cross_package_superclass_hops']===967);
$check('all_declared_tags_matched_but_values_unverified',
 $counts['unresolved_imported_or_unknown']===0
 &&$counts['declaration_complete_classes']===513
 &&$counts['class_schema_semantics_accepted']===0);
$check('negative_tag_type_mismatch_warned_and_skipped',$negativeType);
$check('negative_array_index_warned_and_skipped',$negativeArray);
echo json_encode(['ok'=>$failed===[],'checks'=>count($checkResults),
 'passed'=>count($checkResults)-count($failed),'failed'=>$failed,
 'counts'=>$counts,'files'=>$fileRows,'unresolved_examples'=>$examples,
 'source_sha256'=>[
   'UnClass.cpp'=>is_string($unclass)?hash('sha256',$unclass):null,
   'UnProp.cpp'=>is_string($unprop)?hash('sha256',$unprop):null],
 'remaining'=>'All 5344 source-serialized tag declarations resolved in the pinned compiled corpus; opaque value semantics, import package generation and compiled native engine value parity remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
