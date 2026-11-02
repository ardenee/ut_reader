#!/usr/bin/env php
<?php
/** Read-only/no-database verifier for strict Upload Bucket package admission and historical unreadable-row reporting. */
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
    'src/Infrastructure/Import/CatalogBucketIdentityProcessor.php',
    'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
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
$identity = $read('src/Infrastructure/Import/CatalogBucketIdentityProcessor.php');
$indexer = $read('src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$pageQuery = $read('src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php');
$cache = $read('src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php');
$handler = $read('src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php');

$record(
    'new_uploads_require_supported_unreal_header',
    str_contains($indexer, 'does not contain a supported Unreal package header')
        && str_contains($indexer, "['UE1', 'UE2', 'UE3', 'UE4', 'UE5']")
        && str_contains($indexer, "empty(\$summary['ok'])"),
    'A new Upload Bucket package must prove a supported serialized Unreal package header before any unverified row is written.'
);

$record(
    'new_uploads_do_not_downgrade_reader_failure',
    !str_contains($indexer, 'Package table parsing failed; indexing basic metadata')
        && !str_contains($indexer, 'Stored and indexed basic metadata; package tables could not be read')
        && str_contains($indexer, "throw new \\RuntimeException(implode(\"\\n\", \$fatal));")
        && str_contains($indexer, 'Reader returned an invalid package table.'),
    'Reader/header/table failures must escape the indexer; they must never be persisted as a healthy-looking basic-metadata bucket row.'
);

$record(
    'legacy_packages_require_guid_when_format_supports_it',
    str_contains($indexer, "['UE1', 'UE2', 'UE3']")
        && str_contains($indexer, '$headerVersion >= 68')
        && str_contains($indexer, '$guid ===')
        && str_contains($indexer, 'missing the required package GUID'),
    'Legacy package versions that serialize a package GUID must be rejected when that GUID cannot be read.'
);

$indexCall = strpos($identity, '$indexed = $this->operations->index(');
$cleanupCatch = strpos($identity, '@unlink($storedPath);');
$record(
    'failed_admission_removes_bucket_file',
    $indexCall !== false
        && $cleanupCatch !== false
        && $indexCall < $cleanupCatch
        && str_contains($identity, 'catch (Throwable $error)')
        && str_contains($identity, 'throw $error;'),
    'If strict indexing fails after physical publication starts, the Upload Bucket copy and note must be removed before the job failure escapes.'
);

$record(
    'dependency_match_is_queued_only_after_successful_index',
    ($queuePosition = strpos($identity, 'enqueueFile($fileId, $uploadedBy)')) !== false
        && $indexCall !== false
        && $indexCall < $queuePosition,
    'Dependency evidence may only be queued after a package has passed strict admission and received a valid unverified file row.'
);

$record(
    'list_query_exposes_historical_package_parse_failure',
    str_contains($pageQuery, 'f.scan_notes')
        && str_contains($pageQuery, "'package_parse_error'")
        && str_contains($pageQuery, 'Unverified table parse failed:'),
    'Historical rows created by the previous loose policy must remain diagnosable until they are removed or repaired.'
);

$record(
    'list_does_not_present_historical_failed_tables_as_empty',
    str_contains($page, 'Package tables unreadable')
        && str_contains($page, 'N/I/E unavailable')
        && str_contains($page, 'GUID: ')
        && str_contains($page, "'unavailable'")
        && str_contains($page, '<strong>Parser issue</strong>'),
    'Historical unreadable rows must be shown as unavailable with the actual parser issue, not as a healthy 0 / 0 / 0 inventory.'
);

$record(
    'dependency_evidence_is_not_claimed_for_historical_unreadable_package',
    str_contains($page, 'Package tables could not be read, so exact dependency evidence was not calculated.')
        && str_contains($handler, 'packageParseError')
        && str_contains($handler, '$this->cache->storeFailed($fileId, $message);')
        && str_contains($handler, "'status' => 'unavailable'"),
    'A historical unreadable row must not acquire false ready dependency evidence.'
);

$parseCheck = strpos($handler, '$parseError = $this->packageParseError');
$matcherCall = strpos($handler, '$matches = $this->matcher->one($fileId);');
$record(
    'historical_parse_failure_gate_runs_before_matcher',
    $parseCheck !== false && $matcherCall !== false && $parseCheck < $matcherCall,
    'Persisted historical package-reader failures must be checked before exact object-path matching begins.'
);

$record(
    'cache_summary_overrides_historical_stale_ready_rows',
    str_contains($cache, 'Unverified table parse failed:')
        && str_contains($cache, 'failed_count')
        && str_contains($cache, 'missing_count'),
    'The Upload Bucket cache summary must count historical parser-failed packages as failed/unavailable even if an older worker left a stale ready cache row.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
