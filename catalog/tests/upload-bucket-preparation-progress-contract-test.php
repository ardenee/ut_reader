<?php
declare(strict_types=1);

function upload_bucket_preparation_progress_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$script = file_get_contents(__DIR__ . '/../assets/upload-bucket-follow.js');
upload_bucket_preparation_progress_expect(is_string($script), 'Could not read Upload Bucket follow/progress script.');

foreach ([
    'Phase 1 of 3 — Prepare durable staging and pause processing',
    'Phase 2 of 3 — Check identities and upload files',
    'Phase 3 of 3 — Finalise batch and create processing jobs',
    'no selected-file bytes transferred yet',
    'Scanning stale incomplete chunk staging',
    'The active Upload Bucket job is finishing normally',
    'elapsedText()',
    "bar.removeAttribute('value')",
    "new MutationObserver(inspect)",
] as $fragment) {
    upload_bucket_preparation_progress_expect(
        str_contains($script, $fragment),
        'Upload Bucket preparation progress is missing: ' . $fragment
    );
}

upload_bucket_preparation_progress_expect(
    str_contains($script, "if (/^Preparing durable upload staging/i.test(text))")
    && str_contains($script, "if (/^Waiting for the current Upload Bucket job/i.test(text))")
    && str_contains($script, "beginTimedPhase('prepare'")
    && str_contains($script, "beginTimedPhase(\n                'finalize'"),
    'Upload Bucket preparation/finalisation messages are not mapped into visible phases.'
);

upload_bucket_preparation_progress_expect(
    str_contains($script, 'showHandoff()')
    && str_contains($script, 'Opening the processing queue in 3 seconds'),
    'The existing automatic processing-queue handoff was lost.'
);

echo "Upload Bucket preparation progress contract tests passed.\n";
