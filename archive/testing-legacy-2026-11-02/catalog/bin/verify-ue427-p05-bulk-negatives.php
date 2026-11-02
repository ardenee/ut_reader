<?php
declare(strict_types=1);
$manifest=$argv[1]??'C:/Temp/ue427-p05-cooked-manifest.txt';
$nativeLog=$argv[2]??'C:/Temp/ue427-p05-native-bulk.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$log=@file_get_contents($nativeLog);
if(!is_array($paths)||!is_string($log))throw new RuntimeException('Missing native cooked evidence');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
if(str_starts_with($log,"\xFF\xFE"))$log=mb_convert_encoding(substr($log,2),'UTF-8','UTF-16LE');
$chosen=null;
foreach($paths as $i=>$path){
 $base=preg_replace('/\.(uasset|umap)$/i','',$path);
 if(is_file($base.'.ubulk')&&filesize($base.'.ubulk')<=200000){$chosen=[$i,$path,$base];break;}
}
if($chosen===null)throw new RuntimeException('No suitably small genuine cooked .ubulk sample');
[$idx,$original,$base]=$chosen;
$dir=sys_get_temp_dir().'/ue427-p05-negative-'.getmypid();
if(!is_dir($dir)&&!mkdir($dir))throw new RuntimeException('Cannot create isolated fixture directory');
$cloneBase=$dir.'/PayloadFixture';
$asset=$cloneBase.'.uasset';$uexp=$cloneBase.'.uexp';$ubulk=$cloneBase.'.ubulk';
foreach(['.uasset'=>$asset,'.uexp'=>$uexp,'.ubulk'=>$ubulk] as $ext=>$dest) {
 if(!copy($base.$ext,$dest))throw new RuntimeException('Unable to copy source '.$ext);
}
$only=$dir.'/manifest.txt';file_put_contents($only,str_replace('\\','/',$asset)."\n");
$reindexed=[];
foreach(explode("\n",$log) as $line){
 foreach(['UE427_NATIVE_BULK_COUNT|','UE427_NATIVE_BULK|'] as $tag){
  $start=strpos($line,$tag);
  if($start===false)continue;
  $content=trim(substr($line,$start+strlen($tag)));
  $parts=explode('|',$content);
  if((int)$parts[0]!==$idx)continue;
  $parts[0]='0';$reindexed[]=$tag.implode('|',$parts);
 }
}
if(count($reindexed)<2)throw new RuntimeException('Missing original compiled-Epic header records');
$native=$dir.'/epic.log';file_put_contents($native,implode("\n",$reindexed)."\n");
$verifier=__DIR__.'/verify-ue427-p05-native-bulk-headers.php';
$cases=[];$run=static function(string $case,bool $expectOk)use(&$cases,$verifier,$native,$only):void {
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($verifier).' '.escapeshellarg($native).' '.escapeshellarg($only);
 $out=[];$exit=0;exec($cmd,$out,$exit);
 $json=json_decode(implode("\n",$out),true);
 $passed=is_array($json)&&((bool)($json['ok']??false)===$expectOk)&&(($exit===0)===$expectOk);
 $cases[$case]=['ok'=>$passed,'verifier_success'=>$json['ok']??null,
  'exit'=>$exit,'reported_mismatches'=>$json['mismatch_count']??null];
};
try{
 $run('unaltered_native_cooked',true);
 file_put_contents($ubulk,substr(file_get_contents($ubulk),0,-1));$run('truncated_ubulk',false);
 copy($base.'.ubulk',$ubulk);
 rename($ubulk,$ubulk.'.missing');$run('missing_ubulk',false);rename($ubulk.'.missing',$ubulk);
 $bytes=file_get_contents($uexp);
 $row=null;foreach($reindexed as $line){if(str_starts_with($line,'UE427_NATIVE_BULK|')){$parts=explode('|',substr($line,strlen('UE427_NATIVE_BULK|')));$row=$parts;break;}}
 if($row===null)throw new RuntimeException('No native bulk descriptor');
 $pos=(int)$row[2];
 file_put_contents($uexp,substr_replace($bytes,pack('V',0xFFFFFFFF),$pos,4));
 $run('corrupted_uexp_bulk_flags',false);
 file_put_contents($uexp,$bytes);
 file_put_contents($uexp,substr_replace($bytes,pack('P',filesize($ubulk)+1),$pos+12,8));
 $run('out_of_bounds_bulk_offset',false);
 file_put_contents($uexp,$bytes);
 rename($uexp,$uexp.'.missing');$run('missing_uexp',false);rename($uexp.'.missing',$uexp);
}finally {
 foreach(glob($dir.'/*')?:[] as $f)@unlink($f);
 @rmdir($dir);
}
$ok=count($cases)===6&&!in_array(false,array_column($cases,'ok'),true);
echo json_encode(['ok'=>$ok,'real_cooked_source'=>$original,'tests'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
