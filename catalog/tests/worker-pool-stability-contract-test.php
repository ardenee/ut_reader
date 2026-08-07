<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies worker pool stability behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function worker_pool_stability_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$run = file_get_contents(__DIR__ . '/../api/v1/job-run.php');
worker_pool_stability_expect(is_string($run) && $run !== '', 'job-run.php could not be read.');

worker_pool_stability_expect(
    str_contains($run, "PHP_OS_FAMILY === 'Windows' ? 45.0 : 25.0")
        && str_contains($run, '$satisfiedSince ??= microtime(true)')
        && str_contains($run, 'microtime(true) - $satisfiedSince >= 1.0'),
    'Apply workers does not wait for the complete pool to remain stable.'
);

worker_pool_stability_expect(
    str_contains($run, 'if ($active + $launching < $workerCount)')
        && str_contains($run, 'catch (Throwable $error)')
        && str_contains($run, '$launchErrors[]')
        && str_contains($run, '$launcher->start($queueName, $maxJobs, $workerCount)'),
    'Partial Windows worker launches are not retried through the reconciliation deadline.'
);

worker_pool_stability_expect(
    str_contains($run, 'acquired stable worker locks after reconciliation')
        && str_contains($run, "'launch_errors' => array_slice(\$launchErrors, -5)"),
    'Incomplete pools do not expose stable-slot and launcher diagnostics.'
);

worker_pool_stability_expect(
    !str_contains($run, 'if ($activeLaunched > 0'),
    'Worker startup can still accept the first active slot as a complete pool.'
);

echo "Worker pool stability contract tests passed.\n";
