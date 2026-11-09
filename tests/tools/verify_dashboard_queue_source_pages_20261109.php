<?php
/**
 * Reproducible read-only count, status and pagination verification against
 * actual catalogue records. Expected counts are taken live from source.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';
require_once dirname(__DIR__, 2) . '/catalog/lib/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferService;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardDetailPageQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardStatsQuery;

function dashboardQueueAssert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$config = catalog_config();
$db = catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $stats = (new PdoDashboardStatsQuery($db))->load();
    $mirrorExpected = (int)$stats['mirrorWaiting'];
    $fedExpected = (int)$stats['fedQueued'];
    $failedExpected = (int)$stats['failed'];
    $mirror = new CatalogExternalMirrorAdminService($db, $config);
    $detail = new PdoDashboardDetailPageQuery($db);

    $mirrorIds = [];
    $first = $mirror->pageJobs(1,100,'waiting');
    dashboardQueueAssert($first['total'] === $mirrorExpected,
        'Mirror Queue waiting filter does not match Dashboard count');
    $pages = max(1, (int)ceil($mirrorExpected / 100));
    for ($page = 1; $page <= $pages; ++$page) {
        $result = $mirror->pageJobs($page,100,'waiting');
        dashboardQueueAssert($result['page'] === $page && $result['total'] === $mirrorExpected,
            'Mirror operational queue pagination or total unstable');
        foreach ($result['rows'] as $row) {
            $id = (int)$row['id'];
            dashboardQueueAssert(!isset($mirrorIds[$id]), 'Repeated mirror job');
            dashboardQueueAssert(in_array((string)$row['status'],
                ['queued','waiting_admin','uploading'], true), 'Nonwaiting mirror job included');
            $mirrorIds[$id] = true;
        }
    }
    dashboardQueueAssert(count($mirrorIds) === $mirrorExpected,
        'Mirror Queue pagination omitted historical waiting jobs');

    $queuedRows = $detail->page('fedQueued',1,100);
    dashboardQueueAssert(count($queuedRows) === min($fedExpected,100),
        'Queued federation detail differs from Dashboard count');
    $fed = new CatalogFederationTransferService($db);
    $operational = $fed->counts();
    dashboardQueueAssert($operational['active'] >= min($fedExpected,100),
        'Operational federation queue still excludes source queued records');
    dashboardQueueAssert(function_exists('federation_visible_transfer_job_sql'),
        'Original pre-refactor federation SQL policy helper missing');
    dashboardQueueAssert(function_exists('federation_base_game_policy_label'),
        'Original pre-refactor federation diagnostics policy helper missing');

    $failed = (int)$db->query(
        'SELECT COUNT(*) FROM ue_files WHERE scan_status="failed"'
    )->fetchColumn();
    dashboardQueueAssert($failed === $failedExpected,
        'Failed files are misreported by dashboard');
    $recorded = (int)$db->query(
        'SELECT COUNT(*) FROM ue_files WHERE scan_status="failed" '
        . 'AND (scan_notes IS NOT NULL OR metadata_error IS NOT NULL)'
    )->fetchColumn();

    $db->rollBack();
    echo json_encode([
        'pass'=>true,'read_only'=>true,
        'mirror_waiting'=>$mirrorExpected,
        'mirror_operation_pages'=>$pages,
        'mirror_unique_rows_reached'=>count($mirrorIds),
        'queued_federation'=>$fedExpected,
        'federation_operational_active'=>$operational['active'],
        'federation_original_policy_helpers_restored'=>true,
        'failed_file_count'=>$failedExpected,
        'failed_files_with_diagnosis'=>$recorded,
        'no_production_mutations'=>true,
    ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'DASHBOARD_QUEUE_READONLY_FAIL '.$error->getMessage().PHP_EOL);
    exit(1);
}
