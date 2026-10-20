<?php
declare(strict_types=1);
$root=$argv[1]??'C:/Temp/ue427-native-fixtures';
$dest=$argv[2]??'C:/Temp/ue427-fstring-fixtures';
if(!is_dir($dest)&&!mkdir($dest,0775,true))throw new RuntimeException('Directory create failed');
$list=[];
foreach([false,true] as $swap){
 $source=$root.'/'.($swap?'native-swapped.uasset':'native-little.uasset');
 $data=@file_get_contents($source);
 if(!is_string($data)||strlen($data)!==4096)throw new RuntimeException('Native baseline missing');
 $type=$swap?'N':'V';
 if(unpack($type,substr($data,24,4))[1]!==4096 || unpack($type,substr($data,28,4))[1]!==0)throw new RuntimeException('Native summary layout differs');
 $summary=substr($data,0,200);
 $folder=mb_convert_encoding('CaféFolder'."\0",$swap?'UTF-16BE':'UTF-16LE','UTF-8');
 $folderLength=-(intdiv(strlen($folder),2));
 $summary=substr_replace($summary,pack($type,$folderLength & 0xFFFFFFFF).$folder,28,4);

 $localization='Locale-ID'."\0";
 $originalLocalizationOffset=44+strlen($folder);
 $summary=substr_replace($summary,pack($type,strlen($localization)).$localization,$originalLocalizationOffset,4);
 if(strlen($summary)>4096)throw new RuntimeException('Summary too large');
 $name=$swap?'swapped-fstring.uasset':'little-fstring.uasset';
 $file=$dest.'/'.$name;
 file_put_contents($file,str_pad($summary,4096,"\0"));
 $list[]=str_replace('\\','/',$file);
}
file_put_contents($dest.'/list.txt',implode("\n",$list)."\n");
echo json_encode(['ok'=>true,'fixtures'=>$list,'expected_folder'=>'CaféFolder','expected_localization'=>'Locale-ID'],JSON_PRETTY_PRINT),PHP_EOL;
