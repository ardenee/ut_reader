<?php
declare(strict_types=1);
/** Read-only genuine UE5.8.3 Engine Content classic serialized package scan. */
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$root='H:/UE5-583-runtime-probe/Engine/Content';
if(!is_dir($root))throw new RuntimeException('Pinned UE5 engine assets unavailable');
$all=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$versions=[];$sizeSum=0;$failures=[];$legacyCount=0;$largest=[];
foreach($all as $f){
 if(!in_array(strtolower($f->getExtension()),['uasset','umap'],true))continue;
 if($f->getSize()>40*1024*1024)continue;
 try{
  $r=new UnrealPackageReader5($f->getPathname());$issues=$r->validatePackage();
  if(count($issues)===1 && str_contains((string)$issues[0],'predates the UE5 summary version field. LegacyFileVersion=-')){$legacyCount++;}
  elseif($issues!==[]){if(count($failures)<15)$failures[]=['file'=>$f->getPathname(),'issues'=>array_slice($issues,0,3)];}
  $h=$r->getHeader();$v=(string)($h['ue5Version']??'missing');
  $versions[$v]=($versions[$v]??0)+1;$sizeSum+=$f->getSize();
 }catch(Throwable $e){if(count($failures)<15)$failures[]=['file'=>$f->getPathname(),'error'=>$e->getMessage()];}
 $checked++;if($checked%100===0){fwrite(STDERR,'CHECKED='.$checked.PHP_EOL);}
 if($checked>=500)break;
}
$ok=$checked===500&&$failures===[];
echo json_encode(['ok'=>$ok,'read_only'=>true,'sample_size'=>$checked,'versions'=>$versions,'bytes_scanned'=>$sizeSum,'legacy_pre_ue5_summary_assets'=>$legacyCount,'ue5_classic_assets'=>$checked-$legacyCount,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
