<?php
/** Isolated genuine-package corruption checks for source-sized TBitArray maps. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('No installed UE4 manifest');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$choice=null;
foreach($paths as $path){
 if(filesize($path)>400000)continue;
 $r=new UnrealPackageReader4($path);$dep=$r->getAssetRegistryDependencyBits();
 if($r->validatePackage()===[]&&isset($dep['importUsedInGame'][0])){
  $choice=['path'=>$path,'offset'=>$dep['offset'],'head'=>$r->getHeader(),
   'imports'=>count($dep['importUsedInGame']),'soft'=>count($dep['softPackageUsedInGame']),
   'initial'=>$dep['importUsedInGame']];
  break;
 }
}
if($choice===null)throw new RuntimeException('No real version-521-or-later dependency fixture found');
$root=sys_get_temp_dir().'/ue427-p06-dep-negative-'.getmypid();
if(!mkdir($root))throw new RuntimeException('Temp path unavailable');
$target=$root.'/DependencyFixture.uasset';$result=[];
$original=file_get_contents($choice['path']);$offset=$choice['offset'];
$softCountAt=$offset+4+4*intdiv($choice['imports']+31,32);
$run=function(string $name,string $data,bool $expected,bool $checkFlip=false)use($target,&$result,$choice):void{
 file_put_contents($target,$data);
 $reader=new UnrealPackageReader4($target);
 $bits=$reader->getAssetRegistryDependencyBits();
 $ok=$reader->validatePackage()===[] && $bits!==[];
 if($checkFlip&&$ok){
  $ok=$bits['importUsedInGame'][0]!==$choice['initial'][0]
    &&count($bits['importUsedInGame'])===$choice['imports'];
 }
 $result[$name]=['ok'=>$ok===$expected,'accepted'=>$ok,
  'issues'=>array_slice($reader->validatePackage(),0,2)];
};
try{
 $run('unaltered_real_package',$original,true);
 $run('negative_import_bits',substr_replace($original,pack('V',0xffffffff),$offset,4),false);
 $run('excessive_import_bits',substr_replace($original,pack('V',0x7fffffff),$offset,4),false);
 $run('wrong_import_map_length',substr_replace($original,pack('V',0),$offset,4),false);
 $run('negative_soft_bits',substr_replace($original,pack('V',0xffffffff),$softCountAt,4),false);
 $run('wrong_soft_map_length',substr_replace($original,pack('V',$choice['soft']+1),$softCountAt,4),false);
 $run('invalid_dependency_section_offset',
   substr_replace($original,pack('P',strlen($original)+17),(int)$choice['head']['assetRegistryDataOffset'],8),false);
 $data=$original;$data[$offset+4]=chr(ord($data[$offset+4])^1);
 $run('valid_first_import_bit_flip',$data,true,true);
}finally{
 foreach(glob($root.'/*')?:[] as $file)@unlink($file);
 @rmdir($root);
}
$ok=count($result)===8&&!in_array(false,array_column($result,'ok'),true);
echo json_encode(['ok'=>$ok,'genuine_source'=>$choice['path'],'checks'=>$result],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
