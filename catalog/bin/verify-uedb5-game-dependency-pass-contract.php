#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$metadata=$root.'/src/Infrastructure/Metadata';
$service=(string)file_get_contents($metadata.'/Uedb5GameDependencyPassService.php');
$statusRepo=(string)file_get_contents($metadata.'/PdoUedb5MigrationStatusRepository.php');
$registration=(string)file_get_contents($metadata.'/PdoUedb5StagingRegistrationRepository.php');
$migration=(string)file_get_contents($root.'/migrations/202610020001_uedb5_dependency_pass_status.php');
$cli=(string)file_get_contents($root.'/bin/migrate-uedb5-dependencies.php');
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$check('pass2_has_exact_payload_checkpoint',
    str_contains($migration,'dependency_payload_sha256 BINARY(32)')
    && str_contains($migration,'dependency_policy VARCHAR(64)'));
$check('pass2_selects_physical_v5_providers',str_contains($service,'PdoUedb5PhysicalProviderSelector'));
$check('pass2_rebuilds_authoritative_v5_dependencies',str_contains($service,'Uedb5DependencyRebuilder'));
$check('pass2_refreshes_existing_v5_registration',str_contains($service,'refreshExisting'));
$check('pass2_publishes_v5_dependency_sql',str_contains($service,'PdoUedb5DependencyProjectionPublisher'));
$check('pass2_marks_completion_after_publication',
    strpos($service,'markDependencySucceeded') > strpos($service,'publisher->publish'));
$check('resume_marker_matches_current_v5_payload',
    str_contains($service,'s.dependency_payload_sha256=v.payload_sha256')
    && str_contains($service,'s.dependency_payload_sha256<>v.payload_sha256'));
$check('source_reparse_invalidates_dependency_checkpoint',
    str_contains($statusRepo,'dependency_policy=NULL')
    && str_contains($statusRepo,'dependency_payload_sha256=NULL'));
$check('dependency_failure_is_durable',str_contains($statusRepo,'markDependencyFailed'));
$check('refresh_existing_does_not_call_v4_assertion',
    preg_match('/function refreshExisting.*?return \$this->upsert/s',$registration,$m)===1
    && !str_contains((string)($m[0]??''),'assertLiveV4Registration'));
$check('pass2_service_has_no_v4_metadata_read',
    !str_contains($service,'ue_file_metadata')
    && !str_contains($service,'BlockedCompressedMetadata')
    && !str_contains($service,'.uedb4'));
$check('pass2_worker_partition_is_resumable',
    str_contains($service,'MOD(f.id,?)=?')
    && str_contains($service,'firstRemainingFileId')
    && str_contains($service,'$cursor = $fileId'));
$check('pass2_cli_supports_pool_preflight_and_force',
    str_contains($cli,"'workers::'")
    && str_contains($cli,"'worker-index::'")
    && str_contains($cli,"'continuous'")
    && str_contains($cli,"'preflight'")
    && str_contains($cli,"'force'"));
$check('pass2_force_requires_apply',str_contains($cli,'--force requires --apply'));
$check('pass2_supports_exact_file_repair',
    str_contains($cli,"'file-id::'")
    && str_contains($cli,'$service->runFile($gameId,$fileId,$apply,$skipWorkerPreflight)')
    && str_contains($service,'public function runFile(int $gameId, int $fileId, bool $apply'));
$check('pass2_exact_file_write_requires_explicit_force',
    str_contains($cli,'Targeted --file-id writes require --force together with --apply'));
$check('pass2_force_bypasses_only_resume_filter',str_contains($service,'if(!$force)')&&str_contains($service,'bool $force = false'));
$check('pass2_cli_uses_game_id_identity',str_contains($cli,"'game-id:'")&&str_contains($service,'Uedb5GameSourceRegistry::sourceKey'));
$check('pass2_preflight_requires_complete_v5_not_v4',
    str_contains($service,"'pass2_ready' => \$ready")
    && str_contains($service,"'staged_count' => \$staged")
    && !str_contains($service,'v4_ready'));

echo json_encode([
    'ok'=>$failures===[],
    'checks'=>$checks,
    'failures'=>$failures,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
