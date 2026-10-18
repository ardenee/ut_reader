<?php
declare(strict_types=1);
/**
 * Retained actual UE5.8.3 Windows cooked-game PreCDO debug-loader evidence.
 * PDB-backed CDB breakpoints run in child UnrealGame.exe, not a stand-in.
 * This gate deliberately does NOT attest a GetImportName fallback call.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader;

$root = dirname(__DIR__).'/tests/fixtures/';
$sources=[
  'map' => ['ue5-583-cooked-precdo-map-original.log','49003f03dde6af2bb7d048b998224ca20a6efd98ce7df228088b57c32558546b'],
  'branch' => ['ue5-583-cooked-precdo-branches-original.log','b57fe43915a0240f5359c25ae4ca7bafe22a8a293f69ff038b4380b7ddb5ca5c'],
  'name_watch' => ['ue5-583-cooked-name-watch-original.log','50dd287951f0a1bea0a214aa052654122fc82e9f9d49940d66c52604a0684cc2'],
];
$checks=[];
$logs=[];
foreach($sources as $name=>[$file,$hash]){
  $path=$root.$file;
  $checks[$name.'_original_trace_sha256']=is_file($path) && hash_file('sha256',$path)===$hash;
  $bytes=is_file($path)?(string)file_get_contents($path):'';
  $logs[$name]=str_starts_with($bytes, "\xFF\xFE") ? (string)iconv('UTF-16LE','UTF-8',substr($bytes,2)) : $bytes;
}
$marker=static function(string $log,string $value):bool {
  return preg_match('/^'.preg_quote($value,'/').'\r?$/m',$log)===1;
};
$checks['live_child_real_unrealgame_symbols']=str_contains($logs['map'],'ModLoad:')
  && str_contains($logs['map'],'UnrealGame.exe')
  && str_contains($logs['map'],'1:004> bp /1 UnrealGame!FCookedScriptObjectsDebug::LoadDebugData+0x1e8');
$checks['precdo_cooked_chunk_existence_true']=$marker($logs['branch'],'UEDB_COOKED_CHUNK_EXISTS_TRUE');
$checks['precdo_iobatch_result_nonnull']=$marker($logs['branch'],'UEDB_COOKED_READ_RESULT_PRESENT');
$checks['precdo_completed_loader']=$marker($logs['branch'],'UEDB_COOKED_LOADER_EXIT');
$checks['precdo_parsed_23672_records']=$marker($logs['map'],'UEDB_COOKED_PARSED_RECORD_COUNT=23672');
$checks['precdo_tmap_live_size_23672']=$marker($logs['map'],'UEDB_COOKED_MAP_ALLOC=23672 FREE=0');
$checks['actual_global_iostore_mounted']=str_contains($logs['map'],'Mounted container')
  && str_contains($logs['map'],'global.utoc');
$checks['normal_cooked_runtime_shutdown']=str_contains($logs['map'],'LogExit: Exiting.');
$checks['no_actual_getimportname_during_observed_startup']=!$marker($logs['name_watch'],'UEDB_COOKED_REAL_GETIMPORTNAME_CALLED')
  && !$marker($logs['name_watch'],'UEDB_COOKED_REAL_DEBUG_PATH_CALLED')
  && str_contains($logs['name_watch'],'LogExit: Exiting.');
$reader=Uedb5ZenScriptObjectsReader::fromToc(new Uedb5IoStoreTocReader($root.'ue5-583-cooked-global.utoc'));
$checks['original_cooked_chunk_matches_runtime_map']=($reader['present']??false)===true && ($reader['count']??0)===23672
  && count($reader['objects']??[])===23672
  && ($reader['runtime_authoritative']??true)===false;
$ok=!in_array(false,$checks,true);
echo json_encode([
 'ok'=>$ok,'operation'=>'UE5-583-SCRIPT-005','disposition'=>'partial','checks'=>$checks,
 'real_cooked_runtime_loaded_debug_map'=>23672,
 'real_nonempty_getimportname_fallback_executed'=>false,
 'limitation'=>'Debugger breaks verify actual PDB-backed engine code paths and map cardinality, not a nonempty cooked GetImportName result.',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
