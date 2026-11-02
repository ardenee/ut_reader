#!/usr/bin/env php
<?php
/** UE1 classic source FPropertyTag: genuine SHA-pinned 92-package fixture. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
$root=dirname(__DIR__).'/compliance/inventory/';
$manifests=[$root.'ue1-textures-v35-v61.csv',$root.'ue1-packages-v68-v69.csv'];
$total=0;$parsed=0;$tags=0;$tail=0;$unsupported=0;$errors=[];$kinds=[];$samples=[];$errorVersions=[];$successVersions=[];$nativeKinds=[];$nativeErrors=[];$nativeClasses=[];$valueKinds=[];$tagTypes=[];$structTypes=[];
foreach($manifests as $manifest){
 $fp=fopen($manifest,'rb');if(!is_resource($fp)){throw new RuntimeException('No manifest');}
 if(fread($fp,3)!=="\xEF\xBB\xBF"){rewind($fp);}
 $head=fgetcsv($fp,0,',','"','');
 while(($row=fgetcsv($fp,0,',','"',''))!==false){
  $r=array_combine($head,$row);
  $path=rtrim((string)($r['SourceRoot']??'L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures'),'\\/')
    .DIRECTORY_SEPARATOR.str_replace(['\\','/'],DIRECTORY_SEPARATOR,(string)$r['RelativePath']);
  if(!is_file($path)||strtolower(hash_file('sha256',$path))!==strtolower((string)$r['Sha256'])){
   $errors[]=['path'=>$path,'error'=>'SHA mismatch'];continue;
  }
  $reader=new CatalogUE1PackageReader($path);$validation=$reader->validatePackage();
  if($validation!==[]){$errors[]=['path'=>$path,'error'=>$validation];continue;}
  $total++;
  foreach($reader->getExports() as $e){
   try{
    $result=Tags::inspect($path,$reader,(int)$e['index']);
    $kinds[$result['status']]=($kinds[$result['status']]??0)+1;
    if($result['status']==='tag_envelope_decoded'){
      $native=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader::inspect($path,$reader,(int)$e['index'],$result);
      $nativeKinds[$native['status']]=($nativeKinds[$native['status']]??0)+1;
      if($native['status']==='class_specific_serializer_unimplemented'){
       $nativeClasses[$native['class_name']]=($nativeClasses[$native['class_name']]??0)+1;
      }
      foreach($result['tags'] as $tag){$valueKinds[$tag['value_kind']]=($valueKinds[$tag['value_kind']]??0)+1;$tagTypes[$tag['type']]=($tagTypes[$tag['type']]??0)+1;if($tag['type']==='StructProperty'){$sk=(string)($tag['struct_name']['text']??'?').'@'.$tag['serialized_size'];$structTypes[$sk]=($structTypes[$sk]??0)+1;}}
      $parsed++;$tags+=count($result['tags']);$tail+=(int)$result['native_tail_bytes'];$successVersions[(int)$r['PackageVersion']]=($successVersions[(int)$r['PackageVersion']]??0)+1;
      if(count($samples)<10 && count($result['tags'])>0){
        $samples[]=['path'=>basename($path),'export'=>$result['object_name'],
          'tag_count'=>count($result['tags']),'first'=>$result['tags'][0]['name'],
          'native_tail_bytes'=>$result['native_tail_bytes']];
      }
    }else{$unsupported++;}
   }catch(Throwable $err){$errorVersions[(int)$r['PackageVersion']]=($errorVersions[(int)$r['PackageVersion']]??0)+1;if(count($errors)<12)$errors[]=['path'=>basename($path),'version'=>$r['PackageVersion'],'export'=>$e['objectNameText'],'class_index'=>$e['classIndex'],'offset'=>$e['serialOffset'],'bytes'=>strtoupper(bin2hex((string)file_get_contents($path,false,null,(int)$e['serialOffset'],64))),'error'=>$err->getMessage()];}
  }
 }
 fclose($fp);
}
// Pinned corpus acceptance: additions or serializer drift must cause
// an explicit review, not silent loss of genuine object coverage.
$nativeComplete=array_sum($nativeKinds);
$ok=$errors===[]&&$total===92&&$parsed===2465&&$tags===15475
    && $nativeComplete===2465
    && count($nativeClasses)===0
    && ($valueKinds['opaque']??0)===0
    && array_sum($valueKinds)===$tags
    && count($nativeKinds)===5;

echo json_encode(['ok'=>$ok,'packages'=>$total,'tagged_exports'=>$parsed,
 'tag_count'=>$tags,'native_tail_bytes'=>$tail,'unsupported_exports'=>$unsupported,
 'statuses'=>$kinds,'native_statuses'=>$nativeKinds,'unimplemented_classes'=>$nativeClasses,'value_kinds'=>$valueKinds,'tag_types'=>$tagTypes,'struct_types'=>$structTypes,'success_by_version'=>$successVersions,'errors_by_version'=>$errorVersions,'samples'=>$samples,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
