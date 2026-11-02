<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-03-C98-MAY1998-100-ORIGINAL-NATIVE-96-SCRIPTS.json'),true,512,JSON_THROW_ON_ERROR);
$path=$m['genuine_original_1998_shipped_DLL_packages_executables_SHA256']['Core.u']['path'];
$register=$m['true_actual_original_compiled_CDB_stdout_scripts_parser_and_register_SHA256']
 ['original_compiled_100_true_UStruct_96_Script_register'];
$j=json_decode((string)file_get_contents($repo.'/'.$register['repo_path']),true,512,JSON_THROW_ON_ERROR);
$pkg=new P($path);$exp=$pkg->getExports();
$errors=[];$counts=['rows'=>0,'script'=>0,'known'=>0,'unknown'=>0,'mismatch'=>0,'incomplete'=>0,'shorter'=>0];$status=[];
$checks=0;
$check=static function(bool $good,string $name)use(&$checks,&$errors):void{
 $checks++;if(!$good&&count($errors)<34)$errors[]=$name;
};
$bad=static function(string $s)use(&$checks,&$errors):void{
 $checks++;if(count($errors)<34)$errors[]=$s;
};
$check($m['G3_03_FULL']==='OPEN'&&$m['G3_12_full']==='OPEN'&&$m['G3_15_full']==='OPEN',
 'G3-03 subset not falsely full accepted');
$check($m['older_C227i_227k_T99_cases_not_inferred_or_repeated']===true,
 'original May 17 retail C98 is not C227 or UT99');
$check($m['original_v1200_May19_1998_source_UnClass_cpp_pin']['NOT_claimed_identical_to_May17_retail_binary']===true,
 'do not claim May17 compiled identical to May19 source snapshot');
foreach($m['genuine_original_1998_shipped_DLL_packages_executables_SHA256'] as $name=>$file){
 $check(is_file($file['path'])&&filesize($file['path'])===$file['bytes']
   &&hash_file('sha256',$file['path'])===$file['sha256'],
   'genuine retail May17 original shipped '.$name.' exact binary SHA');
}
foreach($m['true_actual_original_compiled_CDB_stdout_scripts_parser_and_register_SHA256'] as $name=>$file){
 $pathToEvidence=$repo.'/'.$file['repo_path'];
 $check(is_file($pathToEvidence)&&filesize($pathToEvidence)===$file['bytes']
   &&hash_file('sha256',$pathToEvidence)===$file['sha256'],
   'original actual retail compiled native debugger/source register '.$name.' SHA');
}
$sourcePin=$m['original_v1200_May19_1998_source_UnClass_cpp_pin'];
$check(is_file($sourcePin['path'])&&filesize($sourcePin['path'])===$sourcePin['bytes']
  &&hash_file('sha256',$sourcePin['path'])===$sourcePin['sha256'],
  'actual original licensed v1.200 May19 source hash, no May17 identical claim');
foreach($m['original_1998_reader_source_SHA'] as $name=>$file){
 $check(hash_file('sha256',$repo.'/'.$file['repo_path'])===$file['sha256'],
   'source parser implementation exact SHA '.$name);
}
$check($pkg->validatePackage()===[],'original genuine 1998 US Core.u validates independently');
$check(count($j['native_records'])===100,'exact independent original compiled 100 UStruct captures');
$check(($j['original_May1998_native_nonzero_script_count']??null)===96,
 'compiled original actual loaded script96 count');
$check(($j['original_May1998_native_max_script_length']??null)===15,
 'compiled original loaded ScriptSize maximum 15');
$check(($j['original_May1998_native_children_ptr_nonzero']??null)===96,
 'compiled original Children original loaded pointer presence96');
$check($m['observed_authentic_original_compiled_May17_1998_corpus']['native_children_target_index_dereferenced']===false,
 'do not fabricate C98 actual dereferenced Child pointer identity');
$seen=[];
$actualNativeLinker=$j['original_compiled_source_linker'];
foreach($j['native_records'] as $k=>$nat){

 $idx=(int)$k;
 $check(!isset($seen[$idx]),'original compiled May1998 real export index unique '.$idx);
 $seen[$idx]=true;
 $check($nat['native_original_Core_u_export_zero_based']===$idx,
   'true native WinDBG UStruct index matches genuine original Core.u '.$idx);
 $check($nat['original_native_linker']===$actualNativeLinker,
   'same original C98 genuine source linker for real loaded UStruct '.$idx);
 $check($nat['original_native_friendly_global_FName_index']!==0,
   'real original native C98 FriendlyName process index nonzero '.$idx);
 $source=$exp[$idx]??null;
 if(!$source){$bad($idx.' export missing');continue;}
 try{
  $record=(int)$source['classIndex']===0?C::inspect($path,$pkg,$idx,false,'epic-ue1'):
   N::inspect($path,$pkg,$idx,T::inspect($path,$pkg,$idx,'epic-ue1'),'epic-ue1');
 }catch(Throwable $e){$bad($idx.' PHP failed '.$e->getMessage());continue;}
 $counts['rows']++;
 $status[$record['status']??'none']=($status[$record['status']??'none']??0)+1;
 foreach(['script_size'=>'original_native_script_size','source_line'=>'original_native_line','source_text_pos'=>'original_native_textpos'] as $field=>$name){
  $expect=$nat[$name];if($expect>=0x80000000)$expect-=0x100000000;
  $check(($record[$field]??null)===$expect,
   $idx.' original native '.$field.' saved='.json_encode($record[$field]??null).' native='.$expect);
 }
 $check((bool)($record['script_text_reference']??0)===(bool)$nat['original_native_scripttext_ptr'],
  $idx.' native original ScriptText nullness');
 $check((bool)($record['children_reference']??0)===(bool)$nat['original_native_children_ptr'],
  $idx.' original native Children ptr nullness (not target identity)');
 if($nat['original_native_script_size']>0){
  $counts['script']++;
  $v=$record['script_archive']??null;
  if(!is_array($v)){$bad($idx.' no decoder script');continue;}
  if($v['status']!=='script_archive_expressions_decoded')$counts['incomplete']++;
  $check($v['status']==='script_archive_expressions_decoded',
   $idx.' actual original native fully decoded script opcode corpus');
  $check($v['source_memory_full_byte_reconstruction']===true,
   $idx.' original x86 script source memory reconstructed');
  $check($v['script_memory_decoded']===$nat['original_native_script_size'],
   $idx.' actual original compiled source Script.Num memory count');
  if($v['archive_bytes_consumed']<$nat['original_native_script_size'])$counts['shorter']++;
  $real=hex2bin($nat['original_compiled_real_script_memory_bytes_hex']);$want=hex2bin($v['source_memory_bytes_hex']??'');$mask=hex2bin($v['source_memory_known_mask_hex']??'');
  $n=$nat['original_native_script_size'];
  $correctSizes=is_string($real)&&is_string($want)&&is_string($mask)
   &&strlen($real)===$n&&strlen($want)===$n&&strlen($mask)===$n;
  $check($correctSizes,$idx.' real native/PHP exact script length and known-byte mask');
  if(!$correctSizes)continue;
  for($i=0;$i<$n;$i++){
   if(ord($mask[$i])===1){
    $counts['known']++;
    if($real[$i]!==$want[$i])$counts['mismatch']++;
    $check($real[$i]===$want[$i],
      $idx.' original retail compiled script byte at '.$i
        .' actual='.bin2hex($real[$i]).' PHP='.bin2hex($want[$i]));
   }else{$counts['unknown']++;}
  }
 }
}
$check(count($seen)===100,'exact 100 independently original compiled real loaded UStruct exports');
$check($counts['rows']===100,'all original C98 structs decoded without exceptions');
$check($counts['script']===96,'real original May17 source loaded 96 nonzero scripts');
$check($counts['known']===175,'exact 175 deterministic original compiled C98 bytecode values');
$check($counts['unknown']===668,'process pointer/name relocated byte values explicitly masked, not invented');
$check($counts['mismatch']===0,'no known original real loaded native script-byte mismatch');
$check($counts['shorter']===94,'94 source compact archived Script counts shorter than loaded x86 buffer');
$check($counts['incomplete']===0,'original May17 all 96 script opcode sequences fully decoded');
$check(($status['native_function_record_complete']??0)===100,'100 native UFunction source class subset, not all UStruct variants');
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
 'errors'=>$errors,'C98_original_May17_coreu_native_UStructs'=>$counts['rows'],
 'original_native_nonzero_scripts'=>$counts['script'],
 'original_retail_native_known_bytes_matched'=>$counts['known'],
 'source_pointer_fname_relocation_bytes_deliberately_masked'=>$counts['unknown'],
 'archive_script_compact_shorter_than_loaded_memory'=>$counts['shorter'],
 'original_source_profile'=>'C98_May17_retail_only',
 'G3_03_FULL'=>'OPEN','previous_native_ACCEPTED_tests_rerun'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
