<?php
declare(strict_types=1);

function live_layout_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/background-jobs.php');
$jobs = file_get_contents($root . '/assets/background-jobs-stable.js');
$uploadPage = file_get_contents($root . '/upload-bucket.php');
$coordinator = file_get_contents($root . '/assets/upload-bucket-coordinator.js');
$workerQueue = file_get_contents($root . '/src/Infrastructure/Persistence/WorkerJobQueue.php');
$statusApi = file_get_contents($root . '/api/v1/job-status.php');

foreach (compact('page', 'jobs', 'uploadPage', 'coordinator', 'workerQueue', 'statusApi') as $name => $source) {
    live_layout_expect(is_string($source), $name . ' source is missing.');
}

live_layout_expect(
    str_contains($page, 'background-jobs-stable.js')
        && !str_contains($page, 'assets/background-jobs.js?v=')
        && str_contains($page, 'jobs-detail-row')
        && str_contains($page, 'colspan="9"'),
    'Background Jobs is not using the stable two-row layout exclusively.'
);

live_layout_expect(
    str_contains($jobs, 'const rowPairs = new Map()')
        && str_contains($jobs, 'tableBody.insertBefore(pair.main, cursor)')
        && str_contains($jobs, 'tableBody.insertBefore(pair.detail, cursor)')
        && !str_contains($jobs, "tableBody.textContent = ''")
        && str_contains($jobs, 'if (!document.hidden) refresh()'),
    'Background Jobs still rebuilds the complete table instead of updating keyed rows in place.'
);

live_layout_expect(
    str_contains($workerQueue, 'SELECT cancel_requested_at,cancel_reason,leased_at,progress_json')
        && str_contains($workerQueue, "\$result['job_id'] = \$job->id")
        && str_contains($workerQueue, "\$result['file_started_at'] = \$leasedAt")
        && str_contains($workerQueue, "\$progress['file_completed_at'] = \$now")
        && !str_contains($workerQueue, 'worker_id=NULL, lease_token=NULL, leased_at=NULL, lease_expires_at=NULL'),
    'Worker completion still erases the current file start time or loses its final progress checkpoint.'
);

live_layout_expect(
    str_contains($statusApi, 'result_json,last_error')
        && str_contains($statusApi, '$resultJobId')
        && str_contains($statusApi, '$nameMismatch')
        && str_contains($statusApi, 'Stored result identity mismatch')
        && str_contains($statusApi, 'Metadata repair completed for '),
    'The status API does not decode and validate each job result independently.'
);

live_layout_expect(
    str_contains($statusApi, '$successfulCompletion')
        && str_contains($statusApi, "unset(\$result['message'])")
        && str_contains($statusApi, "\$progress['message'] = \$completionMessage"),
    'Successful completion text can still be displayed both as status and as Error/result.'
);

live_layout_expect(
    str_contains($uploadPage, 'data-processing-url=')
        && str_contains($uploadPage, 'upload-bucket-coordinator.js')
        && str_contains($coordinator, 'Open processing jobs')
        && str_contains($coordinator, 'Review Upload Bucket')
        && !str_contains($coordinator, 'window.location.assign(queueUrl)'),
    'Upload Bucket does not provide an explicit, non-timed handoff to processing jobs.'
);

echo "Background Jobs live-layout, per-job runtime, result-isolation and Upload Bucket handoff contract tests passed.\n";
