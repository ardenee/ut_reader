<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);} $root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$tool=(string)file_get_contents($root.'/bin/transition-uedb5-source-identity-policy.php');
$impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoClassicSourceIdentityImpactQuery.php');
$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php');
$contract=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5SqlProjectionContract.php');
$hash=(string)file_get_contents($root.'/src/Infrastructure/Metadata/CatalogUnrealIdentityHash.php');
$overflow=(string)file_get_contents($root.'/src/Infrastructure/Metadata/CompactTermOverflowWriter.php');
$ue1Impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe1VerifyImportImpactQuery.php');
$ue3Impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe3VerifyImportImpactQuery.php');
$ue2Impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe2VerifyImportImpactQuery.php');
$ue4Impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe4VerifyImportImpactQuery.php');
$ue5Impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe5ClassicVerifyImportImpactQuery.php');
$zenImpact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe5ZenDependencyImpactQuery.php');
$pass1=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameSourceMigrationService.php');
$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('transition_moves_v1_through_v11_to_v12',str_contains($tool,"const OLD_POLICIES=['uedb5-dependency-pass-v1','uedb5-dependency-pass-v2','uedb5-dependency-pass-v3','uedb5-dependency-pass-v4','uedb5-dependency-pass-v5','uedb5-dependency-pass-v6','uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10','uedb5-dependency-pass-v11']")&&str_contains($service,"DEPENDENCY_POLICY = 'uedb5-dependency-pass-v12'"));
$check('exact_classic_package_key_has_distinct_kind',str_contains($contract,'PACKAGE_KEY_CLASSIC_FNAME = 3')&&str_contains($tool,'PACKAGE_KEY_CLASSIC_FNAME'));
$check('all_classic_linkerload_schemas_use_exact_fname_key',str_contains($contract,"str_starts_with(\$schema, 'ue1.')")&&str_contains($contract,"str_starts_with(\$schema, 'ue4.')")&&str_contains($contract,"str_starts_with(\$schema, 'ue5.')")&&str_contains($contract,'? self::PACKAGE_KEY_CLASSIC_FNAME'));
$check('verifyimport_hash_algorithm_is_exact_text_revision',str_contains($hash,"SOURCE_FNAME_ALGORITHM = 'md5-fname-ci-v2-exact-text'")&&str_contains($hash,'sourceFnameBinary')&&str_contains($hash,'self::fnameKey($objectName)'));
$check('impact_uses_raw_import_identity_terms',str_contains($impact,'import_object_term_id')&&str_contains($impact,'import_class_name_term_id')&&str_contains($impact,'import_class_package_term_id'));
$check('modern_package_name_loss_is_conservatively_covered_by_namemap',str_contains($impact,'consumer_modern_namemap_normalization')&&str_contains($impact,'sensitiveNameMapFiles($modernIds)')&&str_contains($impact,"\$engineKey==='UE4'")&&str_contains($impact,"\$engineKey==='UE5'"));
$check('zen_is_excluded_from_classic_fname_transition',str_contains($impact,"UE5_CLASSIC_FAMILY = 'classic-linkerload'")&&str_contains($impact,"\$engineKey==='UE5'&&\$packageFamily===self::UE5_CLASSIC_FAMILY"));
$check('impact_covers_provider_identity',str_contains($impact,'ue_uedb5_provider_keys')&&str_contains($impact,'ue_name_lookup')&&str_contains($impact,'provider_fname_normalization'));
$check('impact_covers_php_trim_byte_set_and_complete_overflow_values',str_contains($impact,"TRIM_HEX = ['20','09','0A','0D','00','0B']")&&str_contains($impact,'HEX(RIGHT(')&&str_contains($impact,'t.is_overflow=1')&&str_contains($impact,'<>t.value_length')&&str_contains($overflow,'Stores complete values for compact terms longer than the historical 200-byte prefix.'));
$check('impact_starts_from_exact_old_policy_file_ids_not_full_term_scan',str_contains($impact,'currentOldPolicyFiles')&&str_contains($impact,'file_id IN (')&&!str_contains($impact,'sensitiveTermIds'));
$check('policy_specific_rebuild_rules_are_preserved',
    str_contains($tool,"'uedb5-dependency-pass-v1'=>\$identityWhy")
    && str_contains($tool,"'uedb5-dependency-pass-v2'=>\$identityWithoutAmbiguity")
    && str_contains($tool,"'uedb5-dependency-pass-v3'=>\$modern?\$identityWithoutAmbiguity:[]")
    && str_contains($tool,'default=>[]')
    && str_contains($tool,"array_intersect(\$ue1Why,['ut99_mesh_rehack'])")
    && str_contains($tool,"'uedb5-dependency-pass-v11'=>[]")
);
$check('unaffected_rows_roll_forward_without_container_reads',str_contains($tool,'rolled_forward_unaffected')&&str_contains($tool,'UPDATE ue_uedb5_dependency_edges')&&str_contains($tool,'UPDATE ue_uedb5_dependency_packages')&&!str_contains($tool,'Uedb5MetadataReader'));
$check('exact_package_hash_uses_shared_php_fname_key_not_sql_lower',str_contains($tool,'classicPackageKeyBinary')&&!str_contains($tool,'MD5(LOWER(CONVERT('));
$check('v12_impacted_rebuild_is_exact_file_and_skips_game_preflight_only_inside_transition',str_contains($tool,'$svc->runFile((int)$r[\'game_id\'],$fid,true,true)')&&str_contains($tool,"'rebuild-impacted'"));
$check('live_v4_refresh_is_optional_exact_file_only',str_contains($tool,"'rebuild-v4-impacted'")&&str_contains($tool,'$v4->rebuild((int)$r[\'file_id\']')&&str_contains($tool,'PdoGameCatalogStats'));
$check('ue1_transition_is_bounded_to_file_indexed_staged_edges',str_contains($ue1Impact,'e.file_id=v.file_id')&&str_contains($ue1Impact,'e.outcome IN (0,4)')&&str_contains($ue1Impact,'l.file_id IN (')&&str_contains($ue1Impact,'import_class_name_term_id'));
$check('pre50_ue1_requires_exact_pass1_restage',str_contains($ue1Impact,'ue1_pre50_pass1_reparse')&&str_contains($tool,"'reparse-pass1-impacted'")&&str_contains($pass1,'public function runFile(int $gameId, int $fileId, bool $apply)'));
$check('later_ue1_source_is_not_inherited',
    str_contains($ue1Impact,'ue1_source_implementation_unavailable')
    && str_contains($tool,'blocked_prerequisites')
    && str_contains($tool,"'pass1_reparse_required'")
);
$check('ue2_transition_is_object_edge_bounded_and_includes_ut2004',str_contains($ue2Impact,'required_object_key IS NOT NULL')&&str_contains($ue2Impact,"'ue2-unreal2-'")&&str_contains($ue2Impact,"'ue2-ut2003-'")&&str_contains($ue2Impact,"'ue2-ut2004-'")&&str_contains($ue2Impact,'ue2_ut2004_verifyimport_profile_change'));
$check('v6_only_rebuilds_new_ut2004_delta',str_contains($tool,"'uedb5-dependency-pass-v6'=>array_values(array_filter(\$ue2Why")&&str_contains($tool,"str_starts_with(\$x,'ue2_ut2004_')"));
$check('unreal2_v69_requires_exact_pass1_restage',str_contains($ue2Impact,'ue2_unreal2_v69_pass1_reparse')&&str_contains($tool,"'ue2_unreal2_v69_pass1_reparse'"));
$check('v5_ue1_only_rebuilds_new_mesh_delta',str_contains($tool,"'uedb5-dependency-pass-v5'=>array_values(array_intersect(\$ue1Why,['ut99_mesh_rehack']))"));
$check('v7_only_rebuilds_new_ut3_delta',str_contains($tool,"'uedb5-dependency-pass-v7'=>array_values(array_filter(\$ue3Why")&&str_contains($tool,"str_starts_with(\$x,'ue3_ut3_')")&&str_contains($ue3Impact,'required_object_key IS NOT NULL'));
$check('v12_reopens_ut4_for_clean_master_delta',
    str_contains($tool,'$ue4Effective=$ue4Why')
    && str_contains($ue4Impact,'ue4_ut4_verifyimport_outcome_change')
    && str_contains($ue4Impact,'ue4_ut4_bad_v510_pass1_repair_required')
    && str_contains($ue4Impact,'ue4_ut4_source_policy_refresh_required')
    && str_contains($tool,"'ue4_ut4_bad_v510_pass1_repair_required'")
    && str_contains($ue4Impact,'ue_export_path_lookup')
);
$check('completed_ue5_classic_delta_is_not_replayed_by_v10_or_v11',
    str_contains($tool,"\$ue5Effective=in_array(\$policy,['uedb5-dependency-pass-v10','uedb5-dependency-pass-v11'],true)?[]:\$ue5Why")
    && str_contains($ue5Impact,'ue5_classic_verifyimport_outcome_change')
    && str_contains($ue5Impact,'ue5_classic_private_package_access_recheck')
);
$check('completed_ue5_zen_delta_is_not_replayed_by_v11',
    str_contains($tool,"\$zenEffective=\$policy==='uedb5-dependency-pass-v11'?[]:\$zenWhy")
    && str_contains($zenImpact,'ue5_zen_dependency_v3_outcome_change')
    && str_contains($zenImpact,'PACKAGE_KEY_ZEN_PACKAGE_ID')
    && str_contains($zenImpact,'DEP_SOURCE_CELL_IMPORT')
    && str_contains($zenImpact,'DEP_SOURCE_LOAD_ORDER')
);
$check('later_policies_do_not_replay_completed_legacy_deltas',
    str_contains($tool,"'uedb5-dependency-pass-v6','uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10','uedb5-dependency-pass-v11'=>[]")
    && str_contains($tool,"'uedb5-dependency-pass-v7','uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10','uedb5-dependency-pass-v11'=>[]")
    && str_contains($tool,"'uedb5-dependency-pass-v8','uedb5-dependency-pass-v9','uedb5-dependency-pass-v10','uedb5-dependency-pass-v11'=>[]")
);
$check('transition_is_read_only_by_default',strpos($tool,'if(!$apply)')!==false&&strpos($tool,'if(!$apply)')<strpos($tool,'$db->beginTransaction()'));
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
