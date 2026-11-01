<?php
/** Distinct original 2012 C227i real Engine.u actual CDB linked reference targets; new only. */
declare(strict_types=1);
$cat=dirname(__DIR__);
require_once $cat.'/bootstrap/autoload.php';
require_once $cat.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T074-T075-original-2012-C227i-Engine-4268-1559-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$files=$manifest['pins'];
foreach($files as $label=>$pin){
  $disk=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
  if(!is_file($disk)||filesize($disk)!==$pin['bytes']
    ||hash_file('sha256',$disk)!==$pin['sha256']){
    throw new RuntimeException('2012 C227i original native/source SHA mismatch: '.$label);
  }
}
$file=$files['original_C227i_Engine_u']['path'];
$propLog=(string)file_get_contents($repo.'/'.$files['2012_loaded_property_and_struct_original_native_log']['path']);
$pointerLog=(string)file_get_contents($repo.'/'.$files['2012_loaded_struct_and_pointer_original_native_log']['path']);
$p=new P($file);$exp=$p->getExports();$h=$p->getHeader();
$checks=0;$err=[];$mismatch=[];$matchedProps=0;$matchedStruct=0;$nonnull=['child'=>0,'script'=>0];$seenP=[];$seenS=[];
$ck=static function(bool $good,string $key)use(&$checks,&$err):void{
 ++$checks; if(!$good&&count($err)<35)$err[]=$key;
};
$ck($p->validatePackage()===[],'original physical Engine.u truly valid');
$ck(count($exp)===6140&&$h['version']===69&&$h['licensee']===0,'2012 original true Engine.u profile v69 lic0 6140 exports');
$records=[];$linker=null;
foreach(preg_split('/\r\n|\r|\n/',$propLog) as $line){
 if(!preg_match('/^T074_2012_ENGINE_PROPERTY linker=([0-9a-f]{8}) idx=(\d+) dim=(-?\d+) flags=([0-9a-f]{8})$/i',$line,$m))continue;
 $records[]=['linker'=>$m[1],'idx'=>(int)$m[2],'dim'=>(int)$m[3],'flags'=>hexdec($m[4])];
}
$counts=[];foreach($records as $v)$counts[$v['linker']]=($counts[$v['linker']]??0)+1;
foreach($counts as $k=>$n)if($n===4268)$linker=$k;
$ck($linker!==null&&count($counts)===2,'original 2012 separate Core and Engine linkers');
$cache=[];$source='unreal-v227i';
$load=static function(int $id)use(&$cache,$p,$file,$exp,$source):array{
 if(isset($cache[$id]))return $cache[$id];
 $x=$exp[$id]??null;if(!is_array($x))throw new RuntimeException('not an original genuine exported UObject '.$id);
 return $cache[$id]=((int)$x['classIndex']===0
  ?C::inspect($file,$p,$id,false,$source)
  :N::inspect($file,$p,$id,T::inspect($file,$p,$id,$source),$source));
};
foreach($records as $x){
 if($x['linker']!==$linker)continue;
 $id=$x['idx'];
 $ck(!isset($seenP[$id]),'unique 2012 native original Engine property '.$id);$seenP[$id]=1;
 try{$rec=$load($id);}catch(Throwable $e){if(count($err)<35)$err[]=$e->getMessage();continue;}
 $ok=$rec['array_dim']===$x['dim']&&$rec['property_flags']===$x['flags'];
 $ck($ok,'2012 engine property actual saved dimensions and flags '.$id);
 if($ok)$matchedProps++;
}
$rows=[];
foreach(preg_split('/\r\n|\r|\n/',$pointerLog) as $line){
 if(preg_match('/^T075_2012_ENGINE_STRUCT linker=([0-9a-f]{8}) idx=(\d+) child=([0-9a-f]{8}) scripttext=([0-9a-f]{8}) line=(-?\d+) textpos=(-?\d+) scriptnum=(\d+)$/i',$line,$m)){
  $rows[]=['linker'=>$m[1],'idx'=>(int)$m[2],'child'=>hexdec($m[3]),'script'=>hexdec($m[4]),'scriptnum'=>(int)$m[7]];continue;
 }
 if(preg_match('/^T075_2012_(CHILD_TARGET|SCRIPT_TARGET) idx=(\d+) linker=([0-9a-f]{8})$/i',$line,$m)){
  $n=count($rows)-1;if($n>=0)$rows[$n][($m[1]==='CHILD_TARGET'?'child_target':'script_target')]=['id'=>(int)$m[2],'linker'=>$m[3]];
 }
}
foreach($rows as $row){
 if($row['linker']!==$linker)continue;
 $id=$row['idx'];
 $ck(!isset($seenS[$id]),'unique actual 2012 UStruct native object '.$id);$seenS[$id]=1;
 try{$rec=$load($id);}catch(Throwable $e){if(count($err)<35)$err[]=$e->getMessage();continue;}
 $ok=true;
 foreach(['child'=>'children_reference','script'=>'script_text_reference'] as $kind=>$field){
   $ptr=$row[$kind];$target=$row[$kind.'_target']??null;$stored=$rec[$field]??null;
   if($ptr===0){$good=$target===null&&$stored===0;}
   else{
     $nonnull[$kind]++;
     $good=$target!==null&&$target['linker']===$linker
       &&is_int($stored)&&$stored===$target['id']+1
       &&isset($exp[$target['id']]);
   }
   $ck($good,'2012 true linked original source actual '.$field.' '.$id);
   if(!$good){$ok=false;if(count($mismatch)<8)$mismatch[]=['id'=>$id,'field'=>$field,'actual'=>$row,'stored'=>$stored];}
 }
 if($ok)$matchedStruct++;
}
$ck($matchedProps===4268&&count($seenP)===4268,'all 4268 Engine properties exact native');
$ck($matchedStruct===1559&&count($seenS)===1559,'all 1559 Engine struct original pointer references exact');
$ck($nonnull['child']===1162&&$nonnull['script']===118,'all original nonnull source pointer target callbacks genuinely observed');
echo json_encode(['ok'=>$err===[],'checks'=>$checks,'property_2012_loaded_matched'=>$matchedProps,
 'struct_2012_exact_loaded_ref_identity_matched'=>$matchedStruct,'native_nonnull_ref_dereferences'=>$nonnull,
 'errors'=>$err,'original_differences'=>$mismatch,
 '2012_source_Line_TextPos_accepted'=>false,'full_T074_or_T075_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($err===[]?0:1);
