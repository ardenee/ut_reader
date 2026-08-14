<?php
/**
 * Read-only queue runtime invariant audit.
 *
 * Intended for manual/server diagnostics without GitHub Actions. It verifies the
 * invariants that must hold after concurrent claims: resource-class limits and
 * exclusive concurrency keys. It never changes queue state.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobAdmissionGuard;

$application = catalog_bootstrap(false);
$db = $application->db;
$queue = trim((string)($application->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
$guard = new PdoJobAdmissionGuard($db);

$resourceViolations = [];
$resource = $db->prepare(
    'SELECT COALESCE(NULLIF(resource_class,""),"default") resource_class,'
    . 'COUNT(*) running_jobs,MAX(GREATEST(1,COALESCE(resource_limit,1))) persisted_limit '
    . 'FROM ue_background_jobs WHERE queue_name=? AND status="running" '
    . 'GROUP BY COALESCE(NULLIF(resource_class,""),"default") ORDER BY resource_class'
);
$resource->execute([$queue]);
foreach ($resource->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $class = (string)$row['resource_class'];
    $running = (int)$row['running_jobs'];
    $effectiveLimit = $guard->currentLimit($class, (int)$row['persisted_limit']);
    if ($running > $effectiveLimit) {
        $resourceViolations[] = [
            'resource_class' => $class,
            'running' => $running,
            'effective_limit' => $effectiveLimit,
        ];
    }
}

$concurrencyViolations = [];
$concurrency = $db->prepare(
    'SELECT concurrency_key,COUNT(*) running_jobs,GROUP_CONCAT(id ORDER BY id) job_ids '
    . 'FROM ue_background_jobs WHERE queue_name=? AND status="running" '
    . 'AND concurrency_key IS NOT NULL AND concurrency_key<>"" '
    . 'GROUP BY concurrency_key HAVING COUNT(*)>1 ORDER BY concurrency_key'
);
$concurrency->execute([$queue]);
foreach ($concurrency->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $concurrencyViolations[] = [
        'concurrency_key' => (string)$row['concurrency_key'],
        'running' => (int)$row['running_jobs'],
        'job_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string)($row['job_ids'] ?? ''))
        ))),
    ];
}

$result = [
    'queue' => $queue,
    'verified_at' => gmdate(DATE_ATOM),
    'ok' => $resourceViolations === [] && $concurrencyViolations === [],
    'resource_limit_violations' => $resourceViolations,
    'concurrency_key_violations' => $concurrencyViolations,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result['ok'] ? 0 : 2);
