<?php
/** Frozen T018. The original executables were run once in isolated DEV
 * fixtures; this acceptance reads only their retained original output. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2).'/';$e=$root.'catalog/compliance/evidence/o06/';$d='C:/Temp/UnrealDB/O06/';
$n=0;$errors=[];$ck=static function(bool $v,string $label)use(&$n,&$errors):void{++$n;if(!$v)$errors[]=$label;};
$pins=[
 [$e.'20261031-G3-T018-C227i-native-G3Bulk-default-export.log','540c883b8e539339f7900a154f0d1b06c2d42351bedcd84e11a542123b361ef6'],
 [$e.'20261031-G3-T018-C227i-native-G3Bulk-default.uc','104af83a35b9b66cd951b72bd29167ca0916ed1cdde245c370d2f48f05797175'],
 [$e.'20261031-G3-T018-C227k-native-G3Bulk-default-export.log','8ca6a04e0b258248cd295c013ac99c1ba78618119bbe458ba05c084e5d071b0f'],
 [$e.'20261031-G3-T018-C227k-native-G3Bulk-default.uc','279ff23caf673a78684a30e25994a2feef16f4ba599ee9dbd574ac17acab18a3'],
 [$d.'G3-T018-original-2012-C227i-Engine-native-export/Engine.uc','fa424ce39de35663dbe0d300b3a2ddb4a24626a00f76486d40893a6d0f9c89e7'],
 [$d.'G3-T018-original-2012-C227i-Engine-native-export/SecurityData.uc','bf1e23596231b7c137070a8ef6007168005ef333b3bf72766f5d35e19f21a009'],
 [$e.'20261029-G3-T018-original-C227i-Engine-UC-native-export-FAILED.log','cd28d28352d34a9e147a23122ca591858d36a2bb52234a12e2db539e7d343d66'],
 [$e.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log','f78d3d518a01952667a3ca51698bf683cfc1995ca2d9374bb12b02993b4ef13d'],
 [$e.'20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json','760a82b677c59fcc476b732b374756e26ec07a14d97727a7a817b7c47f4d6a66'],
 [$e.'20261029-G3-T015-original-227i227k-C227-class-phase-1633pass.json','cf4fbf2b1c235ff0dd1bd8edd4a610bf6f58b794ed19a3dee4065e0ae60e683b'],
 [$d.'Native-C227i-G304/System/Core.dll','287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'],
 [$d.'Native-227k/System/Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$d.'Native-C227i-G304/System/Engine.u','931ffb4ddf3b9b608cbfd49bc6d5f86ddbcbe9e9148ac8c7769d6ac3b13d4ac7'],
 [$d.'Native-227k/System/Engine.u','a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'],
 [$d.'Native-C227i-G304/System/G3Bulk227.u','e181c4a49aa51ba914baeead9a9f4c95f0a0154534bd92bbfb70c41f32adb50f'],
 [$d.'Native-227k/System/G3Bulk227.u','e181c4a49aa51ba914baeead9a9f4c95f0a0154534bd92bbfb70c41f32adb50f'],
 [$root.'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','888e12fbc43dffd0d55cc0536f5e964acd017cb41ac68c16f744906435a0d4d4']
];
foreach($pins as [$file,$hash])$ck(is_file($file)&&hash_file('sha256',$file)===$hash,'SHA256 original loaded/source: '.basename($file));
$originalText=static function(string $f):string{
 $s=(string)file_get_contents($f);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$log2012=$originalText($e.'20261031-G3-T018-C227i-native-G3Bulk-default-export.log');
$log2026=$originalText($e.'20261031-G3-T018-C227k-native-G3Bulk-default-export.log');
$failure=(string)file_get_contents($e.'20261029-G3-T018-original-C227i-Engine-UC-native-export-FAILED.log');
$ck(str_contains($log2012,'Exported Class G3Bulk227.G3Bulk227Actor')&&str_contains($log2012,'0 warning(s)'),'original compiled 2012 UCC successfully loaded and exported class CDO');
$ck(str_contains($log2026,'Exported Class G3Bulk227.G3Bulk227Actor')&&str_contains($log2026,'0 warning(s)'),'original compiled 2026 UCC successfully loaded and exported same 2012-produced class');
$ck(str_contains($failure,'Exported Class Engine.Engine')&&str_contains($failure,'Exported Class Engine.SecurityData')&&str_contains($failure,'General protection fault!'),'original compiled 2012 stock Engine.u two class CDO exports occurred before subsequent exporter failure; failure not suppressed');
$archive=['Numbers(0)'=>11,'Numbers(1)'=>-7,'Labels(0)'=>'Alpha','Labels(1)'=>'Beta','BigNeg'=>-2147483647,'MaxByte'=>255,'NegFloat'=>-1.5,'bTrueValue'=>true,'NonZeroName'=>'Alpha'];
$native2012=(string)file_get_contents($e.'20261031-G3-T018-C227i-native-G3Bulk-default.uc');
$native2026=(string)file_get_contents($e.'20261031-G3-T018-C227k-native-G3Bulk-default.uc');
foreach(['Numbers(0)=11','Numbers(1)=-7','Labels(0)="Alpha"','Labels(1)="Beta"','BigNeg=-2147483647','MaxByte=255','NegFloat=-1.500000','bTrueValue=True','NonZeroName="Alpha"'] as $field)$ck(str_contains($native2012,$field),'2012 actual compiled loaded class default '.$field);
foreach(['Numbers(0)=11','Numbers(1)=-7','Labels(0)="Alpha"','Labels(1)="Beta"','BigNeg=-2147483647','MaxByte=255','NegFloat=-1.5','bTrueValue=True','NonZeroName="Alpha"'] as $field)$ck(str_contains($native2026,$field),'2026 actual compiled loaded class default '.$field);
$ck(!str_contains($native2012,'bFalseValue=')&&!str_contains($native2012,'NoneName='),'2012 original class exporter omits default-valued false/None; no claim of differing memory');
$ck(str_contains($native2026,'bFalseValue=False')&&str_contains($native2026,'NoneName="None"'),'2026 original class exporter explicitly writes default-valued false/None');
$nativeEngine2012=(string)file_get_contents($d.'G3-T018-original-2012-C227i-Engine-native-export/Engine.uc');
$nativeSecurity2012=(string)file_get_contents($d.'G3-T018-original-2012-C227i-Engine-native-export/SecurityData.uc');
foreach(['CacheSizeMegs=8','UseSound=True'] as $x)$ck(str_contains($nativeEngine2012,$x),'original genuine 2012 stock Engine.Engine CDO field '.$x);
$ck(str_contains($nativeSecurity2012,'MaxIPLogLen=5000'),'original genuine 2012 stock Engine.SecurityData CDO field');
$native2026Engine=(string)file_get_contents($e.'20261029-G3-T060-T061-C227k-original-Engine-6-CDO.log');
$ck(str_contains($native2026Engine,'native_error=0')&&str_contains($native2026Engine,'G5_NATIVE_OBJECT_COUNT 6'),'original 2026 compiled stock Engine six true runtime loaded CDOs');
$record=json_decode((string)file_get_contents($e.'20261029-G3-T060-T061-original-C227k-six-CDO-902pass.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($record['ok']??false)===true&&($record['checks']??0)===902&&($record['failures']??null)===[],'previous sealed original 2026 engine native CDO six-class loaded-value parity 902 pass, no replay');
$previous=json_decode((string)file_get_contents($e.'20261029-G3-T015-original-227i227k-C227-class-phase-1633pass.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($previous['ok']??false)===true&&($previous['checks']??0)===1633,'previous original class layout 2012 vs 2026 metadata/within/source gate 1633 pass, no replay');
foreach([['Native-C227i-G304','unreal-v227i'],['Native-227k','unreal-v227k']] as [$folder,$profile]){
 $enginePath=$d.$folder.'/System/Engine.u';$pkg=new P($enginePath);$cls=[];
 foreach($pkg->getExports() as $index=>$ex)if($ex['classIndex']===0&&in_array($ex['objectNameText'],['Engine','SecurityData','Actor','GameInfo'],true))$cls[$ex['objectNameText']]=C::inspect($enginePath,$pkg,$index,false,$profile);
 $ck(count($cls)===4,'actual source selected original real Engine native class metadata four classes '.$folder);
 foreach(['Engine'=>['CacheSizeMegs'=>'IntProperty','UseSound'=>'BoolProperty'],
           'SecurityData'=>['MaxIPLogLen'=>'IntProperty']] as $class=>$fields){
  $c=$cls[$class]??[];
  $ck(($c['source_file_archive_complete']??false)===true,'actual original stock class serialized fully '.$folder.' '.$class);
  $byName=[];foreach($c['default_property_tags']??[] as $t)$byName[$t['name']]=$t;
  foreach($fields as $field=>$type){
   $ck(($byName[$field]['type']??null)===$type,'exact source build native default field kind '.$folder.' '.$class.'.'.$field);
   $value=$field==='UseSound'?true:($field==='CacheSizeMegs'?8:5000);
   $ck(($byName[$field]['value']??null)===$value,'exact native and current source class default VALUE '.$folder.' '.$class.'.'.$field);
  }
  $ck(($c['class_config_name']['text']??null)===($class==='SecurityData'?'Security':'System'),'source profile class config name/Within '.$folder.' '.$class);
  $ck(($c['class_within_reference']??null)===-3,'native original loaded class within UObject importer '.$folder.' '.$class);
 }
 $f=$d.$folder.'/System/G3Bulk227.u';$pp=new P($f);$schema=C::inspect($f,$pp,0,false,$profile);$by=[];
 foreach($schema['default_property_tags']??[] as $tag)$by[$tag['name']]=$tag;
 foreach([['BigNeg','IntProperty',-2147483647],['MaxByte','ByteProperty',255],['NegFloat','FloatProperty',-1.5],['bTrueValue','BoolProperty',true]] as [$name,$kind,$value]){
  $ck(($by[$name]['type']??null)===$kind,'original 2012 UCC produced class has class default tag kind '.$folder.' '.$name);
  $ck(($by[$name]['value']??null)===$value,'actual original C227 build loaded value equals archive scalar '.$folder.' '.$name);
 }
 $ck(($schema['source_file_archive_complete']??false)===true,'actual 2012 genuine compiled class archive whole native class consumed '.$folder);
}
$out=['ok'=>$errors===[],'checks'=>$n,'errors'=>$errors,'frozen_T018'=>$errors===[]?'PASS':'BLOCKED',
 'original_2012_stock_real_Engine_Classes_exported_successfully_before_exporter_crash'=>2,
 'original_2012_native_custom_actor_loaded_nonzero_defaults'=>9,
 'original_2026_native_custom_actor_loaded_nonzero_defaults'=>9,
 'original_2026_stock_Engine_CDOs_reused'=>6,'source_family_overlap_v69_not_equated'=>true,
 'native_CDO_exporter_omitted_vs_explicit_zero_values_not_conflated_with_runtime_memory'=>true,
 'original_compiled_native_engine_rerun_during_verifier'=>0,'G3_full_acceptance'=>false];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
