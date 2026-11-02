<?php
declare(strict_types=1);
/** Independent Epic extracted PAK11 content vs production UnrealDB decoder. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/lib/CatalogPakArchive.php';
$pak=getenv('UE583_SATISFACTORY_PAK') ?: 'C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak';
$epicRoot=getenv('UE583_EPIC_EXTRACTED_DIR') ?: 'C:/Temp/ue583-epic-extracted-20261019';
$root=realpath($epicRoot);
if($root===false||!is_dir($root)||!is_file($pak))throw new RuntimeException('Actual Epic-extracted fixture and source PAK are required');
$foot=catalog_pak_footer_candidates($pak);
if(count($foot)!==1||(int)$foot[0]['version']!==11)throw new RuntimeException('Expected real PAK11');
$index=catalog_pak_parse_index($pak,$foot[0]);
$tmp=tempnam(sys_get_temp_dir(),'ue583-epic-diff-');
if($tmp===false)throw new RuntimeException('Temporary output unavailable');
$total=0;$bytes=0;$fail=[];$methods=[];
try{
 foreach($index['entries'] as $e){
  $name=(string)$e['filename'];
  $expected=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$name));
  if($expected===false||!str_starts_with(strtolower(str_replace('\\','/',$expected)),strtolower(str_replace('\\','/',$root)).'/')){
   if(count($fail)<16)$fail[]=['file'=>$name,'error'=>'Epic extracted entry missing/unsafe'];
   continue;
  }
  $method=(string)($e['compression_name']??'unknown');
  try{
   if(!catalog_pak_write_entry_data($pak,$e,$tmp)){throw new RuntimeException('Production decoder returned false');}
   $a=hash_file('sha256',$tmp);$b=hash_file('sha256',$expected);
   if(filesize($tmp)!==filesize($expected)||!hash_equals((string)$a,(string)$b)){
    if(count($fail)<16)$fail[]=['file'=>$name,'error'=>'Decoded content SHA256/size mismatch'];
   }else{$total++;$bytes+=filesize($tmp);$methods[$method]=($methods[$method]??0)+1;}
  }catch(Throwable $err){if(count($fail)<16)$fail[]=['file'=>$name,'error'=>$err->getMessage()];}
  if(($total+count($fail))%1000===0){fwrite(STDERR,'PROGRESS='.$total.PHP_EOL);}
 }
}finally{if(is_file($tmp))unlink($tmp);}
$ok=$total===4761&&count($index['entries'])===4761&&$fail===[];
echo json_encode(['ok'=>$ok,'independent_oracle'=>'Files extracted by official Epic UE5.8.3 UnrealPak.exe','epic_extracted_root'=>$root,'entries'=>count($index['entries']),'matched_sha256_and_decoded_size'=>$total,'decoded_bytes_verified'=>$bytes,'methods'=>$methods,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
