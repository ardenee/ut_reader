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
$handoff = file_get_contents($root . '/assets/upload-bucket-follow.js');

live_layout_expect(is_string($page), 'Background Jobs page is missing.');
live_layout_expect(is_string($jobs), 'Stable Background Jobs controller is missing.');
live_layout_expect(is_string($uploadPage), 'Upload Bucket page is missing.');
live_layout_expect(is_string($handoff), 'Upload Bucket handoff controller is missing.');

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
    str_contains($uploadPage, 'data-processing-url=')
        && str_contains($uploadPage, 'upload-bucket-follow.js')
        && str_contains($handoff, 'Opening the processing queue in 3 seconds')
        && str_contains($handoff, 'window.location.assign(queueUrl)'),
    'Upload Bucket does not automatically hand completed batches to the processing queue.'
);

echo "Background Jobs live-layout and Upload Bucket handoff contract tests passed.\n";
