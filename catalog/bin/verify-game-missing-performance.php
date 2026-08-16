#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for the game-missing responsiveness contract. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = @file_get_contents($path);
    return is_string($source) ? $source : '';
};
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$files = [
    'game-missing.php',
    'src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($files as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('game-missing.php');
$query = $read('src/Infrastructure/Persistence/PdoGameMissingDependencyQuery.php');

$sessionClose = strpos($page, 'session_write_close();');
$firstHeavyRead = strpos($page, '$missingQuery->totals(');
$record(
    'session_lock_released_before_dependency_queries',
    $sessionClose !== false && $firstHeavyRead !== false && $sessionClose < $firstHeavyRead,
    'Admin authentication may use the session, but dependency aggregation must run after the PHP session lock is released.'
);

$record(
    'page_does_not_use_textual_dependency_view',
    !str_contains($page, 'PdoDependencyReadSource')
        && !str_contains($page, 'base_game_package_exists_sql')
        && str_contains($page, 'PdoGameMissingDependencyQuery'),
    'game-missing.php must not filter the large compatibility dependency view or correlated base-game SQL.'
);

$record(
    'totals_use_one_summary_projection_scan',
    str_contains($query, 'COALESCE(SUM(s.missing_count),0) missing_objects')
        && str_contains($query, 'COUNT(DISTINCT s.required_package) missing_packages')
        && str_contains($query, 'COUNT(DISTINCT s.file_id) files_with_missing')
        && substr_count($query, 'FROM ue_dependency_package_summaries s WHERE') >= 3,
    'Summary totals should be calculated together from the package-summary projection rather than three independent full dependency scans.'
);

$record(
    'base_game_scope_is_precomputed_once',
    str_contains($query, 'officialBaseGamePackageNames')
        && str_contains($query, 'FROM ue_base_game_files b')
        && str_contains($query, '.required_package IN (')
        && !str_contains($query, 'LOWER(TRIM('),
    'Official package identities should be loaded once, then applied as an indexed summary-package scope without correlated LOWER/TRIM expressions.'
);

$record(
    'package_detail_uses_exact_compact_term_identity',
    str_contains($query, 'WHERE value_hash=? AND value_length=? LIMIT 1')
        && str_contains($query, 'l.required_package_term_id=?')
        && str_contains($query, 'l.status=0')
        && str_contains($query, 'FROM ue_dependency_links l')
        && !str_contains($query, 'PdoDependencyReadSource::sql'),
    'A selected package must resolve to one ue_terms ID and query compact dependency links directly.'
);

$record(
    'detail_count_prefers_package_summary',
    str_contains($query, 'SELECT COALESCE(SUM(missing_count),0) FROM ue_dependency_package_summaries')
        && str_contains($query, 'required_package=?'),
    'The package-detail count should come from the compact package summary when available instead of counting expanded dependency text rows.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
