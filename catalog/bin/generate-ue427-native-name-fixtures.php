<?php
declare(strict_types=1);
// UE4.27 P02 native name-map fixtures

$root = $argv[1] ?? 'C:/Temp/ue427-native-fixtures';
$dest = $argv[2] ?? 'C:/Temp/ue427-name-fixtures';
if (!is_dir($dest) && !mkdir($dest,0775,true)) throw new RuntimeException('Create fixture directory failed');
$entries = [
 ['name'=>'AlphaTesting','wide'=>false,'hashes'=>[0x1234,0xABCD]],
 ['name'=>'CaféWide','wide'=>true,'hashes'=>[0xFF01,0x2207]],
 ['name'=>'Object_42','wide'=>false,'hashes'=>[0,0xFFFF]],
];
$list=[];
foreach ([false,true] as $swapped) {
 $file=$root.'/'.($swapped?'native-swapped.uasset':'native-little.uasset');
 $data=@file_get_contents($file);
 if (!is_string($data)||strlen($data)!==4096) throw new RuntimeException('Missing native baseline');
 $type=$swapped?'N':'V';
 foreach ([24=>4096,28=>0,36=>0,40=>0] as $pos=>$expected) {
   if (unpack($type,substr($data,$pos,4))[1]!==$expected) throw new RuntimeException('Unexpected native summary layout');
 }
 $offset=512;$serialized='';

 foreach($entries as $entry) {
  $name=$entry['name'];
  if ($entry['wide']) {
   $payload=mb_convert_encoding($name."\0",'UTF-16LE','UTF-8');
   $length=-intdiv(strlen($payload),2);
  } else {
   $payload=$name."\0";$length=strlen($payload);
  }
  $serialized.=pack($type,$length & 0xFFFFFFFF).$payload;
  foreach ($entry['hashes'] as $hash) $serialized.=pack($swapped?'n':'v',$hash);
 }
 if (strlen($serialized)>4096-$offset) throw new RuntimeException('Name map too large');
 $data=substr_replace($data,pack($type,count($entries)).pack($type,$offset),36,8);
 $data=substr_replace($data,$serialized,$offset,strlen($serialized));
 $path=$dest.'/'.($swapped?'swapped-names.uasset':'little-names.uasset');
 file_put_contents($path,$data);
 $list[]=str_replace('\\','/',$path);
}
file_put_contents($dest.'/list.txt',implode("\n",$list)."\n");
echo json_encode(['ok'=>true,'files'=>$list,'names'=>$entries],JSON_PRETTY_PRINT),PHP_EOL;
