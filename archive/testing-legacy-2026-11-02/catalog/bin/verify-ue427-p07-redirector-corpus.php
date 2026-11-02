<?php
require 'C:/Users/arden/source/repos/ut_reader/UE4/UnrealPackageReader.php';
$files=file('C:/Temp/ue427-all-assets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$files[0]=preg_replace('/^\\xEF\\xBB\\xBF/','',$files[0]);
$redirectors=[];$all=[];$count=0;
foreach($files as $path){
 $r=new UnrealPackageReader4($path);$imps=$r->getImports();$exps=$r->getExports();
 $pkg='/Engine/'.substr(str_replace('\\','/',$path),strlen('H:/Epic Games/UE_4.27/Engine/Content/'),-7);
 foreach($exps as $e){
  $ci=(int)$e['classIndex'];if($ci>=0||($imps[-$ci-1]['objectNameText']??'')!=='ObjectRedirector')continue;
  $name=(string)$e['objectNameText'];$redirectors[$pkg.'|'.$name]=['path'=>$path,'package'=>$pkg,'name'=>$name,'index'=>$e['index']];++$count;
 }
 $all[]=[$path,$pkg,$imps];
}
$matches=[];
foreach($all as [$file,$pkg,$imps])foreach($imps as $imp){
 if($imp['classNameText']==='Package'||$imp['classNameText']==='ObjectRedirector')continue;
 $outer=(int)$imp['outerIndex'];$depth=0;$packageName='';
 while($outer<0&&++$depth<20){
  $p=$imps[-$outer-1]??null;if(!$p)break;
  if($p['classNameText']==='Package' && (int)$p['outerIndex']===0){$packageName=$p['objectNameText'];break;}
  $outer=(int)$p['outerIndex'];
 }
 $key=$packageName.'|'.$imp['objectNameText'];
 if(isset($redirectors[$key])){
  $matches[]=['consumer_file'=>$file,'consumer_package'=>$pkg,'import_index'=>$imp['index'],
   'expected_class'=>$imp['classNameText'],'name'=>$imp['objectNameText'],
   'provider'=>$redirectors[$key]];
 }
}
$ok=count($all)===1749&&$count===64&&count($matches)===3;
echo json_encode(['ok'=>$ok,'scope'=>'Serialized UE4.27 ObjectRedirector/consumer corpus identity only, NOT compiled VerifyImport results',
 'packages'=>count($all),'redirector_exports'=>$count,'matched_consumer_imports'=>count($matches),
 'sample'=>$matches],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($ok?0:2);
