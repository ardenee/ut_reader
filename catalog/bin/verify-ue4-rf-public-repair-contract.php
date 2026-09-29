<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$scriptPath = $root . '/bin/repair-ue4-rf-public-dependencies.php';
$source = is_file($scriptPath) ? (file_get_contents($scriptPath) ?: '') : '';
$checks = [];
$failures = [];
$check = static function(string $name, bool $ok, string $detail='') use (&$checks,&$failures): void {
    $checks[] = ['check'=>$name,'ok'=>$ok,'detail'=>$detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$check('repair_script_exists', $source !== '');
$check('dry_run_is_default', str_contains($source, "array_key_exists('apply', \$options)"));
$check('repair_is_resumable', str_contains($source, "'after-id::'"));
$check('requires_current_missing_rows', str_contains($source, 'l.status=0'));
$check('requires_exact_provider_package', str_contains($source, 'ue_package_providers'));
$check('requires_exact_relative_path', str_contains($source, 'e.path_hash=l.required_path_hash'));
$check('detects_ue4_public_bit', str_contains($source, '(ep.object_flags & 1)<>0'));
$check('detects_old_wrong_native_bit', str_contains($source, '(ep.object_flags & 4)=0'));
$check(
    'authoritative_rebuilder_owns_writes',
    str_contains($source, 'PdoCatalogDependencyRebuilder')
        && str_contains($source, '$rebuilder->rebuild(')
);
$check(
    'schema_aware_identity_prefilter',
    str_contains($source, "SHOW COLUMNS FROM ue_export_path_lookup")
        && str_contains($source, '$hasExactIdentityColumns')
);
$check(
    'game_stats_refresh_after_apply',
    str_contains($source, 'PdoGameCatalogStats')
        && str_contains($source, '$apply && $rebuilt > 0')
);
$diagSource = file_get_contents($root . '/lib/CatalogDependencyDiagnostics.php') ?: '';
$check(
    'ue4_diagnostic_uses_current_export_projection',
    str_contains($diagSource, "\$engine==='UE3'||\$engine==='UE4'")
        && str_contains($diagSource, 'ue_export_path_lookup')
);
$syntax = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($scriptPath) . ' 2>&1') ?: '';
$check('php_syntax', str_contains($syntax, 'No syntax errors detected'), trim($syntax));

$result = ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
