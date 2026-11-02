#!/usr/bin/env php
<?php
/**
 * Genuine installed compiled UT99 v68/v69 UClass SerializeExpr archive
 * regression, pinned by SHA-256. Actual package bytes, not synthetic tests.
 * Epic native runtime execution still explicitly unverified.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
 $repo.'/catalog/compliance/inventory/ue1-o06-genuine-ut99-scripts.json'),
 true,512,JSON_THROW_ON_ERROR);
$source=(string)@file_get_contents($manifest['epic_source']);
$assertions=[];$failed=[];$summaries=[];$errors=[];
$check=static function(string $name,bool $ok)use(&$assertions,&$failed):void{
 $assertions[$name]=$ok;if(!$ok)$failed[]=$name;
};
$check('Epic_v400_uses_FLabelEntry_resolved_NAME_None',
 str_contains($source,'FLabelEntry* E = (FLabelEntry*)&Script(iCode);')
 &&str_contains($source,'XFER(FLabelEntry);')
 &&str_contains($source,'if( E->Name == NAME_None )')
 &&str_contains($source,'while( iCode < ScriptSize )')
 &&str_contains($source,'SerializeExpr( iCode, Ar );'));
$check('manifest_six_pinned_installed_original_packages',
 ($manifest['schema']??null)===1
 &&count($manifest['files']??[])===6);
$totals=['files'=>0,'classes'=>0,'default_tags'=>0,
 'exception_count'=>0,'label_tables'=>0,'genuine_exports'=>0];
$engine=null;$allGood=true;
foreach($manifest['files'] as $row){
 $name=(string)$row['name'];$path=$manifest['source_root'].'/'.$name;
 $check($name.'_installed_file_exists',is_file($path));
 if(!is_file($path)){$allGood=false;continue;}
 $check($name.'_source_length_identical',
     filesize($path)===$row['bytes']);
 $check($name.'_SHA256_unchanged',
     hash_file('sha256',$path)===$row['sha256']);
 $pkg=new Package($path);
 $check($name.'_archive_version_exact',
     (int)($pkg->getHeader()['version']??0)===(int)$row['version']);
 $check($name.'_valid_UE1_header_and_tables',
     $pkg->validatePackage()===[]);
 $classes=0;$tagCount=0;$fails=0;
 foreach($pkg->getExports() as $ex){
  if((int)$ex['classIndex']!==0){continue;}
  ++$classes;
  try{
   // These are pinned installed UT99 files; source provenance is known.
   $r=Tags::inspect($path,$pkg,(int)$ex['index'],'ut99-v432');
   $tagCount+=count($r['default_property_tags']??[]);
  }catch(Throwable $e){
   $fails++;$totals['exception_count']++;
   if(count($errors)<24){
    $errors[]=['file'=>$name,'class'=>$ex['objectNameText'],
     'export_index'=>$ex['index'],'error'=>substr($e->getMessage(),0,250)];
   }
  }
 }
 $check($name.'_all_UClass_exports_source_decoded', $fails===0);
 $check($name.'_class_export_count_pinned',
     $classes===(int)$row['class_exports']);
 $check($name.'_class_default_tag_count_pinned',
     $tagCount===(int)$row['tag_count']);
 $totals['files']++;$totals['classes']+=$classes;
 $totals['default_tags']+=$tagCount;
 $totals['genuine_exports']+=count($pkg->getExports());
 $summaries[]=['file'=>$name,'version'=>$row['version'],
  'exports'=>count($pkg->getExports()),'classes'=>$classes,
  'default_tags'=>$tagCount,'exceptions'=>$fails,
  'sha256'=>$row['sha256']];
 if($name==='Engine.u'){$engine=$pkg;}
}
$check('six_genuine_packages_count', $totals['files']===6);
$check('3194_actual_class_exports', $totals['classes']===3194);
$check('11366_actual_serialized_default_tags',
 $totals['default_tags']===11366);
$check('all_six_genuine_class_script_archives_without_exceptions',
 $totals['exception_count']===0);
// Two different *package-local* slots refer to the same resolved FName
// NAME_None. The original UT99 Engine.u label table proves the behaviour:
// first FLabelEntry is Begin, then terminal local NameMap[3344]=None.
if($engine instanceof Package){
 $names=$engine->getNames();
 $check('real_Engine_u_NameMap0_is_None',
   strcasecmp((string)($names[0]['name']??''),'None')===0);
 $check('real_Engine_u_NameMap3344_is_also_None',
   strcasecmp((string)($names[3344]['name']??''),'None')===0);
 $file=$manifest['source_root'].'/Engine.u';
 $f=fopen($file,'rb');
 if($f===false)throw new RuntimeException('Cannot read pinned Engine.u label table');
 try{
  if(fseek($f,318020)!==0)throw new RuntimeException('Cannot locate Engine.u label table');
  $disk=fread($f,16);
 }finally{fclose($f);}
 $check('real_Engine_u_EX_LabelTable_at_pinned_offset',
   strlen($disk)===16 && ord($disk[0])===0x0C
   &&bin2hex(substr($disk,0,7))==='0c540100000000');
 $check('real_Engine_u_terminal_FName_compact_3344',
   bin2hex(substr($disk,7,2))==='5034'
   &&bin2hex(substr($disk,9,4))==='ffff0000'
   &&count($names)>3344);
 $totals['label_tables']=1;
}
echo json_encode(['ok'=>$failed===[],'checks'=>count($assertions),
 'passed'=>count($assertions)-count($failed),'failed'=>$failed,
 'totals'=>$totals,'files'=>$summaries,'exception_examples'=>$errors,
 'epic_source_sha256'=>is_string($source)?hash('sha256',$source):null,
 'repro'=>['file'=>'Engine.u','absolute_archive_offset'=>318020,
   'first_label'=>'Begin','terminal_name_index'=>3344,
   'terminal_resolved_FName'=>'None',
   'epic_comparison'=>'E->Name == NAME_None, NOT archive local index === 0'],
 'remaining'=>'No actual UT99 native execution or independently instrumented archive oracle performed. Only six pinned installed genuine .u packages tested; runtime object memory, bytecode execution, v400 vs installed binary compiler differences remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
