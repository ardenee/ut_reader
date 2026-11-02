<?php
/**
 * New original compiled 2012 C227i G3-03 UStruct POST-LOAD and Script.TArray.
 * This gate runs ZERO previous C227i 915-UField or other accepted scripts.
 * It compares the exact 237 original x86 Core.dll-loaded structs and
 * 223 actual compiled source-native script memory buffers against PHP,
 * masking unknown FName/UObject process pointer relocation bytes.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$manifest=json_decode(
 (string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-03-C227I-237-ORIGINAL-NATIVE-223-SCRIPTS.json'),
 true,512,JSON_THROW_ON_ERROR);
$count=0;$errors=[];
$ck=static function(bool $good,string $description) use (&$count,&$errors):void{
 $count++;
 if(!$good&&count($errors)<40)$errors[]=$description;
};
$ck($manifest['G3_03_full_status']==='OPEN'&&$manifest['old_native_tests_reexecuted']===0,
 'cannot promote G3-03 or rerun old native tests');
foreach($manifest['native_original_shipped_binary_inputs'] as $name=>$record){
 $file=$record['path'];
 $ck(is_file($file)&&filesize($file)===$record['bytes']
   &&hash_file('sha256',$file)===$record['sha256'],
   'Original shipped source immutable SHA '.$name);
}
foreach($manifest['original_native_raw_logs_and_commands_SHA256'] as $name=>$record){
 $file=$repo.'/'.$record['repo_path'];
 $ck(is_file($file)&&filesize($file)===$record['bytes']
   &&hash_file('sha256',$file)===$record['sha256'],
   'actual x86 original native CDB/structured witness SHA '.$name);
}
foreach($manifest['current_production_reader_SHA'] as $name=>$record){
 $file=$repo.'/'.$record['repo_path'];
 $ck(is_file($file)&&hash_file('sha256',$file)===$record['sha256'],
   'current exact source implementation SHA '.$name);
}
$datasetPin=$manifest['original_native_raw_logs_and_commands_SHA256']['original_compiled_native_CDB_237_223_parsed_register'];
$dataset=json_decode((string)file_get_contents($repo.'/'.$datasetPin['repo_path']),
 true,512,JSON_THROW_ON_ERROR);
$metrics=$dataset['native_original_2012_C227i'];
foreach(['original_native_unique_UStruct_loaded'=>237,
 'original_native_script_buffers'=>223,'original_native_zero_scripts'=>14,
 'original_native_max_scriptSize'=>36,
 'actual_original_children_ptr_nonzero'=>232,
 'original_native_script_sizes_count_greater_zero'=>223] as $name=>$value){
 $ck($metrics[$name]===$value,'actual original genuine x86 native source metric '.$name);
}
$source=$manifest['native_original_shipped_binary_inputs']['original_2012_C227i_Core_u']['path'];
$p=new P($source);
$ck($p->validatePackage()===[],'genuine original 2012 227i Core.u archive valid');
$ck(count($p->getExports())===934,'original C227i genuine export count 934');
$ck(($p->getHeader()['version']??null)===69,'original C227i version69 source archive');
$headers=$dataset['native_structs'];
$nativeScripts=$dataset['native_script_first36_hex'];
$ck(count($headers)===237,'exact genuine compiled source UStruct count');
$ck(count($nativeScripts)===223,'exact genuine compiled x86 script buffers count');
$seen=[];$scripts=0;$knownBytes=0;$comparedBytes=0;$children=0;
$kinds=[];$classes=0;$maxScriptSize=0;$savedSmaller=0;
foreach($headers as $i=>$native){
 $id=(int)$i;
 $ck(!isset($seen[$id]),'genuine native object export unique '.$id);
 $seen[$id]=true;
 $exp=$p->getExports()[$id]??null;
 $ck(is_array($exp),'original native id exists in actual Core.u '.$id);
 if(!is_array($exp))continue;
 $data=null;
 try{
  if((int)$exp['classIndex']===0){
   $data=C::inspect($source,$p,$id,false,'unreal-v227');$classes++;
  }else{
   $data=N::inspect($source,$p,$id,
     T::inspect($source,$p,$id,'unreal-v227'),'unreal-v227');
  }
 }catch(Throwable $e){
  $ck(false,'original source native reader throws on genuine export '.$id.': '.$e->getMessage());
  continue;
 }
 $ck(($native['original_native_index_zero_based']??-1)===$id,
   'original native CDB export identity same as core package '.$id);
 $ck($native['original_native_linker']===$dataset['original_compiled_source_linker'],
   'authentic original compiled all 237 structurally loaded from one linker '.$id);
 $ck($native['native_friendly_global_name_id']!==0,
   'original runtime UStruct FriendlyName not None '.$id);
 $ck((bool)($data['script_text_reference']??0)===(bool)$native['native_scripttext_ptr'],
   'real original native vs UnrealDB ScriptText nullness '.$id);
 $ck(($data['script_size']??null)===$native['native_script_size'],
   'real x86 original Script.Num vs saved UnrealDB source ScriptSize '.$id);
 $maxScriptSize=max($maxScriptSize,$native['native_script_size']);
 foreach(['source_line'=>'native_line','source_text_pos'=>'native_textpos'] as $field=>$key){
  $want=$native[$key];
  if($want>=0x80000000)$want-=0x100000000;
  $ck(($data[$field]??null)===$want,
    'original x86 compiled C227i signed '.$field.' '.$id);
 }
 $childTarget=$native['native_children_linked_target'];
 $expectedRef=0;
 if(is_array($childTarget)){
  $children++;
  $ck($childTarget['linker']===$native['original_native_linker'],
   'actual original Children deref pointer on same original Core.u linker '.$id);
  $expectedRef=$childTarget['zero_based_export_index']+1;
 }
 $ck(($data['children_reference']??null)===$expectedRef,
  'native original Children loaded actual linker export == UnrealDB saved reference '.$id);
 $kind=(string)($data['status']??'none');
 $kinds[$kind]=($kinds[$kind]??0)+1;
 if($native['native_script_size']===0){
  $ck(!isset($nativeScripts[$i]),'native zero-ScriptSize has no original script dump '.$id);
  continue;
 }
 $scripts++;
 $record=$data['script_archive']??null;
 if(!is_array($record)){
  $ck(false,'native original nonzero script missing PHP script archive '.$id);continue;
 }
 $ck(($record['status']??null)==='script_archive_expressions_decoded',
   'actual original 227i compiled script tokens decoded '.$id);
 $ck(($record['script_memory_decoded']??null)===$native['native_script_size'],
   'script in-memory decoded bytecount exact original compiled TArray '.$id);
 $ck(($record['source_memory_full_byte_reconstruction']??false)===true,
   'entire decoded script accounted for without guessing pointer addresses '.$id);
 $ck(is_int($record['archive_bytes_consumed']??null)
   &&$record['archive_bytes_consumed']>0
   &&$record['archive_bytes_consumed']<=$native['native_script_size'],
   'original compiled script byte archive footprint bounded '.$id);
 if($record['archive_bytes_consumed']<$native['native_script_size'])$savedSmaller++;
 $nativeData=hex2bin($nativeScripts[$i]??'');
 $predicted=hex2bin($record['source_memory_bytes_hex']??'');
 $knownMask=hex2bin($record['source_memory_known_mask_hex']??'');
 $size=$native['native_script_size'];
 $good=is_string($nativeData)&&strlen($nativeData)>=$size
   &&is_string($predicted)&&strlen($predicted)===$size
   &&is_string($knownMask)&&strlen($knownMask)===$size;
 $ck($good,'native/PHP script buffers lengths and masks agree '.$id);
 if(!$good)continue;
 for($j=0;$j<$size;$j++){
  if(ord($knownMask[$j])!==1)continue; // original native pointer/FName relocation process-variant.
  $knownBytes++;
  $ck($nativeData[$j]===$predicted[$j],
   'REAL original native compiled bytecode byte '.$id.':'.$j);
  $comparedBytes++;
 }
}
$ck(count($seen)===237,'all genuine original 237 unique compiled UField-derived structs certified');
$ck($scripts===223,'actual source 223 nonzero original compiled script method paths');
$ck($children===232,'actual source 232 original resolved compiled native Child pointers');
$ck($classes===2,'authentic two original Class exports handled via dedicated source class reader');
$ck(($kinds['native_struct_record_complete']??0)===12,'native original 12 direct UStruct exports');
$ck(($kinds['native_function_record_complete']??0)===223,'native original 223 UFunction nonzero script exports');
$ck($maxScriptSize===36,'native original script memory limit');
$ck($knownBytes===646,'exact 646 independently original loaded script bytes not unresolved relocations');
$ck($savedSmaller>0,'script memory length differs from serialized archive size on original compiled callbacks');
$good=$errors===[];
echo json_encode(['ok'=>$good,'checks'=>$count,'passed'=>$count-count($errors),
 'failed'=>$errors,'actual_original_native_2012_loaded_UStruct_objects'=>count($seen),
 'actual_original_native_C227i_script_buffers'=>$scripts,
 'original_compiled_native_known_script_bytes_matched'=>$comparedBytes,
 'actual_native_source_children_targets'=>$children,
 'source_loader_script_memory_vs_archive_shorter_count'=>$savedSmaller,
 'original_profiles_certified'=>'2012 C227i Core.u subset only',
 'G3_03_FULL'=>'OPEN','previous_accepted_native_tests_rerun'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($good?0:1);
