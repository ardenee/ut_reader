<?php
declare(strict_types=1);
/** Original UE5.8.3 Shipping import-store construction, not registration completion. */
$path=dirname(__DIR__).'/tests/fixtures/ue5-b015-shipping-constructor-original.log';
$bytes=is_file($path)?file_get_contents($path):false;
$text=is_string($bytes)&&str_starts_with($bytes,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($bytes,2)):'';
$checks=[
 'original_log_sha256'=>is_string($bytes)&&hash('sha256',$bytes)==='8f36f95483f41a6945291eea564035400a19fc86b001a904fd98be34f7e994cb',
 'child_shipment_symbol_breakpoint_installed'=>str_contains($text,'bp UnrealGame_Win64_Shipping!FGlobalImportStore::FGlobalImportStore'),
 'compiled_real_import_store_ctor_called'=>preg_match('/^UEDB_B015_SHIPPING_IMPORTSTORE_CONSTRUCTED\r?$/m',$text)===1,
 'compiled_ctor_callsite'=>str_contains($text,'UnrealGame_Win64_Shipping!FGlobalImportStore::FGlobalImportStore'),
 'actual_async_loading_thread_callsite'=>str_contains($text,'UnrealGame_Win64_Shipping!FAsyncLoadingThread2::FAsyncLoadingThread2+0x240'),
 'matching_shipping_process_loaded'=>str_contains($text,'UnrealGame-Win64-Shipping.exe'),
];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'operation'=>'UE5-583-B015-1','status'=>'partial','checks'=>$checks,'no_check_registration_completion_verified'=>false,'verse_uht_scriptcells_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
