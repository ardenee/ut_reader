<?php
/** Real-file isolated negative checks for raw AssetRegistry main data. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$paths=@file($argv[1]??'C:/Temp/ue427-all-assets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('Missing genuine UE4 corpus');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$chosen=null;
foreach($paths as $path){
 if(filesize($path)>300000)continue;
 $r=new UnrealPackageReader4($path);$rows=$r->getAssetRegistryObjects();
 if(!$r->validatePackage() && isset($rows[0]['tags'][0])){
  $chosen=['path'=>$path,'first'=>$rows[0],'header'=>$r->getHeader()];
  break;
 }
}
if($chosen===null)throw new RuntimeException('No authentic package with AssetRegistry tags found');
$root=sys_get_temp_dir().'/ue427-p06-reg-negative-'.getmypid();
if(!mkdir($root))throw new RuntimeException('Cannot create disposable fixture');
$target=$root.'/RegistryFixture.uasset';$tests=[];
try{
 $original=file_get_contents($chosen['path']);
 if($original===false)throw new RuntimeException('Cannot read genuine file');
 $run=static function(string $name,string $data,bool $expected)use($target,&$tests):void{
  file_put_contents($target,$data);
  $r=new UnrealPackageReader4($target);
  $accepted=$r->validatePackage()===[] && count($r->getAssetRegistryObjects())>0;
  $tests[$name]=['passed'=>$accepted===$expected,'accepted'=>$accepted,
   'reader_issue_count'=>count($r->validatePackage())];
 };
 $run('unchanged_genuine_asset',$original,true);
 $summary=$chosen['header'];$offset=(int)$summary['assetRegistryDataOffset'];
 $pre=(int)$summary['version']<521 || (((int)$summary['packageFlags']&0x80000000)!==0);
 $countAt=$offset+($pre?0:8);
 $run('negative_object_count',substr_replace($original,pack('V',0xFFFFFFFF),$countAt,4),false);
 $run('unbounded_object_count',substr_replace($original,pack('V',0x7FFFFFFF),$countAt,4),false);
 $objectAt=$chosen['first']['offset'];
 $run('unbounded_object_fstring',substr_replace($original,pack('V',0x7FFFFFFF),$objectAt,4),false);
 $reader=UE4BinaryReader::open($chosen['path']);$reader->seek($objectAt);
 $reader->fstring();$reader->fstring();$tagCountAt=$reader->tell();
 $run('negative_tag_count',substr_replace($original,pack('V',0xFFFFFFFF),$tagCountAt,4),false);
 $run('unbounded_tag_count',substr_replace($original,pack('V',0x7FFFFFFF),$tagCountAt,4),false);
 $tagAt=$chosen['first']['tags'][0]['offset'];
 $run('unbounded_tag_fstring',substr_replace($original,pack('V',0x7FFFFFFF),$tagAt,4),false);
}finally{
 foreach(glob($root.'/*')?:[] as $file)@unlink($file);
 @rmdir($root);
}
$ok=count($tests)===7&&!in_array(false,array_column($tests,'passed'),true);
echo json_encode(['ok'=>$ok,'genuine_sample'=>$chosen['path'],'checks'=>$tests],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
