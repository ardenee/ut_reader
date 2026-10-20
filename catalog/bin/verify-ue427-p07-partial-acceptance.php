<?php
/**
 * P07 partial evidence only; does not claim source-level VerifyImportInner
 * native decision-tree closure. UT4 clean-master static profile is separate
 * from stock UE4.27.2 compiled runtime package tests.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$checks=[
 'ut4_static_matching'=>[__DIR__.'/verify-ue4-dependency-matching-contract.php',
  [],['checks'=>37]],
 'stock_ue427_compiled_postload'=>[__DIR__.'/verify-ue427-p07-native-runtime-imports.php',
  [],['packages_checked'=>2,'import_rows_compared'=>22,'serialized_identity_fields_compared'=>110,
      'runtime_xobject_loaded'=>16,'postload_source_linker_rows'=>0]],
 'stock_ue427_native_provider_class_identity'=>[__DIR__.'/verify-ue427-p07-native-export-identity.php',[],
  ['native_export_count'=>33,'mismatch_count'=>0]],
 'stock_ue427_import_branch_corpus'=>[__DIR__.'/verify-ue427-p07-import-branch-corpus.php',[],[]],
 'v5_dependency_resolution_regression'=>[__DIR__.'/verify-uedb5-unreal-classic-dependency-resolution.php',[],[]]
];
$results=[];$errors=[];
foreach($checks as $name=>[$file,$args,$fields]){
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($file);
 foreach($args as $arg)$command.=' '.escapeshellarg($arg);
 $lines=[];$status=0;
 exec($command.' 2>&1',$lines,$status);
 $j=json_decode(implode("\n",$lines),true);
 $failure=[];
 if(!is_array($j)||($j['ok']??false)!==true||$status!==0)
  $failure[]=['bad_status'=>$status,'output'=>array_slice($lines,0,15)];
 if(is_array($j))foreach($fields as $field=>$value)
  if(($j[$field]??null)!==$value)$failure[]=['field'=>$field,'expected'=>$value,'actual'=>$j[$field]??null];
 if($failure!==[])$errors[$name]=$failure;
 $results[$name]=['ok'=>$failure===[],'check_fields'=>count($fields)];
}
$prod=@file_get_contents($root.'/catalog/src/Infrastructure/Persistence/PdoUe4VerifyImportProjectionResolver.php');
$reader=@file_get_contents($root.'/UE4/UnrealPackageReader.php');
if(!is_string($prod)||!str_contains($prod,"PROFILE_UT4_CLEAN_MASTER = 'ue4-ut4-clean-master-v511'"))
 $errors['profile_boundary']='Current catalog resolver is not pinned to the UT4 v511 source';
if(!is_string($reader)||!str_contains($reader,"'packageNameText' =>"))
 $errors['ue427_reader_boundary']='Serialized UE4.27 PackageName metadata not retained by reader';
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'scope'=>'PARTIAL UE4.27 P07, not closure',
 'verified_checkpoints'=>count($results)-count(array_intersect(array_keys($results),array_keys($errors))),
 'total_checkpoints'=>count($results),
 'results'=>$results,'errors'=>$errors,
 'unverified'=>[
 'Direct compiled VerifyImport/VerifyImportInner per-import source decisions and redirector DestinationObject',
 'Cross-linker PackageName and version-policy provider matching against actual engine packages',
 'Negative/missing provider warning, optional and failed-linker runtime classification',
 'Stock UE4.27 catalog V5 production dispatch is P08/P09, not established by UT4 v511 contracts'
 ]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
