#!/usr/bin/env php
<?php
/** One original 2026 227k compiled native Engine.u run proves nested Rotator
 * and inherited Plane/Vector link offsets. Original 2012 + 2026 Epic SDK
 * sources pin FPlane::operator<< FVectorXYZ + W. No original engine reruns.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$system='C:/Temp/UnrealDB/O06/Native-227k/System/';
$checks=0;$fail=[];
$ck=static function(bool $ok,string $why)use(&$checks,&$fail):void{
 ++$checks;if(!$ok)$fail[]=$why;
};
foreach([
 [$system.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$system.'Engine.u','a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'],
 [$ev.'20261029-G3-T036-T039-C227k-original-nested-struct-native.log','edce7870d4a3522ed053bfe1a5b115e345ac4fd6ba87ffdbd40fdb5bfb1849da'],
 [$ev.'20261029-G3-T036-T039-C227k-original-nested-struct-probe.cpp','59dfc53678899142459a1511cb06f332a545ffaa436592cdf16cab8301c87e9e'],
 [$ev.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log','f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d'],
 [$ev.'20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json','760a82b677c59fcc476b732b374756e26ec07a14d97727a7a817b7c47f4d6a66'],
 ['L:/Source/Games/Unreal/Unreal [v1.227]/Core/Inc/UnMath.h','7db6a8e314ccb8a5943330c677763bfb4ac948b3a595b81400034689d0cec677'],
 ['L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Inc/UnMath.h','2c53261003b3176e63fe7f8b9c47e7f8013cb460e7bdc6fb4433a895b49c91e0'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php','121a706fc546cb484d11eb76e6ef99e3c76a5aa21cacc237de9c44b957a13e87'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468']
] as [$file,$sha])$ck(is_file($file)&&hash_file('sha256',$file)===$sha,
    'original compiled/sources or exact current reader SHA256 '.basename($file));
$session=str_replace("\r",'',(string)file_get_contents($ev.'20261029-G3-T036-T039-C227k-original-nested-struct-native.log'));
$cdo=str_replace("\r",'',(string)file_get_contents($ev.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log'));
$ck(str_contains($session,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')&&str_contains($session,'native_error=0')
    &&str_contains($session,'G5_NATIVE_OBJECT_COUNT 10'),'actual original C227k compiled loader linked 10 real UStructProperty references');
$ck(str_contains($cdo,'G5_NATIVE_OBJECT_COUNT 6')&&str_contains($cdo,'native_error=0'),
    'original compiled true CDO loaded six real Engine.u classes, previous native session reused');
$src2012=(string)file_get_contents('L:/Source/Games/Unreal/Unreal [v1.227]/Core/Inc/UnMath.h');
$src2026=(string)file_get_contents('L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Inc/UnMath.h');
foreach(['C227i'=>$src2012,'C227k'=>$src2026] as $profile=>$src){
 $ck(preg_match('/class\s+alignas\(VECTOR_ALIGNMENT\)\s+FPlane\s*:\s*public\s+FVector/',$src)===1,
      $profile.' genuine original C++ Plane inherits native Vector XYZ');
 $ck(preg_match('/friend FArchive& operator<<\(\s*FArchive& Ar,\s*FPlane\s*&P\s*\)\s*\{\s*return Ar << \(FVector&\)P << P\.W;/s',$src)===1,
      $profile.' genuine Epic serializer is FVector XYZ then own Plane W');
 $ck(preg_match('/friend FArchive& operator<<\(\s*FArchive& Ar,\s*FRotator& R\s*\)\s*\{\s*return Ar << R\.Pitch << R\.Yaw << R\.Roll;/s',$src)===1,
      $profile.' genuine original FRotator serializer Pitch/Yaw/Roll');
}
$structs=[];$members=[];
preg_match_all('/^G3_T036_T039_NATIVE_STRUCT class=Class Engine\.([^|]+)\|property=([^|]+)\|struct=Struct Core\.Object\.([^|]+)\|offset=(\d+)\|array_dim=(\d+)\|element_size=(\d+)\|super=(\d+)$/m',$session,$srows,PREG_SET_ORDER);
foreach($srows as $m){
 $key=$m[1].'.'.$m[2];
 $ck(!isset($structs[$key]),'genuine original compiled unique struct class + property '.$key);
 $structs[$key]=['class'=>$m[1],'property'=>$m[2],'struct'=>$m[3],
       'offset'=>(int)$m[4],'dim'=>(int)$m[5],'element_size'=>(int)$m[6],'super'=>(int)$m[7]];
}
preg_match_all('/^G3_T036_T039_NATIVE_MEMBER class=Class Engine\.([^|]+)\|property=([^|]+)\|member=([^|]+)\|offset=(\d+)\|array_dim=(\d+)\|element_size=(\d+)(?:\|value=(-?\d+))?$/m',$session,$rows,PREG_SET_ORDER);
foreach($rows as $m){
 $key=$m[1].'.'.$m[2];$field=$m[3];
 $ck(!isset($members[$key][$field]),'unique original compiled linked member '.$key.'.'.$field);
 $members[$key][$field]=['offset'=>(int)$m[4],'dim'=>(int)$m[5],
    'element_size'=>(int)$m[6],'native_value'=>isset($m[7])&&$m[7]!==''?(int)$m[7]:null];
}
$ck(count($structs)===10&&count($members)===10,
    'original 227k native 10 linked property structs across four real classes');
$ck(count($rows)===36,'actual native 36 linked nested UProperty fields/offsets captured');
$pkg=new P($system.'Engine.u');$byClass=[];
foreach($pkg->getExports() as $id=>$ex){
 if((int)$ex['classIndex']!==0||!in_array($ex['objectNameText'],['Pawn','Actor','PlayerPawn','HUD'],true))continue;
 $r=C::inspect($system.'Engine.u',$pkg,$id,false,'unreal-v227k');
 $ck($r['source_file_archive_complete']===true,'original genuine 227k fully read archive '.$ex['objectNameText']);
 $byClass[$ex['objectNameText']]=[];
 foreach($r['default_property_tags'] as $tag)$byClass[$ex['objectNameText']][$tag['name']]=$tag;
}
$ck(count($byClass)===4,'actual four original classes linked into native 227k struct properties');
$imports=$pkg->getImports();
foreach(['Rotator','Plane','Vector'] as $name){
 $found=array_values(array_filter($imports,static fn(array $x):bool=>
 ($x['objectNameText']??'')===$name
 &&($x['classPackageText']??'')==='Core'
 &&($x['classNameText']??'')==='Struct'));
 $ck(count($found)===1,'actual original Engine.u imported real Core.Struct '.$name.' identity not synthetic');
}
$rotation=$structs['Pawn.RotationRate']??null;
$ck($rotation!==null&&$rotation['struct']==='Rotator'&&$rotation['offset']===620
    &&$rotation['dim']===1&&$rotation['element_size']===12&&$rotation['super']===0,
 'original compiled 227k Pawn UStructProperty RotationRate offset620, 1x12 direct Rotator');
foreach(['Pitch'=>[0,4096],'Yaw'=>[4,50000],'Roll'=>[8,3072]] as $field=>[$offset,$expected]){
 $m=$members['Pawn.RotationRate'][$field]??null;
 $ck($m!==null&&$m['offset']===$offset&&$m['dim']===1
  &&$m['element_size']===4&&$m['native_value']===$expected,
  'actual original native 227k FRotator member '.$field.' exact linked offset and nonzero loaded value');
 $other=$members['PlayerPawn.RotationRate'][$field]??null;
 $ck($other===$m,'actual original native inherited PlayerPawn member '.$field.' link and value retained');
}
$tag=$byClass['Pawn']['RotationRate']??null;
$ck($tag!==null&&$tag['type']==='StructProperty'&&($tag['struct_name']['text']??'')==='Rotator'
 &&$tag['serialized_size']===12&&$tag['value_offset']===103531,
 'original Pawn real archive tags Rotator 12 bytes at true physical offset103531');
if($tag!==null){
 $raw=(string)file_get_contents($system.'Engine.u',false,null,(int)$tag['value_offset'],12);
 $ck(bin2hex($raw)==='0010000050c30000000c0000'
    &&$tag['value']===['pitch'=>4096,'yaw'=>50000,'roll'=>3072],
    'real original C227k Rotator serialized INT32 bytes match source-read 4096/50000/3072');
}
$ck(str_contains($cdo,'G5_NATIVE_TAG_VALUE RotationRate=(Pitch=4096,Yaw=50000,Roll=3072)'),
    'original compiled 227k default object native loaded same nonzero Rotator members');
$plane=$structs['Actor.SimAnim']??null;
$ck($plane!==null&&$plane['struct']==='Plane'&&$plane['offset']===644
  &&$plane['dim']===1&&$plane['element_size']===16&&$plane['super']===1,
  'original compiled Actor.SimAnim actual Core.Object.Plane subclass with inherited Vector at member offset644');
$flat=$structs['Actor.RotationRate']??null;
$ck($flat!==null&&$flat['struct']==='Rotator'&&$flat['super']===0&&$flat['element_size']===12,
    'actual original compiled FLAT Rotator 12 bytes contrasted with inherited Plane 16 bytes');
foreach(['X'=>0,'Y'=>4,'Z'=>8,'W'=>12] as $field=>$offset){
 $m=$members['Actor.SimAnim'][$field]??null;
 $ck($m!==null&&$m['offset']===$offset&&$m['dim']===1&&$m['element_size']===4,
    'original 2026 compiled inherited FPlane member '.$field.' offset '.$offset.' not collapsed');
}
$ck(array_keys($members['Actor.SimAnim']??[])===['W','X','Y','Z'],
  'native linked property chain returns local W first, inherited XYZ after, original offsets remain 12,0,4,8');
$tag=$byClass['Actor']['SimAnim']??null;
$ck($tag!==null&&$tag['type']==='StructProperty'&&($tag['struct_name']['text']??'')==='Plane'
  &&$tag['serialized_size']===16&&$tag['value_offset']===104904,
  'actual original source Actor.SimAnim Plane FPropertyTag points to 16 serialized bytes');
if($tag!==null){
 $bytes=(string)file_get_contents($system.'Engine.u',false,null,(int)$tag['value_offset'],16);
 $ck($bytes===str_repeat("\0",16),'original Engine.u Plane raw physical sixteen serialized zero floats');
 $ck($tag['value_kind']==='fplane_inherited_fvector_xyz_w_float32'
 &&$tag['value']===['x'=>0.0,'y'=>0.0,'z'=>0.0,'w'=>0.0],
  'current UE1 reader properly resolves inherited FVector XYZ then W using 2012/2026 original source branch');
}
$ck(str_contains($cdo,'G5_NATIVE_TAG_VALUE SimAnim=(W=0.0,X=0.0,Y=0.0,Z=0.0)'),
  'actual compiled 2026 native loaded Plane members W XYZ zero, archive matches');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$fail,
 'T036'=>$ok?'PASS':'BLOCKED','T039'=>$ok?'PASS':'BLOCKED',
 'original_native_2026_linked_struct_property_count'=>count($structs),
 'original_native_2026_member_offset_events'=>count($rows),
 'native_227k_Pawn_RotationRate'=>$members['Pawn.RotationRate']??[],
 'native_227k_Actor_SimAnim_inherited_Plane'=>$members['Actor.SimAnim']??[],
 'archive_fplane_2012_2026_common_source_serializer'=>'FVector XYZ then FLOAT W',
 'native_original_227k_Engine_Actor_flat_vs_inherited'=>['Rotator'=>$flat,'Plane'=>$plane],
 'new_original_compiled_engine_reruns_during_verifier'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
