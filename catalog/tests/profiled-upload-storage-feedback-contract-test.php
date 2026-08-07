<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies profiled upload storage feedback behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function storage_feedback_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$stream = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php');
$payload = file_get_contents($root . '/lib/CatalogRedirectArchivePayload.php');
$feedback = file_get_contents($root . '/assets/profiled-upload-diagnostics.js');

storage_feedback_expect(is_string($stream), 'Streaming redirect decoder is missing.');
storage_feedback_expect(is_string($payload), 'Legacy redirect decoder is missing.');
storage_feedback_expect(is_string($feedback), 'Profiled upload feedback client is missing.');

storage_feedback_expect(
    str_contains($stream, "tempnam(dirname(\$sourcePath), '.ue_redirect_')"),
    'Streamed UZ2 output is still allocated in the operating-system temp folder.'
);
storage_feedback_expect(
    str_contains($payload, "tempnam(dirname(\$sourcePath), '.ue_redirect_')"),
    'Legacy redirect output is still allocated in the operating-system temp folder.'
);
storage_feedback_expect(
    !str_contains($stream, 'tempnam(sys_get_temp_dir()')
        && !str_contains($payload, 'tempnam(sys_get_temp_dir()'),
    'Redirect imports can still cross the Synology @tmp/shared-folder rename boundary.'
);
storage_feedback_expect(
    str_contains($feedback, 'new MutationObserver(compactFeedback)')
        && str_contains($feedback, 'latestByFile'),
    'Profiled upload feedback is not compacted to one current row per file.'
);
storage_feedback_expect(
    !str_contains($feedback, 'Worker log:'),
    'Raw detached-worker log lines are still written into the upload feedback list.'
);

echo "Profiled upload storage and feedback contract tests passed.\n";
