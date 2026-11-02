<?php
declare(strict_types=1);
/** Source-branch fixtures independently decoded by Epic's native FIoStoreTocResourceView. */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$root=sys_get_temp_dir().'/ue583-native-toc-variants-'.getmypid();
if(!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Cannot create isolated TOC fixture directory');
$cases=[];
foreach(range(2,8) as $v)$cases[]=['name'=>'toc-v'.$v,'version'=>$v,'signed'=>false,'indexed'=>false,'trailing'=>false];
$cases[]=['name'=>'v8-signed-indexed-overflow','version'=>8,'signed'=>true,'indexed'=>true,'trailing'=>false];
$cases[]=['name'=>'v5-signed-indexed-overflow','version'=>5,'signed'=>true,'indexed'=>true,'trailing'=>false];
$cases[]=['name'=>'v8-trailing-extension','version'=>8,'signed'=>false,'indexed'=>false,'trailing'=>true];
$cases[]=['name'=>'v8-encrypted-header','version'=>8,'signed'=>false,'indexed'=>false,'encrypted'=>true,'trailing'=>false];
$results=[];$total=0;$errors=[];$baselineV8=null;$rejections=[];
try{
 foreach($cases as $x){
  $v=$x['version'];$seeds=$v>=4?1:0;$overflow=$v>=5?1:0;
  $flags=($x['signed']?Uedb5IoStoreTocReader::CONTAINER_FLAG_SIGNED:0)|($x['indexed']?Uedb5IoStoreTocReader::CONTAINER_FLAG_INDEXED:0)|(!empty($x['encrypted'])?Uedb5IoStoreTocReader::CONTAINER_FLAG_ENCRYPTED:0);
  $header=Uedb5IoStoreTocReader::TOC_MAGIC.chr($v)."\0".pack('v',0)
   .pack('V9',144,1,1,12,0,32,64,$x['indexed']?4:0,1)
   .strrev(hex2bin('1111222233334444')).str_repeat("\0",16)
   .chr($flags)."\0".pack('v',0).pack('V',$seeds).str_repeat("\xff",8)
   .pack('V2',$overflow,0).str_repeat("\0",40);
  if(strlen($header)!==144)throw new RuntimeException('Invalid header build length');
  $id=strrev(hex2bin('0123456789ABCDEF')).pack('n',0)."\0".chr(1);
  $bytes=$header.$id.str_repeat("\0",5).str_repeat("\0",4).chr(16)
    .($seeds?pack('V',0):'').($overflow?pack('V',0):'')
    .str_repeat("\0",5)."\x10\0\0\x10\0\0\0";
  if($x['signed'])$bytes.=pack('V',20).str_repeat('S',20).str_repeat('T',20).str_repeat('X',20);
  if($x['indexed'])$bytes.='DIR!';
  $bytes.=$v>=8?str_repeat("\xab",20).chr(1).str_repeat("\0",3):str_repeat("\xab",32).chr(1);
  if($x['trailing'])$bytes.='EXT';
  if($x['name']==='toc-v8')$baselineV8=$bytes;
  $path=$root.'/'.$x['name'].'.utoc';
  if(file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('TOC fixture write failed');
  $output=[];$rc=0;
  exec(escapeshellarg(PHP_BINARY).' -d memory_limit=768M '.escapeshellarg(__DIR__.'/verify-ue5-583-epic-native-toc-fields.php').' '.escapeshellarg('--toc='.$path).' 2>&1',$output,$rc);
  $json=json_decode(implode("\n",$output),true);
  $pass=$rc===0&&is_array($json)&&($json['ok']??null)===true;
  $comp=(int)($json['independent_field_comparisons']??0);
  $results[]=['variant'=>$x['name'],'version'=>$v,'passed'=>$pass,'comparisons'=>$comp];
  $total+=$pass?$comp:0;
  if(!$pass)$errors[]=['variant'=>$x['name'],'exit'=>$rc,'output'=>substr(implode("\n",$output),0,400)];
 }
 if(!is_string($baselineV8))throw new RuntimeException('No baseline for negative TOC fixtures');
 $negativeCases=[
  'bad-magic'=>'X'.substr($baselineV8,1),
  'too-old-version'=>substr_replace($baselineV8,chr(1),16,1),
  'too-new-version'=>substr_replace($baselineV8,chr(9),16,1),
  'bad-header-size'=>substr_replace($baselineV8,pack('V',128),20,4),
  'bad-block-entry-size'=>substr_replace($baselineV8,pack('V',11),32,4),
 ];
 $epic='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
 foreach($negativeCases as $name=>$bytes){
  $path=$root.'/'.$name.'.utoc';file_put_contents($path,$bytes);
  $localRejected=false;
  try{new Uedb5IoStoreTocReader($path);}catch(RuntimeException){$localRejected=true;}
  $nativeOutput=[];$nativeExit=0;
  exec(escapeshellarg($epic).' -TocProbe='.escapeshellarg($path).' -unattended 2>&1',$nativeOutput,$nativeExit);
  $nativeText=implode("\n",$nativeOutput);
  $nativeRejected=str_contains($nativeText,'UE583_NATIVE_TOC_ERROR')&&!str_contains($nativeText,'UE583_NATIVE_TOC_ORACLE');
  $pass=$localRejected&&$nativeRejected;
  $rejections[]=['variant'=>$name,'passed'=>$pass];
  if(!$pass)$errors[]=['variant'=>$name,'local_rejected'=>$localRejected,'native_rejected'=>$nativeRejected];
 }
}finally{
 foreach(glob($root.'/*')?:[] as $file)@unlink($file);
 @rmdir($root);
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'variant_count'=>count($results),'independent_field_comparisons'=>$total,'variants'=>$results,'negative_variant_count'=>count($rejections),'negative_variants'=>$rejections,'failures'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
