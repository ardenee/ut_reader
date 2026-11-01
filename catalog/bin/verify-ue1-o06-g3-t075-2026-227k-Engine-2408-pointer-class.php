<?php
/** True 2026 original compiled 227k Engine.u CDB -> archived same-linker 2408 UStruct and 187 UClass object refs. */
declare(strict_types=1);
$cat='C:/Users/arden/source/repos/ut_reader/catalog';
require_once $cat.'/bootstrap/autoload.php';
require_once $cat.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T074-T075-original-227k-Engine-8724-fields-2408-pointer-187-class-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$file=$manifest['pins']['authentic_2026_original_Engine_package']['path'];
$log=(string)file_get_contents($repo.'/'.$manifest['pins']['original_2026_engine_pointer_class_loader_trace']['path']);
$pinChecks=0;
foreach(['authentic_2026_original_Core_DLL','authentic_2026_original_Engine_DLL','authentic_2026_original_Engine_package','authentic_2026_original_commandlet',
'original_2026_engine_property_struct_loader_trace','original_2026_engine_pointer_class_loader_trace',
'original_2026_UnClass_header','original_2026_UnObjBas_header'] as $pinName){
 $pin=$manifest['pins'][$pinName];
 $disk=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
 if(!is_file($disk)||filesize($disk)!==$pin['bytes']||hash_file('sha256',$disk)!==$pin['sha256']){
  throw new RuntimeException('Original compiled UE1 source/native SHA pin changed: '.$pinName);
 }
 ++$pinChecks;
}
$p=new P($file);$exports=$p->getExports();$imports=$p->getImports();
$st=[];$cl=[];
foreach(preg_split('/\r\n|\r|\n/',$log) as $line){
 if(preg_match('/^T075_ENGINE_STRUCT idx=(\d+) linker=([0-9a-f]{8}) child=([0-9a-f]{8}) scripttext=([0-9a-f]{8})$/i',$line,$m)){
  $st[]=['idx'=>(int)$m[1],'linker'=>$m[2],'child'=>hexdec($m[3]),'script'=>hexdec($m[4])];continue;
 }
 if(preg_match('/^T075_ENGINE_(CHILD_TARGET|SCRIPTTEXT_TARGET) idx=(\d+) linker=([0-9a-f]{8})$/i',$line,$m)){
   $n=count($st)-1;if($n>=0)$st[$n][($m[1]==='CHILD_TARGET'?'child_target':'script_target')]=['idx'=>(int)$m[2],'linker'=>$m[3]];
   continue;
 }
 if(preg_match('/^T074_ENGINE_UCLASS idx=(\d+) linker=([0-9a-f]{8}) flags=([0-9a-f]{8}) within=([0-9a-f]{8}) config=([0-9a-f]{8})$/i',$line,$m)){
  $cl[]=['idx'=>(int)$m[1],'linker'=>$m[2],'flags'=>hexdec($m[3]),'within'=>hexdec($m[4]),'config'=>hexdec($m[5])];continue;
 }
 if(preg_match('/^T074_ENGINE_WITHIN_TARGET idx=(\d+) linker=([0-9a-f]{8})$/i',$line,$m)){
  $n=count($cl)-1;if($n>=0)$cl[$n]['within_target']=['idx'=>(int)$m[1],'linker'=>$m[2]];
  continue;
 }
}
$hist=[];foreach($st as $r)$hist[$r['linker']]=($hist[$r['linker']]??0)+1;
$engineLinker=null;foreach($hist as $k=>$v)if($v===2408)$engineLinker=$k;
$checkCount=0;$err=[];$bad=[];$matched=['struct_refs'=>0,'class_flags'=>0,'within_identity'=>0];$childN=0;$scriptN=0;
$ck=static function(bool $ok,string $label)use(&$checkCount,&$err):void{++$checkCount;if(!$ok&&count($err)<35)$err[]=$label;};
$ck($engineLinker!==null&&count($exports)===9477,'genuine 2026 Engine.u linker full export scope');
$ck($p->validatePackage()===[],'SHA-unmodified original engine full source package valid');
$cache=[];$source='unreal-v227k';
$load=static function(int $id)use(&$cache,$exports,$file,$p,$source):array{
 if(isset($cache[$id]))return $cache[$id];
 $exp=$exports[$id]??null;if(!is_array($exp))throw new RuntimeException('not a genuine export');
 return $cache[$id]=((int)$exp['classIndex']===0
 ?C::inspect($file,$p,$id,false,$source)
 :N::inspect($file,$p,$id,T::inspect($file,$p,$id,$source),$source));
};
$seen=[];$crossChild=0;$crossScript=0;
foreach($st as $row){
 if($row['linker']!==$engineLinker)continue;
 $id=$row['idx'];
 $ck(!isset($seen[$id]),'original native UStruct unique idx '.$id);$seen[$id]=1;
 try{$x=$load($id);}catch(Throwable $e){if(count($err)<35)$err[]="UStruct $id: ".$e->getMessage();continue;}
 $okay=true;
 foreach(['child'=>'children_reference','script'=>'script_text_reference'] as $label=>$field){
  $pointer=$row[$label];$target=$row[$label.'_target']??null;$archived=$x[$field]??null;
  if($pointer===0){$ok=$target===null&&$archived===0;}
  else{
   if($label==='child')$childN++;else $scriptN++;
   $same=$target!==null&&$target['linker']===$engineLinker;
   if(!$same){if($label==='child')$crossChild++;else $crossScript++;}
   $ok=$target!==null&&is_int($archived)
     &&($same?($archived===$target['idx']+1):($archived<0))
     &&($same?isset($exports[$target['idx']]):true);
  }
  $ck($ok,'same-linker source native '.$field.' actual identity '.$id);
  if(!$ok)$okay=false;
 }
 if($okay)$matched['struct_refs']++;
 elseif(count($bad)<12)$bad[]=['method'=>'UStruct','idx'=>$id,'archive'=>[$x['children_reference']??null,$x['script_text_reference']??null],'native'=>$row];
}
$ck(count($seen)===2408,'all 2408 true original UStruct export identity records verified');
$classSeen=[];$withinImports=[];$classnameConf=[];
foreach($cl as $row){
 if($row['linker']!==$engineLinker)continue;
 $id=$row['idx'];$ck(!isset($classSeen[$id]),'class unique index '.$id);$classSeen[$id]=true;
 try{$x=$load($id);}catch(Throwable $e){if(count($err)<35)$err[]="UClass $id: ".$e->getMessage();continue;}
 $flags=($x['class_flags']??null)===$row['flags'];$ck($flags,'actual original UClass loaded flags '.$id);
 if($flags)$matched['class_flags']++;
 elseif(count($bad)<12)$bad[]=['method'=>'UClassFlags','idx'=>$id,'archive'=>$x['class_flags']??null,'native'=>$row['flags']];
 $within=$x['class_within_reference']??null;$target=$row['within_target']??null;
 $same=$target!==null&&$target['linker']===$engineLinker;
 $import=$within<0?($imports[-$within-1]??[]):[];
 $ok=$target!==null&&is_int($within)&&($same?$within===$target['idx']+1:
   ($within<0&&($import['objectNameText']??null)==='Object'&&$target['idx']===2));
 $ck($ok,'original native UClass within target from Core.Object or Engine local '.$id);
 if($ok)$matched['within_identity']++;
 elseif(count($bad)<12)$bad[]=['method'=>'UClassWithin','idx'=>$id,'source_ref'=>$within,'target'=>$target,'import'=>$import];
 $withinImports[$within]=($withinImports[$within]??0)+1;
 $classnameConf[$x['class_config_name']['text']??'??']=($classnameConf[$x['class_config_name']['text']??'??']??0)+1;
}
$ck(count($classSeen)===187,'187 unique real stock original UClass callbacks');
echo json_encode(['ok'=>$err===[],'checks'=>$checkCount,'errors'=>$err,'distinct_Engine_UStruct'=>count($seen),
 'distinct_Engine_UClass'=>count($classSeen),'same_linker_live_refs_matched'=>$matched,
 'native_nonzero_child_count'=>$childN,'native_nonzero_script_text_count'=>$scriptN,
 'cross_linker_child'=>$crossChild,'cross_linker_script_text'=>$crossScript,
 'class_within_source_ref_distribution'=>$withinImports,'class_config_source_name_distribution'=>$classnameConf,
 'sample_mismatches'=>$bad,'frozen_all_build_T075_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($err===[]?0:1);
