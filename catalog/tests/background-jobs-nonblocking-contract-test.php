<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies background jobs nonblocking behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function background_jobs_nonblocking_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$apiBootstrap = file_get_contents(__DIR__ . '/../api/v1/_bootstrap.php');
$bridge = file_get_contents(__DIR__ . '/../assets/background-jobs-cursor-bridge.js');

background_jobs_nonblocking_expect(
    is_string($apiBootstrap) && $apiBootstrap !== '',
    'The API bootstrap source is missing.'
);
background_jobs_nonblocking_expect(
    is_string($bridge) && $bridge !== '',
    'The Background Jobs browser bridge source is missing.'
);

background_jobs_nonblocking_expect(
    str_contains($apiBootstrap, 'function catalog_api_release_read_session(): void')
        && str_contains($apiBootstrap, "['GET', 'HEAD', 'OPTIONS']")
        && str_contains($apiBootstrap, 'session_write_close();')
        && str_contains($apiBootstrap, 'catalog_api_release_read_session();'),
    'Read-only authenticated API requests do not release the PHP session lock.'
);

background_jobs_nonblocking_expect(
    str_contains($bridge, 'const activeStatusControllers = new Set();')
        && str_contains($bridge, "window.addEventListener('pagehide', abortStatusRequests")
        && str_contains($bridge, "window.addEventListener('beforeunload', abortStatusRequests")
        && str_contains($bridge, 'const controller = new AbortController();')
        && str_contains($bridge, '}, 15000);')
        && str_contains($bridge, 'await statusFetch(url.toString(), options)')
        && str_contains($bridge, '? await statusFetch(input, requestOptions)'),
    'Background Jobs status requests are not cancellable during navigation.'
);

echo "Background Jobs nonblocking contract tests passed.\n";
