<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket extension filter behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function upload_bucket_extension_filter_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/upload-bucket.php');
$filter = file_get_contents($root . '/assets/upload-bucket-extension-filter.js');

upload_bucket_extension_filter_expect(is_string($page), 'Upload Bucket page is missing.');
upload_bucket_extension_filter_expect(is_string($filter), 'Upload Bucket extension filter is missing.');

upload_bucket_extension_filter_expect(
    str_contains($page, 'data-allowed-extensions=')
        && str_contains($page, 'upload-bucket-extension-filter.js')
        && strpos($page, 'upload-bucket-extension-filter.js') < strpos($page, 'upload-bucket.js'),
    'The server does not expose active profile extensions or load the prefilter before the uploader.'
);

upload_bucket_extension_filter_expect(
    str_contains($filter, 'new window.DataTransfer()')
        && str_contains($filter, 'input.files = transfer.files')
        && str_contains($filter, "['uz', 'uz2', 'uz3']")
        && str_contains($filter, 'Skipped before hashing or upload; no retry was attempted.'),
    'Unsupported files are not removed before hashing, duplicate preflight and transfer.'
);

upload_bucket_extension_filter_expect(
    str_contains($filter, "form.addEventListener('submit'")
        && str_contains($filter, 'event.stopImmediatePropagation()')
        && str_contains($filter, 'No selected files use an allowed package extension.'),
    'An all-unsupported selection can still enter the resumable uploader.'
);

echo "Upload Bucket extension prefilter contract tests passed.\n";
