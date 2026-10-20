<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$paths=@file($argv[1]??'C:/Temp/ue427-all-assets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($paths))throw new RuntimeException('Corpus manifest missing');
$sources=[];
foreach($paths as $path){
 $r=new UnrealPackageReader4($path);$h=$r->getHeader();
 if($r->validatePackage()!==[])continue;
 if(!isset($sources['legacy'])&&$h['version']<514&&$h['stringAssetReferencesCount']>0)$sources['legacy']=['path'=>$path,'offset'=>$h['stringAssetReferencesOffset']];
 if(!isset($sources['modern'])&&$h['version']>=514&&$h['stringAssetReferencesCount']>0)$sources['modern']=['path'=>$path,'offset'=>$h['stringAssetReferencesOffset']];
 if(!isset($sources['depends'])&&$h['dependsOffset']>0&&$h['exportCount']>0)$sources['depends']=['path'=>$path,'offset'=>$h['dependsOffset']];
 if(count($sources)===3)break;
}
if(count($sources)!==3)throw new RuntimeException('Required real-file baseline not found');
$tests=[
 ['legacy','fstring_too_large',0x7FFFFFFF,'Could not parse string asset references map'],
 ['legacy','fstring_wide_overflow',0x80000000,'Could not parse string asset references map'],
 ['modern','fname_out_of_range',0x7FFFFFFF,'UE4 FName index'],
 ['depends','negative_depends_array',0xFFFFFFFF,'Could not parse UE4 DependsMap'],
 ['depends','huge_depends_array',0x7FFFFFFF,'Could not parse UE4 DependsMap'],
];
$temp=tempnam(sys_get_temp_dir(),'ue427-p04-');$results=[];
try {
 foreach($tests as [$source,$label,$replacement,$expected]){
  $base=$sources[$source];$bytes=file_get_contents($base['path']);
  if(!is_string($bytes))throw new RuntimeException('Unopenable original');
  $original=(int)unpack('V',substr($bytes,(int)$base['offset'],4))[1];
  $fixture=substr_replace($bytes,pack('V',$replacement),$base['offset'],4);
  file_put_contents($temp,$fixture);
  $reader=new UnrealPackageReader4($temp);
  $issues=$reader->validatePackage();
  $matched=false;
  foreach($issues as $issue)if(str_contains($issue,$expected)){$matched=true;break;}
  $results[$label]=['ok'=>$matched,'source'=>basename($base['path']),
   'offset'=>$base['offset'],'original'=>$original,'mutation'=>$replacement,
   'errors'=>array_slice($issues,0,2)];
 }
}finally{@unlink($temp);}
$ok=count($results)===5&&!in_array(false,array_column($results,'ok'),true);
echo json_encode(['ok'=>$ok,'tests'=>count($results),'result'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
