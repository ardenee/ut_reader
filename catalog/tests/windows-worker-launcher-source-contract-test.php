<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies windows worker launcher source behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Detached worker source is missing.');
}

$required = [
    'Start-Process -FilePath',
    'RedirectStandardOutput',
    'RedirectStandardError',
    'Write-Output \\$process.Id',
    'microtime(true) + 10.0',
    "time() - 15",
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing Windows worker launcher contract: ' . $needle);
    }
}
if (str_contains($source, 'start "" /B')) {
    throw new RuntimeException('The unreliable cmd.exe start /B launcher is still present.');
}

echo "Windows worker launcher source contract tests passed.\n";
