<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$root='H:/Epic Games/UE_4.27/Engine/Content';
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$total=0;$bad=[];$markers=[];$custom=0;$names=0;$imports=0;$exports=0;$preload=0;$refs=0;
foreach($it as $f){
 if(!in_array(strtolower($f->getExtension()),['uasset','umap'],true))continue;
 $p=new UnrealPackageReader4($f->getPathname());$h=$p->getHeader();
 $issues=$p->validatePackage();$n=$p->getNames();$i=$p->getImports();$e=$p->getExports();
 $good=$issues===[]&&count($n)===(int)($h['nameCount']??-1)&&count($i)===(int)($h['importCount']??-1)&&count($e)===(int)($h['exportCount']??-1);
 if(!$good&&count($bad)<30)$bad[]=['file'=>$f->getFilename(),'issues'=>array_slice($issues,0,2),'name'=>[count($n),$h['nameCount']??null],'import'=>[count($i),$h['importCount']??null],'export'=>[count($e),$h['exportCount']??null]];
 $markers[(string)($h['legacyFileVersion']??'?')]=($markers[(string)($h['legacyFileVersion']??'?')]??0)+1;
 $custom+=count($h['customVersions']??[]);$names+=count($n);$imports+=count($i);$exports+=count($e);$preload+=count($p->getPreloadDependencies());$refs+=count($p->getStringAssetReferences());$total++;
}
ksort($markers);
$ok=$bad==[]&&$total>1000;
echo json_encode(['ok'=>$ok,'real_assets'=>$total,'legacy_markers'=>$markers,'custom_entries'=>$custom,'names'=>$names,'imports'=>$imports,'exports'=>$exports,'preload_entries'=>$preload,'string_asset_refs'=>$refs,'first_failures'=>$bad],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
