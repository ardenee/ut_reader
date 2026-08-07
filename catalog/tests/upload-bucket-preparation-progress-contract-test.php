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

$script = file_get_contents(__DIR__ . '/../assets/upload-bucket-v2-coordinator.js');
upload_bucket_progress_expect(is_string($script) && $script !== '', 'Could not read Upload Bucket coordinator.');

foreach ([
    "'Requesting the existing Upload Bucket worker to stop after its current file...'",
    "'Calculating MD5 and SHA-1 for '",
    "'Uploading '",
    "'Verifying staged file '",
    "'Waiting for ' + workerDescription(processing)",
    "'Validating and queuing: '",
    "overallLabel.textContent = 'Upload queue complete'",
    'workerDescription(processing)',
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
    str_contains($script, "initialProcessing = await processingState('begin_batch')")
        && str_contains($script, 'await waitUntilPaused(initialProcessing, lineId)')
        && str_contains($script, 'await uploadFile(file, name, inspection'),
    'Worker pause and durable file transfer are no longer coordinated through the one-file v2 pipeline.'
);

fwrite(STDOUT, "Upload Bucket state-based progress contract tests passed.\n");
