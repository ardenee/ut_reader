<?php
declare(strict_types=1);

function orphan_recovery_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$recovery = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogOrphanedJobRecovery.php');
$worker = file_get_contents($root . '/bin/catalog-worker-detached.php');
$status = file_get_contents($root . '/api/v1/job-worker-status.php');
$run = file_get_contents($root . '/api/v1/job-run.php');
$batch = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$jobWorker = file_get_contents($root . '/src/Application/Jobs/JobWorker.php');

foreach (compact('recovery', 'worker', 'status', 'run', 'batch', 'jobWorker') as $name => $source) {
    orphan_recovery_expect(is_string($source), $name . ' source is missing.');
}

orphan_recovery_expect(
    str_contains($recovery, 'attempts')
        && str_contains($recovery, 'max_attempts')
        && str_contains($recovery, 'status="dead_letter"')
        && str_contains($recovery, 'status="queued"')
        && !str_contains($recovery, 'attempts=GREATEST(attempts-1,0)'),
    'Orphan recovery does not consume attempts or cannot reach dead-letter.'
);
orphan_recovery_expect(
    str_contains($recovery, 'Last checkpoint:')
        && str_contains($recovery, 'without recording a PHP exception message')
        && str_contains($recovery, "error === \"''\""),
    'Orphan recovery can still record an empty error or lose the final checkpoint.'
);
orphan_recovery_expect(
    str_contains($worker, 'register_shutdown_function')
        && str_contains($worker, 'Fatal PHP worker error:')
        && str_contains($worker, 'recordWorkerCrash'),
    'Detached workers do not persist fatal shutdown diagnostics.'
);
orphan_recovery_expect(
    str_contains($status, 'recoverInactiveQueue')
        && str_contains($status, 'auto_recovery')
        && str_contains($status, 'start($queueName, 10000)'),
    'Background Jobs does not automatically recover and restart an orphaned queue.'
);
orphan_recovery_expect(
    str_contains($run, 'recoverInactiveQueue')
        && str_contains($batch, 'recoverInactiveQueue'),
    'Manual queue start or Upload Bucket finalisation can still start behind orphaned rows.'
);
orphan_recovery_expect(
    str_contains($jobWorker, 'was thrown without an error message')
        && str_contains($jobWorker, "error === \"''\""),
    'Job failure results can still contain an empty error string.'
);

echo "Bounded orphaned job recovery contract tests passed.\n";
