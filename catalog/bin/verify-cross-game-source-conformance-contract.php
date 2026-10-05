#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
$source=(string)file_get_contents($root.'/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php');
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};
$check('cross_game_ue1_ue2_use_profiled_verify_import',
    str_contains($source,'PdoUe1VerifyImportProjectionResolver::resolveProviderOutcome')
    && str_contains($source,'PdoUe2VerifyImportProjectionResolver::resolveProviderOutcome')
    && str_contains($source,'ue1VerifyImportProfile')
    && str_contains($source,'ue2VerifyImportProfile'));
$check('cross_game_legacy_ue2_path_is_ut2004_only',
    str_contains($source,"return \$sourceKey === 'ut2004' ? 'standard' : null;")
    && str_contains($source,'PdoLegacyVerifyImportProjectionResolver::resolveProviderVariants'));
$check('cross_game_source_unverified_ue1_ue2_fail_closed',
    str_contains($source,"\$targetEngine === 'UE1' && \$ue1Profile !== null")
    && str_contains($source,"\$targetEngine === 'UE2' && \$ue2Profile !== null")
    && str_contains($source,'cannot be certified or queued as dependency-complete here'));
$check('cross_game_ue3_uses_verify_import',
    str_contains($source,'PdoUe3VerifyImportProjectionResolver::resolveProvider'));
$check('cross_game_ue4_uses_verify_import',
    str_contains($source,'PdoUe4VerifyImportProjectionResolver::resolveProviderOutcome')
    && str_contains($source,'$this->consumerExports($consumerId)'));
$check('cross_game_does_not_use_generic_coverage_as_semantics',
    !str_contains($source,'PdoPackageObjectCoverageResolver')
    && !str_contains($source,'genericCoverageInputs'));
$check('cross_game_unsupported_profile_fails_closed',
    str_contains($source,'cannot be certified or queued as dependency-complete here'));
$check('cross_game_queue_requires_source_complete_consumer',
    str_contains($source,"return (int)(\$row['complete_consumer_count'] ?? 0) > 0 ? \$row : null;"));
$check('cross_game_ue4_loads_consumer_exports_only_when_needed',
    str_contains($source,'loadDependencySnapshot($consumerFileId, true)'));
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
