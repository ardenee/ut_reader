<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port p,@@datadir d')->fetch();if((int)$ident['p']!==3339||stripos($ident['d'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Non-isolated database refused');
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe';
$toc='C:/Temp/uedb5-iostore-retention-test/games/unreal2/iostore/63e4f0e322276684718563e13619474c8cf5981b29e15e502620a4373e351ab5/FactoryGame-WindowsServer.utoc';
$root='C:/Temp/ue583-epic-zen-retained-extract-20261019/';
if(!is_file($exe)||!is_file($toc)||!is_dir($root))throw new RuntimeException('Epic artifacts missing');
$lines=[];$exit=-1;exec(escapeshellarg($exe).' '.escapeshellarg($toc).' -List 2>&1',$lines,$exit);if($exit!==0)throw new RuntimeException('Epic listing failed');
$physical=[];
foreach($lines as $line){
 if(!preg_match('/^LogIoStore: Display: "(.+)" offset:/',$line,$m))continue;
 $p=$m[1];if($p==='<ContainerHeader>')continue;$logical=null;
 if(str_starts_with($p,'../../../FactoryGame/Content/'))$logical='/Game/'.substr($p,strlen('../../../FactoryGame/Content/'));
 elseif(str_starts_with($p,'../../../Engine/Content/'))$logical='/Engine/'.substr($p,strlen('../../../Engine/Content/'));
 elseif(preg_match('~^\.\./\.\./\.\./Engine/Plugins/Interchange/Assets/Content/(.+)$~',$p,$z))$logical='/InterchangeAssets/'.$z[1];
 elseif(preg_match('~^\.\./\.\./\.\./Engine/Plugins/Interchange/Runtime/Content/(.+)$~',$p,$z))$logical='/Interchange/'.$z[1];
 elseif(preg_match('~^\.\./\.\./\.\./(?:Engine|FactoryGame)/Plugins/(?:.+/)?([^/]+)/Content/(.+)$~',$p,$z))$logical='/'.$z[1].'/'.$z[2];
 if($logical===null)throw new RuntimeException('Unknown Epic mount: '.$p);
 $name=strtolower(preg_replace('/\.(uasset|umap)$/i','',$logical));
 if(isset($physical[$name]))throw new RuntimeException('Duplicate native name: '.$name);
 $physical[$name]=$root.substr($p,strlen('../../../'));
}
$r=new Uedb5IoStoreTocReader($toc,null,'D:/php8.5/ext/oo2core_9_win64.dll');
$query=$db->query('SELECT toc_index,package_name,logical_size FROM ue_iostore_packages WHERE container_id=4 AND chunk_index=0 ORDER BY toc_index');
$checked=0;$totalBytes=0;$fail=[];$visited=[];
while($row=$query->fetch()){
 $name=strtolower($row['package_name']);$visited[$name]=true;
 try{
  if(!isset($physical[$name]))throw new RuntimeException('Missing from Epic listing');
  $stem=preg_replace('/\.(uasset|umap)$/i','',$physical[$name]);
  $a=@file_get_contents($stem.'.uheader');$b=@file_get_contents($stem.'.uexp');
  if(!is_string($a)||!is_string($b))throw new RuntimeException('Missing Epic extraction fragments');
  $raw=$r->readChunk((int)$row['toc_index']);
  if(strlen($raw)!==(int)$row['logical_size']||strlen($raw)!==strlen($a)+strlen($b)||!hash_equals(hash('sha256',$raw),hash('sha256',$a.$b)))throw new RuntimeException('Exact reconstructed package bytes differ');
  $checked++;$totalBytes+=strlen($raw);
 }catch(Throwable $error){if(count($fail)<25)$fail[]=['name'=>$name,'error'=>$error->getMessage()];}
 if(($checked+count($fail))%2500===0){fwrite(STDERR,'MATCHED='.$checked.' FAIL='.count($fail).PHP_EOL);}
}
$ok=$checked===27240&&count($physical)===27240&&count($visited)===27240&&$fail===[];
echo json_encode(['ok'=>$ok,'epic_native_packages'=>count($physical),'unrealdb_packages'=>count($visited),'matching_reconstructed_package_sha256'=>$checked,'matched_bytes'=>$totalBytes,'failures'=>$fail,'independent'=>'Epic UnrealPak extracted .uheader+.uexp vs UnrealDB IoStore chunk reader'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
