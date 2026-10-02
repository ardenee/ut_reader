#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
require_once $root.'/lib/GameProfiles.php';
$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameSourceMigrationService.php');
$factory=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php');
$registry=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameSourceRegistry.php');
$validator=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5MigrationValidator.php');
$cli=(string)file_get_contents($root.'/bin/migrate-uedb5-game.php');
$publisher=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5BaseProjectionPublisher.php');
$checks=[]; $failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok){$failures[]=$name;}};
$check('migration_hashes_original_md5',str_contains($service,'md5_file($path)'));
$check('migration_hashes_original_sha1',str_contains($service,'sha1_file($path)'));
$check('migration_checks_original_size',str_contains($service,'filesize($path)'));
$check('migration_never_reads_uedb4_container',!str_contains($service,'BlockedCompressedMetadataReader')&&!str_contains($service,'.uedb4'));
$check('migration_uses_canonical_reader_resolver',str_contains($factory,'CatalogReaderResolver::resolve'));
$check('migration_and_validation_share_source_factory',str_contains($service,'Uedb5SourceSnapshotFactory')&&str_contains($service,'sourceSnapshots->build'));
$check('migration_writes_v5_snapshot',str_contains($service,'writer->write($snapshot)'));
$check('migration_registers_v5_only_after_source_parse',str_contains($service,'registration->register'));
$check('projection_failure_removes_staged_registration',str_contains($service,'registration->remove'));
$check('resume_selection_uses_missing_v5_registration',str_contains($service,'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id')&&str_contains($service,'v.file_id IS NULL'));
$check('continuous_run_has_internal_cursor',str_contains($service,'f.id>?')&&str_contains($service,'$cursor = (int)$file'));
$check('game_profile_is_version_gate',str_contains($factory,'gp_required_profile_for_game')
    &&str_contains($factory,"'min_version' => \$profile['package_version_min']")
    &&str_contains($factory,"'max_version' => \$profile['package_version_max']")
    &&str_contains($factory,'gp_profile_version_decision'));
$check('migration_selection_has_no_private_version_between',!str_contains($service,'f.package_version BETWEEN'));
$check('legacy_builders_have_no_private_game_version_bounds',
    !str_contains((string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5Ut99SnapshotBuilder.php'),'MIN_VERSION')
    &&!str_contains((string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5UnrealSnapshotBuilder.php'),'MIN_VERSION')
    &&!str_contains((string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5Unreal2SnapshotBuilder.php'),'MIN_VERSION')
    &&!str_contains((string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5Ut2003SnapshotBuilder.php'),'MIN_VERSION')
    &&!str_contains((string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5Ut2004SnapshotBuilder.php'),'MIN_VERSION'));
$check('preflight_reports_version_distribution',str_contains($service,'package_version_distribution'));
$check('preflight_reports_unsupported_files',str_contains($service,'unsupported_source_files'));
$check('preflight_sql_has_no_literal_quote_backslashes',!str_contains($service,'scan_status=\\\"verified\\\"'));
$check('preflight_reports_missing_v4_files',str_contains($service,'missing_v4_files')&&str_contains($service,'v4_ready'));
$check('ue5_game_version_gate_is_profile_owned',str_contains($factory,"'ue5' => ['engine_key'=>'UE5']")
    &&!str_contains($factory,"'min_version'=>1000")&&!str_contains($factory,"'max_version'=>1018"));
$check('ue5_classic_uses_assigned_parser_profile',str_contains($factory,'catalog_ue5_reader_options')&&str_contains($factory,'catalog_ue5_set_next_reader_options'));
$check('ue5_classic_uses_canonical_reader',str_contains($factory,'\'ue5\' => $reader instanceof \\UnrealPackageReader5')&&str_contains($factory,'Uedb5Ue5ClassicSnapshotBuilder::build'));
$check('base_pass_clears_dependency_projection_for_second_pass',str_contains($publisher,'ue_uedb5_dependency_edges')&&str_contains($publisher,'ue_uedb5_dependency_packages'));
$check('cli_defaults_to_explicit_apply',str_contains($cli,'isset($options')&&str_contains($cli,"'apply'"));
$check('cli_supports_preflight',str_contains($cli,"'preflight'"));
$check('cli_supports_game_id',str_contains($cli,"'game-id:'")&&str_contains($cli,'--game-id=')&&str_contains($cli,'$gameId'));
$check('slug_is_compatibility_only',str_contains($cli,"'game:'")&&str_contains($cli,'SELECT id FROM ue_games WHERE slug=? LIMIT 1'));
$check('migration_uses_game_id_identity',str_contains($service,'public function preflight(int $gameId)')&&str_contains($service,'WHERE id=? LIMIT 1'));
$check('migration_storage_uses_stable_registry',str_contains($service,'Uedb5GameSourceRegistry::storageKey($gameId)')&&!str_contains($service,'verifiedDirectory((string)$game'));
$check('unreal_game_id_has_stable_unrealgold_source_key',str_contains($registry,"12 => 'unrealgold'")&&UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry::sourceKey(12)==='unrealgold');
$check('source_factory_supports_game_id',str_contains($factory,'contractForGameId(int $gameId)')&&str_contains($factory,'buildForGameId(int $gameId'));
$check('validation_source_path_uses_game_id_registry',str_contains($validator,'Uedb5GameSourceRegistry::storageKey($gameId)')&&str_contains($validator,'buildForGameId($gameId'));

$check('cli_loads_game_profile_helpers',str_contains($cli,"GameProfiles.php")&&str_contains($cli,"CatalogUE4ParserProfile.php")&&str_contains($cli,"CatalogUE5ParserProfile.php"));
$check('cli_supports_continuous',str_contains($cli,"'continuous'"));
$check('cli_supports_multiple_workers',str_contains($cli,"'workers::'")&&str_contains($cli,'proc_open')&&str_contains($cli,"'worker-index::'"));
$check('workers_partition_file_ids_without_overlap',str_contains($service,'MOD(f.id,?)=?')&&str_contains($service,'$workerCount, $workerIndex'));
$check('workers_start_at_lowest_remaining_partition_id',str_contains($service,'SELECT MIN(f.id)')&&str_contains($service,'first_remaining_file_id')&&str_contains($service,'$firstRemainingId - 1'));
$check('projection_retries_mysql_contention',str_contains($publisher,'PdoContention::retryable')&&str_contains($publisher,'$maxAttempts = $started ? 5 : 1'));
$check('search_dictionary_locks_use_deterministic_order',str_contains($publisher,'usort($searchRows')&&str_contains($publisher,'fingerprint'));
$check('file_projection_locks_use_deterministic_order',str_contains($publisher,'usort($nameRows')&&str_contains($publisher,'usort($objectRows'));
$check('shared_search_dictionary_uses_short_insert_only_batches',str_contains($publisher,'publishSearchDictionary($searchRows)')&&str_contains($publisher,'INSERT IGNORE INTO '));
$check('source_factory_does_not_reopen_package_for_profile_gate',
    !str_contains($factory,'gp_read_legacy_summary($path)')
    &&str_contains($factory,'profileAllowsCatalogRow($gameId, $file)')
    &&str_contains($factory,'assertParsedHeaderAllowed($gameId, $engineKey'));
$check('worker_parent_runs_preflight_once',str_contains($cli,'pool_preflight_start')&&str_contains($cli,'--skip-worker-preflight'));
$check('worker_pool_emits_immediate_spawn_feedback',str_contains($cli,'worker_spawned')&&str_contains($cli,'fflush(STDOUT)'));
$check('worker_pool_emits_heartbeats',str_contains($cli,'pool_heartbeat')&&str_contains($cli,'microtime(true)-$lastHeartbeat>=30.0'));
$check('worker_emits_boot_before_remaining_id_query',str_contains($service,"'status'=>'worker_boot'")&&str_contains($service,'bool $skipPreflight = false'));
$check('worker_pool_uses_direct_console_output',str_contains($cli,'1=>STDOUT')&&str_contains($cli,'2=>STDERR')&&!str_contains($cli,'stream_get_contents($stream)'));
$profileFixture=[
    'package_version_min'=>60,'package_version_max'=>69,
    'compatibility_rules_json'=>json_encode([[
        'detected_engine'=>'UE1','reader_engine'=>'UE1','package_version_min'=>70,'package_version_max'=>83,'label'=>'version override'
    ]],JSON_THROW_ON_ERROR),
];
$check('profile_native_range_is_accepted',!empty(gp_profile_version_decision($profileFixture,69,0,'UE1')['ok']));
$overrideDecision=gp_profile_version_decision($profileFixture,71,0,'UE1');
$check('profile_compatibility_rule_overrides_range',!empty($overrideDecision['ok'])&&is_array($overrideDecision['compatibility']));
$check('profile_rejects_version_outside_range_and_override',empty(gp_profile_version_decision($profileFixture,84,0,'UE1')['ok']));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
