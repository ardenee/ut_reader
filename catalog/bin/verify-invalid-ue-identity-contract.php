#!/usr/bin/env php
<?php
/** Read-only contract for durable invalid Unreal package identities. */
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

$migration = $read('migrations/202609230002_invalid_ue_file_identities.php');
$preflight = $read('src/Infrastructure/Import/CatalogPublicUploadBatchPreflight.php');
$resolver = $read('src/Infrastructure/Persistence/PdoDependencyResolver.php');
$coverage = $read('src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php');
$marker = $read('bin/mark-invalid-ue-files.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'invalid_identity_is_durable',
    str_contains($migration, 'ue_invalid_file_identities')
        && str_contains($migration, 'UNIQUE KEY uq_invalid_file_identity (md5,sha1,file_size)')
        && str_contains($migration, 'ON DELETE SET NULL'),
    'Invalid byte identity must survive deletion of the original ue_files row.'
);
$record(
    'public_upload_rejects_known_invalid_bytes',
    str_contains($preflight, 'invalidIdentityMatches')
        && str_contains($preflight, "'reason' => 'known_invalid_ue_file'")
        && str_contains($preflight, 'ue_invalid_file_identities'),
    'Public preflight must reject exact size/MD5/SHA-1 identities already confirmed invalid.'
);
$record(
    'dependency_package_resolution_excludes_invalid_bytes',
    substr_count($resolver, 'ue_invalid_file_identities') >= 3
        && str_contains($resolver, 'NOT EXISTS'),
    'Primary, provider and alias package matching must exclude invalid byte identities.'
);
$record(
    'dependency_object_coverage_excludes_invalid_bytes',
    substr_count($coverage, 'ue_invalid_file_identities') >= 2
        && str_contains($coverage, 'NOT EXISTS'),
    'Object-level complete-provider selection must exclude invalid byte identities.'
);
$record(
    'marking_is_explicit_and_dry_run_by_default',
    str_contains($marker, "array_key_exists('apply', \$options)")
        && str_contains($marker, "'dry_run' => !\$apply")
        && str_contains($marker, 'deleteFileProjections')
        && str_contains($marker, 'affectedIds')
        && str_contains($marker, 'refreshIds'),
    'Bulk invalid marking must require --apply, remove provider projections and refresh affected dependencies.'
);

$syntaxFailures = [];
foreach ([
    $root . '/migrations/202609230002_invalid_ue_file_identities.php',
    $root . '/src/Infrastructure/Import/CatalogPublicUploadBatchPreflight.php',
    $root . '/src/Infrastructure/Persistence/PdoDependencyResolver.php',
    $root . '/src/Infrastructure/Persistence/PdoPackageObjectCoverageResolver.php',
    $root . '/bin/mark-invalid-ue-files.php',
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
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
