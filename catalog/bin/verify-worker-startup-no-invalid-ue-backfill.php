#!/usr/bin/env php
<?php
/**
 * Regression gate: worker startup must not backfill historical invalid-UE
 * System Errors as a side effect of starting new queue work.
 *
 * Historical backfill remains available only through the explicit ledger-only
 * CLI command.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$factoryPath = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
$cliPath = $root . '/bin/backfill-invalid-ue-system-errors.php';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$lint = static function (string $path): array {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [false, 'Could not run php -l for ' . $path];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [$exit === 0, trim((string)$stderr . ' ' . (string)$stdout)];
};

[$factorySyntax, $factoryLint] = $lint($factoryPath);
[$cliSyntax, $cliLint] = $lint($cliPath);
$record('php_syntax', $factorySyntax && $cliSyntax, trim($factoryLint . ' | ' . $cliLint));

$factory = (string)@file_get_contents($factoryPath);
$cli = (string)@file_get_contents($cliPath);

$record(
    'worker_startup_does_not_run_invalid_ue_backfill',
    !str_contains($factory, 'PdoInvalidUeSystemErrorBackfill')
        && !str_contains($factory, 'invalidUeBackfill'),
    'Starting workers for new queue work must not create System Errors from unrelated historical terminal jobs.'
);

$record(
    'explicit_backfill_cli_remains_available',
    str_contains($cli, 'PdoInvalidUeSystemErrorBackfill')
        && str_contains($cli, "'mode' => 'ledger_only'")
        && str_contains($cli, "'workers_started' => false"),
    'Historical invalid-UE backfill must remain an explicit operator action.'
);

$record(
    'worker_comment_documents_no_backfill_side_effect',
    str_contains($factory, 'Historical invalid-UE System Error backfill is intentionally NOT run')
        && str_contains($factory, 'backfill-invalid-ue-system-errors.php'),
    'Future changes should preserve the separation between worker startup and historical telemetry maintenance.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
