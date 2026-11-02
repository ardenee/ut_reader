<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$root='H:/UE5-583-runtime-probe/Engine/Content';
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$rows=0;$refs=0;$versions=[];$errors=[];
foreach($it as $file){
 if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true)||$file->getSize()>2000000)continue;
 $reader=new UnrealPackageReader5($file->getPathname());
 if($reader->validatePackage()!==[])continue;
 $ours=$reader->getDependsMap();
 $lines=[];$rc=0;
 exec(escapeshellarg($exe).' -DependsProbe='.escapeshellarg($file->getPathname()).' -unattended 2>&1',$lines,$rc);
 $raw=str_replace('\\n',"\n",implode("\n",$lines));
 if(!in_array($rc,[0,777006],true)||!preg_match('/^UE583_NATIVE_DEPENDS_COUNT (\d+)$/m',$raw,$count)){
  $errors[]=['file'=>$file->getFilename(),'error'=>'Epic native marker missing','exit'=>$rc];break;
 }
 if((int)$count[1]!==count($ours)){$errors[]=['file'=>$file->getFilename(),'error'=>'count mismatch','native'=>(int)$count[1],'ours'=>count($ours)];break;}
 preg_match_all('/^UE583_NATIVE_DEPENDS_ROW (\d+) (\d+)((?: -?\d+)*)$/m',$raw,$nativeRows,PREG_SET_ORDER);
 if(count($nativeRows)!==count($ours)){$errors[]=['file'=>$file->getFilename(),'error'=>'row count mismatch','rows'=>count($nativeRows)];break;}
 foreach($nativeRows as $entry){
  $index=(int)$entry[1];$refsNative=trim($entry[3])===''?[]:array_map('intval',preg_split('/\s+/',trim($entry[3])));
  $expected=$ours[$index]['references']??null;
  if((int)$entry[2]!==count($refsNative)||$expected!==$refsNative){$errors[]=['file'=>$file->getFilename(),'export'=>$index,'native'=>$refsNative,'unrealdb'=>$expected];break 2;}
  $refs+=count($refsNative);$rows++;
 }
 $checked++;$v=(string)($reader->getHeader()['ue5Version']??'');$versions[$v]=($versions[$v]??0)+1;
 if($checked>=140)break;
}
$ok=$checked===140&&$errors===[];
echo json_encode(['ok'=>$ok,'real_packages'=>$checked,'export_dependency_rows'=>$rows,'exact_dependency_reference_indices'=>$refs,'versions'=>$versions,'failures'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
