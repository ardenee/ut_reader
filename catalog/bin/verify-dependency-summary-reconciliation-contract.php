#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repairPath = $root . '/bin/reconcile-dependency-package-summaries.php';
$repair = file_get_contents($repairPath) ?: '';
$stats = file_get_contents($root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php') ?: '';
$failures = [];
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[] = ['check'=>$name,'ok'=>$ok];
    if (!$ok) $failures[] = $name;
};

$check('repair_script_exists', $repair !== '');
$check('dry_run_is_default', str_contains($repair, "array_key_exists('apply', \$options)"));
$check('repair_is_resumable', str_contains($repair, "'after-id::'") && str_contains($repair, "'resume_after_id'"));
$check('rebuilds_current_verified_format_only', str_contains($repair, 'm.format_version=?') && str_contains($repair, 'f.scan_status="verified"'));
$check('uses_bounded_bulk_summary_api', str_contains($repair, '$summaryWriter->rebuildFiles($ids)'));
$check('removes_stale_summary_rows_only_on_finalize', str_contains($repair, 'DELETE s FROM ue_dependency_package_summaries s') && str_contains($repair, '$finalized'));
$check('stats_refresh_only_after_full_reconciliation', str_contains($repair, '$limit === 0') && str_contains($repair, 'PdoGameCatalogStats'));
$check('reports_authoritative_vs_summary_parity', str_contains($repair, "'missing_count_parity'") && str_contains($repair, "'authoritative_links'"));
$check('refuses_zero-current-format_destructive_apply', str_contains($repair, 'Refusing to reconcile: no current-format verified files were found'));
$check('game_stats_ignore_retired_summary_files', substr_count($stats, 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=') >= 3);
$check('game_stats_require_verified_summary_owners', substr_count($stats, 'f.scan_status="verified"') >= 3);

$syntaxRepair = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($repairPath) . ' 2>&1') ?: '';
$syntaxStats = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/src/Infrastructure/Persistence/PdoGameCatalogStats.php') . ' 2>&1') ?: '';
$check('repair_php_syntax', str_contains($syntaxRepair, 'No syntax errors detected'));
$check('stats_php_syntax', str_contains($syntaxStats, 'No syntax errors detected'));

echo json_encode([
    'ok'=>$failures === [],
    'checks'=>$checks,
    'failures'=>$failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
