<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths)||count($paths)===0)throw new RuntimeException('Genuine asset manifest absent');
if(isset($paths[0]))$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$result=[
 'packages'=>0,'bad_files'=>0,'with_uexp'=>0,'with_ubulk'=>0,
 'with_uptnl'=>0,'with_m_ubulk'=>0,'positive_bulk_start'=>0,
 'infile_export_spans'=>0,'export_spans_beyond_uasset'=>0,
 'zero_size_exports'=>0,
];
$examples=[];$bad=[];
foreach($paths as $path){
 $reader=new UnrealPackageReader4($path);$header=$reader->getHeader();
 $issues=$reader->validatePackage();
 if($issues!==[]){$result['bad_files']++;if(count($bad)<12)$bad[]=['path'=>$path,'issues'=>array_slice($issues,0,2)];continue;}
 $result['packages']++;
 $base=preg_replace('/\.(uasset|umap)$/i','',$path);
 foreach(['uexp'=>'.uexp','ubulk'=>'.ubulk','uptnl'=>'.uptnl','m_ubulk'=>'.m.ubulk'] as $label=>$ext){
  if(is_file($base.$ext)){
   $result['with_'.$label]++;
   if(count($examples)<20)$examples[]=['file'=>$path,'companion'=>$base.$ext];
  }
 }
 if((int)($header['bulkDataStartOffset']??0)>0)$result['positive_bulk_start']++;
 $size=@filesize($path);
 if($size===false){$result['bad_files']++;continue;}
 foreach($reader->getExports() as $export){
  $serialSize=(int)($export['serialSize']??0);
  if($serialSize===0){$result['zero_size_exports']++;continue;}
  $end=(int)($export['serialOffset']??0)+$serialSize;
  if($end>$size){
   $result['export_spans_beyond_uasset']++;
   if(count($examples)<20)$examples[]=['file'=>$path,'start'=>$export['serialOffset'],'length'=>$serialSize,'uasset_length'=>$size];
  }else $result['infile_export_spans']++;
 }
}
$ok=$result['bad_files']===0&&$result['packages']===count($paths);
echo json_encode(['ok'=>$ok,'purpose'=>'P05 physical payload corpus coverage only, NOT split-payload source certification',
  'asset_manifest'=>$manifest,'counts'=>$result,'examples'=>$examples,'failures'=>$bad],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
