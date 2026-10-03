<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$tool = (string)file_get_contents($root . '/bin/backfill-name-search-projection.php');
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$record(
    'uses_current_v4_format_constant',
    str_contains($tool, 'BlockedCompressedMetadataContainer::FORMAT_VERSION')
        && !str_contains($tool, 'format_version=3')
);
$record(
    'detects_partial_name_projection_drift',
    str_contains($tool, 'SELECT COUNT(*) FROM ue_name_lookup n WHERE n.file_id=f.id')
        && str_contains($tool, '<>f.name_count')
);
$record(
    'supports_game_scoping',
    str_contains($tool, '--game=')
        && str_contains($tool, 'SELECT id,name,slug FROM ue_games WHERE slug=? LIMIT 1')
);
$record(
    'repairs_from_authoritative_v4_names',
    str_contains($tool, 'page($fileId, \'names\'')
        && str_contains($tool, '$writer->writeNames($snapshot, $sqlBatches)')
        && str_contains($tool, 'CompactTermOverflowWriter')
);
$record(
    'verifies_repaired_row_count',
    str_contains($tool, 'SELECT COUNT(*) FROM ue_name_lookup WHERE file_id=?')
        && str_contains($tool, 'Name search projection count still differs after repair')
);
$record(
    'remaining_count_uses_same_drift_rule',
    substr_count($tool, 'SELECT COUNT(*) FROM ue_name_lookup n WHERE n.file_id=f.id') >= 2
        && substr_count($tool, '<>f.name_count') >= 2
);

$syntaxOk = false;
$pipes = [];
$process = proc_open([PHP_BINARY, '-l', $root . '/bin/backfill-name-search-projection.php'], [
    1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes);
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $syntaxOk = proc_close($process) === 0;
}
$record('php_syntax', $syntaxOk);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
