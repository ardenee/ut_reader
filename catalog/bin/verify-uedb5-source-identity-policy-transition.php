#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);} $root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$tool=(string)file_get_contents($root.'/bin/transition-uedb5-source-identity-policy.php');
$impact=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoClassicSourceIdentityImpactQuery.php');
$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php');
$contract=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5SqlProjectionContract.php');
$hash=(string)file_get_contents($root.'/src/Infrastructure/Metadata/CatalogUnrealIdentityHash.php');
$overflow=(string)file_get_contents($root.'/src/Infrastructure/Metadata/CompactTermOverflowWriter.php');
$checks=[];$fail=[];$check=static function(string$n,bool$ok)use(&$checks,&$fail){$checks[$n]=$ok;if(!$ok)$fail[]=$n;};
$check('transition_moves_v1_or_v2_to_v3',str_contains($tool,"const OLD_POLICIES=['uedb5-dependency-pass-v1','uedb5-dependency-pass-v2']")&&str_contains($service,"DEPENDENCY_POLICY = 'uedb5-dependency-pass-v3'"));
$check('exact_classic_package_key_has_distinct_kind',str_contains($contract,'PACKAGE_KEY_CLASSIC_FNAME = 3')&&str_contains($tool,'PACKAGE_KEY_CLASSIC_FNAME'));
$check('section_2a_exact_key_is_limited_to_ue1_ue2_ue3',str_contains($contract,"str_starts_with(\$schema, 'ue1.')")&&str_contains($contract,"str_starts_with(\$schema, 'ue3.')")&&str_contains($contract,': self::PACKAGE_KEY_CLASSIC_NAME')&&str_contains($tool,'IN ("UE1","UE2","UE3")'));
$check('verifyimport_hash_algorithm_is_exact_text_revision',str_contains($hash,"SOURCE_FNAME_ALGORITHM = 'md5-fname-ci-v2-exact-text'")&&str_contains($hash,'sourceFnameBinary')&&str_contains($hash,'self::fnameKey($objectName)'));
$check('impact_uses_raw_import_identity_terms',str_contains($impact,'import_object_term_id')&&str_contains($impact,'import_class_name_term_id')&&str_contains($impact,'import_class_package_term_id'));
$check('impact_covers_provider_identity',str_contains($impact,'ue_uedb5_provider_keys')&&str_contains($impact,'ue_name_lookup')&&str_contains($impact,'provider_fname_normalization'));
$check('impact_covers_php_trim_byte_set_and_complete_overflow_values',str_contains($impact,"TRIM_HEX = ['20','09','0A','0D','00','0B']")&&str_contains($impact,'HEX(RIGHT(')&&str_contains($overflow,'Stores complete values for compact terms longer than the historical 200-byte prefix.'));
$check('impact_starts_from_exact_old_policy_file_ids_not_full_term_scan',str_contains($impact,'currentOldPolicyFiles')&&str_contains($impact,'file_id IN (')&&!str_contains($impact,'sensitiveTermIds'));
$check('v1_rechecks_ambiguity_but_v2_only_rebuilds_source_identity_changes',str_contains($tool,"\$identityWhy=array_values(array_filter(\$why,static fn(string\$x):bool=>\$x!=='provider_environment_ambiguity'))")&&str_contains($tool,"\$needs=\$policy==='uedb5-dependency-pass-v1'?(\$why!==[]):(\$identityWhy!==[])"));
$check('unaffected_rows_roll_forward_without_container_reads',str_contains($tool,'rolled_forward_unaffected')&&str_contains($tool,'UPDATE ue_uedb5_dependency_edges')&&str_contains($tool,'UPDATE ue_uedb5_dependency_packages')&&!str_contains($tool,'Uedb5MetadataReader'));
$check('exact_package_hash_uses_shared_php_fname_key_not_sql_lower',str_contains($tool,'classicPackageKeyBinary')&&!str_contains($tool,'MD5(LOWER(CONVERT('));
$check('impacted_rebuild_is_exact_file_and_skips_game_preflight_only_inside_transition',str_contains($tool,'$svc->runFile((int)$r[\'game_id\'],(int)$r[\'file_id\'],true,true)')&&str_contains($tool,"'rebuild-impacted'"));
$check('transition_is_read_only_by_default',strpos($tool,'if(!$apply)')!==false&&strpos($tool,'if(!$apply)')<strpos($tool,'$db->beginTransaction()'));
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fail===[]?0:1);
