<?php
/** FAssetData semantics: native-authored v522 fixtures and guarded source gates. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$dir=$argv[1]??'C:/Temp/UE427Oracle/Saved/UE427AssetFixtures';
$files=[
 'empty'=>$dir.'/WorldWithoutRows.uasset',
 'with'=>$dir.'/WorldWithRows.uasset',
 'nested'=>$dir.'/RelativeSubObject.uasset'
];
foreach($files as $file)if(!is_file($file))throw new RuntimeException("Missing compiled Epic fixture: $file");
$readers=[];foreach($files as $k=>$f)$readers[$k]=new UnrealPackageReader4($f);
$assets=[];
foreach($readers as $k=>$r){
 if($r->validatePackage()!==[])throw new RuntimeException("Invalid native fixture $k");
 $assets[$k]=$r->getAssetRegistryAssetData('/Game/UE427AssetFixtures/'.match($k){
  'empty'=>'WorldWithoutRows','with'=>'WorldWithRows','nested'=>'RelativeSubObject'
 });
}
$check=[];
$check['synthetic_empty_world']=count($assets['empty'])===1
 && $assets['empty'][0]['sourceIndex']===-1
 && $assets['empty'][0]['syntheticWorld']
 && $assets['empty'][0]['assetClass']==='World'
 && $assets['empty'][0]['chunkIDs']===[3,7]
 && $assets['empty'][0]['objectPath']==='/Game/UE427AssetFixtures/WorldWithoutRows.WorldWithoutRows';
$check['real_map_rows_no_duplicate_synthesis']=count($assets['with'])===2
 && !$assets['with'][0]['syntheticWorld']
 && !$assets['with'][1]['syntheticWorld'];
$check['external_path_allowed_modern']=($assets['with'][1]['objectPath']??'')
 ==='/Game/External/ExternalObject.ExternalObject'
 && ($assets['with'][1]['assetName']??'')==='ExternalObject';
$check['nested_relative_object_skipped']=$assets['nested']===[];
$tags=$assets['with'][0]['tags'];
$check['empty_searchable_tags_filtered']=count($tags)===1 && $tags[0]['key']==='Title'
 && $tags[0]['value']==='Native Example'
 && $tags[0]['rawValue']==='NSLOCTEXT("", "ExampleId", "Native Example")';
$head=new ReflectionProperty(UnrealPackageReader4::class,'header');
$reader=$readers['with'];$original=$head->getValue($reader);
try{
 $temp=$original;$temp['version']=519;$head->setValue($reader,$temp);
 $legacyExternal=$reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows');
 $check['external_path_blocked_v519']=count($legacyExternal)===1
   && ($legacyExternal[0]['sourceIndex']??-2)===0;
 $temp['version']=520;$head->setValue($reader,$temp);
 $currentExternal=$reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows');
 $check['external_path_allowed_v520']=count($currentExternal)===2;
 $temp['version']=336;$head->setValue($reader,$temp);
 $legacyMap=$reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows');
 $check['legacy_map_synthesis_before_337']=count($legacyMap)===2
   && $legacyMap[0]['syntheticWorld']===true
   && $legacyMap[1]['syntheticWorld']===false;
 $temp['version']=337;$head->setValue($reader,$temp);
 $noSynthesis=$reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows');
 $check['modern_map_does_not_synthesize_337']=count($noSynthesis)===1;
 $temp=$original;$temp['packageFlags']|=1;$head->setValue($reader,$temp);
 $check['editor_masks_pk_newly_created']=($reader->getHeader()['packageFlags']&1)===1
   && $reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows')[0]['packageFlags']===131072;
 $temp=$original;$temp['assetRegistryDataOffset']=0;$head->setValue($reader,$temp);
 try{$reader->getAssetRegistryAssetData('/Game/UE427AssetFixtures/WorldWithRows');$noSection=false;}
 catch(RuntimeException $e){$noSection=str_contains($e->getMessage(),'valid serialized AssetRegistry section');}
 $check['reject_absent_registry_section']=$noSection;
}finally{$head->setValue($reader,$original);}
foreach(['','WorldWithRows','/Game/Bad//Path','/Game/Bad/','/Game/Bad.Package'] as $k=>$name){
 try{$readers['with']->getAssetRegistryAssetData($name);$reject=false;}
 catch(InvalidArgumentException $e){$reject=true;}
 $check['reject_invalid_package_name_'.$k]=$reject;
}
$ok=count($check)===16&&!in_array(false,$check,true);
echo json_encode(['ok'=>$ok,'checks'=>$check,'pass_count'=>count(array_filter($check)),
 'total_checks'=>count($check),'scope'=>'Real compiled v522 semantic fixtures + pinned compiled 337/520 threshold mutations'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
