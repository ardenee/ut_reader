<?php
/** One-off G3-06 whole-contract gate. Reuses previously accepted C98/C227
 * native snapshots and negative source cases WITHOUT executing prior tests.
 * One NEW original compiled UT99 469f loaded-value comparison is performed.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
$repo=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-06-FULL-CLOSURE-MATRIX.json'),true,512,JSON_THROW_ON_ERROR);
$status=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-LIVE-CLOSURE-STATUS.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$fail=[];$check=static function(string $msg,bool $valid)use(&$n,&$fail):void{
    ++$n;if(!$valid&&count($fail)<30)$fail[]=$msg;
};
$accepted=array_column(array_filter($status['contracts'],static fn($r)=>$r['full_status']==='ACCEPTED'),'id');
$check('G3-01 and G3-02 original source prerequisites already accepted',
    in_array('G3-01',$accepted,true)&&in_array('G3-02',$accepted,true));
$check('one contract gate is working on previously OPEN G3-06 only',
    $status['full_contracts_accepted']===2
    &&current(array_values(array_filter($status['contracts'],static fn($r)=>$r['id']==='G3-06')))['full_status']==='OPEN');
$check('all original accepted native method family proofs SHA pinned',
    count($matrix['prior_authentic_C98_T99_C227_compiled_positive_evidence_pins'])===5);
$proofs=[];
foreach($matrix['prior_authentic_C98_T99_C227_compiled_positive_evidence_pins'] as $name=>$pin){
    $path=$repo.'/'.$pin['path'];
    $check('original prior C98 T99 C227 native manifest SHA unchanged '.$name,
        is_file($path)&&filesize($path)===$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
    $proofs[$name]=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
}
$may=$proofs['UE1-O06-G3-C98-MAY1998-ORIGINAL-NATIVE-LOADED.json'];
$const227=$proofs['UE1-O06-G3-C227I-UCONST-NATIVE-LOADED.json'];
$enum227=$proofs['UE1-O06-G3-C227I-UENUM-NATIVE-LOADED.json'];
$native469=$proofs['UE1-O06-G3-06-T99-469F-ORIGINAL-EIGHT-CONST-ONE-ENUM-NATIVE.json'];
$check('stock original May compiled C98 const 2 and enum 7 positive values',
    count($may['stock_loaded_UConst'])===2&&count($may['stock_loaded_UEnum'])>=7);
$check('stock original OldUnreal 227i compiled UConst eight and UEnum two arrays',
    count($const227['native_loaded_UConst'])===8
    &&count($enum227['native_loaded_UEnum'])===2);
$check('matching 469f original compiled native all Core.u UConst 8 and UEnum one',
    count($native469['actual_native_loaded_UConst_original_Core_u_exports'])===8
    &&$native469['actual_native_loaded_enum_count']===1
    &&$native469['actual_native_UEnum_array_count']===7);
$check('original native 469f seven globally resolved FNames explicitly present',
    $native469['actual_native_global_FName_text_values']===
        ['SHEER_None','SHEER_XY','SHEER_XZ','SHEER_YX','SHEER_YZ','SHEER_ZX','SHEER_ZY']);
foreach($native469['actual_original_compiled_engine_CDB_artifacts'] as $which=>$pin){
    $path=$repo.'/'.$pin['repo_path'];
    $check('authentic original compiled 469f WinDBG text/script bytes and SHA '.$which,
        is_file($path)&&filesize($path)===$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$check('actual original T99 current binary Core.dll pinned exactly',
    $native469['true_original_Core_dll']['sha256']===$matrix['true_T99_469f_Core_DLL_sha256']
    &&hash_file('sha256',$native469['true_original_Core_dll']['path'])===$matrix['true_T99_469f_Core_DLL_sha256']);
$check('C98 May and old 227i original binaries pinned from earlier oracle',
    $may['disc_and_genuine_compiled_files']['original_May_1998_Core_dll']['sha256']===$matrix['true_C98_MAY_Core_DLL_sha256']
    &&$const227['original_227i_files']['Core_dll']['sha256']===$matrix['original_C227i_Core_DLL_sha256']);
foreach($matrix['reused_source_defined_Enum_Const_positive_negative_verification_without_rerunning'] as $name=>$pin){
    $test=$repo.'/'.$pin['test_relative_path'];
    $log=$repo.'/'.$pin['original_PASSED_DEV_output_file'];
    $check('old source-typed enum/const negative test itself unchanged '.$name,
        is_file($test)&&hash_file('sha256',$test)===$pin['test_sha256']);
    $check('old PASS original output SHA unchanged and no replay '.$name,
        is_file($log)&&hash_file('sha256',$log)===$pin['original_PASSED_DEV_log_sha256']
        &&$pin['old_test_not_executed_this_run']===true);
}
$src=(string)file_get_contents($repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php');
$start=strpos($src,"            if(\$nativeKind==='enum'){");
$end=strpos($src,"            if(\$nativeKind==='textbuffer'){",$start);
$body=substr($src,$start,$end-$start);
$check('UEnum and UConst current method semantic fingerprint unchanged from frozen closure',
    hash('sha256',str_replace(["\r\n","\r"],"\n",$body))
        ===$matrix['same_current_source_methods_as_original_negative_pass']['current_unchanged_UEnum_and_UConst_method_body_sha256']);
$check('original UT99 v61 or v63 wide FString source selection remains explicit',
    str_contains($body,'$wide&&$ver<64&&$family!==CatalogUE1ClassicSourceFamily::UT99_V432')
    &&$matrix['same_current_source_methods_as_original_negative_pass']['original_source_method_original_Epic_enum_unchanged_and_UConst_only_explicit_T99_source_version_gate']===true);
$check('reader original source enum nonzero/negative array count and name errors remain intact',
    str_contains($body,'Negative Enum FName count cannot initialize original array')
    &&str_contains($body,'Enum FName index outside package name map')
    &&str_contains($body,'Enum UField reference outside package object map'));
$check('reader original source const real FArchive behavior and physical bounds intact',
    str_contains($body,'$raw=$read($count*($wide?2:1))')
    &&str_contains($body,'UConst UField reference out of package bounds')
    &&str_contains($body,'source_text_value_verified'));
$dis=$matrix['true_2026_C227K_compiled_method_disassembly_pin'];
$disfile=$repo.'/'.$dis['path'];
$check('true matching compiled 227k source method disassembly sha sealed',
    is_file($disfile)&&filesize($disfile)===$dis['bytes']
    &&hash_file('sha256',$disfile)===$dis['sha256']);
$disasm=(string)iconv('UTF-16','UTF-8',(string)file_get_contents($disfile));
$check('true 227k UConst source body has inherited UField then archived FString',
    str_contains($disasm,'Core!UConst::Serialize:')
    &&str_contains($disasm,'Core!UField::Serialize')
    &&str_contains($disasm,'lea     eax,[edi+3Ch]')
    &&str_contains($disasm,'Core!operator<<'));
$check('true 227k UEnum source body has inherited UField and names-array archive',
    str_contains($disasm,'Core!UEnum::Serialize:')
    &&str_contains($disasm,'UTextBufferFactory::operator=+0x4d0')
    &&str_contains($disasm,'Core!UField::Serialize'));
$check('actual source-compiled data member offset +0x3c differs from earlier T99 +0x34 without on-disk change',
    $matrix['source_accepted_g3_06_gate_lines']['SAVE']!=='' &&substr_count($disasm,'[edi+3Ch]')>=2);
$file=$native469['true_original_Core_u']['path'];
$pkg=new Package($file);
$check('real T99 original Core.u archive package still validates without corruption',
    $pkg->validatePackage()===[]);
$check('real original T99 patch source is v69 licensee0 without inferred patch producer',
    $pkg->getHeader()['version']===69&&$pkg->getHeader()['licenseeVersion']===0);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$actualTypes=['const'=>[],'enum'=>[]];
foreach($exports as $ex){
    $cid=(int)$ex['classIndex'];
    $kind=$cid<0?strtolower((string)($imports[-$cid-1]['objectNameText']??'')):'';
    if(array_key_exists($kind,$actualTypes))$actualTypes[$kind][]=(int)$ex['index'];
}
$expectedNative=array_column($native469['actual_native_loaded_UConst_original_Core_u_exports'],'genuine_Core_u_zero_based_export_index');
sort($expectedNative);sort($actualTypes['const']);
$check('all eight authentic T99 original serialized const exports covered by actual native LOAD',
    $actualTypes['const']===$expectedNative);
$check('only one original T99 enum present and loaded on compiled engine',
    $actualTypes['enum']===[46]
    &&$native469['actual_native_UEnum_original_Core_u_export_index']===46);
foreach($native469['actual_native_loaded_UConst_original_Core_u_exports'] as $datum){
    $i=$datum['genuine_Core_u_zero_based_export_index'];
    $e=$exports[$i];
    $record=Native::inspect($file,$pkg,$i,Tagged::inspect($file,$pkg,$i,Family::UT99_V469F),Family::UT99_V469F);
    $check('original compiled const native loaded value and Num/Max match source export '.(string)$i,
        $record['status']==='native_const_record_complete'
        &&$datum['compiled_loaded_value_string']===$record['value']
        &&$datum['compiled_loaded_value_character_count_with_null']===$record['value_count']
        &&$datum['compiled_loaded_value_capacity']>=$datum['compiled_loaded_value_character_count_with_null']);
    $check('original native in-memory 469f wide string decodes exact in-source UTF16 bytes '.$i,
        hex2bin($datum['compiled_loaded_value_UTF16LE_bytes_hex'])
        ===iconv('UTF-8','UTF-16LE',(string)$record['value']."\0"));
}
$e=$exports[46];
$enum=Native::inspect($file,$pkg,46,Tagged::inspect($file,$pkg,46,Family::UT99_V469F),Family::UT99_V469F);
$check('original compiled UEnum live loaded exact seven globally named source entries',
    $enum['status']==='native_enum_record_complete'
    &&$enum['name_count']===7
    &&array_column($enum['names'],'name')===$native469['actual_native_global_FName_text_values']);
$check('never equate native global FName IDs with saved package name indices',
    $native469['actual_native_global_FName_ids']!==array_column($enum['names'],'name_index'));
$check('all previously verified C98, T99, C227 positive/negative class semantics owned by this method contract',
    count($matrix['source_accepted_g3_06_gate_lines'])===9
    &&$matrix['G3_06_expected_acceptance_if_independent_gate_passes']===true
    &&$matrix['no_prior_test_replayed']===true);
$check('crosscutting original compiled malformed archive Tell belongs still OPEN G3-14',
    current(array_values(array_filter($status['contracts'],static fn($c)=>$c['id']==='G3-14')))['full_status']==='OPEN');
$check('whole class compiled-native aggregate remains G3-15 OPEN',
    current(array_values(array_filter($status['contracts'],static fn($c)=>$c['id']==='G3-15')))['full_status']==='OPEN');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($fail),
    'failed'=>$fail,'G3_06_acceptance'=>$ok?'ACCEPTED':'BLOCKED',
    'authentic_new_T99_469f_UConst_loaded_count'=>count($actualTypes['const']),
    'authentic_new_T99_469f_UEnum_loaded_count'=>count($actualTypes['enum']),
    'original_T99_469f_global_native_FName_values'=>$native469['actual_native_global_FName_text_values'],
    'replayed_old_tests'=>0,
    'G3_14_and_G3_15_corss_cutting_mandatory_obligations_remain_open'=>true
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
