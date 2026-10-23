#!/usr/bin/env php
<?php
/** Fatal C98 linker index bounds for real v61 class-default tagged values. */
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
$src='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnLinker.h';
$source=@file_get_contents($src);
$checks=[];$failed=[];$samples=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void{
 $checks[$label]=$ok;if(!$ok)$failed[]=$label;
};
$check('source_C98_linker_object_bounds_fatal',is_string($source)
 &&str_contains($source,'ExportMap.IsValidIndex( Index-1 )')
 &&str_contains($source,'ImportMap.IsValidIndex( -Index-1 )')
 &&str_contains($source,'IndexToObject( Index );'));
$check('source_C98_linker_fname_bounds_fatal',is_string($source)
 &&str_contains($source,'NameMap.IsValidIndex(NameIndex)')
 &&str_contains($source,'Bad name index %i/%i'));
$totals=['objects'=>0,'names'=>0,'null_objects'=>0,'export_objects'=>0,
 'import_objects'=>0,'null_names'=>0,'checked'=>0,'classes'=>0];
$badObject=false;$badName=false;
foreach($manifest['files'] as $entry){
 if(strtolower(pathinfo($entry['path'],PATHINFO_EXTENSION))!=='u')continue;
 $file=$entry['path'];$name=basename($file);
 $check('pinned_genuine_'.$name,is_file($file)
   &&strtolower(hash_file('sha256',$file))===strtolower($entry['sha256']));
 $pkg=new P($file);
 $exportMax=count($pkg->getExports());
 $importMax=count($pkg->getImports());
 $nameMax=count($pkg->getNames());
 foreach($pkg->getExports() as $ex){
  if((int)$ex['classIndex']!==0)continue;
  $class=T::inspect($file,$pkg,(int)$ex['index']);
  if(($class['status']??'')!=='class_default_tags_structurally_decoded_schema_unverified')continue;
  $totals['classes']++;
  $schema=$class['default_property_declaration_validation'];
  $locallyChecked=0;
  foreach($class['default_property_tags'] as $i=>$tag){
   $kind=$tag['type'];$index=$tag['value'];
   if($kind==='ObjectProperty'||$kind==='ClassProperty'){
    $totals['objects']++;$valid=is_int($index)
      &&$tag['value_kind']==='package_object_index'
      &&$index <= $exportMax&&$index>=-$importMax;
    if($index===0)$totals['null_objects']++;
    elseif($index>0)$totals['export_objects']++;
    else $totals['import_objects']++;
    if(!$badObject){
     $mutated=$class['default_property_tags'];
     $mutated[$i]['value']=$exportMax+1;
     try{Schema::inspect($file,$pkg,$class,$mutated);}
     catch(RuntimeException $e){$badObject=str_contains($e->getMessage(),'invalid linker object reference');}
    }
   }elseif($kind==='NameProperty'){
    $totals['names']++;$valid=is_int($index)
      &&$tag['value_kind']==='fname_index'
      &&$index>=0&&$index<$nameMax;
    if($index===0)$totals['null_names']++;
    if(!$badName){
     $mutated=$class['default_property_tags'];
     $mutated[$i]['value']=$nameMax;
     try{Schema::inspect($file,$pkg,$class,$mutated);}
     catch(RuntimeException $e){$badName=str_contains($e->getMessage(),'invalid linker FName');}
    }
   }else{continue;}
   $locallyChecked++;
   if(!$valid){$check('bad_actual_ref_'.$name.'_'.$ex['index'].'_'.$i,false);}
   if(count($samples)<8)$samples[]=['file'=>$name,'class'=>$ex['objectNameText'],
     'property'=>$tag['name'],'kind'=>$kind,'reference'=>$index];
  }
  $check('genuine_class_ref_count_'.$name.'_'.$ex['index'],
    ($schema['value_linker_reference_bounds_checked']??-1)===$locallyChecked);
  $totals['checked']+=$locallyChecked;
 }
}
$check('genuine_1423_object_refs',$totals['objects']===1423);
$check('genuine_84_name_refs',$totals['names']===84);
$check('genuine_1367_export_1_import_55_null',
 $totals['export_objects']===1367&&$totals['import_objects']===1&&$totals['null_objects']===55);
$check('genuine_1507_linker_bounds_checked',$totals['checked']===1507);
$check('invalid_object_export_is_fatal',$badObject);
$check('invalid_name_index_is_fatal',$badName);
echo json_encode(['ok'=>$failed===[],
 'checks'=>count($checks),'passed'=>count($checks)-count($failed),
 'failed'=>$failed,'totals'=>$totals,'samples'=>$samples,
 'epic_linker_source_sha256'=>is_string($source)?hash('sha256',$source):null,
 'remaining'=>'Bounds/compact reference identity only; actual cross-package imported object resolution, runtime object/class assignability and compiled engine oracle not accepted.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
