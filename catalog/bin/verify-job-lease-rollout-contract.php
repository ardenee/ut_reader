#!/usr/bin/env php
<?php
/** Read-only source contract for lease ownership and rolling-deployment recovery. */
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

$lease = $read('src/Infrastructure/Persistence/PdoJobLeaseStore.php');
$worker = $read('src/Application/Jobs/JobWorker.php');
$writer = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$check(
    'heartbeat_ownership_not_rowcount',
    str_contains($lease, 'never infer lease loss from a')
        && str_contains($lease, 'WHERE id=? AND status="running" AND lease_token=?')
        && !str_contains($lease, "if (\$statement->rowCount() !== 1) {\n            return 'lost';"),
    'A no-op MySQL UPDATE must not be mistaken for a lost lease; ownership is verified by id/status/token.'
);
$check(
    'unknown_job_type_is_deferred',
    str_contains($worker, 'deferUnknownJobType')
        && str_contains($worker, "\$this->queue->defer(\$job, 30, \$progress)")
        && str_contains($worker, 'without consuming an attempt'),
    'An older worker in a rolling deployment must defer a newer job type instead of dead-lettering it.'
);
$check(
    'compact_publication_retries_contention',
    str_contains($writer, 'CONTENTION_ATTEMPTS = 5')
        && str_contains($writer, 'PdoContention::retryable($error)')
        && str_contains($writer, 'PdoContention::backoffMicros($attempt, 25000)')
        && str_contains($writer, 'publishAttempt('),
    'MySQL deadlock/lock-wait contention must retry the complete atomic compact publication, not one SQL statement.'
);

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
