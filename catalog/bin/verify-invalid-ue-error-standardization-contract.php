#!/usr/bin/env php
<?php
/** Read-only contract for standardized Unreal package validation errors. */
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

require_once $root . '/src/Application/Telemetry/CatalogInvalidUeErrorClassifier.php';
require_once $root . '/src/Infrastructure/Import/CatalogInvalidPackageException.php';

use UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier;
use UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException;

$classifier = $read('src/Application/Telemetry/CatalogInvalidUeErrorClassifier.php');
$exception = $read('src/Infrastructure/Import/CatalogInvalidPackageException.php');
$indexer = $read('src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$verifiedInspector = $read('src/Infrastructure/Import/CatalogVerifiedPackageInspector.php');
$reporter = $read('src/Infrastructure/Telemetry/CatalogInvalidUeFileReporter.php');
$bucket = $read('src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php');
$staged = $read('src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php');
$backfill = $read('src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php');
$repair = $read('src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php');
$reader = $read('parsers/EpicUE3PackageReader.php');
$policy = $read('src/Application/Jobs/JobFailureRetryPolicy.php');
$fingerprint = $read('src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$systemErrors = $read('system-errors.php');
$systemExport = $read('system-errors-export.php');
$inspector = $read('bin/inspect-ue3-compression.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$warArguments = [
    'chunk_index' => 1,
    'compressed_offset' => 959438,
    'compressed_size' => 949548,
    'compressed_end' => 1908986,
    'physical_size' => 1441792,
    'uncompressed_offset' => 1044630,
    'uncompressed_size' => 1036450,
    'compression_flags' => '0x00000002',
    'chunk_count' => 4,
    'package_version' => 512,
    'licensee_version' => 0,
];
$war = CatalogInvalidUeErrorClassifier::classify(
    'UE3 compressed chunk is outside the physical package.',
    'ue3.compressed_chunk_out_of_bounds',
    $warArguments
);

$record(
    'war_flurry_error_has_stable_type',
    $war['code'] === 'ue3.compressed_chunk_out_of_bounds'
        && $war['group'] === 'ue3'
        && $war['error_type'] === 'InvalidUnrealPackage.ue3.compressed_chunk_out_of_bounds',
    'WAR-Flurry must group under one stable UE3 compressed-chunk bounds error type.'
);

$record(
    'war_flurry_reason_contains_exact_bounds',
    str_contains($war['reason'], 'chunk 1')
        && str_contains($war['reason'], 'compressed_offset=959438')
        && str_contains($war['reason'], 'compressed_size=949548')
        && str_contains($war['reason'], 'compressed_end=1908986')
        && str_contains($war['reason'], 'physical_size=1441792')
        && str_contains($war['reason'], 'uncompressed_offset=1044630')
        && str_contains($war['reason'], 'uncompressed_size=1036450')
        && str_contains($war['reason'], 'compression_flags=0x00000002')
        && str_contains($war['reason'], 'chunk_count=4')
        && str_contains($war['reason'], 'package_version=512'),
    'The operator message must contain the exact values that prove the overrun.'
);

$record(
    'war_flurry_context_arguments_remain_structured',
    ($war['arguments']['chunk_index'] ?? null) === 1
        && ($war['arguments']['compressed_end'] ?? null) === 1908986
        && ($war['arguments']['physical_size'] ?? null) === 1441792
        && ($war['arguments']['compression_flags'] ?? null) === '0x00000002',
    'The same values must remain machine-readable in validation_arguments.'
);

$legacy = CatalogInvalidUeErrorClassifier::classify(
    'RuntimeException: RuntimeException: Epic UE3 compressed chunk exceeds physical package size '
    . 'File: D:/catalog/parsers/EpicUE3PackageReader.php:249 PHP: 8.5.9 '
    . 'Package: L:/bucket/WAR-Flurry-RC2_LOC_int.upk Trace: #0 old trace'
);
$record(
    'legacy_trace_text_is_cleaned_and_grouped',
    $legacy['code'] === 'ue3.compressed_chunk_out_of_bounds'
        && $legacy['error_type'] === 'InvalidUnrealPackage.ue3.compressed_chunk_out_of_bounds'
        && !str_contains($legacy['reason'], 'Trace:')
        && !str_contains($legacy['reason'], 'PHP:')
        && !str_contains($legacy['reason'], 'Package:')
        && !str_contains($legacy['reason'], 'RuntimeException:'),
    'Historical text-only errors must still group by type and lose stack/runtime/path noise.'
);

$typed = new CatalogInvalidPackageException(
    'UE3 compressed chunk is outside the physical package.',
    'ue3.compressed_chunk_out_of_bounds',
    $warArguments
);
$record(
    'typed_invalid_package_exception_carries_arguments',
    $typed->validationCode() === 'ue3.compressed_chunk_out_of_bounds'
        && ($typed->validationArguments()['compressed_size'] ?? null) === 949548,
    'The import boundary must carry structured validation data instead of reconstructing it from message text.'
);

$record(
    'ue3_reader_returns_structured_validation_issues',
    str_contains($reader, 'function getValidationIssues(): array')
        && str_contains($reader, "'ue3.compressed_chunk_out_of_bounds'")
        && str_contains($reader, '\'compressed_offset\' => $cOff')
        && str_contains($reader, '\'physical_size\' => $physicalSize')
        && str_contains($reader, "'compression_flags' => sprintf")
        && !str_contains($reader, 'getTraceAsString'),
    'UE3 reader validation output must be structured and must not embed a stack trace.'
);

$record(
    'physical_overruns_stop_before_reads',
    str_contains($reader, 'private function inflatePackage(): bool')
        && str_contains($reader, 'if ($cOff<0 || $cLen<0 || $compressedEnd>$physicalSize)')
        && str_contains($reader, "'ue3.read_range_out_of_bounds'")
        && str_contains($reader, "'ue3.compressed_block_out_of_bounds'")
        && str_contains($reader, 'private function readFileRange(')
        && str_contains($reader, 'return null;')
        && str_contains($reader, 'return false;'),
    'Serialized range contradictions must terminate validation cleanly before an invalid seek/read/decompression.'
);

$record(
    'generic_binary_overruns_are_typed',
    str_contains($reader, 'final class CatalogUE3ValidationException')
        && str_contains($reader, "'ue3.binary_seek_out_of_bounds'")
        && str_contains($reader, "'ue3.binary_read_out_of_bounds'")
        && str_contains($reader, "'ue3.serialized_array_out_of_bounds'")
        && str_contains($reader, '\'requested_length\'=>$len')
        && str_contains($reader, '\'remaining\'=>$remaining'),
    'Low-level UE3 binary bounds checks must preserve arguments rather than emitting opaque OutOfBoundsException text.'
);

$record(
    'structured_validation_reaches_system_errors',
    str_contains($indexer, 'method_exists($reader, \'getValidationIssues\')')
        && str_contains($indexer, 'new CatalogInvalidPackageException(')
        && str_contains($verifiedInspector, 'method_exists($package, \'getValidationIssues\')')
        && str_contains($verifiedInspector, 'new CatalogInvalidPackageException(')
        && str_contains($bucket, '\'error_code\' => $error instanceof CatalogInvalidPackageException')
        && str_contains($bucket, '\'arguments\' => $error instanceof CatalogInvalidPackageException')
        && str_contains($staged, '\'arguments\' => $error instanceof CatalogInvalidPackageException')
        && str_contains($reporter, 'CatalogInvalidUeErrorClassifier::classify(')
        && str_contains($reporter, '\'validation_code\' => $classified[\'code\']')
        && str_contains($reporter, '\'validation_arguments\' => $classified[\'arguments\']'),
    'The reader code/arguments must survive every layer into System Error context.'
);

$record(
    'validation_details_are_durable_for_backfill',
    str_contains($bucket, "'validation_code' => \$validation['code']")
        && str_contains($bucket, "'validation_arguments' => \$validation['arguments']")
        && str_contains($staged, "'validation_code' => \$validation['code']")
        && str_contains($staged, "'validation_arguments' => \$validation['arguments']")
        && str_contains($backfill, "'error_code' => trim((string)(\$result['validation_code'] ?? ''))")
        && str_contains($backfill, "is_array(\$result['validation_arguments'] ?? null)")
        && str_contains($repair, "'validation_code' => \$validation['code']")
        && str_contains($repair, "'validation_arguments' => \$validation['arguments']"),
    'Exact validation arguments must survive in terminal job metadata so ledger-only System Error recovery cannot lose them.'
);

$record(
    'expected_validation_errors_have_no_trace',
    str_contains($reporter, "'source_file' => ''")
        && !str_contains($reporter, "'trace_text' =>")
        && str_contains($reporter, '\'message\' => $fileName . \': \' . $reason'),
    'Expected invalid-file validation errors should store concise reason/context, not parser stack traces.'
);

$record(
    'legacy_retry_policy_recognizes_standardized_reasons',
    str_contains($policy, "'ue3 compressed chunk is outside the physical package'")
        && str_contains($bucket, 'if ($error instanceof CatalogInvalidPackageException)'),
    'Standardized wording must remain terminal/non-retryable without depending on an old sentence.'
);

$record(
    'system_errors_can_filter_and_export_by_type',
    str_contains($systemErrors, '$_GET[\'type\']')
        && str_contains($systemErrors, 'SELECT error_type,COUNT(*) c')
        && str_contains($systemErrors, '$where[] = \'error_type=?\'')
        && str_contains($systemErrors, '<label>Type <select name="type">')
        && str_contains($systemExport, '$_GET[\'type\']')
        && str_contains($systemExport, '$where[] = \'error_type=?\'')
        && str_contains($systemExport, '$errorType'),
    'Stable error types need a first-class page/export filter so related failures are easy to group.'
);

$record(
    'inspector_exposes_structured_validation',
    str_contains($inspector, '\'validation_issues\' => $validationIssues'),
    'The one-file diagnostic tool must show the same structured validation result used by imports.'
);

$record(
    'worker_fingerprint_tracks_standardization_runtime',
    str_contains($fingerprint, '/src/Application/Telemetry/CatalogInvalidUeErrorClassifier.php')
        && str_contains($fingerprint, '/src/Infrastructure/Import/CatalogInvalidPackageException.php')
        && str_contains($fingerprint, '/src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php')
        && str_contains($fingerprint, '/src/Infrastructure/Import/CatalogVerifiedPackageInspector.php')
        && str_contains($fingerprint, '/parsers/EpicUE3PackageReader.php'),
    'Detached workers must restart when validation/error-standardization code changes.'
);

$syntaxFailures = [];
foreach ([
    $root . '/src/Application/Telemetry/CatalogInvalidUeErrorClassifier.php',
    $root . '/src/Infrastructure/Import/CatalogInvalidPackageException.php',
    $root . '/src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
    $root . '/src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    $root . '/src/Infrastructure/Telemetry/CatalogInvalidUeFileReporter.php',
    $root . '/src/Infrastructure/Jobs/CatalogBucketStagedPackageJobHandler.php',
    $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php',
    $root . '/src/Infrastructure/Persistence/PdoInvalidUeSystemErrorBackfill.php',
    $root . '/src/Infrastructure/Persistence/PdoArchiveProfileMismatchOutcomeRepair.php',
    $root . '/src/Application/Jobs/JobFailureRetryPolicy.php',
    $root . '/parsers/EpicUE3PackageReader.php',
    $root . '/bin/inspect-ue3-compression.php',
    $root . '/system-errors.php',
    $root . '/system-errors-export.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not run php -l';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
