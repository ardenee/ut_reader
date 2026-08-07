<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies canonical Upload Bucket extension filtering before inspection and transfer.
 * Why: Unsupported files must be skipped cheaply without invoking hashing, duplicate preflight, or upload work.
 * Role: Contract test for the integrated v2 coordinator extension policy.
 * Audit: Keep aligned with the canonical Upload Bucket coordinator; the retired standalone prefilter must not return.
 */
declare(strict_types=1);

function upload_bucket_extension_filter_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/upload-bucket-v2.php');
$coordinator = file_get_contents($root . '/assets/upload-bucket-v2-coordinator.js');

foreach (compact('page', 'coordinator') as $name => $source) {
    upload_bucket_extension_filter_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

upload_bucket_extension_filter_expect(
    str_contains($page, 'data-allowed-extensions=')
        && str_contains($page, 'upload-bucket-v2-coordinator.js')
        && !str_contains($page, 'upload-bucket-extension-filter.js'),
    'The canonical page does not expose profile extensions directly to the integrated coordinator.'
);

upload_bucket_extension_filter_expect(
    str_contains($coordinator, 'const allowedExtensions = new Set(')
        && str_contains($coordinator, "['uz', 'uz2', 'uz3'].forEach")
        && str_contains($coordinator, 'function isAllowedName(name)')
        && str_contains($coordinator, "finishLine(lineId, 'SKIPPED', 'skipped', 'EXTENSION NOT ALLOWED')")
        && strpos($coordinator, "!isAllowedName(name)") < strpos($coordinator, 'await inspectFile(file, name'),
    'Unsupported files are not skipped before browser inspection, hashing, preflight and transfer.'
);

upload_bucket_extension_filter_expect(
    !is_file($root . '/assets/upload-bucket-extension-filter.js'),
    'The retired standalone extension prefilter still exists.'
);

echo "Canonical Upload Bucket integrated extension filter contract tests passed.\n";
