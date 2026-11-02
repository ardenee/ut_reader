<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$root='H:/UE5-583-runtime-probe/Engine/Content';
if(!is_file($exe)||!is_dir($root))throw new RuntimeException('Pinned Epic tool unavailable');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$fields=0;$errors=[];$versions=[];
foreach($it as $file){
 if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true)||$file->getSize()>2000000)continue;
 $r=new UnrealPackageReader5($file->getPathname());if($r->validatePackage()!==[])continue;
 $output=[];$rc=0;exec(escapeshellarg($exe).' -j PackageInfo -p '.escapeshellarg($file->getPathname()).' --names --imports --exports 2>&1',$output,$rc);
 $raw=implode("\n",$output);$pos=strpos($raw,'{');$j=$pos===false?null:json_decode(substr($raw,$pos),true);
 if($rc!==0||!is_array($j)){$errors[]=['file'=>$file->getFilename(),'reason'=>'native command failed'];break;}
 foreach([['Names',$r->getNames()],['Imports',$r->getImports()],['Exports',$r->getExports()]] as [$kind,$ours]){
  $native=$j[$kind]??null;if(!is_array($native)){$errors[]=['file'=>$file->getFilename(),'reason'=>'missing native '.$kind];break 2;}
  if(count($native)!==count($ours)){$errors[]=['file'=>$file->getFilename(),'section'=>$kind,'epic_count'=>count($native),'unrealdb_count'=>count($ours)];break 2;}
  foreach($native as $i=>$row){
   if($kind==='Names'){$expected=(string)$row;$actual=(string)($ours[$i]['name']??'');if($expected!==$actual){$errors[]=['file'=>$file->getFilename(),'section'=>$kind,'row'=>$i,'epic'=>$expected,'unrealdb'=>$actual];break 3;} $fields++;continue;}
   $keys=$kind==='Imports'?['ObjectName'=>'objectNameText','OuterIndex'=>'outerIndex','Optional'=>'importOptional']:['ObjectName'=>'objectNameText','OuterIndex'=>'outerIndex','ClassIndex'=>'classIndex','SuperIndex'=>'superIndex','TemplateIndex'=>'templateIndex','SerialSize'=>'serialSize','SerialOffset'=>'serialOffset','ScriptSerializationStartOffset'=>'scriptSerializationStartOffset','ScriptSerializationEndOffset'=>'scriptSerializationEndOffset'];
   foreach($keys as $epic=>$local){if(!array_key_exists($epic,$row))continue;$expected=$row[$epic];$actual=$ours[$i][$local]??null;if($expected!==$actual){$errors[]=['file'=>$file->getFilename(),'section'=>$kind,'row'=>$i,'field'=>$epic,'epic'=>$expected,'unrealdb'=>$actual];break 4;}$fields++;}
  }
 }
 $checked++;$v=(string)($r->getHeader()['ue5Version']??'');$versions[$v]=($versions[$v]??0)+1;if($checked>=140)break;
}
echo json_encode(['ok'=>$checked>=10&&$errors===[],'packages'=>$checked,'fields'=>$fields,'versions'=>$versions,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($checked>=10&&$errors===[]?0:2);
