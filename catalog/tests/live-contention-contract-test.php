<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies live contention behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function live_contention_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../live-contention.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
live_contention_expect(is_string($page) && $page !== '', 'Live contention page is missing.');
live_contention_expect(is_string($navigation) && $navigation !== '', 'Administrator navigation is missing.');

foreach ([
    'information_schema.PROCESSLIST',
    'information_schema.innodb_trx',
    'sys.innodb_lock_waits',
    'ue_background_jobs WHERE queue_name=? AND status="running"',
    'session_write_close()',
    'live_contention_sql(',
    'SQL literals are replaced with question marks',
] as $fragment) {
    live_contention_expect(str_contains($page, $fragment), 'Live contention diagnostics are missing: ' . $fragment);
}
live_contention_expect(
    str_contains($navigation, "'Live Contention' => \$root . 'live-contention.php'"),
    'Live Contention is missing from the Maintenance navigation.'
);

echo "Live contention contract tests passed.\n";
