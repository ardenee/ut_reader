#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies the durable staged-package import path does not recreate a redundant parser working copy.
 * Role: Read-only import performance/failure-retention regression test.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$path = $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php';
$source = (string)@file_get_contents($path);
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
$record('php_syntax', $code === 0, implode(' ', $out));
$record(
    'no_full_working_copy',
    !str_contains($source, 'tempnam(sys_get_temp_dir(), \'unrealdb-import-\')')
        && !str_contains($source, 'function workingCopy(')
        && str_contains($source, '$workingPath = $sourcePath;')
        && str_contains($source, 'Parse that file directly instead of reading and writing a'),
    'ordinary durable staged packages are parsed in place after identity validation'
);
$record(
    'identity_guard_retained',
    str_contains($source, '$this->verifyIdentity($sourcePath, $payload);')
        && str_contains($source, "hash_file('sha256', $path)")
        && str_contains($source, 'Staged import file identity changed before execution.'),
    'zero-copy import still verifies the queued SHA-256 identity before parsing'
);
$record(
    'temporary_cleanup_boundary',
    str_contains($source, '$workingTemporary = false;')
        && str_contains($source, '$workingTemporary = true;')
        && str_contains($source, 'if ($workingTemporary && $workingPath !== \'\' && is_file($workingPath))'),
    'only helper-created temporary redirect files are disposable in finally'
);
$record(
    'unverified_fallback_retained',
    str_contains($source, 'new LegacyUnverifiedFileStager($this->db, $this->config)')
        && str_contains($source, '->stageFailedUpload(')
        && str_contains($source, "'status' => $staged !== null ? 'unverified' : 'rejected'"),
    'failed Unreal packages still move into the established unverified queue'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
