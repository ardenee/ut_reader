#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/bin/repair-ue4-v4-package-name-unresolved.php';
$source = @file_get_contents($path);
$checks = [];
$failures = [];
$check = static function(string $name, bool $ok, string $detail='') use (&$checks,&$failures): void {
    $checks[] = ['check'=>$name,'ok'=>$ok,'detail'=>$detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': '.$detail : '');
};

$check('repair_script_exists', is_string($source) && $source !== '');
$source = is_string($source) ? $source : '';
$check(
    'selection_is_version520_plus_missing_only',
    str_contains($source, 'f.package_version>=520')
        && str_contains($source, 'l.status=0')
        && str_contains($source, 'm.format_version=?'),
    'Repair must not scan every UE4 file.'
);
$check(
    'detection_uses_sparse_import_graph_reads',
    str_contains($source, 'rowsByIndexes($fileId, \'imports\', $indexes)')
        && str_contains($source, 'if ($outer > 0)'),
    'Detection should inspect only missing Import ancestry.'
);
$check(
    'only_affected_files_are_rebuilt',
    str_contains($source, 'if (!$isAffected)')
        && str_contains($source, '$rebuilder->rebuild(')
        && str_contains($source, "'Repairing UE4 v4 PackageName dependency state'"),
    'Provider verification must run only after export-outer detection.'
);
$check(
    'repair_is_resumable',
    str_contains($source, "'after-id::'")
        && str_contains($source, "'resume_after_id'=>\$lastCompletedId"),
    'Interrupted work must expose a cursor to resume from.'
);
$check(
    'dry_run_is_default',
    str_contains($source, "\$apply = array_key_exists('apply', \$options)")
        && str_contains($source, 'if ($apply) {'),
    'Running without --apply must remain read-only.'
);
$check(
    'game_stats_refresh_once_after_apply',
    str_contains($source, 'if ($apply && $rebuilt > 0 && $failed === 0)')
        && str_contains($source, 'PdoGameCatalogStats($db))->rebuildGame($gameId, 15)'),
    'Game counters should refresh once after the targeted repair.'
);

$syntax = shell_exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1');
$check('php_syntax', is_string($syntax) && str_contains($syntax, 'No syntax errors detected'), trim((string)$syntax));
$result = ['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures];
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failures===[] ? 0 : 2);
