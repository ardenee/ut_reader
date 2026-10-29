#!/usr/bin/env php
<?php
/** T060 + T061: one original compiled 227k CDO load, mixed saved defaults and absent-tag inheritance.
 * The reader is an archive reader; runtime CDO overlay is compared against
 * original compiled, not falsely claimed as an implemented net/instance decoder.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);$base=$repo.'/catalog/compliance/evidence/o06/';
$system='C:/Temp/UnrealDB/O06/Native-227k/System/';
$checkCount=0;$errors=[];
$check=static function(bool $ok,string $what)use(&$checkCount,&$errors):void{
 ++$checkCount;if(!$ok)$errors[]=$what;
};
foreach([
 [$system.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$system.'Engine.u','a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'],
 [$base.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log','f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d'],
 [$base.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO-probe.cpp','bf74e2ae67c5c61ed7407d7c9c69b4358b823eeda82e95079b78c08aeafa161b'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468']
] as [$filename,$sha])$check(is_file($filename)&&hash_file('sha256',$filename)===$sha,
 'actual original stock engine/package/archive reader and real native CDO evidence SHA256 '.basename($filename));
$raw=str_replace("\r",'',(string)file_get_contents($base.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log'));
$check(str_contains($raw,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')&&str_contains($raw,'native_error=0')
  &&str_contains($raw,'G5_NATIVE_OBJECT_COUNT 6'),'actual compiled C227k Core.dll loaded original Engine.u no native errors, six distinct classes');
$native=[];$sizes=[];
foreach(['Actor','Pawn','PlayerPawn','HUD','GameInfo','LevelInfo'] as $name){
 $regex='~G3_T060_ORIGINAL_227K_CDO_BEGIN Class Engine\.'.$name.'\|defaultPtr=[A-Fa-f0-9]+\n(.*?)G3_T060_ORIGINAL_227K_CDO_END Class Engine\.'.$name.'~s';
 $ok=preg_match($regex,$raw,$m)===1;
 $check($ok,'actual compiled 227k native CDO body exists and fully returned '.$name);
 if(!$ok)continue;
 preg_match_all('/^G5_NATIVE_TAG_VALUE\s+([A-Za-z_][A-Za-z0-9_]*)=(.*)$/m',$m[1],$properties,PREG_SET_ORDER);
 $fields=[];
 foreach($properties as $property)$fields[$property[1]]=trim($property[2]);
 $check(count($fields)>=5,'genuine original 227k compiled CDO returned actual properties '.$name);
 $native[$name]=$fields;$sizes[$name]=count($properties);
}
$check(count($native)===6,'all six original 227k CDO native classes recovered without duplicates');
$package=new P($system.'Engine.u');
$exports=$package->getExports();
$check((int)$package->getHeader()['version']===69,
       'actual original C227k Engine.u package version69; source lineage/build not inferred from version alone');
$classes=[];$savedTags=[];
foreach($exports as $i=>$export){
 $name=$export['objectNameText'];
 if((int)$export['classIndex']!==0||!isset($native[$name]))continue;
 $r=C::inspect($system.'Engine.u',$package,$i,false,'unreal-v227k');
 $check($r['source_file_archive_complete']===true,'original 227k real Engine.u class fully archived consumed '.$name);
 $tags=$r['default_property_tags'];
 $check(count($tags)===$r['defaults_tag_count'],'original C227k true class saved FPropertyTag count '.$name);
 $byName=[];$last=-1;
 foreach($tags as $tag){
   $offset=(int)$tag['tag_offset'];
   $check($offset>$last,'original 227k real archive strict monotone order '.$name.' byte '.$offset);
   $last=$offset;
   $byName[$tag['name']]=$tag;
 }
 $ref=(int)$r['super_field_reference'];
 $super=$ref>0?($exports[$ref-1]['objectNameText']??null):null;
 $classes[$name]=['source'=>$r,'byName'=>$byName,'super'=>$super,'class_export_index'=>$i];
 $savedTags[$name]=count($tags);
}
$check(count($classes)===6,'all 6 genuine source-selected compiled C227k class archives located');
$check($classes['Pawn']['super']==='Actor'&&$classes['PlayerPawn']['super']==='Pawn'
 &&$classes['HUD']['super']==='Actor','original 227k inherited serialized class chain Actor Pawn PlayerPawn and HUD');
$game=$classes['GameInfo']['byName'];
foreach([
 ['MaxPlayers','IntProperty',16,'16'],
 ['bPauseable','BoolProperty',true,'True'],
 ['DefaultPlayerState','NameProperty',null,'"PlayerWalking"']
] as [$field,$kind,$expected,$loaded]){
 $tag=$game[$field]??null;
 $check($tag!==null,'original C227k actual saved nonzero tagged GameInfo.'.$field);
 if($tag===null)continue;
 $check($tag['type']===$kind,'source-selected C227k archive tag kind '.$field);
 if($field==='DefaultPlayerState'){
    $nameindex=(int)$tag['value'];
    $check($nameindex===59 && ($package->getNames()[$nameindex]['text']??null)==='PlayerWalking',
      'source selected 227k actual FName index59 resolves PlayerWalking and not stock v432 index63');
 }elseif($kind==='BoolProperty'){
    $check($tag['bool_value']===true && $tag['value']===true,
      'native original archived C227k UBoolProperty special info B=1 source');
 }else{
    $check((int)$tag['value']===$expected,'native original archived C227k integer value 16');
 }
 $check(($native['GameInfo'][$field]??null)===$loaded,
       'actual original compiled C227k independently loaded GameInfo.'.$field.' value');
}
$pawn=$classes['Pawn']['byName'];$player=$classes['PlayerPawn']['byName'];
foreach([
 ['Health','IntProperty',100,'100'],
 ['AirControl','FloatProperty',0.05,'0.05']
] as [$field,$kind,$numeric,$loaded]){
 $tag=$pawn[$field]??null;
 $check($tag!==null && $tag['type']===$kind,'original actual parent Pawn saved '.$field.' property');
 if($tag!==null)$check(abs((float)$tag['value']-$numeric)<1e-6,
     'actual 227k Pawn nonzero saved member '.$field.' source value');
 $check(($native['Pawn'][$field]??null)===$loaded,
       'actual compiled native loaded parent Pawn.'.$field.' nonzero default');
 $check(!isset($player[$field]),'actual original PlayerPawn archive has NO saved '.$field.' property tag');
 $check(($native['PlayerPawn'][$field]??null)===$loaded,
       'actual compiled 227k real PlayerPawn inherited unsaved '.$field.' from original parent Pawn');
}
$check(isset($player['bCanJump']) && $player['bCanJump']['bool_value']===true
 &&isset($pawn['bCanJump']) && $pawn['bCanJump']['bool_value']===false,
 'original PlayerPawn saved true overriding Pawn original saved false for bCanJump');
$check(($native['PlayerPawn']['bCanJump']??null)==='True'
 &&($native['Pawn']['bCanJump']??null)==='False',
 'original compiled 227k loaded overridden native PlayerPawn.bCanJump=True and Pawn.False');
$check(!isset($player['bHidden'])&&!isset($pawn['bHidden'])
 &&isset($classes['Actor']['byName']['bHidden'])
 &&$classes['Actor']['byName']['bHidden']['bool_value']===false,
 'two original class levels omit Actor.bHidden saved tag, original Actor has false');
$check(($native['PlayerPawn']['bHidden']??null)==='False'
 &&($native['Pawn']['bHidden']??null)==='False',
 'original compiled same class chain retains inherited bHidden false without inventing child zero tags');
$check(isset($classes['HUD']['byName']['bHidden'])
 &&$classes['HUD']['byName']['bHidden']['bool_value']===true
 &&($native['HUD']['bHidden']??null)==='True',
 'original compiled genuine HUD saved Bool true overrides Actor False on load');
$check(($native['GameInfo']['bHidden']??null)==='True',
 'native original GameInfo class inherited or locally overridden true, no invented child tag assertion');
$result=['ok'=>$errors===[],'checks'=>$checkCount,'failures'=>$errors,
 'T060'=>$errors===[]?'PASS':'BLOCKED','T061'=>$errors===[]?'PASS':'BLOCKED',
 'native_loaded_C227k_classes'=>array_keys($native),
 'native_CDO_property_lines_by_class'=>$sizes,
 'genuine_original_source_serialized_default_tag_counts'=>$savedTags,
 'C227k_original_true_source_FName_index59'=>'PlayerWalking',
 'native_original_PlayerPawn_inherits_parent_Pawn_unsaved_fields'=>['Health'=>'100','AirControl'=>'0.05','bHidden'=>'False'],
 'native_original_PlayerPawn_overrides_parent_Pawn_bCanJump'=>['Pawn'=>'False','PlayerPawn'=>'True'],
 'original_compiled_engine_sessions_rerun_by_verifier'=>0];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['ok']?0:1);
