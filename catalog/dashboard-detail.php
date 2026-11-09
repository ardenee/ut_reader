<?php
/**
 * Administrator-only, bounded detail rows behind Dashboard statistic cards.
 * Reads real source rows with predicates matching the dashboard counters.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardStatsQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDashboardDetailPageQuery;

catalog_start_session();
if (!catalog_require_admin_page('Dashboard details')) {
    exit;
}

// These are explicit administrator-only maintenance actions; viewing the
// dashboard and opening its links remain read-only. A counted confirmation,
// CSRF and status-qualified transaction protect each bulk cancellation.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $metric = (string)($_POST['metric'] ?? '');
    if (!in_array($metric, ['mirrorWaiting','fedQueued'], true)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsupported dashboard queue action.';
        exit;
    }
    try {
        catalog_check_csrf('dashboard_queue_actions');
    } catch (Throwable $error) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid administrator request.';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['job_id'] ?? 0);
    $expected = (int)($_POST['expected_count'] ?? 0);
    $page = max(1, min(100000, (int)($_POST['page'] ?? 1)));
    $pageSize = (int)($_POST['page_size'] ?? 50);
    if (!in_array($pageSize, [50,100], true)) $pageSize = 50;
    try {
        $config = catalog_config();
        $db = catalog_db($config);
        if ($metric === 'mirrorWaiting') {
            $service = new \UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService($db, $config);
            if ($action === 'cancel_one') {
                if ($id < 1) throw new RuntimeException('A valid mirror job ID is required.');
                $job = $service->job($id);
                if (!in_array((string)$job['status'], ['queued','waiting_admin'], true)) {
                    throw new RuntimeException('Only queued/waiting-admin mirror jobs can be cancelled.');
                }
                $message = $service->handleQueueAction('cancel', $id);
            } elseif ($action === 'cancel_all') {
                if ((string)($_POST['confirm_phrase'] ?? '') !== 'CANCEL') {
                    throw new RuntimeException('Type CANCEL to confirm the bulk operation.');
                }
                $count = $service->cancelPendingBulk($expected);
                $message = 'Cancelled ' . $count . ' queued/waiting-admin mirror jobs. '
                    . 'Historical records, files and active mirror links were kept.';
            } elseif ($action === 'purge_cancelled_all') {
                if ((string)($_POST['confirm_phrase'] ?? '') !== 'PURGE') {
                    throw new RuntimeException('Type PURGE to confirm permanent deletion.');
                }
                $count = $service->purgeCancelledBulk($expected);
                $message = 'Permanently removed ' . $count . ' cancelled mirror job records. '
                    . 'Original packages and external download links were preserved.';
            } else {
                throw new RuntimeException('Unsupported mirror queue action.');
            }
        } else {
            $service = new \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferService($db);
            if ($action === 'cancel_one') {
                if ($id < 1) throw new RuntimeException('A valid federation job ID is required.');
                $outcome = $service->handle($id, 'cancel', 'active');
                $message = (string)$outcome['flash'];
            } elseif ($action === 'cancel_all') {
                if ((string)($_POST['confirm_phrase'] ?? '') !== 'CANCEL') {
                    throw new RuntimeException('Type CANCEL to confirm the bulk operation.');
                }
                $count = $service->cancelQueuedBulk($expected);
                $message = 'Cancelled ' . $count . ' queued federation transfers. '
                    . 'A cancellation event was logged per job; no history was deleted.';
            } elseif ($action === 'purge_cancelled_all') {
                if ((string)($_POST['confirm_phrase'] ?? '') !== 'PURGE') {
                    throw new RuntimeException('Type PURGE to confirm permanent deletion.');
                }
                $count = $service->purgeCancelledBulk($expected);
                $message = 'Permanently removed ' . $count . ' cancelled federation job records. '
                    . 'The federation transfer log entries were retained.';
            } else {
                throw new RuntimeException('Unsupported federation queue action.');
            }
        }
        $_SESSION['dashboard_queue_flash'] = $message;
    } catch (Throwable $error) {
        error_log('[UnrealDB dashboard queue action] ' . $error->getMessage());
        $_SESSION['dashboard_queue_flash'] = 'Queue action was not applied: ' . $error->getMessage();
    }
    header('Location: ' . dashboard_detail_url($metric, $page, $pageSize) . '#queue-controls', true, 303);
    exit;
}
$queueFlash = $_SESSION['dashboard_queue_flash'] ?? null;
unset($_SESSION['dashboard_queue_flash']);
$dashboardQueueCsrf = catalog_csrf('dashboard_queue_actions');
$fileMaintenanceCsrf = catalog_csrf('catalog-maintenance');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * One explicit destination for each drill-down category. Dependencies and
 * Games already have dedicated source-correct catalogue pages.
 *
 * @return array<string,array{title:string,description:string,source:string}>
 */
function dashboard_detail_definitions(): array
{
    return [
        'files' => [
            'title' => 'Total files',
            'description' => 'Every indexed file, including verified, duplicate and failed records.',
            'source' => 'library.php',
        ],
        'verified' => [
            'title' => 'Verified files',
            'description' => 'Files whose original scan_status is verified.',
            'source' => 'library.php',
        ],
        'failed' => [
            'title' => 'Failed files',
            'description' => 'Files whose original scan_status is failed. Review the stored diagnostic and open each file.',
            'source' => 'library.php',
        ],
        'fedQueued' => [
            'title' => 'Queued federation jobs',
            'description' => 'Federation transfers with status queued, not all active transfer states.',
            'source' => 'federation/queue.php?tab=active',
        ],
        'fedDownloaded' => [
            'title' => 'Downloaded waiting import',
            'description' => 'Federation transfers with status downloaded, awaiting import.',
            'source' => 'federation/queue.php?tab=waiting',
        ],
        'fedFailed' => [
            'title' => 'Failed federation jobs',
            'description' => 'Federation transfers with status failed and their recorded errors.',
            'source' => 'federation/queue.php?tab=failed',
        ],
        'mirrorWaiting' => [
            'title' => 'Mirror jobs waiting',
            'description' => 'Mirror jobs in queued, waiting_admin or uploading state, including records beyond the original 500-job list.',
            'source' => 'mirror-queue.php',
        ],
        'mirrorActive' => [
            'title' => 'Active mirror links',
            'description' => 'External mirror links whose status is active.',
            'source' => 'mirror-links.php',
        ],
        'joinPending' => [
            'title' => 'Pending join requests',
            'description' => 'Federation child join requests with status pending.',
            'source' => 'federation/connections.php#incoming-child-join-requests',
        ],
    ];
}

function dashboard_detail_url(string $metric, int $page, int $pageSize): string
{
    return 'dashboard-detail.php?' . http_build_query([
        'metric' => $metric,
        'page' => $page,
        'page_size' => $pageSize,
    ]);
}

function dashboard_detail_pagination(string $metric, int $page, int $pageCount, int $pageSize): string
{
    if ($pageCount <= 1) {
        return '';
    }
    $link = static function (string $label, int $target, bool $current = false) use ($metric, $pageSize): string {
        if ($current) {
            return '<strong class="button secondary" aria-current="page">' . catalog_h($label) . '</strong>';
        }
        return '<a class="button secondary" href="'
            . catalog_h(dashboard_detail_url($metric, $target, $pageSize)) . '">'
            . catalog_h($label) . '</a>';
    };
    $html = '<nav class="page-links" aria-label="Dashboard detail pages">';
    if ($page > 1) {
        $html .= $link('First', 1) . ' ' . $link('Previous', $page - 1) . ' ';
    }
    $shown = [];
    for ($i = 1; $i <= $pageCount; $i++) {
        if ($i === 1 || $i === $pageCount || abs($i - $page) <= 2) {
            $shown[] = $i;
        }
    }
    $last = 0;
    foreach ($shown as $i) {
        if ($last !== 0 && $i > $last + 1) $html .= '<span class="muted">…</span> ';
        $html .= $link((string)$i, $i, $i === $page) . ' ';
        $last = $i;
    }
    if ($page < $pageCount) {
        $html .= $link('Next', $page + 1) . ' ' . $link('Last', $pageCount);
    }
    return $html . '</nav>';
}

function dashboard_detail_trim(mixed $value, int $limit = 240): string
{
    $value = trim((string)($value ?? ''));
    return catalog_h(strlen($value) > $limit ? substr($value, 0, $limit) . '…' : ($value === '' ? '—' : $value));
}

/**
 * A deliberate POST-only, CSRF-protected action. Browsing remains read-only.
 */
function dashboard_detail_single_cancel_form(
    string $metric, int $id, string $csrf, int $page, int $pageSize
): string {
    return '<form method="post" onsubmit="return confirm(\'Cancel this queued job? The record will remain in history.\')">'
        . '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">'
        . '<input type="hidden" name="metric" value="' . catalog_h($metric) . '">'
        . '<input type="hidden" name="job_id" value="' . $id . '">'
        . '<input type="hidden" name="page" value="' . $page . '">'
        . '<input type="hidden" name="page_size" value="' . $pageSize . '">'
        . '<button class="danger" type="submit" name="action" value="cancel_one">Cancel</button>'
        . '</form>';
}

/**
 * @param list<array<string,mixed>> $rows
 */
function dashboard_detail_table(string $metric, array $rows, string $csrf, int $page, int $pageSize, string $fileCsrf): void
{
    if ($rows === []) {
        echo '<p class="muted">No matching records for this status on the selected page.</p>';
        return;
    }
    $files = in_array($metric, ['files','verified','failed'], true);
    $federation = in_array($metric, ['fedQueued','fedDownloaded','fedFailed'], true);

    echo '<div class="table-wrap"><table class="dashboard-detail-table"><thead><tr>';
    if ($files) {
        echo '<th>File ID</th><th>Game</th><th>Package / filename</th><th>Type</th><th>Scan status</th><th>Diagnostic</th><th>Open</th>';
    } elseif ($federation) {
        echo '<th>Job ID</th><th>Peer</th><th>Direction</th><th>Status</th><th>Remote / local file</th><th>Attempts</th><th>Last error</th><th>Created</th>';
        if ($metric === 'fedQueued') echo '<th>Action</th>';
    } elseif ($metric === 'mirrorWaiting') {
        echo '<th>Mirror job</th><th>Game</th><th>Package / filename</th><th>Status</th><th>Attempts</th><th>Error</th><th>Created</th><th>Action</th>';
    } elseif ($metric === 'mirrorActive') {
        echo '<th>Link ID</th><th>File</th><th>Provider ID</th><th>Status</th><th>Expires</th><th>Requests</th><th>Manage</th>';
    } elseif ($metric === 'joinPending') {
        echo '<th>Request ID</th><th>Site</th><th>URL</th><th>Contact</th><th>Status</th><th>Received</th><th>Review</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        echo '<tr>';
        if ($files) {
            $fileHref = 'file-info.php?id=' . $id;
            $gameId = (int)$row['game_id'];
            $reason = trim((string)($row['metadata_error'] ?? ''));
            if ($reason === '') $reason = (string)($row['scan_notes'] ?? '');
            echo '<td class="mono">'
                . ($metric === 'failed'
                    ? '<input class="failed-file-select" type="checkbox" data-file-id="' . $id
                        . '" aria-label="Select failed file #' . $id . '"> '
                    : '')
                . '<a href="' . $fileHref . '">#' . $id . '</a></td>'
                . '<td><a href="game-files.php?id=' . $gameId . '">' . catalog_h((string)$row['game_name']) . '</a></td>'
                . '<td class="path"><strong>' . dashboard_detail_trim($row['package_name'], 120)
                . '</strong><br><span class="muted small">' . dashboard_detail_trim($row['original_name'], 180) . '</span></td>'
                . '<td>' . dashboard_detail_trim($row['extension'], 20) . '</td>'
                . '<td>' . catalog_h((string)$row['scan_status']) . '</td>'
                . '<td class="path">' . dashboard_detail_trim($reason) . '</td>'
                . '<td>' . ($metric === 'failed'
                    ? '<a href="file-info.php?id=' . $id . '#scan-status">Review failure</a> '
                      . '<a href="file-info.php?id=' . $id . '#failed-maintenance">Revalidate</a> '
                      . '<a href="file-info.php?id=' . $id . '#failed-maintenance">'
                      . 'Remove</a>'
                    : '<a href="file-examine.php?id=' . $id . '">Examine</a>') . '</td>';
        } elseif ($federation) {
            $localFileId = (int)($row['local_file_id'] ?? 0);
            echo '<td class="mono">#' . $id . '</td>'
                . '<td>' . dashboard_detail_trim($row['peer_name'], 100) . '</td>'
                . '<td>' . dashboard_detail_trim($row['direction'], 30) . '</td>'
                . '<td>' . catalog_h((string)$row['status']) . '</td>'
                . '<td class="mono">' . dashboard_detail_trim($row['remote_file_id'], 60) . ' / '
                . ($localFileId > 0
                    ? '<a href="file-info.php?id=' . $localFileId . '">#' . $localFileId . '</a>'
                    : '—') . '</td>'
                . '<td>' . (int)$row['attempts'] . '</td>'
                . '<td class="path">' . dashboard_detail_trim($row['last_error']) . '</td>'
                . '<td class="nowrap">' . dashboard_detail_trim($row['created_at'], 40) . '</td>';
            if ($metric === 'fedQueued') {
                echo '<td>' . dashboard_detail_single_cancel_form($metric, $id, $csrf, $page, $pageSize) . '</td>';
            }
        } elseif ($metric === 'mirrorWaiting') {
            $fileId = (int)($row['file_id'] ?? 0);
            $fileHref = $fileId > 0 ? 'file-info.php?id=' . $fileId : '';
            echo '<td><a class="mono" href="mirror-job.php?id=' . $id . '">#' . $id . '</a></td>'
                . '<td>' . dashboard_detail_trim($row['game_name'], 100) . '</td>'
                . '<td class="path">' . ($fileHref !== ''
                    ? '<a href="' . $fileHref . '">'
                        . dashboard_detail_trim($row['package_name'], 120) . '</a>'
                    : dashboard_detail_trim($row['package_name'], 120))
                . '<br><span class="muted small">' . dashboard_detail_trim($row['original_name'], 160) . '</span></td>'
                . '<td>' . catalog_h((string)$row['status']) . '</td>'
                . '<td>' . (int)$row['attempts'] . '</td>'
                . '<td class="path">' . dashboard_detail_trim($row['last_error']) . '</td>'
                . '<td class="nowrap">' . dashboard_detail_trim($row['created_at'], 40) . '</td>';
            echo '<td>' . (in_array((string)$row['status'], ['queued','waiting_admin'], true)
                ? dashboard_detail_single_cancel_form($metric, $id, $csrf, $page, $pageSize)
                : 'Uploading: cancel unavailable') . '</td>';
        } elseif ($metric === 'mirrorActive') {
            $fileId = (int)$row['file_id'];
            echo '<td class="mono">#' . $id . '</td>'
                . '<td><a href="file-info.php?id=' . $fileId . '">'
                . dashboard_detail_trim($row['package_name'], 100) . '</a><br>'
                . dashboard_detail_trim($row['original_name'], 100) . '</td>'
                . '<td>' . (int)$row['provider_id'] . '</td>'
                . '<td>' . catalog_h((string)$row['status']) . '</td>'
                . '<td>' . dashboard_detail_trim($row['expires_at'], 40) . '</td>'
                . '<td>' . (int)$row['requested_count'] . '</td>'
                . '<td><a href="mirror-links.php?file_id=' . $fileId . '">Manage</a></td>';
        } else {
            echo '<td class="mono">#' . $id . '</td>'
                . '<td>' . dashboard_detail_trim($row['site_name'], 100) . '</td>'
                . '<td class="path">' . dashboard_detail_trim($row['site_url'], 160) . '</td>'
                . '<td>' . dashboard_detail_trim($row['contact_name'], 90) . '<br>'
                . dashboard_detail_trim($row['contact_email'], 100) . '</td>'
                . '<td>' . catalog_h((string)$row['status']) . '</td>'
                . '<td>' . dashboard_detail_trim($row['created_at'], 40) . '</td>'
                . '<td><a href="federation/connections.php#incoming-child-join-requests">Review</a></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

try {
    $metric = (string)($_GET['metric'] ?? '');
    $definitions = dashboard_detail_definitions();
    if (!isset($definitions[$metric])) {
        http_response_code(404);
        catalog_head('Unknown dashboard counter');
        echo '<div class="card"><h1>Unknown dashboard counter</h1>'
            . '<p><a href="dashboard.php">Return to Dashboard</a></p></div>';
        catalog_foot();
        exit;
    }
    $spec = $definitions[$metric];

    $db = catalog_db(catalog_config());
    $stats = (new PdoDashboardStatsQuery($db))->load();
    $total = max(0, (int)($stats[$metric] ?? 0));
    $pageSize = (int)($_GET['page_size'] ?? 50);
    if (!in_array($pageSize, [50,100], true)) $pageSize = 50;
    $pageCount = max(1, (int)ceil($total / $pageSize));
    $requestedPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    $page = $requestedPage === false || $requestedPage === null
        ? 1 : max(1, min((int)$requestedPage, $pageCount));
    $rows = (new PdoDashboardDetailPageQuery($db))->page($metric, $page, $pageSize);

    catalog_head($spec['title']);
    if (is_string($queueFlash) && $queueFlash !== '') catalog_flash($queueFlash);
    catalog_page_header(
        $spec['title'],
        $spec['description'],
        ['Dashboard' => 'dashboard.php', 'Manage / full view' => $spec['source']]
    );
    $first = $total > 0 ? ($page - 1) * $pageSize + 1 : 0;
    $last = $total > 0 ? min($total, ($page - 1) * $pageSize + count($rows)) : 0;
    echo '<div class="card"><h2>' . number_format($total) . ' ' . catalog_h($spec['title']) . '</h2>'
        . '<p class="muted">Dashboard counter snapshot. Showing records '
        . number_format($first) . '–' . number_format($last) . '. '
        . 'Details come from the corresponding source table.</p>'
        . '<p class="page-links"><a class="button secondary" href="'
        . catalog_h(dashboard_detail_url($metric,1,50)) . '">50 per page</a> '
        . '<a class="button secondary" href="'
        . catalog_h(dashboard_detail_url($metric,1,100)) . '">100 per page</a></p>'
        . dashboard_detail_pagination($metric, $page, $pageCount, $pageSize);

    if ($metric === 'failed') {
        echo '<section class="card" id="failed-cleanup-controls">'
            . '<h3>Manage failed files</h3><p>Review or revalidate each stored source using '
            . 'the current game-specific scanner. Revalidation does not silently change the '
            . 'record or known-invalid byte fingerprint. '
            . '<strong>Remove</strong> permanently deletes the selected managed bytes and catalogue '
            . 'record using the existing file-maintenance cleanup, including dependency reconciliation.</p>'
            . '<p><label><input type="checkbox" id="failed-select-page"> Select all failed files on this page</label> '
            . '<button class="danger" type="button" id="failed-remove-selected">Remove selected failed files</button></p>'
            . '<p id="failed-removal-progress" role="status" aria-live="polite" class="muted">'
            . 'No failed files selected. Confirmed invalid-file hashes will remain blocked after removal.</p></section>';
    }
    if (in_array($metric, ['mirrorWaiting','fedQueued'], true)) {
        if ($metric === 'mirrorWaiting') {
            $cancelableCount = (int)$db->query(
                'SELECT COUNT(*) FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin")'
            )->fetchColumn();
            $additional = 'Jobs already uploading are not cancelled automatically.';
            $purgeCount = (int)$db->query(
                'SELECT COUNT(*) FROM ue_external_mirror_jobs WHERE status="cancelled"'
            )->fetchColumn();
            $historyHref = 'mirror-queue.php?status=cancelled';
        } else {
            $visible = federation_visible_transfer_job_sql($db, 'j');
            $cancelableCount = (int)$db->query(
                'SELECT COUNT(*) FROM ue_federation_transfer_jobs j '
                . 'WHERE j.status="queued" AND ' . $visible
            )->fetchColumn();
            $additional = 'Running, downloaded and completed transfers are never included.';
            $purgeCount = (int)$db->query(
                'SELECT COUNT(*) FROM ue_federation_transfer_jobs j '
                . 'WHERE j.status="cancelled" AND ' . $visible
            )->fetchColumn();
            $historyHref = 'federation/queue.php?tab=cancelled';
        }
        echo '<section class="card" id="queue-controls">'
            . '<h3>Manage waiting jobs</h3><p>'
            . number_format($cancelableCount)
            . ' currently cancellable job(s). Cancelling removes jobs from the waiting counter, '
            . 'but preserves their historical records. ' . catalog_h($additional) . '</p>';
        if ($cancelableCount > 0) {
            echo '<form method="post" onsubmit="return confirm(\'Cancel ALL '
                . $cancelableCount . ' eligible jobs? This cannot be undone without requeueing each job.\')">'
                . '<input type="hidden" name="csrf" value="' . catalog_h($dashboardQueueCsrf) . '">'
                . '<input type="hidden" name="metric" value="' . catalog_h($metric) . '">'
                . '<input type="hidden" name="action" value="cancel_all">'
                . '<input type="hidden" name="expected_count" value="' . $cancelableCount . '">'
                . '<input type="hidden" name="page_size" value="' . $pageSize . '">'
                . '<p><label>Type <strong>CANCEL</strong> to confirm clearing '
                . number_format($cancelableCount) . ' pending jobs: '
                . '<input type="text" name="confirm_phrase" required pattern="CANCEL" autocomplete="off" '
                . 'aria-label="Type CANCEL to confirm all waiting jobs"></label> '
                . '<button class="danger" type="submit">Cancel all ' . $cancelableCount
                . ' pending jobs</button></p></form>';
        }
        echo '<h3>Clear cancelled history</h3><p><a href="'
            . catalog_h($historyHref) . '">Browse cancelled jobs</a>. '
            . number_format($purgeCount)
            . ' cancelled job(s) currently eligible for permanent cleanup. '
            . 'Purging only deletes cancelled job rows, never original file bytes or active/download links. '
            . 'Federation transfer audit messages remain recorded.</p>';
        if ($purgeCount > 0) {
            echo '<form method="post" onsubmit="return confirm(\'Permanently delete ALL '
                . $purgeCount . ' cancelled jobs?\')">'
                . '<input type="hidden" name="csrf" value="' . catalog_h($dashboardQueueCsrf) . '">'
                . '<input type="hidden" name="metric" value="' . catalog_h($metric) . '">'
                . '<input type="hidden" name="action" value="purge_cancelled_all">'
                . '<input type="hidden" name="expected_count" value="' . $purgeCount . '">'
                . '<p><label>Type <strong>PURGE</strong> '
                . '<input type="text" name="confirm_phrase" required pattern="PURGE" '
                . 'autocomplete="off"></label> '
                . '<button class="danger" type="submit">Permanently purge '
                . number_format($purgeCount) . ' cancelled jobs</button></p></form>';
        }
        echo '</section>';
    }

    dashboard_detail_table($metric, $rows, $dashboardQueueCsrf, $page, $pageSize, $fileMaintenanceCsrf);
    echo dashboard_detail_pagination($metric, $page, $pageCount, $pageSize)
        . '</div>';

    if ($metric === 'failed') {
        $csrfJson = json_encode($fileMaintenanceCsrf, JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        echo '<script>(function(){\'use strict\';'
            . 'const token=' . $csrfJson . ';'
            . 'const checks=Array.from(document.querySelectorAll(".failed-file-select"));'
            . 'const all=document.getElementById("failed-select-page");'
            . 'const go=document.getElementById("failed-remove-selected");'
            . 'const status=document.getElementById("failed-removal-progress");'
            . 'if(!go||!status)return;'
            . 'if(all)all.addEventListener("change",()=>{checks.forEach(c=>c.checked=all.checked);});'
            . 'go.addEventListener("click",async()=>{'
            . 'const selected=checks.filter(c=>c.checked).map(c=>c.dataset.fileId);'
            . 'if(!selected.length){status.textContent="Select at least one failed file first.";return;}'
            . 'if(prompt("Type DELETE to permanently delete "+selected.length+" selected failed files and their stored bytes:")!=="DELETE")return;'
            . 'if(!confirm("Remove "+selected.length+" source files and catalogue records? This is irreversible."))return;'
            . 'go.disabled=true;let done=0;const errors=[];'
            . 'for(const id of selected){'
            . 'status.textContent="Removing "+(done+1)+" of "+selected.length+" (file #"+id+")";'
            . 'try{const data=new FormData();data.set("csrf",token);data.set("file_id",id);'
            . 'data.set("operation","remove_failed");data.set("confirm_phrase","DELETE");'
            . 'const response=await fetch("file-maintenance.php",{method:"POST",credentials:"same-origin",'
            . 'headers:{"Accept":"application/json"},body:data});'
            . 'const result=await response.json();'
            . 'if(!response.ok||!result.ok)throw Error(result.error||"Maintenance rejected request");'
            . 'done++;const box=checks.find(c=>c.dataset.fileId===id);'
            . 'if(box){box.checked=false;const row=box.closest("tr");if(row)row.remove();}'
            . '}catch(error){errors.push("#"+id+": "+error.message);break;}'
            . '}go.disabled=false;'
            . 'status.textContent="Removed "+done+" of "+selected.length+" selected failed files. "'
            . '+(errors.length?errors.join("; "):"Refresh the page for updated counts.");'
            . 'if(!errors.length&&done>0)window.location.reload();'
            . '});})();</script>';
    }

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB dashboard detail][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) catalog_head('Dashboard details error');
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Dashboard details could not be loaded.');
    catalog_foot();
}
