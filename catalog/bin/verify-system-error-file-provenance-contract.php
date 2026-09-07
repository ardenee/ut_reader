#!/usr/bin/env php
<?php
/** Read-only contract for file-specific background-job/System Error provenance. */
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

$resolver = $read('src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php');
$export = $read('system-errors-export.php');
$diagnose = $read('bin/diagnose-system-error-provenance.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'future_job_errors_attach_verified_file_identity',
    str_contains($resolver, 'private function applyVerifiedFileIdentity(')
        && str_contains($resolver, 'f.detected_package_version')
        && str_contains($resolver, 'canonical_relative_path')
        && str_contains($resolver, '$this->applyVerifiedFileIdentity($context, $payload);'),
    'A failed file-scoped job must record the file id/name/path/version/hash context instead of only the Full Sync parent label.'
);

$record(
    'existing_error_exports_are_enriched_from_job_payload',
    str_contains($export, 'function system_error_export_enrich_context(')
        && str_contains($export, 'SELECT id,job_type,payload_json FROM ue_background_jobs')
        && str_contains($export, 'FROM ue_files f LEFT JOIN ue_games g')
        && str_contains($export, '$context = system_error_export_enrich_context($db, $row, $context);'),
    'Existing System Error rows must be exportable with retained job->file provenance without rerunning the failed jobs.'
);

$record(
    'legacy_reader_numeric_arguments_are_exported',
    str_contains($export, 'Invalid\\s+(Names|Imports|Exports)\\s+table\\s+offset')
        && str_contains($export, 'entry_head_hex')
        && str_contains($export, 'fstring_length')
        && str_contains($export, "'package_version'")
        && str_contains($export, "'licensee_version'"),
    'Legacy package errors must surface the exact table/entry offsets, package size and version/licensee values needed for format diagnosis.'
);

$record(
    'cli_provenance_diagnostic_resolves_retained_file',
    str_contains($diagnose, 'SELECT job_type,payload_json FROM ue_background_jobs')
        && str_contains($diagnose, "'file_id' => (int)")
        && str_contains($diagnose, "'package_version' => (int)")
        && str_contains($diagnose, "'canonical_relative_path' => (string)"),
    'The read-only provenance CLI must identify existing affected files directly from retained background-job payloads.'
);

$syntaxTargets = [
    'src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php',
    'system-errors-export.php',
    'bin/diagnose-system-error-provenance.php',
    'bin/verify-system-error-file-provenance-contract.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $relative) {
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
        if (proc_close($process) !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
