<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$scriptPath = $root . '/bin/repair-ue4-provider-present-missing.php';
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
$check('requires_exact_package_provider', str_contains($source, 'JOIN ue_package_providers p'));
$check('excludes_known_invalid_provider_bytes', str_contains($source, 'ue_invalid_file_identities'));
$check('does_not_require_export_prefilter', !str_contains($source, 'JOIN ue_export_lookup'));
$check('packages_are_batched_per_file', str_contains($source, '$packagesByFile[$owner][$package] = true'));
$check('uses_targeted_package_rebuild', str_contains($source, '$rebuilder->rebuildForPackages($fileId, $packages, false)'));
$check('bulk_refreshes_changed_summaries', str_contains($source, '$summaryWriter->rebuildFiles('));
$check('refreshes_game_stats_once', str_contains($source, 'PdoGameCatalogStats') && str_contains($source, '$apply && $rebuilt > 0'));
$syntax = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($scriptPath) . ' 2>&1') ?: '';
$check('php_syntax', str_contains($syntax, 'No syntax errors detected'), trim($syntax));

$result = ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
