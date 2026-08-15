#!/usr/bin/env php
<?php
/**
 * Read-only source contract for production performance invariants.
 *
 * These checks intentionally guard implementation shape rather than benchmark
 * absolute timings, which vary by host/storage/database state. Runtime impact is
 * measured separately through Workload Tracing and real queue/import workloads.
 */
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

$cache = $read('src/Infrastructure/Cache/CatalogPublicResponseCacheService.php');
$record(
    'anonymous_home_is_short_cached',
    str_contains($cache, "'index.php:home'")
        && str_contains($cache, ': 15;')
        && str_contains($cache, "'index.php:search'"),
    'anonymous home/search traffic must be cacheable with short configurable TTLs'
);
$record(
    'cache_hits_stream_body',
    str_contains($cache, 'self::readMeta($path)')
        && str_contains($cache, 'fpassthru($stream)')
        && str_contains($cache, 'private static function servePath('),
    'cache HIT/STALE must not materialize the complete HTML body in PHP memory'
);
$record(
    'cache_pruning_is_rate_limited',
    str_contains($cache, 'PRUNE_INTERVAL_SECONDS')
        && str_contains($cache, "'.prune.lock'")
        && str_contains($cache, 'LOCK_EX | LOCK_NB')
        && !str_contains($cache, 'random_int(1, 100)'),
    'request volume must not translate into probabilistic full cache-directory scans'
);
$lengthPos = strpos($cache, '$bodyLength = ob_get_length()');
$contentsPos = strpos($cache, '$body = ob_get_contents()');
$record(
    'oversized_response_copy_is_avoided',
    $lengthPos !== false && $contentsPos !== false && $lengthPos < $contentsPos,
    'cache publisher must reject oversized buffers before copying them to a PHP string'
);

$container = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php');
$reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
$snapshotWriter = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$record(
    'compact_verification_streams_from_disk',
    str_contains($container, 'public static function verifyFile(')
        && str_contains($container, "hash_init('sha256')")
        && str_contains($container, 'hash_update($hash, $compressed)')
        && str_contains($reader, 'BlockedCompressedMetadataContainer::verifyFile('),
    'physical .uedb2 verification must keep only one block/decoded payload in memory'
);
$record(
    'compact_reader_avoids_whole_file_verify_copy',
    !str_contains($reader, '$bytes = file_get_contents(')
        && !str_contains($reader, 'verifyBytes($bytes'),
    'metadata health checks must not allocate the complete container as a PHP string'
);
$record(
    'snapshot_temp_write_uses_streaming_hash',
    str_contains($snapshotWriter, "hash_file('sha256', \$temporaryPath, true)")
        && !str_contains($snapshotWriter, '$temporaryBytes = file_get_contents('),
    'temporary publication verification must not reread/decompress a second full container copy'
);

$lookup = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$record(
    'compact_terms_are_resolved_once',
    str_contains($lookup, 'public function primeSnapshotTerms(array $snapshot, int &$sqlBatches): array')
        && str_contains($lookup, '?array $resolvedTermIds = null')
        && str_contains($snapshotWriter, '$resolvedTermIds = $lookupWriter->primeSnapshotTerms(')
        && str_contains($snapshotWriter, '$lookupWriter->writeVersioned(')
        && substr_count($snapshotWriter, '$resolvedTermIds') >= 3,
    'normal publication must reuse the dictionary IDs resolved before its transaction'
);
$record(
    'compact_term_source_is_generator',
    str_contains($lookup, 'private function snapshotTermValues(array $snapshot): \\Generator')
        && str_contains($lookup, 'private function resolveTermIds(iterable $values,'),
    'repeated source strings must not first be duplicated into a flat values array'
);
$record(
    'projection_rows_are_bounded',
    str_contains($lookup, 'count($exportRows) >= self::WRITE_BATCH_SIZE')
        && str_contains($lookup, 'count($dependencyRows) >= self::WRITE_BATCH_SIZE')
        && str_contains($lookup, 'Compact lookup insert batch exceeded the bounded row limit.')
        && !str_contains($lookup, 'private function bulkInsert('),
    'export/dependency projection memory must be bounded independently of package size'
);

$overflow = $read('src/Infrastructure/Metadata/CompactTermOverflowWriter.php');
$record(
    'overflow_terms_are_batched',
    str_contains($overflow, 'private const BATCH_SIZE = 200')
        && str_contains($overflow, 'private function writeBatch(')
        && str_contains($overflow, 'UPDATE ue_terms SET value_prefix=CASE ')
        && !str_contains($overflow, 'ON DUPLICATE KEY UPDATE'),
    'long terms must use bounded batched validation/update/verification without auto-increment churn'
);

$search = $read('src/Infrastructure/Search/PdoCatalogSearchRepository.php');
$record(
    'search_scans_stream_rows',
    str_contains($search, 'while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false)')
        && !str_contains($search, 'fetchAll(PDO::FETCH_ASSOC)'),
    'bounded candidate scans must not add a second PHP array containing every returned row'
);

$guard = $read('src/Infrastructure/Persistence/PdoJobAdmissionGuard.php');
$claimer = $read('src/Infrastructure/Persistence/PdoJobClaimer.php');
$record(
    'worker_admission_reuses_prepared_statements',
    str_contains($guard, 'private ?PDOStatement $acquireLockStatement')
        && str_contains($guard, 'private ?PDOStatement $runningResourceStatement')
        && str_contains($claimer, 'private readonly PdoJobAdmissionGuard $admissionGuard')
        && str_contains($claimer, '$guard = $this->admissionGuard;'),
    'worker hot loop must reuse admission guard/prepared statements across claims'
);
$record(
    'resource_admission_uses_indexed_column_directly',
    str_contains($guard, 'status="running" AND resource_class=?')
        && !str_contains($guard, 'COALESCE(NULLIF(resource_class')
        && str_contains($claimer, "'j.resource_class NOT IN ('"),
    'resource-class admission should leave the indexed column unwrapped'
);

$criticalPhp = [
    'bin/verify-performance-contract.php',
    'src/Infrastructure/Cache/CatalogPublicResponseCacheService.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Metadata/CompactTermOverflowWriter.php',
    'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
    'src/Infrastructure/Search/PdoCatalogSearchRepository.php',
    'src/Infrastructure/Persistence/PdoJobAdmissionGuard.php',
    'src/Infrastructure/Persistence/PdoJobClaimer.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open unavailable; run php -l manually on performance-pass files');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
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
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
