#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$cache = (string)@file_get_contents(
    $root . '/src/Infrastructure/Persistence/PdoPackageCoverageCache.php'
);
$reconcile = (string)@file_get_contents(
    $root . '/src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php'
);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'targeted_package_reconciliation_exists',
    str_contains($cache, 'public function reconcilePackage(')
        && str_contains($cache, '$this->deletePackage($gameId, $packageName);'),
    'coverage cache must support targeted rebuild/prune after provider changes'
);

$record(
    'single_provider_cache_is_pruned',
    str_contains($cache, 'count($providers) < 2')
        && str_contains($cache, '$requiredCount < 1'),
    'coverage cache must not retain stale multi-provider results'
);

$record(
    'game_rebuild_revisits_existing_cache',
    str_contains($cache, 'SELECT package_name FROM ue_package_coverage_cache WHERE game_id=?')
        && str_contains($cache, '$this->reconcilePackage($gameId, $packageName)'),
    'game rebuild must revisit stale historical cached package names'
);

$record(
    'projection_reconciliation_refreshes_coverage',
    str_contains($reconcile, 'new PdoPackageCoverageCache($this->db)')
        && str_contains($reconcile, '->reconcilePackage($gameId, $packageName)'),
    'provider/dependency projection reconciliation must refresh package coverage'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
