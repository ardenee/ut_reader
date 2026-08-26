#!/usr/bin/env php
<?php
/**
 * Read-only regression contract for the 2026-08-26 profiled-import error set:
 * retired game+MD5 identities, legacy-ZIP detector fallback, signature-detected
 * RAR members and System Error/source-Issue classification.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$paths = [
    'identity' => $root . '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
    'adapter' => $root . '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
    'sequential' => $root . '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php',
    'rar' => $root . '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php',
    'policy' => $root . '/src/Application/Jobs/JobFailureRetryPolicy.php',
    'worker' => $root . '/src/Application/Jobs/JobWorker.php',
    'fingerprint' => $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
];

$content = [];
foreach ($paths as $key => $path) {
    $raw = is_file($path) ? file_get_contents($path) : false;
    $content[$key] = is_string($raw) ? $raw : '';
}

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'exact_md5_lookup_includes_retired_duplicates',
    str_contains($content['identity'], 'game_id=? AND md5=?')
        && str_contains($content['identity'], 'scan_status IN ("verified","duplicate")'),
    'The database game+MD5 unique key remains owned by retired duplicate rows, so exact binary identity must see them.'
);
$record(
    'exact_md5_lookup_does_not_mask_failed_rows',
    !str_contains($content['identity'], 'scan_status IN ("verified","duplicate","failed")'),
    'Failed/unverified package records must not be silently treated as healthy duplicates.'
);
$record(
    'retired_md5_resolves_active_canonical_guid',
    str_contains($content['identity'], 'matched_retired_duplicate_id')
        && str_contains($content['identity'], 'package_guid=? AND scan_status="verified"'),
    'A retired exact-MD5 row should preserve retirement and route future source evidence to its active verified canonical identity.'
);
$record(
    'post_insert_duplicate_race_is_recovered',
    str_contains($content['adapter'], 'catch (PDOException $error)')
        && str_contains($content['adapter'], 'uq_ue_files_game_md5')
        && str_contains($content['adapter'], '$this->identity->findVerifiedDuplicate($gameId, $inspection, 0)'),
    'The database remains the final identity arbiter if another worker publishes between SELECT and INSERT.'
);
$record(
    'legacy_zip_detector_failure_falls_through',
    str_contains($content['sequential'], 'nativeZipHasLegacyCompression')
        && str_contains($content['sequential'], "'zip64 member fields'")
        && str_contains($content['sequential'], "'end-of-central-directory record was not found'")
        && substr_count($content['sequential'], '->hasLegacyCompression($archivePath)') === 1,
    'ZIP64/EOCD limitations in the narrow legacy detector must not prevent ext-zip/libarchive from inspecting the same source.'
);
$record(
    'rar_decoder_trusts_signature_selected_content',
    !str_contains($content['rar'], 'PHP RAR compatibility reader only accepts RAR archives')
        && str_contains($content['rar'], 'Content identity is authoritative'),
    'A RAR member misnamed with a .zip extension must remain processable after byte-level format detection selected RAR.'
);
$record(
    'short_archive_stream_is_deterministic_source_issue',
    str_contains($content['policy'], "'libarchive member stream stopped unexpectedly'"),
    'A repeatable short stream from immutable archive bytes should not consume three worker attempts.'
);
$record(
    'queued_retry_is_not_system_error',
    str_contains($content['worker'], "if (\$disposition === 'retry_queued')")
        && str_contains($content['worker'], 'Queue retries belong to job/event diagnostics'),
    'A non-terminal retry belongs in job history, not the System Error ledger.'
);
$record(
    'deterministic_source_issue_is_not_system_error',
    str_contains($content['worker'], 'JobFailureRetryPolicy::isDeterministicFailure($job, $exception)')
        && str_contains($content['worker'], 'Background Jobs -> Issues'),
    'Immutable source/archive problems remain actionable Issues without hiding genuine application defects.'
);
$record(
    'worker_fingerprint_tracks_changed_paths',
    str_contains($content['fingerprint'], '/src/Application/Jobs/JobWorker.php')
        && str_contains($content['fingerprint'], '/src/Application/Jobs/JobFailureRetryPolicy.php')
        && str_contains($content['fingerprint'], '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php')
        && str_contains($content['fingerprint'], '/src/Infrastructure/Archive/CatalogSequentialArchiveReader.php')
        && str_contains($content['fingerprint'], '/src/Infrastructure/Archive/CatalogExternalArchiveReader.php'),
    'Detached workers must exit/restart after these recovery/classification changes deploy.'
);

$archiveJob = new ClaimedJob(
    1,
    'catalog',
    JobType::IMPORT_STAGED_ARCHIVE,
    ['original_name' => 'sample.zip'],
    'test-lease',
    1,
    3,
    new DateTimeImmutable('+2 minutes')
);
$record(
    'short_stream_policy_returns_zero_retry_delay',
    JobFailureRetryPolicy::retryDelaySeconds(
        $archiveJob,
        new RuntimeException('ZIP sequential archive member "Map.unr" failed: libarchive member stream stopped unexpectedly; bytes_consumed=100; eof=false; timed_out=false.')
    ) === 0,
    'The exact current short-stream failure class should terminate after one attempt and remain in Issues.'
);
$record(
    'zip64_capability_is_not_mislabeled_as_bad_source',
    JobFailureRetryPolicy::retryDelaySeconds(
        $archiveJob,
        new RuntimeException('Native legacy ZIP decoding does not support ZIP64 member fields.')
    ) > 0,
    'ZIP64 is a reader capability concern, not proof that the source archive is corrupt.'
);

$syntaxFailures = [];
foreach ($paths as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
