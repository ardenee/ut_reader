<?php
/** Real UE4 thumbnail payload, isolated mutations; do not alter Epic assets. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$paths=@file($argv[1]??'C:/Temp/ue427-all-assets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('Installed UE4 corpus missing');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$chosen=null;
foreach($paths as $path){
 if(filesize($path)>500000)continue;
 $r=new UnrealPackageReader4($path);
 if($r->validatePackage()!==[])continue;
 foreach($r->getThumbnailIndex() as $i=>$entry){
  $p=$r->getThumbnailPayload($i);
  if($p['width']>0&&$p['height']>0&&$p['compressedDataSize']>40){
   $chosen=['path'=>$path,'index'=>$i,'offset'=>$p['fileOffset'],
    'end'=>$p['endOffset'],'originalHash'=>sha1($p['compressedData'])];
   break 2;
  }
 }
}
if($chosen===null)throw new RuntimeException('Genuine nonempty thumbnail not found');
$original=file_get_contents($chosen['path']);
$tmp=sys_get_temp_dir().'/ue427-p06-thumb-payload-negative-'.getmypid();
if(!mkdir($tmp))throw new RuntimeException('Cannot create disposable path');
$file=$tmp.'/mutant.uasset';
$tests=[];
$test=static function(string $label,string $bytes,bool $expected,bool $differentHash=false)use(&$tests,$file,$chosen):void{
 file_put_contents($file,$bytes);
 $accepted=false;$error='';
 try{
  $reader=new UnrealPackageReader4($file);
  if($reader->validatePackage()!==[])throw new RuntimeException(implode('; ',$reader->validatePackage()));
  $payload=$reader->getThumbnailPayload($chosen['index']);
  $accepted=$payload['compressedDataSize']===strlen($payload['compressedData']);
  if($differentHash)$accepted=$accepted && sha1($payload['compressedData'])!==$chosen['originalHash'];
 }catch(Throwable $e){$error=$e->getMessage();}
 $tests[$label]=['ok'=>$accepted===$expected,'accepted'=>$accepted,'error'=>$error];
};
$pos=$chosen['offset'];
try{
 $test('unchanged_real_payload',$original,true);
 $test('negative_image_width',substr_replace($original,pack('V',0xffffffff),$pos,4),false);
 $test('negative_image_height',substr_replace($original,pack('V',0xffffffff),$pos+4,4),false);
 $test('negative_compressed_byte_count',substr_replace($original,pack('V',0xffffffff),$pos+8,4),false);
 $test('excessive_compressed_byte_count',substr_replace($original,pack('V',0x7fffffff),$pos+8,4),false);
 $test('truncated_payload',substr($original,0,$chosen['end']-1),false);
 $data=$original;$data[$pos+12]=chr(ord($data[$pos+12])^0x01);
 $test('valid_modified_payload_hash',$data,true,true);
 file_put_contents($file,$original);
 try{$r=new UnrealPackageReader4($file);$r->getThumbnailPayload(-1);$miss=false;}
 catch(OutOfBoundsException $e){$miss=true;}
 $tests['invalid_thumbnail_index']=['ok'=>$miss,'accepted'=>!$miss,'error'=>''];
}finally{
 foreach(glob($tmp.'/*')?:[] as $path)@unlink($path);
 @rmdir($tmp);
}
$ok=count($tests)===8&&!in_array(false,array_column($tests,'ok'),true);
echo json_encode(['ok'=>$ok,'genuine_source'=>$chosen['path'],'checks'=>$tests],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
