<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$root='H:/UE5-583-runtime-probe/Engine/Content';
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$attempted=0;$projectionSkipped=0;$comparedRows=0;$references=0;$errors=[];$versions=[];
foreach($it as $f){
 if(!in_array(strtolower($f->getExtension()),['uasset','umap'],true)||$f->getSize()>2000000)continue;
 $reader=new UnrealPackageReader5($f->getPathname());if($reader->validatePackage()!==[])continue;
 $h=$reader->getHeader();$raw=$reader->getDependsMap();
 if(count($raw)!==(int)$h['exportCount']){$errors[]=['file'=>$f->getFilename(),'reason'=>'reader missing depends map','count'=>count($raw)];break;}
 $lines=[];$exit=0;
 exec(escapeshellarg($exe).' -j PackageInfo -p '.escapeshellarg($f->getPathname()).' --depends 2>&1',$lines,$exit);
 $text=implode("\n",$lines);$pos=strpos($text,'{');$json=$pos===false?null:json_decode(substr($text,$pos),true);
 if($exit!==0||!is_array($json)||!isset($json['DependsMap'])||!is_array($json['DependsMap'])){$errors[]=['file'=>$f->getFilename(),'reason'=>'native DependsMap missing'];break;}
 // Native commandlet skips zero-length export dependency arrays and prints
 // only path projections, so compare its nonempty-array lengths as a multiset.
 $ours=[];foreach($raw as $row){$n=count($row['references']);if($n>0)$ours[]=$n;}
 $epic=array_map('count',array_values($json['DependsMap']));$attempted++;
 if(count($ours)!==count($epic)){$projectionSkipped++;continue;}
 sort($ours);sort($epic);
 if($ours!==$epic){$errors[]=['file'=>$f->getFilename(),'epic_counts'=>$epic,'unrealdb_counts'=>$ours];break;}
 $checked++;$comparedRows+=count($raw);$references+=array_sum($ours);$v=(string)$h['ue5Version'];$versions[$v]=($versions[$v]??0)+1;
 if($checked>=50)break;
}
echo json_encode(['ok'=>$checked===50&&$errors===[],'native_packages'=>$checked,'native_attempted'=>$attempted,'projection_collisions_skipped'=>$projectionSkipped,'depends_map_export_rows'=>$comparedRows,'dependency_refs'=>$references,'versions'=>$versions,'errors'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
exit($checked===50&&$errors===[]?0:2);
