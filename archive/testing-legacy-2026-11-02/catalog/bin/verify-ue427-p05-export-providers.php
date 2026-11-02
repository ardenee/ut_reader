<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$genuine=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$cooked=$argv[2]??'C:/Temp/ue427-p05-cooked-manifest.txt';
$counts=['unsplit_packages'=>0,'split_packages'=>0,'infile_export_spans'=>0,
 'uexp_export_spans'=>0,'uexp_export_bytes'=>0,'infile_export_bytes'=>0,
 'empty_export_spans'=>0];
$errors=[];$example=null;
$expectedCooked=@file($cooked,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($expectedCooked)||count($expectedCooked)<244)throw new RuntimeException('Cooked native UE4 corpus manifest incomplete');
foreach([[$genuine,'uasset'],[$cooked,'uexp']] as [$manifest,$expectedProvider]){
 $paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
 if(!is_array($paths)||!$paths)throw new RuntimeException('Missing genuine/native corpus manifest '.$manifest);
 $paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
 foreach($paths as $path){
  $r=new UnrealPackageReader4($path);
  if($r->validatePackage()!==[]){$errors[]=['path'=>$path,'issues'=>$r->validatePackage()];continue;}
  $counts[$expectedProvider==='uexp'?'split_packages':'unsplit_packages']++;
  foreach($r->getExports() as $i=>$row){
   $size=(int)$row['serialSize'];
   if($size===0){$counts['empty_export_spans']++;continue;}
   try{$span=$r->resolveExportPayloadSpan((int)$i);}
   catch(Throwable $e){
    if(count($errors)<20)$errors[]=['path'=>$path,'export'=>$i,'error'=>$e->getMessage()];
    continue;
   }
   $actual=$span['provider'];
   if($actual!==$expectedProvider || $span['size']!==$size ||
      $span['logicalOffset']!==(int)$row['serialOffset'] ||
      $span['exportIndex']!==$i ||
      $span['physicalOffset']!==($expectedProvider==='uexp'
       ? (int)$row['serialOffset']-(int)filesize($path)
       : (int)$row['serialOffset'])){
    if(count($errors)<20)$errors[]=['path'=>$path,'export'=>$i,'wrong_span'=>$span];
    continue;
   }
   $fileSize=@filesize($span['path']);
   if($fileSize===false || $span['physicalOffset']<0 ||
      $span['size']>(int)$fileSize-$span['physicalOffset']){
    if(count($errors)<20)$errors[]=['path'=>$path,'export'=>$i,'invalid_physical_span'=>$span];
    continue;
   }
   $counts[$actual==='uexp'?'uexp_export_spans':'infile_export_spans']++;
   $counts[$actual==='uexp'?'uexp_export_bytes':'infile_export_bytes']+=$size;
   if($actual==='uexp' && $example===null && filesize($path)<300000 && filesize($span['path'])<300000){
    $example=['path'=>$path,'index'=>$i,'span'=>$span];
   }
  }
 }
}
$negative=[];
if($example===null){$errors[]=['missing_negative_fixture'=>true];}
else{
 $root=sys_get_temp_dir().'/ue427-p05-export-negative-'.getmypid();
 if(!mkdir($root))throw new RuntimeException('Unable to make test directory');
 $source=$example['path'];$asset=$root.'/Payload.uasset';$uexp=$root.'/Payload.uexp';
 $sourceUexp=$example['span']['path'];
 try{
  if(!copy($source,$asset)||!copy($sourceUexp,$uexp))throw new RuntimeException('Fixture copy failed');
  $reader=new UnrealPackageReader4($asset);
  $i=$example['index'];
  $positive=$reader->resolveExportPayloadSpan($i);
  $negative['original_resolves']=$positive['provider']==='uexp'
   && $positive['physicalOffset']===$example['span']['physicalOffset'];
  rename($uexp,$uexp.'.missing');
  try{$reader->resolveExportPayloadSpan($i);$negative['missing_uexp_rejected']=false;}
  catch(RuntimeException $e){$negative['missing_uexp_rejected']=str_contains($e->getMessage(),'missing .uexp');}
  rename($uexp.'.missing',$uexp);
  $fp=fopen($uexp,'c+b');ftruncate($fp,0);fclose($fp);clearstatcache(true,$uexp);
  try{$reader->resolveExportPayloadSpan($i);$negative['truncated_uexp_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['truncated_uexp_rejected']=str_contains($e->getMessage(),'exceeds .uexp');}
  try{$reader->resolveExportPayloadSpan(-1);$negative['negative_index_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['negative_index_rejected']=true;}
  try{$reader->resolveExportPayloadSpan(PHP_INT_MAX);$negative['invalid_index_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['invalid_index_rejected']=true;}
  // Mutate decoded export metadata in memory only. No on-disk package is edited.
  $prop=new ReflectionProperty(UnrealPackageReader4::class,'exports');
  $exports=$prop->getValue($reader);
  $original=$exports[$i];
  $exports[$i]['serialOffset']=filesize($asset)-4;
  $exports[$i]['serialSize']=8;
  $prop->setValue($reader,$exports);
  try{$reader->resolveExportPayloadSpan($i);$negative['base_split_boundary_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['base_split_boundary_rejected']=str_contains($e->getMessage(),'crosses');}
  $exports[$i]=$original;
  $exports[$i]['serialOffset']=PHP_INT_MAX-2;
  $exports[$i]['serialSize']=9;
  $prop->setValue($reader,$exports);
  try{$reader->resolveExportPayloadSpan($i);$negative['offset_overflow_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['offset_overflow_rejected']=str_contains($e->getMessage(),'Invalid UE4 export span');}
  $exports[$i]=$original;
  $exports[$i]['serialSize']=-1;
  $prop->setValue($reader,$exports);
  try{$reader->resolveExportPayloadSpan($i);$negative['negative_size_rejected']=false;}
  catch(OutOfBoundsException $e){$negative['negative_size_rejected']=str_contains($e->getMessage(),'Invalid UE4 export span');}
  $prop->setValue($reader,array_replace($exports,[$i=>$original]));
 }finally{
  foreach(glob($root.'/*')?:[] as $file)@unlink($file);
  @rmdir($root);
 }
}
$ok=$errors===[] && count($negative)===8 && !in_array(false,$negative,true)
 &&$counts['unsplit_packages']===1749 && $counts['split_packages']===count($expectedCooked)
 &&$counts['infile_export_spans']===37552 &&$counts['uexp_export_spans']>=380;
echo json_encode(['ok'=>$ok,'scope'=>'Genuine installed editor and Epic-cooked UE4.27 export physical provider resolution',
'counts'=>$counts,'negative_checks'=>$negative,'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,16)],
JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
