#!/usr/bin/env php
<?php
/** Read-only regression verifier for detached-worker liveness ownership. */
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
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$detached = (string)@file_get_contents($root . '/bin/catalog-worker-detached.php');
$normal = (string)@file_get_contents($root . '/bin/catalog-worker.php');
$recovery = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogOrphanedJobRecovery.php');
$fingerprint = (string)@file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');

$syntaxFailures = [];
foreach ([
    'bin/catalog-worker-detached.php',
    'bin/catalog-worker.php',
    'src/Infrastructure/Persistence/PdoWorkerOwnership.php',
    'src/Infrastructure/Jobs/CatalogOrphanedJobRecovery.php',
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $syntaxFailures[] = $relative . ': ' . implode(' ', $output);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$record(
    'detached_worker_holds_database_ownership',
    str_contains($detached, 'use UnrealDb\\Catalog\\Infrastructure\\Persistence\\PdoWorkerOwnership;')
        && str_contains($detached, '$ownership = new PdoWorkerOwnership($application->db);')
        && str_contains($detached, '$ownershipLock = $ownership->acquire($queueName, $workerId);')
        && str_contains($detached, '$ownership->release($ownershipLock);'),
    'Detached workers must hold the same MySQL connection-scoped liveness lock as normal CLI workers for their whole process lifetime.'
);

$record(
    'normal_worker_holds_database_ownership',
    str_contains($normal, '$ownership = new PdoWorkerOwnership($application->db);')
        && str_contains($normal, '$ownershipLock = $ownership->acquire($queueName, $workerId);'),
    'Normal and detached workers must use the same authoritative database liveness primitive.'
);

$record(
    'orphan_recovery_checks_database_ownership_first',
    str_contains($recovery, 'if ($ownership->isAlive($queueName, $workerId))')
        && str_contains($recovery, 'continue;'),
    'A live database ownership lock must prevent orphan recovery from requeueing a running job.'
);

$record(
    'worker_fingerprint_tracks_ownership_primitive',
    str_contains($fingerprint, "'/src/Infrastructure/Persistence/PdoWorkerOwnership.php'"),
    'Changes to worker ownership semantics must invalidate detached workers.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
