<?php
declare(strict_types=1);
/**
 * Actual Epic UE5.8.3 cooked IoStore artifact vs UnrealDB decoder and offline
 * diagnostic preview. This is NOT a cooked executable or a live UObject test.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptImportDiagnosticPreview;
$root=dirname(__DIR__);
$toc=$root.'/tests/fixtures/ue5-583-cooked-global.utoc';
$ucas=$root.'/tests/fixtures/ue5-583-cooked-global.ucas';
$c=[];
$c['epic_generated_utoc_sha256']=is_file($toc)&&hash_file('sha256',$toc)==='203c39d08ca160942637d344ed89518fd89af71803801265fe8062346b048650';
$c['epic_generated_ucas_sha256']=is_file($ucas)&&hash_file('sha256',$ucas)==='af44471c639d433e8552c77d9cedd0c1edeb1b1956a554feae2e62e92c827f3e';
try {
  $parsed=Uedb5ZenScriptObjectsReader::fromToc(new Uedb5IoStoreTocReader($toc));
  $objects=$parsed['objects']??[];
  $c['actual_cooked_script_objects_chunk_present']=($parsed['present']??false)===true;
  $c['actual_cooked_script_object_count']=($parsed['count']??0)===23672 && count($objects)===23672;
  $c['actual_cooked_diagnostic_path_reconstruction']=Uedb5ZenScriptImportDiagnosticPreview::getCookedDiagnosticPathName($objects,'4D0960BDE89F7B36')==='/Script/Engine.MaterialFunctionMaterialLayerBlend'
    && Uedb5ZenScriptImportDiagnosticPreview::getCookedDiagnosticPathName($objects,'5635D6648A5A6CB7')==='/Script/ActionableMessage.ActionableMessageSubsystem';
  $c['offline_cooked_records_do_not_claim_runtime_resolution']=($parsed['runtime_authoritative']??true)===false
    && ($objects['4D0960BDE89F7B36']['runtime_resolved']??true)===false;
} catch (Throwable $e) {
  $c['parse_error']=$e->getMessage();
}
$ok=!in_array(false,$c,true)&&!isset($c['parse_error']);
echo json_encode(['ok'=>$ok,'scope'=>'actual_cooked_iostore_format_and_offline_diagnostics_only','checks'=>$c,'original_operation'=>'UE5-583-SCRIPT-005','disposition'=>'partial','live_cooked_PreCDO_debug_chunk_loading_attested'=>false,'live_nonempty_GetImportName_attested'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
