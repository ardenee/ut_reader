#!/usr/bin/env php
<?php
/** Regression contract for ue_terms AUTO_INCREMENT conservation and recovery. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$lookup = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$search = $read('src/Infrastructure/Metadata/CompactSearchProjectionWriter.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$repair = $read('bin/repair-ue-terms-auto-increment.php');

$record(
    'lookup_dictionary_resolves_before_insert',
    strpos($lookup, '$this->resolveTermSet($terms, $terms, $resolved, $sqlBatches);')
        < strpos($lookup, '$missing = array_filter(')
        && str_contains($lookup, 'static fn(array $term, string $key): bool => !isset($resolved[$key])')
        && str_contains($lookup, '$this->resolveTermSet($missing, $terms, $resolved, $sqlBatches);'),
    'Full compact publication must read existing ue_terms first, insert only missing terms, then resolve the missing subset.'
);

$record(
    'search_dictionary_resolves_before_insert',
    strpos($search, '$this->resolveTermSet($terms, $terms, $resolved, $sqlBatches);')
        < strpos($search, '$missing = array_filter(')
        && str_contains($search, 'static fn(array $term, string $key): bool => !isset($resolved[$key])')
        && str_contains($search, '$this->resolveTermSet($missing, $terms, $resolved, $sqlBatches);'),
    'Standalone search projection repair must not burn AUTO_INCREMENT IDs on already-existing dictionary terms.'
);

$record(
    'auto_increment_exhaustion_has_actionable_error',
    str_contains($lookup, '$mysqlCode === 1467')
        && str_contains($lookup, 'ue_terms AUTO_INCREMENT cannot allocate another term ID')
        && str_contains($lookup, 'repair-ue-terms-auto-increment.php --apply'),
    'MySQL error 1467 must identify the exhausted dictionary allocator and the recovery command.'
);

$record(
    'repair_is_dry_run_by_default_and_refuses_running_workers',
    str_contains($repair, "getopt('', ['apply', 'force'])")
        && str_contains($repair, 'SELECT COUNT(*) row_count,COALESCE(MAX(id),0) max_id FROM ue_terms')
        && str_contains($repair, 'information_schema.TABLES')
        && str_contains($repair, 'status="running"')
        && str_contains($repair, 'Refusing AUTO_INCREMENT repair while background jobs are running')
        && str_contains($repair, 'ALTER TABLE ue_terms AUTO_INCREMENT='),
    'Recovery must inspect first, require explicit --apply, and avoid changing the allocator beneath active workers.'
);

$record(
    'repair_refuses_uint32_exhausted_live_ids',
    str_contains($repair, '$uint32Max = 4294967295')
        && str_contains($repair, 'migration 202609040001')
        && str_contains($repair, 'BIGINT UNSIGNED'),
    'Resetting AUTO_INCREMENT is valid only while MAX(id)+1 still fits INT UNSIGNED; a full live-ID exhaustion must direct the operator to the coordinated BIGINT migration.'
);

$record(
    'worker_fingerprint_tracks_dictionary_writers',
    str_contains($fingerprint, 'CompressedMetadataLookupWriter.php')
        && str_contains($fingerprint, 'CompactSearchProjectionWriter.php'),
    'Detached workers must be considered stale when either term-dictionary writer changes.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    $root . '/src/Infrastructure/Metadata/CompactSearchProjectionWriter.php',
    $root . '/bin/repair-ue-terms-auto-increment.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
