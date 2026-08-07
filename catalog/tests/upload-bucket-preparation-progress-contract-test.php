<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket preparation progress behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function upload_bucket_progress_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$script = file_get_contents(__DIR__ . '/../assets/upload-bucket-coordinator.js');
upload_bucket_progress_expect(is_string($script) && $script !== '', 'Could not read Upload Bucket coordinator.');

foreach ([
    "overallLabel.textContent = 'Uploading selected files to durable staging'",
    "currentLabel.textContent = 'Calculating MD5 and SHA-1 for '",
    "currentLabel.textContent = 'Uploading '",
    "currentLabel.textContent = 'Verifying durable staged file '",
    "currentLabel.textContent = 'Transfers are complete. Waiting for '",
    "overallLabel.textContent = 'Queuing staged files one at a time'",
    "currentLabel.textContent = 'Validating and queuing staged file '",
    "overallLabel.textContent = 'Upload and file-by-file finalisation complete'",
    'workerDescription(processing)',
    'result.retained',
] as $fragment) {
    upload_bucket_progress_expect(
        str_contains($script, $fragment),
        'Upload Bucket state-based progress is missing: ' . $fragment
    );
}

upload_bucket_progress_expect(
    !str_contains($script, 'elapsedText()')
        && !str_contains($script, 'setInterval(')
        && !str_contains($script, 'Opening the processing queue in 3 seconds'),
    'Upload Bucket progress still depends on elapsed-time or countdown presentation.'
);

upload_bucket_progress_expect(
    str_contains($script, 'const pausePromise = processingState(\'begin_batch\')')
        && str_contains($script, 'await waitUntilPaused(pausePromise)')
        && str_contains($script, 'No newly uploaded file is being processed yet.'),
    'Worker pause is not performed in parallel with durable file transfer.'
);

fwrite(STDOUT, "Upload Bucket state-based progress contract tests passed.\n");
