<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies background jobs failure export behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$bridge = file_get_contents($root . '/assets/background-jobs-cursor-bridge.js');
$export = file_get_contents($root . '/assets/background-jobs-failure-export.js');

if (!is_string($bridge) || !str_contains($bridge, 'background-jobs-failure-export.js')) {
    throw new RuntimeException('Background Jobs does not load the compact failure export.');
}
if (!is_string($export)) {
    throw new RuntimeException('Compact failure export script is missing.');
}
foreach ([
    'Compact filename / error list',
    'Generate from current filters',
    'Copy filenames',
    'Copy filename + error',
    'Download TSV',
    "per_page: '1000'",
    'Include repeated attempts',
    'new XMLHttpRequest()',
] as $required) {
    if (!str_contains($export, $required)) {
        throw new RuntimeException('Compact failure export is missing: ' . $required);
    }
}
if (!str_contains($export, "if (!includeRepeated && seen.has(key)) return;")) {
    throw new RuntimeException('Compact failure export does not suppress repeated attempts by default.');
}

echo "Background Jobs compact failure export contract passed.\n";
