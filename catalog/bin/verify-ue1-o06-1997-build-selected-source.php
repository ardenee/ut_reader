#!/usr/bin/env php
<?php
/**
 * G2 May/July 1997 build-selected archive source identity.
 *
 * May contains a stale Engine/UnRes.cpp that defines many of the same
 * UObject and UDatabase routines as compiled Engine/UnObj.cpp. Only the
 * selected VS6 Engine.dsp translation units are the executable authority.
 */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
$roots=[
 'may'=>'L:/Source/Games/Unreal/Unreal [v0.86x] [1997-05-25]/Src/Engine',
 'july'=>'L:/Source/Games/Unreal/Unreal [v0.867] [1997-07-25]/Src/Engine',
];
$checks=[];$failed=[];
$assert=static function(string $key,bool $ok)use(&$checks,&$failed):void{
 $checks[$key]=$ok;if(!$ok)$failed[]=$key;
};
$hashes=[];$selected=[];$unselected=[];
foreach($roots as $label=>$root){
 $dsp=@file_get_contents($root.'/Engine.dsp');
 $obj=@file_get_contents($root.'/UnObj.cpp');
 $old=@file_get_contents($root.'/UnRes.cpp');
 $assert($label.'_project_exists',is_string($dsp));
 $assert($label.'_real_source_exists',is_string($obj));
 $cpp=is_string($dsp)
    ? (preg_match_all('/^\s*SOURCE=\.[\\\\\/]([^\\r\\n]+\.cpp)\s*$/mi',$dsp,$matches)
       ? array_map('strtolower',$matches[1]):[]) : [];
 $assert($label.'_source_listing_not_empty',count($cpp)>12);
 $assert($label.'_build_selects_UnObj',in_array('unobj.cpp',$cpp,true));
 $assert($label.'_does_not_build_stale_UnRes',!in_array('unres.cpp',$cpp,true));
 $assert($label.'_has_one_UObject_archive_definition_in_project',
     is_string($obj)&&substr_count($obj,'void UObject::SerializeHeader(')===1);
 $assert($label.'_default_aware_binary_archive_selected',
     is_string($obj)
     &&str_contains($obj,'Ar.Preload( Class->Bins[PROPBIN_PerObject] );')
     &&str_contains($obj,'SerializeBin( Ar, PROPBIN_PerObject, this, Defaults )'));
 $selected[$label]=['root'=>$root,'engine_cpp_count'=>count($cpp),
   'unobj_selected'=>in_array('unobj.cpp',$cpp,true),
   'unres_selected'=>in_array('unres.cpp',$cpp,true)];
 foreach(['Engine.dsp'=>$dsp,'UnObj.cpp'=>$obj] as $file=>$value){
  $hashes[$label][$file]=is_string($value)?hash('sha256',$value):null;
 }
 if($label==='may'){
  $assert('may_obsolete_duplicate_present',is_string($old)
     &&substr_count($old,'void UObject::SerializeHeader(')===1);
  $assert('may_duplicate_has_different_serializer_semantics',
     is_string($old)
     &&str_contains($old,'SerializeBin( Ar, PROPBIN_PerObject, this, 1 );')
     &&!str_contains($old,'SerializeBin( Ar, PROPBIN_PerObject, this, Defaults )'));
  $hashes[$label]['UnRes.cpp']=is_string($old)?hash('sha256',$old):null;
 }
}
$register=json_decode((string)file_get_contents(
 $repo.'/catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json'),
 true,512,JSON_THROW_ON_ERROR);
$affected=[];
foreach($register['entries']??[] as $entry){
 if(($entry['branch']??null)!=='U86')continue;
 $locations=$entry['locations']??[];
 $paths=array_column($locations,'path');
 if(!in_array('Engine/UnRes.cpp',$paths,true))continue;
 $affected[]=$entry['id'];
 $real=array_values(array_filter($locations,
   static fn(array $x):bool=>$x['path']==='Engine/UnObj.cpp'));
 $obsolete=array_values(array_filter($locations,
   static fn(array $x):bool=>$x['path']==='Engine/UnRes.cpp'));
 $assert('may_stale_sighting_has_compiled_peer_'.$entry['id'],
  count($real)===1&&$real[0]['build_selected']===true
  &&count($obsolete)===1&&$obsolete[0]['build_selected']===false);
}
sort($affected);
$assert('may_13_registered_duplicate_sightings_count',count($affected)===13);
$assert('source_provenance_does_not_assert_runtime_parity',
 is_file($repo.'/catalog/src/Infrastructure/Readers/CatalogUE1OriginalUnrealfileStackReader.php'));
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'build_selected'=>$selected,
 'source_hash_sha256'=>$hashes,
 'unbuilt_obsolete_source_sightings'=>$affected,
 'required_policy'=>'Trust only build-selected UnObj.cpp and Engine.dsp for May UObject archive semantics; do not use duplicate unbuilt UnRes.cpp when asserting parity.',
 'remaining'=>'A verified compiler source selection is not a compiled engine oracle or full G2 source acceptance.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
