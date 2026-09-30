#!/usr/bin/env php
<?php
/** Static contract for the Step 11 UEDB5 cutover gate. */
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5CutoverReadinessVerifier.php');
$cli=(string)file_get_contents($root.'/bin/verify-uedb5-cutover-ready.php');
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok){$failures[]=$name;}};
$withoutComments=static function(string $source):string{
    $out='';foreach(token_get_all($source) as $token){
        if(is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)){continue;}
        $out.=is_array($token)?$token[1]:$token;
    }return$out;
};
$serviceCode=$withoutComments($service);
$cliCode=$withoutComments($cli);

$check('cli_supports_database_gate',str_contains($cli,"'database'"));
$check('cli_reports_cutover_ready',str_contains($cli,"'cutover_ready'"));
$check('cli_emits_deep_progress',str_contains($cli,'deep_validation_progress')||str_contains($service,'deep_validation_progress'));
$check('verifier_is_read_only',preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i',$serviceCode)===0);
$check('requires_every_verified_v5',str_contains($service,'every_verified_file_has_staged_v5'));
$check('requires_format5_registration',str_contains($service,'every_verified_v5_registration_is_format5'));
$check('requires_step8_validated',str_contains($service,'every_verified_file_is_step8_validated'));
$check('requires_current_validator_policy',str_contains($service,'validated_status_uses_current_policy'));
$check('requires_current_validated_hash',str_contains($service,'validated_status_matches_current_v5_payload'));
$check('checks_invalid_file_exclusions',str_contains($service,'invalid_file_identities_are_excluded_from_v5'));
$check('checks_primary_provider_projection',str_contains($service,'every_verified_v5_has_primary_provider_key'));
$check('checks_dependency_count_before_deep_pass',str_contains($service,'classic_dependency_edge_count_matches_import_count'));
$check('checks_engine_source_contract_coverage',str_contains($service,'every_verified_game_has_v5_source_contract')&&str_contains($service,'every_verified_file_is_within_source_contract'));
$check('deep_revalidates_every_verified_file',str_contains($service,'Uedb5MigrationValidator')&&str_contains($service,'$validator->validate($fileId)'));
$check('deep_requires_dependency_ready',str_contains($service,'dependency_not_ready'));
$check('deep_requires_container_hash_integrity',str_contains($service,'every_v5_container_exists_and_hash_matches_registration'));
$check('deep_requires_dependency_projection_parity',str_contains($service,'dependency_rows_correspond_to_v5'));
$check('deep_requires_projection_parity',str_contains($service,'projections_correspond_to_v5'));
$check('gate_requires_no_v4_metadata_dependency',str_contains($service,'no_verified_file_requires_v4_metadata'));
$check('gate_requires_no_v4_only_blockers',str_contains($service,'no_v4_only_migration_blockers_remain'));
$check('source_contract_rejects_v4_fallback',str_contains($service,'v5_runtime_has_no_v4_fallback_literals'));
$check('source_contract_ignores_comments',str_contains($service,'T_DOC_COMMENT')&&str_contains($service,'T_COMMENT'));
$check('source_only_mode_does_not_claim_cutover_ready',str_contains($cli,'$cutoverReady=$withDatabase'));

echo json_encode([
    'ok'=>$failures===[],
    'checks'=>$checks,
    'failures'=>$failures,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
