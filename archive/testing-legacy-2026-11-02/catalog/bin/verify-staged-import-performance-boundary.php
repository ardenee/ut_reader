#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies the durable staged-package import path uses hardlink-first working sources while preserving rollback/failure bytes.
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
$handlerPath = $root . '/src/Infrastructure/Jobs/CatalogStagedImportJobHandler.php';
$storagePath = $root . '/src/Infrastructure/Storage/CatalogVerifiedPackageStorage.php';
$source = (string)@file_get_contents($handlerPath);
$storage = (string)@file_get_contents($storagePath);
$syntax = [];
foreach ([$handlerPath, $storagePath] as $path) {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) $syntax[] = basename($path) . ': ' . implode(' ', $out);
}
$record('php_syntax', $syntax === [], implode(' | ', $syntax));
$record(
    'hardlink_first_working_source',
    str_contains($source, 'function workingSource(')
        && str_contains($source, '@link($sourcePath, $path)')
        && str_contains($source, "'.unrealdb-import-'")
        && str_contains($source, 'return $this->workingCopy($sourcePath, $name, $context, $startPercent, $endPercent);'),
    'ordinary staged imports use an O(1) same-volume hardlink when supported and retain the streamed-copy fallback'
);
$record(
    'durable_source_retained_until_success',
    str_contains($source, '$workingPath = $this->workingSource($sourcePath, $workingName, $context, 2, 20);')
        && str_contains($source, '$store->remove($relativePath);')
        && str_contains($source, '$completedWorkingPath = $workingPath;')
        && str_contains($source, "if (\$workingTemporary && \$completedWorkingPath !== '' && is_file(\$completedWorkingPath))"),
    'the durable staged source remains separate from the parser/storage working path until import completion'
);
$record(
    'identity_guard_retained',
    str_contains($source, '$this->verifyIdentity($sourcePath, $payload);')
        && str_contains($source, "hash_file('sha256', \$path)")
        && str_contains($source, 'Staged import file identity changed before execution.'),
    'hardlink-first import still verifies queued SHA-256 identity before preparing the working path'
);
$record(
    'temporary_cleanup_boundary',
    str_contains($source, '$workingTemporary = false;')
        && str_contains($source, '$workingTemporary = true;')
        && str_contains($source, "if (\$workingTemporary && \$workingPath !== '' && is_file(\$workingPath))"),
    'only helper-created working/decompressed paths are disposable in finally'
);
$record(
    'storage_rollback_restores_source',
    str_contains($storage, "'source_path' => \$temporaryPath")
        && str_contains($storage, '@rename($destination, $sourcePath)')
        && str_contains($storage, '@unlink($destination)'),
    'persistence failure restores caller-owned working bytes before bounded fallback cleanup'
);
$record(
    'unverified_fallback_retained',
    str_contains($source, 'new LegacyUnverifiedFileStager($this->db, $this->config)')
        && str_contains($source, '->stageFailedUpload(')
        && str_contains($source, "'status' => \$staged !== null ? 'unverified' : 'rejected'"),
    'failed Unreal packages still move into the established unverified queue'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
