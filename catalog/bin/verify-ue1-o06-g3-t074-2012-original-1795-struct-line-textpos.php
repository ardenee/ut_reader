<?php
/** Focus G3 T074/T075 new original 2012 C227i UStruct compiler source Line/TextPos/ScriptNum, NO rechecking sealed pointers. */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
require_once $repo.'/catalog/bootstrap/autoload.php';
require_once $repo.'/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$source='unreal-v227i';
$evidence=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T074-2012-C227i-1795-UStruct-Line-TextPos-native-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($evidence['pins'] as $name=>$pin){
    $p=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
    $sha=is_file($p)?hash_file('sha256',$p):null;
    $size=is_file($p)?filesize($p):-1;
    $original=$sha===$pin['sha256']&&$size===$pin['bytes'];
    $canonical=isset($pin['canonical_git_blob_sha256'])
      &&$sha===$pin['canonical_git_blob_sha256']
      &&$size===$pin['canonical_git_blob_bytes'];
    if(!$original && !$canonical){
        throw new RuntimeException('Original C227i native/source input SHA pin changed: '.$name);
    }
}
$raw=(string)file_get_contents($repo.'/'.$evidence['pins']['correct_original_2012_native_line_textpos_log']['path']);
$data=[];$counts=[];
foreach(preg_split('/\r\n|\n|\r/',$raw) as $line){
 if(!preg_match('/^T074_2012_REAL_LINE_TEXTPOS linker=([0-9a-f]{8}) idx=(\d+) sourceLine=(-?\d+) textPos=(-?\d+) scriptNum=(\d+)$/i',$line,$m))continue;
 $data[$m[1]][]=['idx'=>(int)$m[2],'line'=>(int)$m[3],'textpos'=>(int)$m[4],'scriptNum'=>(int)$m[5]];
}
$errors=[];$checks=0;$matched=0;$samples=[];$details=[];$i=0;
foreach(['Core.u'=>236,'Engine.u'=>1559] as $fname=>$expected){
 $f=$evidence['pins'][$fname==='Core.u'?'original_2012_Core_u':'original_2012_Engine_u']['path'];
 $p=new P($f);$exps=$p->getExports();$l=null;
 foreach($data as $key=>$rows){if(count($rows)===$expected)$l=$key;}
 if($l===null){$errors[]="$fname linker missing";continue;}
 $seen=[];$matches=0;$nonzero=0;$fixed=[];$byclass=[];$archiveCounts=[];
 foreach($data[$l] as $one){
  $id=$one['idx'];$checks++;
  if(isset($seen[$id])){$errors[]="$fname duplicate $id";continue;}
  $seen[$id]=true;
  $exp=$exps[$id]??null;
  if(!is_array($exp)){$errors[]="$fname no export $id";continue;}
  try {
   $obj=(int)$exp['classIndex']===0
    ? C::inspect($f,$p,$id,false,$source)
    : N::inspect($f,$p,$id,T::inspect($f,$p,$id,$source),$source);
  }catch(Throwable $e){$errors[]="$fname object $id ".$e->getMessage();continue;}
  $kind=(int)$exp['classIndex']===0?'Class':($exp['objectNameText']??'unknown');
  $byclass[$kind]=($byclass[$kind]??0)+1;
  foreach(['source_line'=>'line','source_text_pos'=>'textpos','script_size'=>'scriptNum'] as $field=>$native){
   $checks++;
   $good=($obj[$field]??null)===$one[$native];
   if(!$good){
    if(count($errors)<35)$errors[]="$fname object $id $field native=".$one[$native]." archived=".json_encode($obj[$field]??null);
    if(count($samples)<18)$samples[]=['package'=>$fname,'idx'=>$id,'name'=>$exp['objectNameText']??null,'field'=>$field,'native'=>$one[$native],'reader'=>$obj[$field]??null];
   }else{$matches++;}
  }
  if($one['line']!==0 && $one['line']!==-1)$nonzero++;
  $archiveCounts[$one['scriptNum']]=($archiveCounts[$one['scriptNum']]??0)+1;
 }
 $details[$fname]=['linker'=>$l,'records'=>count($data[$l]),'unique'=>count($seen),
  'matched_field_members'=>$matches,'nonzero_nonnegative_source_lines'=>$nonzero,
  'script_sizes_top_10'=>array_slice($archiveCounts,0,10,true)];
 $matched+=$matches;
 $checks++;
 if(count($seen)!==$expected)$errors[]="$fname record mismatch ".count($seen)."/$expected";
}
$checks++;
if(count($data)!==2 || array_sum(array_map('count',$data))!==1795)$errors[]='unexpected original native distinct linker events';
echo json_encode(['ok'=>count($errors)===0,'original_compiled_profile'=>'unreal-v227i 2012',
 'new_methods_only'=>['UStruct.SourceLine','UStruct.TextPos','UStruct.Script.Num'],
 'details'=>$details,'checks'=>$checks,'matched_saved_vs_loaded_field_values'=>$matched,
 'examples_mismatch'=>$samples,'errors'=>$errors,'full_T074_T075_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!$errors?0:1);
