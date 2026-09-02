#!/usr/bin/env php
<?php
/**
 * Regression gate: worker construction must not mutate unrelated historical
 * queue/error state as a side effect of starting new work.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$factoryPath = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
$backfillPath = $root . '/bin/backfill-invalid-ue-system-errors.php';
$maintenancePath = $root . '/bin/repair-background-job-compatibility.php';

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

$syntaxOk = true;
$syntaxDetails = [];
foreach ([$factoryPath, $backfillPath, $maintenancePath] as $path) {
    [$ok, $detail] = $lint($path);
    $syntaxOk = $syntaxOk && $ok;
    if ($detail !== '') {
        $syntaxDetails[] = $detail;
    }
}
$record('php_syntax', $syntaxOk, implode(' | ', $syntaxDetails));

$factory = (string)@file_get_contents($factoryPath);
$backfill = (string)@file_get_contents($backfillPath);
$maintenance = (string)@file_get_contents($maintenancePath);

$record(
    'worker_factory_has_no_historical_mutation_hooks',
    !str_contains($factory, 'PdoInvalidUeSystemErrorBackfill')
        && !str_contains($factory, 'PdoArchiveProfileMismatchOutcomeRepair')
        && !str_contains($factory, 'PdoArchiveParentLifecycleRepair')
        && !str_contains($factory, 'synchronizeQueuedPolicies()'),
    'Constructing a worker must create handlers only; historical backfill/repair/policy migration belongs to explicit maintenance.'
);

$record(
    'explicit_invalid_ue_backfill_remains_available',
    str_contains($backfill, 'PdoInvalidUeSystemErrorBackfill')
        && str_contains($backfill, "'mode' => 'ledger_only'")
        && str_contains($backfill, "'workers_started' => false"),
    'Historical invalid-UE System Error backfill remains an explicit operator action.'
);

$record(
    'explicit_job_compatibility_repair_remains_available',
    str_contains($maintenance, 'PdoArchiveProfileMismatchOutcomeRepair')
        && str_contains($maintenance, 'PdoArchiveParentLifecycleRepair')
        && str_contains($maintenance, 'synchronizeQueuedPolicies()')
        && str_contains($maintenance, "array_key_exists('execute', $options)")
        && str_contains($maintenance, "'changed' => false"),
    'Historical queue compatibility repair must require an explicit --execute command.'
);

$record(
    'worker_factory_documents_side_effect_boundary',
    str_contains($factory, 'Worker construction is deliberately side-effect free')
        && str_contains($factory, 'Compatibility repairs are explicit maintenance tasks.'),
    'The architectural boundary should stay visible beside worker construction.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
