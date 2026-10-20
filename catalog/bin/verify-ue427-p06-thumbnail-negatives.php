<?php
/**
 * P06 real UE4 thumbnail-table negative mutations; only disposable copies.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('Missing installed UE4 corpus');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$chosen=null;
foreach($paths as $path){
 if(filesize($path)>15000)continue;
 $reader=new UnrealPackageReader4($path);
 $rows=$reader->getThumbnailIndex();
 if($rows&&$reader->validatePackage()===[]){
  $chosen=['path'=>$path,'table'=>(int)$reader->getHeader()['thumbnailTableOffset'],'first'=>$rows[0]];
  break;
 }
}
if($chosen===null)throw new RuntimeException('No genuine small thumbnail test asset');
$dir=sys_get_temp_dir().'/uedb-ue427-p06-thumb-'.getmypid();
if(!mkdir($dir))throw new RuntimeException('Cannot create disposable test directory');
$copy=$dir.'/ThumbFixture.uasset';$result=[];
try{
 $source=file_get_contents($chosen['path']);
 if($source===false)throw new RuntimeException('Cannot read fixture');
 $test=static function(string $label,string $bytes,bool $accepted)use($copy,&$result):void{
  file_put_contents($copy,$bytes);
  $reader=new UnrealPackageReader4($copy);
  $ok=$reader->validatePackage()===[] && count($reader->getThumbnailIndex())>0;
  $result[$label]=['passed'=>$ok===$accepted,'accepted'=>$ok,'issue_count'=>count($reader->validatePackage())];
 };
 $test('authentic_unchanged',$source,true);
 $table=$chosen['table'];
 $test('negative_count',substr_replace($source,pack('V',0xFFFFFFFF),$table,4),false);
 $test('unbounded_count',substr_replace($source,pack('V',0x7FFFFFFF),$table,4),false);
 $first=$chosen['first']['offset'];
 $test('unbounded_class_fstring',substr_replace($source,pack('V',0x7FFFFFFF),$first,4),false);
 $r=UE4BinaryReader::open($chosen['path']);$r->seek($first);
 $r->fstring();$r->fstring();$field=$r->tell();
 $test('invalid_image_offset',substr_replace($source,pack('V',strlen($source)+1),$field,4),false);
}finally{
 foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
 @rmdir($dir);
}
$ok=count($result)===5&&!in_array(false,array_column($result,'passed'),true);
echo json_encode(['ok'=>$ok,'source'=>$chosen['path'],'checks'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
