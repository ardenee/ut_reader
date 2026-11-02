<?php
declare(strict_types=1);
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=$db->query('SELECT @@port p,@@datadir d')->fetch(PDO::FETCH_ASSOC);
if((int)$id['p']!==3339||stripos($id['d'],'uedb5-isolated-mysql')===false)throw new RuntimeException('unsafe');
$names=[];foreach($db->query('SELECT package_name FROM ue_files WHERE game_id=2') as $x)$names[strtolower($x['package_name'])]=true;
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe';
$toc='C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$lines=[];$exit=0;exec(escapeshellarg($exe).' '.escapeshellarg($toc).' -List 2>&1',$lines,$exit);
if($exit)throw new RuntimeException('Epic listing failed');
$paths=[];$unmapped=[];$count=0;
foreach($lines as $line){
 if(!preg_match('/^LogIoStore: Display: "(.+)" offset:/',$line,$m))continue;
 $p=$m[1];if($p==='<ContainerHeader>')continue;$count++;$name=null;
 if(str_starts_with($p,'../../../FactoryGame/Content/'))$name='/Game/'.substr($p,strlen('../../../FactoryGame/Content/'));
 elseif(str_starts_with($p,'../../../Engine/Content/'))$name='/Engine/'.substr($p,strlen('../../../Engine/Content/'));
 elseif(preg_match('~^\.\./\.\./\.\./Engine/Plugins/Interchange/Assets/Content/(.+)$~',$p,$pm))$name='/InterchangeAssets/'.$pm[1];
 elseif(preg_match('~^\.\./\.\./\.\./Engine/Plugins/Interchange/Runtime/Content/(.+)$~',$p,$pm))$name='/Interchange/'.$pm[1];
 elseif(preg_match('~^\.\./\.\./\.\./(?:Engine|FactoryGame)/Plugins/(?:.+/)?([^/]+)/Content/(.+)$~',$p,$pm))$name='/'.$pm[1].'/'.$pm[2];
 if($name===null){if(count($unmapped)<12)$unmapped[]=$p;continue;}
 $name=strtolower(preg_replace('/\.(uasset|umap)$/i','',$name));$paths[$name]=true;
}
 $ok=$count===27240&&count($paths)===27240&&count($names)===27240&&count(array_intersect_key($names,$paths))===27240&&$unmapped===[];
echo json_encode(["ok"=>$ok,'native_entries'=>$count,'native_normalized'=>count($paths),'db_entries'=>count($names),'overlap'=>count(array_intersect_key($names,$paths)),'unmapped'=>$unmapped,'native_only'=>array_slice(array_keys(array_diff_key($paths,$names)),0,12),'db_only'=>array_slice(array_keys(array_diff_key($names,$paths)),0,12)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
