<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$root='H:/Epic Games/UE_4.27/Engine/Content';
if(!is_dir($root))throw new RuntimeException('UE4.27 engine binary Content directory missing');
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$markers=[];$versions=[];$failures=[];$custom=0;
foreach($files as $file){
 if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true))continue;
 $p=new UnrealPackageReader4($file->getPathname());
 $issues=$p->validatePackage();$h=$p->getHeader();
 $wire=file_get_contents($file->getPathname(),false,null,0,24);
 $marker=unpack('V',substr($wire,4,4))[1];if($marker>=2147483648)$marker-=4294967296;
 $serialized=(int)($h['serializedUE4Version']??-1);
 $valid=($issues===[]&&($h['legacyFileVersion']??null)===$marker&&$marker>=-7&&$marker<=-2
  &&$serialized>=214&&$serialized<=522
  &&($h['serializedSignature']??null)===0x9E2A83C1
  &&($h['version']??null)===$serialized);
 if(!$valid)$failures[]=['file'=>$file->getFilename(),'marker'=>$marker,'version'=>$serialized,'issues'=>$issues];
 $markers[(string)$marker]=($markers[(string)$marker]??0)+1;$versions[(string)$serialized]=($versions[(string)$serialized]??0)+1;
 $custom+=count($h['customVersions']??[]);$checked++;
 if($checked>=100)break;
}
ksort($markers);$ok=$checked===100&&$failures===[];
echo json_encode(['ok'=>$ok,'real_assets'=>$checked,'legacy_markers'=>$markers,'distinct_versions'=>count($versions),'custom_version_entries'=>$custom,'failures'=>$failures],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
