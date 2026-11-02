<?php
/** UE4.27.2 P07 genuine-corpus branch inventory, NOT linker runtime success. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$files=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($files)||count($files)!==1749)throw new RuntimeException('Genuine UE427 1749-file manifest missing');
$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
$stats=['packages'=>0,'imports'=>0,'package_name_field_records'=>0,
 'nonempty_explicit_package_names'=>0,'package_name_field_packages'=>0,
 'explicit_package_name_packages'=>0,'outer_import'=>0,'outer_export'=>0,
 'null_outer'=>0,'null_outer_package_class'=>0,'script_package_imports'=>0,
 'object_name_none'=>0,'class_name_none'=>0,'class_package_none'=>0,
 'bad_outer_indices'=>0,'pre_version_520'=>0,'version_520_plus'=>0,
 'filtered_editor_only'=>0];
$sample=[];$errors=[];
foreach($files as $fileIndex=>$path){
 $reader=new UnrealPackageReader4($path);
 if($reader->validatePackage()!==[]){if(count($errors)<10)$errors[]=['file'=>$fileIndex,'issues'=>$reader->validatePackage()];continue;}
 $header=$reader->getHeader();$ver=(int)$header['version'];$flags=(int)$header['packageFlags'];
 ++$stats['packages'];
 ++$stats[$ver>=520?'version_520_plus':'pre_version_520'];
 if(($flags & 0x80000000)!==0)++$stats['filtered_editor_only'];
 $imports=$reader->getImports();$stats['imports']+=count($imports);
 $foundField=false;$foundExplicit=false;
 foreach($imports as $item){
  $index=(int)$item['index'];$outer=(int)$item['outerIndex'];
  $hasField=(bool)$item['packageNamePresent'];
  if($hasField){++$stats['package_name_field_records'];$foundField=true;}
  if($hasField!==($ver>=520&&($flags&0x80000000)===0))
   $errors[]=['file'=>$fileIndex,'import'=>$index,'wrong_package_name_gate'=>[$ver,$flags,$hasField]];
  $explicit=(string)$item['packageNameText'];
  if($explicit!==''&&strcasecmp($explicit,'None')!==0){
   ++$stats['nonempty_explicit_package_names'];$foundExplicit=true;
   if(count($sample)<12)$sample[]=['file'=>$fileIndex,'path'=>$path,
     'import_index'=>$index,'class'=>$item['classNameText'],
     'object'=>$item['objectNameText'],'outer'=>$outer,'package_name'=>$explicit];
  }
  if($outer<0)++$stats['outer_import'];
  elseif($outer>0)++$stats['outer_export'];
  else{
   ++$stats['null_outer'];
   if(strcasecmp((string)$item['classNameText'],'Package')===0)
    ++$stats['null_outer_package_class'];
  }
  if($outer<0&&-$outer-1>=count($imports))++$stats['bad_outer_indices'];
  if($outer>0&&$outer>count($reader->getExports()))++$stats['bad_outer_indices'];
  if(str_starts_with(strtolower($explicit),'/script/'))++$stats['script_package_imports'];
  if(strcasecmp((string)$item['objectNameText'],'None')===0)++$stats['object_name_none'];
  if(strcasecmp((string)$item['classNameText'],'None')===0)++$stats['class_name_none'];
  if(strcasecmp((string)$item['classPackageText'],'None')===0)++$stats['class_package_none'];
 }
 if($foundField)++$stats['package_name_field_packages'];
 if($foundExplicit)++$stats['explicit_package_name_packages'];
}
$ok=$errors===[]&&$stats['packages']===1749&&$stats['imports']===20207
 &&$stats['bad_outer_indices']===0
 &&$stats['null_outer']===$stats['null_outer_package_class']
 &&$stats['outer_import']+$stats['outer_export']+$stats['null_outer']===$stats['imports'];
echo json_encode(['ok'=>$ok,'scope'=>'Source-shaped raw FObjectImport branch coverage in 1749 genuine installed packages; NOT runtime VerifyImport proof',
 'counts'=>$stats,'positive_explicit_package_samples'=>$sample,
 'errors'=>array_slice($errors,0,12)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
