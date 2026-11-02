#!/usr/bin/env php
<?php
/**
 * Regression gate for the read-only Unreal file inspector batch/full modes.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/bin/inspect-unreal-file.php';
$source = @file_get_contents($path);
$source = is_string($source) ? $source : '';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$pipes = [];
$process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$syntaxOk = false;
$syntaxDetail = '';
if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $syntaxOk = $exit === 0;
    $syntaxDetail = trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxOk, $syntaxDetail);

$record(
    'full_mode_uses_production_reader_resolver',
    str_contains($source, 'scanner_load_reader_class($config, $engine)')
        && str_contains($source, "method_exists(\$reader, 'validatePackage')")
        && str_contains($source, "method_exists(\$reader, 'getValidationIssues')"),
    'Full validation must instantiate the production engine reader and use its validation contract.'
);

$record(
    'recursive_mode_is_sequential_and_redirect_scoped',
    str_contains($source, 'RecursiveDirectoryIterator')
        && str_contains($source, 'preg_match(')
        && str_contains($source, '(?:uz|uz2|uz3)$/i')
        && str_contains($source, 'foreach ($expandedPaths as $path)'),
    'Recursive mode must enumerate UZ/UZ2/UZ3 files and process them one at a time.'
);

$record(
    'source_corruption_check_precedes_reader_validation',
    str_contains($source, 'CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($parsePath)')
        && str_contains($source, "if (\$corruption !== null)"),
    'Known zero-to-space source corruption must stay deterministic and must not be disguised as a reader bug.'
);

$record(
    'legacy_failures_use_system_error_classifier',
    str_contains($source, 'CatalogInvalidUeErrorClassifier::classify(')
        && str_contains($source, "'validation_code'")
        && str_contains($source, "'validation_reason'"),
    'Reader failures should group under the same stable codes used by System Errors.'
);

$record(
    'batch_summary_reports_pass_fail_and_codes',
    str_contains($source, "'Files tested")
        && str_contains($source, "'Passed")
        && str_contains($source, "'Failed")
        && str_contains($source, "'Failure ' . \$code"),
    'Batch output must end with counts that are easy to compare with the original error export.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
