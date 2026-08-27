#!/usr/bin/env php
<?php
/** Read-only source contract for production performance invariants. */
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
    str_contains($cache, "'index.php:home'") && str_contains($cache, ': 15;'),
    'anonymous home traffic must avoid PHP/MySQL regeneration on every request'
);
$record(
    'search_cache_cardinality_is_bounded',
    str_contains($cache, 'DEFAULT_SEARCH_CACHE_SLOTS = 4096')
        && str_contains($cache, "'public_search_cache_slots'")
        && str_contains($cache, "'search-' . str_pad(dechex(\$slot)")
        && str_contains($cache, "'key_hash'")
        && str_contains($cache, 'matchesIdentity('),
    'arbitrary search terms must not create an unbounded file-cache namespace or serve slot collisions'
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
$invalidateStart = strpos($cache, 'public static function invalidate(array $config): int');
$pruneStart = strpos($cache, 'public static function pruneDirectory(', $invalidateStart === false ? 0 : $invalidateStart);
$invalidateBody = $invalidateStart !== false && $pruneStart !== false && $pruneStart > $invalidateStart
    ? substr($cache, $invalidateStart, $pruneStart - $invalidateStart)
    : '';
$record(
    'cache_invalidation_is_constant_time',
    $invalidateBody !== ''
        && str_contains($invalidateBody, "'.generation'")
        && str_contains($invalidateBody, 'LOCK_EX')
        && !str_contains($invalidateBody, 'FilesystemIterator')
        && !str_contains($invalidateBody, '@unlink(')
        && str_contains($cache, 'private static function generationToken(')
        && str_contains($cache, '$generation . "\\n" . $script . "\\n" . $query'),
    'settings changes must re-key the public cache in O(1), never walk/delete the full cache directory'
);
$lengthPos = strpos($cache, '$bodyLength = ob_get_length()');
$contentsPos = strpos($cache, '$body = ob_get_contents()');
$record(
    'oversized_response_copy_is_avoided',
    $lengthPos !== false && $contentsPos !== false && $lengthPos < $contentsPos,
    'oversized output buffers must be rejected before copying them into another PHP string'
);

$support = $read('lib/CatalogSupport.php');
$publicGuard = $read('src/Infrastructure/Security/CatalogPublicAccessGuard.php');
$settingsStore = $read('src/Infrastructure/Security/CatalogPublicAccessSettingsStore.php');
$crawlerPos = strpos($support, 'catalog_public_access_guard_crawler_request()');
$cachePos = strpos($support, 'catalog_public_cache_bootstrap(');
$burstPos = strpos($support, 'catalog_public_access_guard_burst_request()');
$record(
    'cache_hits_avoid_per_ip_burst_writes',
    $crawlerPos !== false && $cachePos !== false && $burstPos !== false
        && $crawlerPos < $cachePos && $cachePos < $burstPos
        && str_contains($publicGuard, 'public function guardCrawlerRequest(): void')
        && str_contains($publicGuard, 'public function guardBurstRequest(): void'),
    'crawler blocking stays pre-cache, while synchronized per-IP burst state is paid only by cache misses'
);
$record(
    'public_settings_are_read_once_per_request',
    str_contains($settingsStore, 'private static array $requestCache')
        && str_contains($settingsStore, 'isset(self::$requestCache[$path])'),
    'crawler and burst gates in one request must reuse normalized public-access settings'
);

$container = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php');
$reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
$snapshotWriter = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$converter = $read('src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php');
$record(
    'compact_verification_streams_from_disk',
    str_contains($container, 'public static function verifyFile(')
        && str_contains($container, "hash_init('sha256')")
        && str_contains($container, 'hash_update($hash, $compressed)')
        && str_contains($reader, 'BlockedCompressedMetadataContainer::verifyFile('),
    'physical .uedb2 verification must keep only one compressed/decoded block in memory'
);
$record(
    'production_container_build_is_streamed',
    str_contains($container, 'public static function buildToFile(')
        && str_contains($container, 'private static function buildPayload(')
        && str_contains($container, '$chunk = array_slice($rows, $rowStart, $blockSize)')
        && str_contains($container, 'COPY_BUFFER_BYTES')
        && str_contains($snapshotWriter, 'BlockedCompressedMetadataContainer::buildToFile(')
        && !str_contains($snapshotWriter, 'BlockedCompressedMetadataContainer::build($snapshot)')
        && !str_contains($snapshotWriter, '$bytes ='),
    'production publication must build block-by-block instead of allocating the complete compressed container'
);
$record(
    'successful_publication_avoids_second_full_scan',
    str_contains($snapshotWriter, "'verified' => true")
        && !str_contains($snapshotWriter, 'new BlockedCompressedMetadataReader('),
    'the fully verified temp file must not be immediately reread/decompressed after atomic rename'
);
$record(
    'projection_maintenance_avoids_whole_container_copy',
    str_contains($converter, 'BlockedCompressedMetadataContainer::verifyFile(')
        && str_contains($converter, '$lookupWriter->writeVersionedMetadata(')
        && !str_contains($converter, 'file_get_contents($path)')
        && !str_contains($converter, 'verifyBytes($bytes'),
    'projection maintenance must not allocate the complete .uedb2 file'
);

$lookup = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$record(
    'compact_terms_are_resolved_once',
    str_contains($lookup, 'public function primeSnapshotTerms(array $snapshot, int &$sqlBatches): array')
        && str_contains($lookup, 'public function writeVersionedMetadata(')
        && str_contains($snapshotWriter, '$resolvedTermIds = $lookupWriter->primeSnapshotTerms(')
        && str_contains($snapshotWriter, '$lookupWriter->writeVersionedMetadata('),
    'normal publication must reuse the term dictionary resolved before its transaction'
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
    'export/dependency SQL row memory must be bounded independently of package size'
);

$searchProjection = $read('src/Infrastructure/Metadata/CompactSearchProjectionWriter.php');
$searchWriterUsesResolvedTerms = preg_match(
    '/new CompactSearchProjectionWriter\(\$this->db\)\)->write\(\s*\$snapshot,\s*\$sqlBatches,\s*\$resolvedTermIds\s*\)/s',
    $snapshotWriter
) === 1;
$record(
    'search_projection_reuses_terms_and_batches_updates',
    str_contains($searchProjection, '?array $resolvedTermIds = null')
        && str_contains($searchProjection, 'count($importBatch) >= self::UPDATE_BATCH_SIZE')
        && str_contains($searchProjection, 'count($exportBatch) >= self::UPDATE_BATCH_SIZE')
        && str_contains($searchProjection, 'private static array $schemaAvailable')
        && $searchWriterUsesResolvedTerms,
    'search projections must reuse the shared dictionary, bound update maps and cache schema availability'
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
    'lib/CatalogSupport.php',
    'lib/CatalogPublicAccess.php',
    'src/Infrastructure/Cache/CatalogPublicResponseCacheService.php',
    'src/Infrastructure/Security/CatalogPublicAccessGuard.php',
    'src/Infrastructure/Security/CatalogPublicAccessSettingsStore.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Metadata/CompactSearchProjectionWriter.php',
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
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
