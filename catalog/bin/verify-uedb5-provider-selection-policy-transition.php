#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$tool = (string)file_get_contents($root . '/bin/transition-uedb5-provider-selection-policy.php');
$service = (string)file_get_contents($root . '/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php');
$checks = [];
$failures = [];
$check = static function(string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$check('transition_moves_v1_to_v2',
    str_contains($tool, "const OLD_POLICY = 'uedb5-dependency-pass-v1'")
    && str_contains($service, "public const DEPENDENCY_POLICY = 'uedb5-dependency-pass-v2'"));
$check('transition_requires_migrated_v5_schema',
    str_contains($tool, "'ue_uedb5_migration_status'")
    && str_contains($tool, "'ue_uedb5_dependency_edges'")
    && str_contains($tool, 'information_schema.tables'));
$check('impact_is_derived_from_existing_required_package_edges',
    str_contains($tool, 'FROM ue_uedb5_dependency_edges e')
    && str_contains($tool, 'e.required_package_key_kind')
    && str_contains($tool, 'e.required_package_key'));
$check('duplicate_provider_identity_requires_multiple_physical_files',
    str_contains($tool, 'HAVING COUNT(DISTINCT p.file_id)>1'));
$check('candidate_filter_matches_authoritative_selector_boundary',
    str_contains($tool, 'pf.scan_status="verified"')
    && str_contains($tool, 'ue_invalid_file_identities bad')
    && str_contains($tool, 'bad.file_size=pf.file_size')
    && str_contains($tool, 'bad.md5=LOWER(pf.md5)')
    && str_contains($tool, 'bad.sha1=LOWER(pf.sha1)'));
$check('only_current_v1_payloads_are_rollforward_eligible',
    str_contains($tool, 's.dependency_policy=?')
    && str_contains($tool, 's.dependency_payload_sha256=v.payload_sha256'));
$check('impacted_files_are_excluded_from_rollforward',
    str_contains($tool, "' AND NOT ' . \$impactExistsSql")
    && str_contains($tool, "'impacted_file_ids_by_game'"));
$check('stale_payloads_are_reported_not_rollforwarded',
    str_contains($tool, "'old_policy_stale_payload_count'")
    && str_contains($tool, 's.dependency_payload_sha256<>v.payload_sha256'));
$check('default_execution_is_read_only',
    strpos($tool, 'if (!$apply)') !== false
    && strpos($tool, 'if (!$apply)') < strpos($tool, 'UPDATE ue_uedb5_migration_status'));
$check('impacted_rebuild_is_exact_file_only_and_bounded',
    str_contains($tool, "'rebuild-impacted'")
    && str_contains($tool, 'array_slice($impactedRows, 0, $limit)')
    && str_contains($tool, '$service->runFile($gid, $fid, true)'));
$check('transition_does_not_scan_or_reparse_package_bytes',
    !str_contains($tool, 'Uedb5MetadataReader')
    && !str_contains($tool, 'SourceSnapshot')
    && !str_contains($tool, '.uedb5'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures===[] ? 0 : 1);
