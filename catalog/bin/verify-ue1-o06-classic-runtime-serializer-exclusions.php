#!/usr/bin/env php
<?php
/**
 * Source-backed exclusion of classic UE1 non-package runtime serializers.
 *
 * Only exclude when pinned Epic source explicitly declares CLASS_Transient,
 * or for UClient documents that the native class cannot be loaded/saved.
 * This does NOT exclude any saveable actor or engine source family by name
 * association alone. No synthetically fabricated package or C++ oracle.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$register=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$entries=array_column($register['entries'],null,'id');
$roots=[
 'C98'=>rtrim('L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]','\\'),
 'T99'=>rtrim('L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]','\\'),
];
$classes=[
 'C98'=>[
  'UClient'=>['Engine/Inc/UnCamera.h','nonsaveable_comment'],
  'UEditorEngine'=>['Editor/Src/Editor.h','transient'],
  'UEngine'=>['Engine/Inc/UnEngine.h','transient'],
  'UGameEngine'=>['Engine/Inc/UnGame.h','transient'],
  'UInput'=>['Engine/Inc/UnIn.h','transient'],
  'UNetConnection'=>['Engine/Inc/UnConn.h','transient'],
  'UNetDriver'=>['Engine/Inc/UnNetDrv.h','transient'],
  'UPlayer'=>['Engine/Inc/UnPlayer.h','transient'],
  'UTransBuffer'=>['Editor/Src/UnEdTran.h','transient'],
  'UViewport'=>['Engine/Inc/UnCamera.h','transient'],
 ],
 'T99'=>[
  'UClient'=>['Engine/Inc/UnCamera.h','nonsaveable_comment'],
  'UEditorEngine'=>['Editor/Inc/Editor.h','transient'],
  'UEngine'=>['Engine/Inc/UnEngine.h','transient'],
  'UGameEngine'=>['Engine/Inc/UnGame.h','transient'],
  'UInput'=>['Engine/Inc/UnIn.h','transient'],
  'UNetConnection'=>['Engine/Inc/UnConn.h','transient'],
  'UNetDriver'=>['Engine/Inc/UnNetDrv.h','transient'],
  'UPackageMap'=>['Core/Inc/UnCoreNet.h','transient'],
  'UPlayer'=>['Engine/Inc/UnPlayer.h','transient'],
  'UViewport'=>['Engine/Inc/UnCamera.h','transient'],
 ],
];
$checks=[];$failed=[];$hashes=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void {
 $checks[$name]=$ok;if(!$ok)$failed[]=$name;
};
$read=static function(string $path)use(&$hashes):string{
 $data=@file_get_contents($path);
 if($data===false){return '';}
 $hashes[$path]=hash('sha256',$data);
 return $data;
};
foreach($classes as $branch=>$data){
 $sourceRoot=$roots[$branch];
 foreach($data as $class=>[$declPath,$proof]){
  $id=$branch.':'.$class.'::Serialize';
  $e=$entries[$id]??null;
  $check($id.':registered_in_pinned_source',is_array($e)
   &&isset($e['locations'][0]['path'],$e['locations'][0]['line']));
  if(!is_array($e)){continue;}
  $source=$read($sourceRoot.'\\'.str_replace('/','\\',$e['locations'][0]['path']));
  $declaration=$read($sourceRoot.'\\'.str_replace('/','\\',$declPath));
  $needle='void '.$class.'::Serialize';
  $pos=strpos($source,$needle);
  $check($id.':real_cpp_serializer_body',$pos!==false);
  $body=$pos===false?'':substr($source,$pos,2400);
  if($proof==='transient'){
   // Don't mistake an adjacent class's flags for this class's declaration.
   $rx='/DECLARE_(?:ABSTRACT_)?CLASS\s*\(\s*'.preg_quote($class,'/').'\s*,[^)]*CLASS_Transient/s';
   $check($id.':explicit_CLASS_Transient_in_class_decl',
     preg_match($rx,$declaration)===1);
  }else{
   $check($id.':explicit_can_not_load_or_save_comment',
    str_contains($body,"Only serialize objects, since this can't be loaded or saved.")
    &&str_contains($body,'Ar << Viewports;'));
   $check($id.':client_abstract_config_decl',
    preg_match('/DECLARE_ABSTRACT_CLASS\s*\(\s*UClient\s*,\s*UObject\s*,\s*CLASS_Config/',$declaration)===1);
  }
  if($class==='UPlayer'){
   $check($id.':only_inherited_native_serializer',
    $pos!==false&&str_contains(substr($body,0,200),'Serialize( Ar )')
      || ($pos!==false&&str_contains(substr($body,0,200),'Serialize(Ar)')));
  }
  if($class==='UPackageMap'){
   $check($id.':only_network_reference_map',
     str_contains($body,'Ar << List << LinkerMap << ClassFieldIndices;'));
  }
 }
}
ksort($hashes);
$digest=hash('sha256',json_encode($hashes,JSON_UNESCAPED_SLASHES));
echo json_encode([
 'ok'=>count($failed)===0,
 'checks'=>count($checks),'passed'=>count($checks)-count($failed),
 'failed'=>$failed,
 'source_files'=>count($hashes),
 'source_sha256_manifest'=>$digest,
 'excluded_symbols'=>array_sum(array_map('count',$classes)),
 'scope'=>'Only explicitly CLASS_Transient or UClient source-nonsaveable runtime classes, not package-resident actors',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
