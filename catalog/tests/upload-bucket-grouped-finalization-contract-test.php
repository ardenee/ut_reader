<?php
declare(strict_types=1);

function upload_bucket_grouped_finalize_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$follow = file_get_contents($root . '/assets/upload-bucket-follow.js');
$endpoint = file_get_contents($root . '/api/v1/upload-bucket-batch.php');
$bootstrap = file_get_contents($root . '/api/v1/_bootstrap.php');

foreach (compact('follow', 'endpoint', 'bootstrap') as $name => $source) {
    upload_bucket_grouped_finalize_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

upload_bucket_grouped_finalize_expect(
    str_contains($follow, 'const FINALIZE_GROUP_SIZE = 500;')
        && str_contains($follow, 'uploadIds.slice(start, start + FINALIZE_GROUP_SIZE)')
        && str_contains($follow, 'start_worker: isFinalGroup')
        && str_contains($follow, 'const nativeFetch = window.fetch.bind(window);')
        && str_contains($follow, 'Finalising batch group ')
        && str_contains($follow, 'totals.queued +=')
        && str_contains($follow, 'totals.duplicates +=')
        && str_contains($follow, 'totals.failed +='),
    'Browser finalisation does not split and aggregate large upload-ID manifests.'
);

upload_bucket_grouped_finalize_expect(
    str_contains($endpoint, "array_key_exists('start_worker', \$payload)")
        && str_contains($endpoint, "if (!is_bool(\$payload['start_worker']))")
        && str_contains($endpoint, 'if ($startWorker && $pendingJobs > 0)')
        && str_contains($endpoint, "'start_worker' => \$startWorker")
        && str_contains($endpoint, 'Finalize no more than 10,000 uploaded files per request.'),
    'Batch endpoint does not keep the worker paused for intermediate finalisation groups.'
);

upload_bucket_grouped_finalize_expect(
    str_contains($bootstrap, 'catalog_api_max_json_bytes')
        && str_contains($bootstrap, '1024 * 1024'),
    'The grouped finalisation fix should not weaken the shared API JSON body limit.'
);

fwrite(STDOUT, "Upload Bucket grouped finalisation contract tests passed.\n");
