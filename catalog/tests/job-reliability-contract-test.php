<?php
declare(strict_types=1);

function job_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$queueContract = file_get_contents(__DIR__ . '/../src/Application/Jobs/JobQueue.php');
job_contract_expect(is_string($queueContract), 'JobQueue.php could not be read.');
foreach (['requestCancellation(', 'cancelClaimed(', 'recoverExpiredLeases(', 'retryDeadLetter('] as $method) {
    job_contract_expect(str_contains($queueContract, $method), 'Durable job contract is missing ' . $method);
}
job_contract_expect(str_contains($queueContract, "'active'|'cancel_requested'|'lost'"), 'Heartbeat ownership states are no longer explicit.');

$context = file_get_contents(__DIR__ . '/../src/Application/Jobs/JobExecutionContext.php');
job_contract_expect(is_string($context), 'JobExecutionContext.php could not be read.');
job_contract_expect(str_contains($context, 'JobCancellationRequested'), 'Execution checkpoints no longer surface cooperative cancellation.');
job_contract_expect(str_contains($context, 'pendingProgress'), 'Execution checkpoints no longer persist bounded progress snapshots.');

$queue = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoJobQueue.php');
job_contract_expect(is_string($queue), 'PdoJobQueue.php could not be read.');
foreach (['status="dead_letter"', 'cancel_requested_at', 'recovery_count=recovery_count+1', 'lease_token=? FOR UPDATE'] as $fragment) {
    job_contract_expect(str_contains($queue, $fragment), 'Queue persistence is missing reliability boundary: ' . $fragment);
}
job_contract_expect(!str_contains($queue, 'if ($statement->rowCount() !== 1) {\n            return \'lost\';'), 'Heartbeat still treats unchanged affected-row count as lease loss.');
job_contract_expect(str_contains($queue, 'worker_id=NULL, lease_token=NULL, leased_at=NULL'), 'Retry or recovery no longer clears former lease ownership.');

$actionApi = file_get_contents(__DIR__ . '/../api/v1/job-action.php');
job_contract_expect(is_string($actionApi), 'job-action.php could not be read.');
job_contract_expect(str_contains($actionApi, "REQUEST_METHOD'] !== 'POST'"), 'Job mutations are not POST-only.');
job_contract_expect(str_contains($actionApi, "catalog_api_require_csrf('job_action')"), 'Job mutations are missing CSRF enforcement.');
job_contract_expect(!str_contains($actionApi, '$_GET'), 'Job mutation endpoint accepts query-string actions.');

$statusApi = file_get_contents(__DIR__ . '/../api/v1/job-status.php');
job_contract_expect(is_string($statusApi), 'job-status.php could not be read.');
foreach (['progress_updated_at', 'last_heartbeat_at', 'recovery_count', 'cancel_requested_at', 'dead_lettered_at'] as $field) {
    job_contract_expect(str_contains($statusApi, $field), 'Job status API is missing ' . $field);
}

$worker = file_get_contents(__DIR__ . '/../bin/catalog-worker.php');
job_contract_expect(is_string($worker), 'catalog-worker.php could not be read.');
job_contract_expect(str_contains($worker, 'lease-seconds'), 'Worker CLI no longer accepts the configured lease duration.');

job_contract_expect(!is_file(__DIR__ . '/../../.github/workflows/one-time-job-schema-baseline.yml'), 'Temporary schema workflow was left in the repository.');
job_contract_expect(!is_file(__DIR__ . '/../../.github/workflows/one-time-heartbeat-rowcount.yml'), 'Temporary heartbeat workflow was left in the repository.');

echo "Background job reliability contract tests passed.\n";
