<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$root=$argv[1]??'C:/Temp/UE427Oracle/Saved/Cooked/WindowsNoEditor';
if(!is_dir($root))throw new RuntimeException('Cooked UE4.27 corpus does not exist');
$iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$count=['packages'=>0,'versions'=>[],'uexp'=>0,'ubulk'=>0,'uptnl'=>0,'m_ubulk'=>0,'uexp_footers'=>0,
  'export_spans_in_uexp'=>0,'bytes_verified'=>0,'bulks_at_logical_end'=>0];
$errors=[];$hash=hash_init('sha256');$have=0;
foreach($iter as $file){
 if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true))continue;
 $path=$file->getPathname();$reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 $count['packages']++;$version=(string)($h['version']??0);
 $count['versions'][$version]=($count['versions'][$version]??0)+1;
 if($reader->validatePackage()!==[]){$errors[]=['file'=>$path,'error'=>'reader issues','issues'=>array_slice($reader->validatePackage(),0,2)];continue;}
 $stem=substr($path,0,strrpos($path,'.'));$uexp=$stem.'.uexp';
 if(!is_file($uexp)){$errors[]=['file'=>$path,'error'=>'missing companion uexp'];continue;}
 $count['uexp']++;
 foreach(['ubulk'=>'.ubulk','uptnl'=>'.uptnl','m_ubulk'=>'.m.ubulk'] as $key=>$ext)if(is_file($stem.$ext))$count[$key]++;
 $headerSize=filesize($path);$uexpSize=filesize($uexp);
 if($uexpSize<4){$errors[]=['file'=>$uexp,'error'=>'short .uexp'];continue;}
 $fd=fopen($uexp,'rb');fseek($fd,-4,SEEK_END);$tag=fread($fd,4);
 if($tag==="\xC1\x83\x2A\x9E")$count['uexp_footers']++;
 else $errors[]=['file'=>$uexp,'error'=>'unexpected end tag','hex'=>bin2hex($tag)];
 if((int)($h['totalHeaderSize']??-1)!==$headerSize)$errors[]=['file'=>$path,'error'=>'uasset header length differs'];
 if((int)($h['bulkDataStartOffset']??-1)===$headerSize+$uexpSize-4)$count['bulks_at_logical_end']++;
 else $errors[]=['file'=>$path,'error'=>'bulk-data logical start offset differs','value'=>$h['bulkDataStartOffset']];
 foreach($reader->getExports() as $e){
  $size=(int)$e['serialSize'];$offset=(int)$e['serialOffset'];
  if($size<=0)continue;
  $local=$offset-$headerSize;
  if($local<0 || $size > ($uexpSize-4)-$local){
   if(count($errors)<30)$errors[]=['file'=>$path,'error'=>'export outside uexp payload','offset'=>$offset,'size'=>$size,'uasset_len'=>$headerSize,'uexp_len'=>$uexpSize];
   continue;
  }
  if(fseek($fd,$local,SEEK_SET)!==0){$errors[]=['file'=>$path,'error'=>'uexp seek failed'];continue;}
  $ctx=hash_init('sha256');
  $read=hash_update_stream($ctx,$fd,$size);
  if($read!==$size){$errors[]=['file'=>$path,'error'=>'short export byte read'];continue;}
  $digest=hash_final($ctx,true);
  hash_update($hash,pack('V',$size).$digest);
  $count['bytes_verified']+=$size;$count['export_spans_in_uexp']++;
 }
 fclose($fd);
}
ksort($count['versions'],SORT_NUMERIC);
$ok=$errors===[]&&$count['packages']>0&&$count['uexp']===$count['packages']
 &&$count['uexp_footers']===$count['uexp']&&$count['bulks_at_logical_end']===$count['packages'];
echo json_encode(['ok'=>$ok,'scope'=>'Authentic UE4.27 cooked split export ranges only; bulk flags/optional payloads not certified',
 'root'=>$root,'counts'=>$count,'sha256_of_export_payload_digests'=>hash_final($hash),
 'mismatch_count'=>count($errors),'errors'=>array_slice($errors,0,20)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
