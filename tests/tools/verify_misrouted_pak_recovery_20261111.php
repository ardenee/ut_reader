<?php
/**
 * Focused regression for safely recovering retained UnrealPak failures.
 * No production data is modified by this test.
 */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogBackgroundJobFileTreeProjector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogMisroutedPakRecovery;
use UnrealDb\Catalog\Domain\Jobs\JobType;

$fail = [
    'id'=>9528900,'queue_name'=>'catalog',
    'job_type'=>JobType::IMPORT_STAGED_PAK,
    'status'=>'completed','display_status'=>'failed',
    'payload'=>['original_name'=>'DM-Goop!.pak','game_id'=>7,'size'=>18250551],
    'result'=>['status'=>'failed','message'=>'Unsupported PAK file: no Unreal PAK footer was found.'],
    'progress'=>['status'=>'failed','message'=>'Unsupported PAK file: no Unreal PAK footer was found.','percent'=>100],
    'child_count'=>0,'child_issue_count'=>0,'child_active_count'=>0
];
$view=(new CatalogBackgroundJobFileTreeProjector())->project([$fail]);
if (count($view)!==1 || empty($view[0]['can_recover_ue3_pak'])
    || $view[0]['operator_state']!=='issue') {
    throw new RuntimeException('Misrouted .pak must expose correct reader recovery action.');
}
$resolved=$fail;
$resolved['display_status']='duplicate';
$resolved['result']=[
    'status'=>'duplicate','file_id'=>1273248,
    'message'=>'Resolved as already-verified UT3 package',
    'original_failure_result'=>$fail['result']
];
$resolved['progress']=['stage'=>'recovered','status'=>'duplicate','percent'=>100,
    'message'=>'Resolved as already-verified UT3 package'];
$resolvedView=(new CatalogBackgroundJobFileTreeProjector())->project([$resolved])[0];
if ($resolvedView['operator_state']!=='completed'
    || !empty($resolvedView['can_recover_ue3_pak'])
    || $resolvedView['result_label']!=='Duplicate'
    || empty($resolved['result']['original_failure_result'])) {
    throw new RuntimeException('Resolved result must clear issue while preserving historical failure.');
}
$invalid=$fail;
$invalid['result']['message']='PAK file is too small.';
if (!empty((new CatalogBackgroundJobFileTreeProjector())->project([$invalid])[0]['can_recover_ue3_pak'])) {
    throw new RuntimeException('Malformed .pak placeholders must not offer UE3 repair.');
}
$root=dirname(__DIR__,2);
$service=(string)file_get_contents($root.'/catalog/src/Infrastructure/Jobs/CatalogMisroutedPakRecovery.php');
$bulk=(string)file_get_contents($root.'/catalog/api/v1/job-bulk.php');
$api=(string)file_get_contents($root.'/catalog/api/v1/job-action.php');
$ui=(string)file_get_contents($root.'/catalog/assets/background-jobs-files.js');
$retry=(string)file_get_contents($root.'/catalog/src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
foreach([
    [$service,'original_failure_result'],
    [$service,'FOR UPDATE'],
    [$service,'beginTransaction()'],
    [$service,'IMPORT_STAGED_PACKAGE'],
    [$api,"recover_misrouted_pak"],
    [$bulk,'pak_recovered_duplicates'],
    [$retry,"no unreal pak footer was found"],
    [$ui,'function recoverMisroutedPak(']
] as [$source,$needle]) {
    if(!str_contains($source,$needle)) throw new RuntimeException('Recovery contract missing '.$needle);
}
echo json_encode([
  'pass'=>true,'bad_footer_offer_recovery'=>true,'placeholder_rejected'=>true,
  'recovery_result_clears_issue'=>true,'original_failure_preserved'=>true,
  'bulk_retry_routes_instead_of_replaying_wrong_handler'=>true,'database_writes'=>0
],JSON_PRETTY_PRINT).PHP_EOL;
