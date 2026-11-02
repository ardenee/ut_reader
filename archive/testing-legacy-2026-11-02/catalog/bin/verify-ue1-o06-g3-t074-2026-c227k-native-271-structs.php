<?php
declare(strict_types=1);
$cat=dirname(__DIR__);
require_once $cat.'/bootstrap/autoload.php';
require_once $cat.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as Classes;
$repo=dirname(__DIR__,2);
$proof=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-T074-C227k-271-UStruct-two-UClass-pointer-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$file=$proof['authentic_runtime']['Core.u']['path'];
$log=$repo.'/'.$proof['evidence']['struct_native_load_trace']['path'];
$pointerLog=$repo.'/'.$proof['evidence']['two_loaded_scripttext_target_trace']['path'];
$pkg=new Package($file);$exports=$pkg->getExports();$trace=(string)file_get_contents($log);
$errors=[];$count=0;$matched=0;$nonzero=0;$classTextTargets=0;$seen=[];$linker=null;

foreach(['authentic_runtime','source_headers','current_reader','evidence'] as $group){
 foreach($proof[$group] as $key=>$pin){
   $disk=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
   if(!is_file($disk)||filesize($disk)!==$pin['bytes']||hash_file('sha256',$disk)!==$pin['sha256']){
     $errors[]='Original source/native evidence SHA mismatch: '.$group.'/'.$key;
   }
 }
}
if($proof['frozen_owner']!=='T074'||$proof['source_profile']!=='unreal-v227k'
   ||$proof['complete_T074']!==false)$errors[]='Frozen T074 exact source scope invalid';
$method=(string)file_get_contents($repo.'/'.$proof['evidence']['compiled_UStruct_method_disassembly']['path']);
if(!str_contains($method,'Core!UStruct::Serialize:')||
 !str_contains($method,'8d4f3c')||!str_contains($method,'8d4f40')||
 !str_contains($method,'8d4f50')||!str_contains($method,'Core!UStruct::Serialize+0x24c')){
  $errors[]='Original 2026 compiled UStruct member source and postload method not pinned';
}

$pointerRecords=[];
preg_match_all('/^G3T075_SCRIPTTEXT_RUNTIME source_export=(\d+) source_linker=([0-9a-f]{8}) ptr=([0-9a-f]{8}) target_linker=([0-9a-f]{8}) target_export=(\d+)$/mi',
    str_replace([chr(13).chr(10),chr(13)],chr(10),(string)file_get_contents($pointerLog)),$proofRows,PREG_SET_ORDER);
foreach($proofRows as $native){
    $idx=(int)$native[1];
    if(isset($pointerRecords[$idx]))$errors[]='duplicate real original native ScriptText pointer '.$idx;
    $pointerRecords[$idx]=['file_ref'=>(int)$native[5]+1,
        'original_linker_same'=>strcasecmp($native[2],$native[4])===0,
        'pointer_nonzero'=>hexdec($native[3])>0];
}
if(count($pointerRecords)!==2 || array_keys($pointerRecords)!==[2,7])$errors[]='expected exactly two separately original compiled 227k UClass ScriptText pointer targets';
$rows=[];
foreach(preg_split('/\r\n|\r|\n/',$trace) as $line){
 if(preg_match('/^G3T074_STRUCT idx=(\d+) linker=([0-9a-f]{8}) scriptnum=(\d+) line=(-?\d+) textpos=(-?\d+) scripttext=([0-9a-f]{8}) children=([0-9a-f]{8})$/i',$line,$m)){
  $rows[]=['id'=>(int)$m[1],'linker'=>$m[2],'size'=>(int)$m[3],
   'line'=>(int)$m[4],'textpos'=>(int)$m[5],'scripttext'=>hexdec($m[6]),'children'=>hexdec($m[7])];
 }elseif(preg_match('/^G3T074_CHILD idx=(\d+) linker=([0-9a-f]{8})$/i',$line,$m)){
  if($rows!==[])$rows[count($rows)-1]['target']=['idx'=>(int)$m[1],'linker'=>$m[2]];
 }
}
foreach($rows as $row){
 $id=$row['id'];$count++;$linker??=$row['linker'];
 if(isset($seen[$id]))$errors[]="repeat export $id";$seen[$id]=true;
 if($row['linker']!==$linker)$errors[]="linker mismatch $id";
 $exp=$exports[$id]??null;
 if(!is_array($exp)){$errors[]="missing export $id";continue;}
 $record=((int)$exp['classIndex']===0)
   ?Classes::inspect($file,$pkg,$id,false,'unreal-v227k')
   :Native::inspect($file,$pkg,$id,Tags::inspect($file,$pkg,$id,'unreal-v227k'),'unreal-v227k');
 $child=$row['children']===0?0:(($row['target']['idx']??-200)+1);
 if($row['children']!==0){
  $nonzero++;
  if(($row['target']['linker']??null)!==$linker||!isset($exports[$child-1]))$errors[]="bad target linker/index $id";
 }
 $expected=['children_reference'=>$child,'script_size'=>$row['size'],
  'source_line'=>$row['textpos'],'source_text_pos'=>$row['line'],
  'script_text_reference'=>$row['scripttext']===0?0:null];
 // Original compiled UStruct returns a real UObject*; do NOT confuse it
 // with on-disk signed compact reference. A second native CDB breakpoint
 // dereferenced the two nonnull original UClass ScriptText targets in-process.
 if((int)$exp['classIndex']===0 && in_array($id,[2,7],true)){
    $proof=$pointerRecords[$id]??null;
    if($row['scripttext']===0 || !is_array($proof)
      || !$proof['original_linker_same'] || !$proof['pointer_nonzero']
      || !is_int($record['script_text_reference']??null)
      || $record['script_text_reference']!==$proof['file_ref']){
        $errors[]='original C227k linked ScriptText pointer mismatch '.$id;
    }else{$classTextTargets++;}
    $expected['script_text_reference']=$proof['file_ref']??null;
 }
 $bad=[];
 foreach($expected as $key=>$value){
  if(($record[$key]??null)!==$value)$bad[$key]=[$record[$key]??null,$value];
 }
 if($bad===[])$matched++;
 elseif(count($errors)<35)$errors[]=['idx'=>$id,'name'=>$exp['objectNameText'],'expectedActual'=>$bad,'status'=>$record['status']??null];
}
if(count($rows)!==271)$errors[]="rows not 271: ".count($rows);
echo json_encode(['frozen_owner'=>'T074','source_profile'=>'unreal-v227k','full_T074_acceptance'=>false,'ok'=>$errors===[],'rows'=>$count,'matched'=>$matched,'children_nonzero'=>$nonzero,'UClass_native_linked_scripttext_refs_matched'=>$classTextTargets,
 'distinct'=>count($seen),'error_count'=>count($errors),'errors'=>array_slice($errors,0,30),
 'original_package_exports'=>count($exports)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($errors===[]?0:1);
