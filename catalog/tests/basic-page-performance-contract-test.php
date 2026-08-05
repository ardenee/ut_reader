<?php
declare(strict_types=1);

function basic_performance_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dashboardStats = file_get_contents(__DIR__ . '/../src/Application/Dashboard/CatalogDashboardStats.php');
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
$library = file_get_contents(__DIR__ . '/../library.php');
$missingCounts = file_get_contents(__DIR__ . '/../api/v1/game-missing-counts.php');
$resourceTracing = file_get_contents(__DIR__ . '/../lib/CatalogResourceTracing.php');
$audit = file_get_contents(__DIR__ . '/../basic-performance-audit.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');

foreach ([
    'dashboard stats' => $dashboardStats,
    'dashboard' => $dashboard,
    'library' => $library,
    'game missing-count API' => $missingCounts,
    'resource tracing' => $resourceTracing,
    'audit page' => $audit,
    'navigation' => $navigation,
] as $name => $source) {
    basic_performance_expect(is_string($source) && $source !== '', 'Could not read ' . $name . '.');
}

basic_performance_expect(
    !str_contains($dashboardStats, 'refreshStale('),
    'Dashboard statistics must not synchronously rebuild stale game projections.'
);
basic_performance_expect(
    !str_contains($library, 'refreshStale('),
    'Library must not synchronously rebuild stale game projections.'
);
basic_performance_expect(
    !str_contains($missingCounts, 'refreshStale('),
    'Game missing-count API must not synchronously rebuild stale game projections.'
);
basic_performance_expect(
    str_contains($dashboard, 'session_write_close()'),
    'Dashboard must release the PHP session lock before catalogue reads.'
);
basic_performance_expect(
    str_contains($library, 'session_write_close()'),
    'Library must release the PHP session lock before catalogue reads.'
);
basic_performance_expect(
    str_contains($resourceTracing, "(\$_SESSION['user']['role'] ?? '') === 'admin'"),
    'Resource tracing must preserve admin attribution after session_write_close().' 
);
basic_performance_expect(
    !str_contains($resourceTracing, "session_status() === PHP_SESSION_ACTIVE && ((\$_SESSION['user']['role'] ?? '') === 'admin')"),
    'Resource tracing must not require the admin session lock to remain active.'
);
basic_performance_expect(
    str_contains($audit, 'UnrealDbBasicAuditTargets'),
    'Basic page audit must expose its sequential browser targets.'
);
basic_performance_expect(
    str_contains($audit, 'Timed out after 15 seconds'),
    'Basic page audit must fail bounded requests rather than waiting indefinitely.'
);
basic_performance_expect(
    str_contains($navigation, "'Basic Page Audit' => \$root . 'basic-performance-audit.php'"),
    'Basic Page Audit must be linked from Maintenance.'
);

echo "Basic page performance contract tests passed.\n";
