<?php
/**
 * UE4.27.2 P05 reproducible Windows acceptance driver.
 * Original source and controlled cooked asset fixtures are retained on DEV;
 * this driver NEVER mutates a production database or canonical Epic source.
 */
declare(strict_types=1);

$here=__DIR__;
$assets='C:/Temp/ue427-all-assets.txt';
$cooked='C:/Temp/ue427-p05-optional-manifest.txt';
$native='C:/Temp/ue427-p05-final-native.log';
$checks=[
 'original_unsplit_reader'=>['verify-ue427-real-reader-table-corpus.php',[],[]],
 'export_physical_providers'=>['verify-ue427-p05-export-providers.php',[$assets,$cooked],
   ['counts.unsplit_packages'=>1749,'counts.split_packages'=>245,
    'counts.infile_export_spans'=>37552,'counts.uexp_export_spans'=>381,'mismatch_count'=>0]],
 'cooked_split_spans'=>['verify-ue427-p05-cooked-split.php',[],
   ['counts.packages'=>245,'counts.export_spans_in_uexp'=>381,
    'counts.uptnl'=>32,'counts.bulks_at_logical_end'=>245,'mismatch_count'=>0]],
 'native_import_export'=>['verify-ue427-native-records.php',
   ['C:/Temp/ue427-p05-optional-records-native.log',$cooked],
   ['files'=>245,'imports_compared'=>1418,'exports_compared'=>381,'fields_compared'=>23923,'mismatch_count'=>0]],
 'native_summary'=>['verify-ue427-p05-native-header.php',[$native,$cooked],
   ['authentic_cooked_packages'=>245,'native_header_fields_compared'=>735]],
 'genuine_optional_bulk'=>['verify-ue427-p05-native-optional.php',[$native,$cooked],
   ['uptnl_files'=>32,'native_optional_headers'=>87,'payload_bytes_hashed'=>44497920,'mismatch_count'=>0]],
 'genuine_default_bulk'=>['verify-ue427-p05-native-bulk-headers.php',[$native,$cooked],
   ['ubulk_files'=>37,'native_bulk_headers'=>41,'payload_bytes_hashed'=>2211840,'mismatch_count'=>0]],
 'negative_optional_bulk'=>['verify-ue427-p05-optional-negatives.php',[$cooked,$native],[]],
 'negative_default_bulk'=>['verify-ue427-p05-bulk-negatives.php',[$cooked,$native],[]],
 'native_bulk_storage_policy'=>['verify-ue427-p05-native-policy-fixtures.php',
  ['C:/Temp/ue427-p05-policy-fixtures','C:/Temp/ue427-p05-policy-native.log'],
  ['cases'=>10,'compiled_bulkdata_runtime_cases'=>2,'mismatch_count'=>0]],
 'native_owner_linker'=>['verify-ue427-p05-owner-runtime.php',
  ['C:/Temp/ue427-p05-owner-stream-final.log'],
  ['native_owner_linker_cases'=>8,'mapped_region_cases'=>2,'streaming_payload_bytes_verified'=>128]],
 'ut4_v5_regression'=>['verify-uedb5-ut4-persistence.php',[],[]],
];
$out=[];$pass=0;
foreach($checks as $label=>[$program,$args,$fields]){
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($here.'/'.$program);
 foreach($args as $arg)$cmd.=' '.escapeshellarg($arg);
 $lines=[];$exit=0;
 exec($cmd.' 2>&1',$lines,$exit);
 $json=json_decode(implode("\n",$lines),true);
 $errors=[];
 if(!is_array($json)||($json['ok']??null)!==true||$exit!==0){
  $errors[]=['bad_exit_or_output'=>$exit,'log'=>array_slice($lines,0,12)];
 }
 if(is_array($json))foreach($fields as $path=>$want){
  $actual=$json;
  foreach(explode('.',$path) as $key){
   $actual=is_array($actual)?($actual[$key]??null):null;
  }
  if($actual!==$want)$errors[]=['field'=>$path,'expected'=>$want,'actual'=>$actual];
 }
 $ok=$errors===[];
 $out[$label]=['ok'=>$ok,'exit'=>$exit,'checks'=>count($fields),'failures'=>$errors];
 if($ok)$pass++;
}
$ok=$pass===count($checks);
echo json_encode(['ok'=>$ok,'source'=>'Pinned Epic 4.27.2 compiled oracle and Windows cook',
 'verified_operations'=>$pass,'total_operations'=>count($checks),
 'audit_scope'=>'P05 bulk/export wire format and provider resolution; P08/P09 durable workflow excluded',
 'results'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
