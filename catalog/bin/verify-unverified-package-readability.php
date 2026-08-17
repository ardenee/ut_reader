#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for unreadable Upload Bucket package reporting. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $data = @file_get_contents($path);
    return is_string($data) ? $data : '';
};

$phpFiles = [
    'unverified-files.php',
    'src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
    'src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php',
    'src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php',
];
$syntaxFailures = [];
foreach ($phpFiles as $relative) {
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('unverified-files.php');
$pageQuery = $read('src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php');
$cache = $read('src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php');
$handler = $read('src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php');

$record(
    'list_query_exposes_package_parse_failure',
    str_contains($pageQuery, 'f.scan_notes')
        && str_contains($pageQuery, "'package_parse_error'")
        && str_contains($pageQuery, 'Unverified table parse failed:'),
    'The list read model must carry the persisted package-reader failure instead of reducing it to zero table counts.'
);

$record(
    'list_does_not_present_failed_tables_as_empty',
    str_contains($page, 'Package tables unreadable')
        && str_contains($page, 'N/I/E unavailable')
        && str_contains($page, 'GUID: ')
        && str_contains($page, "'unavailable'")
        && str_contains($page, '<strong>Parser issue</strong>'),
    'Unreadable package tables must be shown as unavailable with the actual parser issue, not as a healthy 0 / 0 / 0 inventory.'
);

$record(
    'dependency_evidence_is_not_claimed_for_unreadable_package',
    str_contains($page, 'Package tables could not be read, so exact dependency evidence was not calculated.')
        && str_contains($handler, 'packageParseError')
        && str_contains($handler, '$this->cache->storeFailed($fileId, $message);')
        && str_contains($handler, "'status' => 'unavailable'"),
    'A file whose package tables are unreadable must complete its match child as unavailable and cache no false ready result.'
);

$parseCheck = strpos($handler, '$parseError = $this->packageParseError');
$matcherCall = strpos($handler, '$matches = $this->matcher->one($fileId);');
$record(
    'parse_failure_gate_runs_before_matcher',
    $parseCheck !== false && $matcherCall !== false && $parseCheck < $matcherCall,
    'The persisted package-reader failure must be checked before exact object-path matching begins.'
);

$record(
    'cache_summary_overrides_stale_ready_rows',
    str_contains($cache, 'Unverified table parse failed:')
        && str_contains($cache, 'failed_count')
        && str_contains($cache, 'missing_count'),
    'The Upload Bucket cache summary must count parser-failed packages as failed/unavailable even if an older worker left a stale ready cache row.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
