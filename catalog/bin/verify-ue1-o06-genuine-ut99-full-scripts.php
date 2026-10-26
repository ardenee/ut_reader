#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$r=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($r.'/catalog/compliance/inventory/ue1-o06-genuine-ut99-all-u-v68-v69.json'),true,512,JSON_THROW_ON_ERROR);
$src=@file_get_contents($m['epic_source']);
$failed=[];$checks=[];$files=[];$errors=[];
$check=static function(string $label,bool $good)use(&$failed,&$checks):void{
 $checks[$label]=$good;if(!$good)$failed[]=$label;
};
$check('source_v400_resolved_label_FName',is_string($src)&&str_contains($src,'if( E->Name == NAME_None )'));
$check('manifest_has_30_installed_packages',count($m['files'])===30);
$counts=['packages'=>0,'exports'=>0,'classes'=>0,'tags'=>0,'exceptions'=>0];
foreach($m['files'] as $row){
 $n=$row['name'];$path=$m['source_root'].'/'.$n;
 $check($n.'_exists',is_file($path));
 if(!is_file($path))continue;
 $check($n.'_size',filesize($path)===$row['bytes']);
 $check($n.'_sha256',hash_file('sha256',$path)===$row['sha256']);
 $p=new P($path);
 $check($n.'_version',$p->getHeader()['version']===$row['archive_version']);
 $check($n.'_tables_valid',$p->validatePackage()===[]);
 $num=0;$tags=0;$bad=0;
 foreach($p->getExports() as $ex){
  if((int)$ex['classIndex']!==0)continue;
  $num++;
  try{
   // This SHA-pinned inventory consists of installed UT99 script packages.
   // Archive v69 is shared with Unreal 227; select the known source, not
   // a guessed engine lineage from the version number alone.
   $class=T::inspect($path,$p,(int)$ex['index'],'ut99-v432');
   $tags+=count($class['default_property_tags']??[]);
  }catch(Throwable $e){
   $bad++;$counts['exceptions']++;
   if(count($errors)<18)$errors[]=['file'=>$n,'class'=>$ex['objectNameText'],
       'error'=>substr($e->getMessage(),0,170)];
  }
 }
 $check($n.'_no_script_exceptions',$bad===0);
 $check($n.'_classes',$num===$row['class_exports']);
 $check($n.'_tags',$tags===$row['tag_count']);
 $counts['packages']++;$counts['exports']+=count($p->getExports());
 $counts['classes']+=$num;$counts['tags']+=$tags;
 $files[]=['name'=>$n,'classes'=>$num,'tags'=>$tags,'exceptions'=>$bad];
}
$check('all_30_packages',$counts['packages']===30);
$check('all_4199_class_exports',$counts['classes']===4199);
$check('all_17700_tags',$counts['tags']===17700);
$check('zero_script_exceptions',$counts['exceptions']===0);
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'totals'=>$counts,'files'=>$files,'errors'=>$errors,
 'source_sha256'=>is_string($src)?hash('sha256',$src):null,
 'limit'=>'On-disk parser and SHA-pinned corpus only; not independent compiled Epic runtime execution.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
