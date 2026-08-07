<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies upload bucket issues behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function upload_issues_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/install.sql');
$store = file_get_contents($root . '/src/Infrastructure/Import/CatalogUploadBucketIssueStore.php');
$api = file_get_contents($root . '/api/v1/upload-bucket-issue.php');
$recorder = file_get_contents($root . '/assets/upload-bucket-v2-issue-recorder.js');
$workerCompatibility = file_get_contents($root . '/assets/upload-file-inspector-worker-compatible.js');
$uploader = file_get_contents($root . '/upload-bucket-v2.php');
$review = file_get_contents($root . '/upload-issues.php');
$navigation = file_get_contents($root . '/lib/CatalogNavigation.php');

foreach (compact('migration', 'store', 'api', 'recorder', 'workerCompatibility', 'uploader', 'review', 'navigation') as $name => $source) {
    upload_issues_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

upload_issues_expect(
    str_contains($migration, '-- 202607310001: persistent Upload Bucket issues.')
        && str_contains($migration, 'CREATE TABLE ue_upload_bucket_issues')
        && str_contains($migration, 'UNIQUE KEY uq_ue_upload_bucket_issues_key')
        && str_contains($migration, 'occurrence_count INT UNSIGNED NOT NULL DEFAULT 1')
        && str_contains($migration, 'resolution_note VARCHAR(500) NULL'),
    'The persistent Upload Issues schema is incomplete.'
);

upload_issues_expect(
    str_contains($store, 'final class CatalogUploadBucketIssueStore')
        && str_contains($store, 'ON DUPLICATE KEY UPDATE')
        && str_contains($store, 'occurrence_count=occurrence_count+1')
        && str_contains($store, 'public function setStatus')
        && str_contains($store, "['open', 'resolved', 'ignored']"),
    'Upload Issue persistence or review-state handling is missing.'
);

upload_issues_expect(
    str_contains($api, 'catalog_api_require_admin(false)')
        && str_contains($api, "catalog_api_require_csrf('upload_bucket_chunk')")
        && str_contains($api, 'CatalogUploadBucketIssueStore')
        && str_contains($api, '$store->record($payload, $userId)'),
    'The Upload Issue API does not enforce admin/CSRF protection and persist records.'
);

upload_issues_expect(
    str_contains($recorder, 'captureCoordinatorLog')
        && str_contains($recorder, 'shouldRecordLine')
        && str_contains($recorder, "if (outcome === 'failed') return true")
        && str_contains($recorder, "outcome === 'skipped' && detail.indexOf('EXTENSION NOT ALLOWED')")
        && !str_contains($recorder, "outcome === 'uploaded' &&")
        && str_contains($recorder, 'window.localStorage')
        && str_contains($recorder, 'flushPending')
        && str_contains($recorder, 'nextInspectIndex')
        && str_contains($recorder, 'originalToErrorIndex')
        && str_contains($recorder, 'applyLogFilter')
        && str_contains($recorder, "recordBatch('worker_pause'")
        && str_contains($recorder, "recordBatch('worker_start'"),
    'The browser recorder does not retain only actual errors or provide the default errors-only view.'
);

upload_issues_expect(
    str_contains($workerCompatibility, "signature !== 1234 && signature !== 5678")
        && str_contains($workerCompatibility, 'Expected 1234 or 5678')
        && str_contains($workerCompatibility, "replace(/\\.uz$/i, '.uz3')")
        && str_contains($workerCompatibility, "importScripts('upload-file-inspector-worker.js')"),
    'The browser inspector does not accept both historic signatures for .uz files.'
);

upload_issues_expect(
    str_contains($uploader, 'data-issue-url="api/v1/upload-bucket-issue.php"')
        && str_contains($uploader, 'upload-bucket-v2-issue-recorder.js')
        && strpos($uploader, 'upload-bucket-v2-issue-recorder.js') < strpos($uploader, 'upload-bucket-v2-coordinator.js')
        && str_contains($uploader, 'id="upload-bucket-errors-only" type="checkbox" checked')
        && str_contains($uploader, 'id="bucket-error-log"')
        && str_contains($uploader, 'upload-file-inspector-worker-compatible.js')
        && str_contains($uploader, "'Upload Issues' => 'upload-issues.php'")
        && str_contains($uploader, 'Only errors are written to'),
    'Canonical Upload Bucket does not expose persistent error recording, signature compatibility and errors-only review.'
);

upload_issues_expect(
    str_contains($review, "catalog_check_csrf('upload_issues')")
        && str_contains($review, 'Resolve')
        && str_contains($review, 'Ignore')
        && str_contains($review, 'Reopen')
        && str_contains($review, 'occurrence_count')
        && str_contains($review, 'Processing job failures')
        && str_contains($review, 'ue_background_jobs')
        && str_contains($navigation, "'Upload Issues' => \$root . 'upload-issues.php'"),
    'The persistent issue review and resolution workflow is incomplete.'
);

fwrite(STDOUT, "Persistent Upload Bucket error review and errors-only display contract tests passed.\n");
