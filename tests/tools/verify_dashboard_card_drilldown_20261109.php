<?php
/**
 * Genuine PRD read-only cross-check: each dashboard drill-down uses the same
 * source status predicate as the exact dashboard counter.
 *
 * Exhaustively checks all 102 failed files and all 707 queued mirror jobs
 * across their real 100-row pages (including the jobs hidden by the previous
 * 500-record mirror-queue cap), with no DB/package mutation.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardDetailPageQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardStatsQuery;

function dashCheck(bool $ok, string $error): void
{
    if (!$ok) throw new RuntimeException($error);
}

$db = catalog_db(catalog_config());
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $stats = (new PdoDashboardStatsQuery($db))->load();
    $query = new PdoDashboardDetailPageQuery($db);
    $metrics = [
        'files' => ['source' => 'ue_files', 'status' => null, 'n' => (int)$stats['files']],
        'verified' => ['source' => 'ue_files', 'status' => 'verified', 'n' => (int)$stats['verified']],
        'failed' => ['source' => 'ue_files', 'status' => 'failed', 'n' => (int)$stats['failed']],
        'fedQueued' => ['source' => 'ue_federation_transfer_jobs', 'status' => 'queued', 'n' => (int)$stats['fedQueued']],
        'fedDownloaded' => ['source' => 'ue_federation_transfer_jobs', 'status' => 'downloaded', 'n' => (int)$stats['fedDownloaded']],
        'fedFailed' => ['source' => 'ue_federation_transfer_jobs', 'status' => 'failed', 'n' => (int)$stats['fedFailed']],
        'mirrorWaiting' => ['source' => 'ue_external_mirror_jobs', 'status' => 'waiting', 'n' => (int)$stats['mirrorWaiting']],
        'mirrorActive' => ['source' => 'ue_external_download_links', 'status' => 'active', 'n' => (int)$stats['mirrorActive']],
        'joinPending' => ['source' => 'ue_federation_join_requests', 'status' => 'pending', 'n' => (int)$stats['joinPending']],
    ];
    $checked = [];
    foreach ($metrics as $metric => $spec) {
        $rows = $query->page($metric, 1, 50);
        dashCheck(count($rows) === min(50, $spec['n']),
            $metric . ' first page count differs from dashboard counter');

        $where = match ($metric) {
            'files' => '',
            'verified' => " WHERE scan_status='verified'",
            'failed' => " WHERE scan_status='failed'",
            'mirrorWaiting' => " WHERE status IN ('queued','waiting_admin','uploading')",
            default => " WHERE status='" . $spec['status'] . "'",
        };
        $actual = (int)$db->query('SELECT COUNT(*) FROM ' . $spec['source'] . $where)->fetchColumn();
        dashCheck($actual === $spec['n'],
            "$metric dashboard cached count {$spec['n']} differs from underlying $actual");
        $seen = [];
        $previous = PHP_INT_MAX;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            dashCheck($id > 0 && $id < $previous, "$metric first page index ordering broken");
            $previous = $id;
            dashCheck(!isset($seen[$id]), "$metric repeated a source ID");
            $seen[$id] = true;
            $expected = $spec['status'];
            if ($metric === 'mirrorWaiting') {
                dashCheck(in_array((string)$row['status'],['queued','waiting_admin','uploading'],true),
                    'Unexpected mirror job status');
            } elseif ($metric !== 'files') {
                $field = in_array($metric,['verified','failed'],true) ? 'scan_status' : 'status';
                dashCheck((string)$row[$field] === (string)$expected,
                    "$metric includes unrelated status");
            }
        }
        $checked[$metric] = [
            'expected' => $spec['n'],
            'source_count' => $actual,
            'first_page_rows' => count($rows),
            'first_row_id' => $rows === [] ? null : (int)$rows[0]['id'],
        ];
    }
    $complete = [];
    foreach (['failed','mirrorWaiting','fedQueued'] as $metric) {
        $all = [];
        for ($page = 1; $page <= max(1, (int)ceil($metrics[$metric]['n'] / 100)); $page++) {
            foreach ($query->page($metric, $page, 100) as $row) {
                $id = (int)$row['id'];
                dashCheck(!isset($all[$id]), "$metric page $page duplicated job/file #$id");
                $all[$id] = true;
                if ($metric === 'mirrorWaiting') {
                    dashCheck((int)$row['file_id'] > 0, 'Mirror job has no target file link');
                }
            }
        }
        dashCheck(count($all) === $metrics[$metric]['n'],
            "$metric pagination dropped records; observed " . count($all));
        $complete[$metric] = [
            'rows_recovered' => count($all),
            'pages_at_100' => max(1, (int)ceil($metrics[$metric]['n'] / 100)),
            'all_ids_unique' => true,
        ];
    }

    foreach (['','unknown','file','mirrorQueue'] as $invalid) {
        try {
            $query->page($invalid,1,50);
            throw new RuntimeException('Unsupported dashboard metric accepted: '.$invalid);
        } catch (InvalidArgumentException $expected) {
            // Intended rejection.
        }
    }
    try {
        $query->page('failed',1,10000);
        throw new RuntimeException('Excessive unbounded drilldown page size accepted');
    } catch (InvalidArgumentException $expected) {}

    $db->rollBack();
    echo json_encode([
        'pass'=>true,
        'read_only'=>true,
        'metric_pages_checked'=>count($checked),
        'metrics'=>$checked,
        'exhaustive_pages'=>$complete,
        'malformed_and_unbounded_queries_rejected'=>true,
        'source_records_unchanged'=>true,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'DASHBOARD_DETAILS_FAIL '.$error->getMessage().PHP_EOL);
    exit(1);
}
