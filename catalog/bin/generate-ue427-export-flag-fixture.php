<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$list=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$dest=$argv[2]??'C:/Temp/ue427-p03-exportflags';
$paths=@file($list,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('Corpus manifest unavailable');
$chosen=null;
foreach($paths as $path){
 $r=new UnrealPackageReader4($path);$h=$r->getHeader();
 if($r->validatePackage()===[]&&($h['version']??0)>=520&&($h['exportCount']??0)>0){
  $chosen=['path'=>$path,'version'=>(int)$h['version'],'first'=>$r->getExports()[0]];
  break;
 }
}
if($chosen===null)throw new RuntimeException('No genuine UE4.27 v520+ export candidate');
$bytes=file_get_contents($chosen['path']);
$offset=(int)$chosen['first']['offset']+24;
if($offset<0||$offset+4>strlen($bytes))throw new RuntimeException('Export flag location out of file');
$raw=(int)unpack('V',substr($bytes,$offset,4))[1];
if(($raw&0x00000004)!==0)throw new RuntimeException('Candidate already has excluded RF_MarkAsNative');
$changed=$raw|0x00000004;
if(!is_dir($dest)&&!mkdir($dest,0775,true))throw new RuntimeException('Cannot make fixture directory');
$fixture=$dest.'/native-mask-export.uasset';
file_put_contents($fixture,substr_replace($bytes,pack('V',$changed),$offset,4));
file_put_contents($dest.'/list.txt',str_replace('\\','/',$fixture)."\n");
echo json_encode(['ok'=>true,'genuine_source'=>$chosen['path'],'native_fixture'=>$fixture,
 'version'=>$chosen['version'],'object_flags_offset'=>$offset,'original_flags'=>$raw,
 'modified_flags'=>$changed,'expected_load_flags'=>$changed&0x02D4003B,
 'manifest'=>$dest.'/list.txt'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
