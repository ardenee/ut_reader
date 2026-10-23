#!/usr/bin/env php
<?php
/**
 * Source-linked UClass default tag -> same-package UProperty declarations.
 * Real SHA-256 pinned compiled UE1 v61 script corpus. Imported ancestors
 * cannot be treated as known declarations; complex payloads remain partial.
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
 'class_schema_semantics_accepted'=>0];
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
   &&($class['defaults_schema_verified']??true)===false);
  $counts['tags']+=$total;$counts['matched']+=$matched;
  $counts['direct']+=$validation['matches_in_this_class'];
  $counts['inherited']+=$validation['matches_in_local_superclasses'];
  $counts['unresolved_imported_or_unknown']+=$unresolved;
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
   try{Schema::inspect($path,$pkg,$class,$mutated);}
   catch(RuntimeException){$negativeType=true;}
   $mutated=$class['default_property_tags'];
   foreach($mutated as &$tag){$tag['array_index']=1048576;}
   unset($tag);
   try{Schema::inspect($path,$pkg,$class,$mutated);}
   catch(RuntimeException){$negativeArray=true;}
  }
 }
 $fileRows[]=['file'=>$file,'classes_with_defaults'=>$localClasses,
   'default_property_tags'=>$localTags,'declaration_matched'=>$localMatched];
}
$check('genuine_513_class_default_exports',$counts['classes']===513);
$check('genuine_5344_default_tags',$counts['tags']===5344);
$check('source_declared_1689_tag_types_and_indices',$counts['matched']===1689);
$check('source_local_861_matches',$counts['direct']===861);
$check('source_inherited_828_matches',$counts['inherited']===828);
$check('import_super_gap_3655_explicitly_unverified',
 $counts['unresolved_imported_or_unknown']===3655);
$check('negative_tag_type_mismatch_rejected',$negativeType);
$check('negative_array_index_mismatch_rejected',$negativeArray);
echo json_encode(['ok'=>$failed===[],'checks'=>count($checkResults),
 'passed'=>count($checkResults)-count($failed),'failed'=>$failed,
 'counts'=>$counts,'files'=>$fileRows,'unresolved_examples'=>$examples,
 'source_sha256'=>[
   'UnClass.cpp'=>is_string($unclass)?hash('sha256',$unclass):null,
   'UnProp.cpp'=>is_string($unprop)?hash('sha256',$unprop):null],
 'remaining'=>'Imported superclass packages must be resolved before class defaults can have full declaration matching; original value payload semantics remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
