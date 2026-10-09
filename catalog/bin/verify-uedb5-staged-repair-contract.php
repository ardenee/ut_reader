<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$source=(string)file_get_contents($root.'/bin/repair-uedb5-staged.php');
$projectionRepair=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5MissingObjectProjectionRepair.php');
$sourceMigration=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameSourceMigrationService.php');
$validator=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5MigrationValidator.php');
$validation=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5MigrationValidationService.php');
$wrapper=(string)file_get_contents($root.'/bin/run-uedb5-staged-repair.ps1');
$profileRepair=(string)file_get_contents($root.'/bin/repair-unreal2-v69-profile.php');
$checks=[
 'unreal2_profile_fix_source_scoped'=>str_contains($profileRepair, "'package_version_min'=>60,'package_version_max'=>69")
    && str_contains($profileRepair, "'detected_engine'=>'UE2','reader_engine'=>'UE2'")
    && str_contains($profileRepair, 'SHA2(CAST(compatibility_rules_json AS CHAR),256)=?'),
 'failed_file_retry_is_opt_in'=>str_contains($source, "'failed-only'")
    && str_contains($source, "'retry-failed'")
    && str_contains($source, "\$failedOnly ? '\"failed\"'")
    && str_contains($wrapper, "'--retry-failed'"),
 'batch_failure_cursor_persisted'=>str_contains($wrapper, "\$exit -eq 2 -and [int]\$completed.failed")
    && str_contains($wrapper, "\$totalFailed += [int]\$completed.failed"),
 'ut3_staged_snapshot_mode_explicit'=>str_contains($source, "'trust-staged-source'")
    && str_contains($source, "\$slug !== 'ut3'")
    && str_contains($source, '$validation->validateFile($slug, $fileId, null, $trustStagedSource)')
    && str_contains($validator, "'trusted_staged_source'")
    && str_contains($wrapper, "'--trust-staged-source'"),
 'staged_resync_bypasses_duplicate_source_validation'=>str_contains($source, "'resync-staged'")
    && str_contains($source, 'if (!$resyncStaged && !$repairNeeded)')
    && str_contains($source, '$source->runFile($gameId,$fileId,true,$verifiedSourceSnapshot)')
    && str_contains($source, '$dependencies->runFile($gameId,$fileId,true,true)')
    && str_contains($source, '$validation->validateFile($slug,$fileId,$verifiedSourceSnapshot)')
    && str_contains($wrapper, "'--resync-staged'"),
 'disjoint_worker_partitioning'=>str_contains($source, 'MOD(s.file_id,?)=?')
    && str_contains($source, '$select->execute([$gameId, $after, $workers, $workerIndex])')
    && str_contains($source, "'list-only'")
    && str_contains($wrapper, '"--workers=$Workers"')
    && str_contains($wrapper, '"--worker-index=$WorkerIndex"'),
 'same_process_source_snapshot_handoff'=>str_contains($sourceMigration, '$verifiedSourceSnapshot = $snapshot;')
    && str_contains($source, '$source->runFile($gameId,$fileId,true,$verifiedSourceSnapshot)')
    && str_contains($source, '$validation->validateFile($slug,$fileId,$verifiedSourceSnapshot)'),
 'ordinary_step8_keeps_source_reparse'=>str_contains($validator, '$trustStagedSource ? $snapshot : $this->validateSourceBytes($context)')
    && str_contains($validation, '$this->validator->validate($fileId, $previouslyVerifiedSourceSnapshot, $trustStagedSource)'),
 'empty_v5_projection_recovery_only'=>str_contains($projectionRepair, 'if ($present !== 0)')
    && str_contains($projectionRepair, 'PdoUedb5BaseProjectionPublisher')
    && str_contains($projectionRepair, 'Uedb5GameDependencyPassService')
    && str_contains($projectionRepair, 'validateFile('),
 'batch_recovers_only_object_projection_mismatch'=>str_contains($source, "'object_projection_mismatch'")
    && str_contains($source, 'Uedb5MissingObjectProjectionRepair'),
 'bounded_batch'=>str_contains($source,'min(500, $limit)') || str_contains($source,'min(500, (int)'),
 'staged_only'=>str_contains($source, "\$retryFailed ? '\"staged\",\"failed\"' : '\"staged\"'"),
 'v5_registration_only'=>str_contains($source,'JOIN ue_uedb5_files'),
 'requires_explicit_apply'=>str_contains($source, "'apply'") && str_contains($source, 'isset($options'),
 'original_container_backup'=>str_contains($source,'Uedb5MetadataContainer::path') && str_contains($source,'hash_file'),
 'authoritative_pass1'=>str_contains($source,'$source->runFile($gameId,$fileId,true,$verifiedSourceSnapshot)'),
 'v5_dependency_pass2'=>str_contains($source,'$dependencies->runFile($gameId,$fileId,true,true)'),
 'step8_source_validation'=>str_contains($source,'$validation->validateFile($slug,$fileId,$verifiedSourceSnapshot)'),
 'no_legacy_sql'=>preg_match('/ue_(?:file_metadata|export_lookup|name_lookup|dependency_links)/',$source)===0,
 'resume_cursor'=>str_contains($source,'s.file_id>?'),
];
$failed=array_keys(array_filter($checks,static fn(bool $pass):bool=>!$pass));
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
