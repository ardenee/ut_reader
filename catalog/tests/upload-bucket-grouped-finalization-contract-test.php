<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket grouped finalization behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function upload_bucket_finalize_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$coordinator = file_get_contents($root . '/assets/upload-bucket-v2-coordinator.js');
$endpoint = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$bootstrap = file_get_contents($root . '/api/v1/_bootstrap.php');

foreach (compact('coordinator', 'endpoint', 'bootstrap') as $name => $source) {
    upload_bucket_finalize_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

upload_bucket_finalize_expect(
    str_contains($coordinator, 'async function finalizeOne(item, lineId)')
        && str_contains($coordinator, 'upload_ids: [item.uploadId]')
        && str_contains($coordinator, 'prepare_queue: !queuePrepared')
        && str_contains($coordinator, 'start_worker: false')
        && str_contains($coordinator, "upload_ids: [],")
        && str_contains($coordinator, 'start_worker: true')
        && str_contains($coordinator, 'queuePrepared = true;')
        && !str_contains($coordinator, 'JSON.stringify({upload_ids: uploadIds})'),
    'Browser finalisation is not performed and recorded one staged file at a time.'
);

upload_bucket_finalize_expect(
    str_contains($endpoint, "array_key_exists('start_worker', \$payload)")
        && str_contains($endpoint, "array_key_exists('prepare_queue', \$payload)")
        && str_contains($endpoint, 'if ($startWorker && $pendingJobs > 0)')
        && str_contains($endpoint, 'A failed uploaded file is a file result')
        && str_contains($endpoint, "'messages' => \$messages")
        && str_contains($endpoint, '], 200);'),
    'Batch endpoint cannot independently finalise one file and return its result normally.'
);

upload_bucket_finalize_expect(
    str_contains($bootstrap, 'catalog_api_max_json_bytes')
        && str_contains($bootstrap, '1024 * 1024'),
    'File-by-file finalisation should not weaken the shared API JSON body limit.'
);

fwrite(STDOUT, "Upload Bucket per-file finalisation contract tests passed.\n");
