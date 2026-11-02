#!/usr/bin/env php
<?php
/** Real original PRD v61 archive, actual compiled v432 Core.dll LoadPackage.
 * Source proof: 308/308 loaded streams, 184/184 native tails.
 * Camera0..3 uninstantiated; not full field-value parity or method closure.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$root=dirname(__DIR__,2);
$read=static function(string $p):array{
 $s=(string)file_get_contents($p);
 if(str_starts_with($s,"\xEF\xBB\xBF"))$s=substr($s,3);
 return json_decode($s,true,512,JSON_THROW_ON_ERROR);
};
$m=$read($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-PRD-REAL-V61-COMPILED-OBJECT-308.json');
$n=0;$bad=[];
$ck=static function(string $name,bool $yes)use(&$n,&$bad):void{
 $n++;if(!$yes)$bad[]=$name;
};
foreach($m['source_files_sha256'] as $key=>$p){
 // The PHP CLI on DEV cannot stat Z: (mapped SMB drive), although
 // Python/PowerShell have already verified the original PRD SHA. Keep
 // the original source path pinned, and verify its SHA-matched DEV copy.
 if($key==='PRD_original_verified_map_read_only'){
   $local=$m['source_files_sha256']['DEV_original_SHA_copied_package'];
   $ck('PRD_readonly_source_sha_recorded_and_DEV_copy_identical',
     str_starts_with($p['path'],'Z:')
     &&$p['sha256']===$local['sha256']
     &&is_file($local['path'])
     &&hash_file('sha256',$local['path'])===$p['sha256']);
   continue;
 }
 $ck('original_or_native_SHA_'.$key,is_file($p['path'])
   &&hash_file('sha256',$p['path'])===$p['sha256']);
}
$refs=$m['source_files_sha256'];
$ck('PRD_original_bytes_identical_to_DEV',
 $refs['PRD_original_verified_map_read_only']['sha256']
 ===$refs['DEV_native_oracle_staged_map']['sha256']);
$original=new P($refs['DEV_native_oracle_staged_map']['path']);
$ck('genuine_version61_export312',
 ($original->getHeader()['version']??null)===61
 &&count($original->getExports())===312);
$source=$read($refs['DEV_current_tagged_and_184_native_tails_source_preflight']['path']);
$st=$source['status'];
$ck('all_native_loaded_source_tagged_streams',
 $st['attempted_source_loaded']===308&&$st['tag_envelope_decoded']===308
 &&$st['source_errors']===0&&$st['status_other']===0);
$ck('all_source_native_tails_complete',
 $st['native_tail_complete']===184&&$st['native_tail_unresolved']===0
 &&$source['errors']===[]&&$source['other']===[]);
$cmp=$read($refs['exact_308_native_object_vs_312_original_export_comparison']['path']);
$ck('308_class_and_export_name_native_matches',
 $cmp['native_class_name_export_matches']===308
 &&$cmp['original_export_count']===312
 &&$cmp['native_loaded_count']===308&&$cmp['unmatched_native']===[]);
$abs=$cmp['original_exports_not_in_native_object_iterator'];
$ck('four_Camera_original_exports_not_native_loaded',
 count($abs)===4
 &&array_diff(array_column($abs,'name'),['Camera0','Camera1','Camera2','Camera3'])===[]
 &&count(array_filter($abs,static fn($r)=>$r['class']==='Camera'))===4);
$log=(string)file_get_contents($refs['genuine_complete_compiled_native_loaded_object_and_property_stdout']['path']);
if(str_starts_with($log,"\xFF\xFE"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
$ck('compiled_UE1_v432_loaded_original_map_success',
 str_contains($log,'G5_NATIVE_OBJECT_COUNT 308')
 &&str_contains($log,'G5_NATIVE_DIRECT_PACKAGE_RESULT')
 &&str_contains($log,'native_error=0'));
$ck('native_308_object_begin_markers_and_no_Camera',
 preg_match_all('/G5_NATIVE_OBJECT_BEGIN /',$log)===308
 &&!str_contains($log,'G5_NATIVE_OBJECT_BEGIN Camera '));
$ck('native_exported_8515_values_not_claimed_parity',
 preg_match_all('/G5_NATIVE_TAG_VALUE /',$log)===8515
 &&$m['compiled_loaded_property_export_line_count_NOT_field_parity']===8515);
$ck('only_v432_loader_not_v1200_or_v227_native',
 $m['source_family_for_loader']==='ut99-v432'
 &&$m['actual_archive_version']===61);
$ck('full_method_deliberately_not_accepted',$m['method_FULL']===false);
$events=array_map(static fn($s)=>json_decode($s,true),
 array_filter(explode("\n",(string)file_get_contents(
 $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
$prior=null;foreach($events as $e){
 if(($e['test']??'')==='verify-ue1-o06-g5-uobject-prd-native-string-decision.php'
 &&($e['state']??'')==='PASS')$prior=$e;
}
$path=$prior?$root.'/'.str_replace('\\','/',$prior['log']):null;
$old=$path&&is_file($path)?$read($path):[];
$ck('previous_37_source_scoped_verdict_SHA',
 $prior!==null&&hash_file('sha256',$path)===$prior['output_sha256']
 &&($old['ok']??null)===true&&($old['checks']??null)===37
 &&($old['previous_113_genuine_Entry_native_tagged_values_SHA_reused']??null)===113);
echo json_encode(['ok'=>$bad===[],'checks'=>$n,'passed'=>$n-count($bad),
 'failures'=>$bad,'method'=>'T99:UObject::Serialize',
 'real_prd_v61_exports'=>312,'compiled_native_v432_loaded_objects'=>308,
 'exact_native_class_name_matches'=>308,'source_archives_for_native_loaded'=>308,
 'source_native_tails_complete'=>184,'native_uninstantiated_Camera'=>4,
 'native_exported_property_lines_not_individually_compared'=>8515,
 'native_oracle_replays_in_acceptance'=>0,'full_method_decision'=>'BLOCKED'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
