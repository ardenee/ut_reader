<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Upload Issues.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogUploadBucketIssueStore;

function upload_issue_status(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['open', 'resolved', 'ignored', 'all'], true) ? $value : 'open';
}

function upload_issue_search(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 200 ? mb_substr($value, 0, 200, 'UTF-8') : $value;
}

function upload_issue_payload_file(array $row): array
{
    $payload = [];
    try {
        $decoded = json_decode((string)($row['payload_json'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        $payload = is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        $payload = [];
    }
    return [
        'file' => trim((string)($payload['source_relative_path'] ?? $payload['original_name'] ?? $payload['file'] ?? '')),
        'size' => (int)($payload['size'] ?? 0),
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('Upload Issues')) {
        exit;
    }

    $store = new CatalogUploadBucketIssueStore($db);
    $available = $store->available();
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('upload_issues');
        if (!$available) {
            throw new RuntimeException('Run the pending database migration before updating Upload Issues.');
        }
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $targetStatus = match ($action) {
            'resolve' => 'resolved',
            'ignore' => 'ignored',
            'reopen' => 'open',
            default => '',
        };
        if ($targetStatus === '') {
            throw new RuntimeException('Choose Resolve, Ignore or Reopen.');
        }
        $note = trim((string)($_POST['resolution_note'] ?? ''));
        $updated = $store->setStatus($ids, $targetStatus, (int)($_SESSION['user']['id'] ?? 0), $note);
        $message = $updated . ' Upload Issue record(s) updated.';
    }

    $status = upload_issue_status((string)($_GET['status'] ?? 'open'));
    $search = upload_issue_search((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $page = max(1, (int)($_GET['p'] ?? 1));

    $counts = ['open' => 0, 'resolved' => 0, 'ignored' => 0, 'all' => 0];
    $rows = [];
    $total = 0;
    if ($available) {
        foreach (catalog_all($db, 'SELECT status,COUNT(*) c FROM ue_upload_bucket_issues GROUP BY status') as $row) {
            $key = strtolower((string)($row['status'] ?? ''));
            if (isset($counts[$key])) {
                $counts[$key] = (int)($row['c'] ?? 0);
                $counts['all'] += (int)($row['c'] ?? 0);
            }
        }

        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'status=?';
            $args[] = $status;
        }
        if ($search !== '') {
            $where[] = '(relative_path LIKE ? OR original_name LIKE ? OR stage LIKE ? OR error_message LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = catalog_count($db, 'SELECT COUNT(*) c FROM ue_upload_bucket_issues' . $whereSql, $args);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = $db->prepare(
            'SELECT * FROM ue_upload_bucket_issues' . $whereSql
            . ' ORDER BY last_seen_at DESC,id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    $baseQueue = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $processingQueues = [$baseQueue . ':bucket-processing', $baseQueue . ':bucket-redirects'];
    $processing = catalog_all(
        $db,
        'SELECT id,queue_name,job_type,status,payload_json,last_error,attempts,max_attempts,updated_at '
        . 'FROM ue_background_jobs WHERE queue_name IN (?,?) AND status IN ("failed","dead_letter","cancelled") '
        . 'ORDER BY updated_at DESC,id DESC LIMIT 200',
        $processingQueues
    );

    catalog_head('Upload Issues');
    echo '<style>'
        . '.upload-issue-cards{grid-template-columns:repeat(4,minmax(130px,1fr));margin-bottom:14px}'
        . '.upload-issue-toolbar,.upload-issue-actions,.upload-issue-pagination{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.upload-issue-toolbar,.upload-issue-actions{margin-bottom:12px}'
        . '.upload-issue-toolbar .search{min-width:280px;flex:1}'
        . '.upload-issue-table{table-layout:fixed;min-width:1250px}'
        . '.upload-issue-table .col-select{width:42px}.upload-issue-table .col-status{width:92px}.upload-issue-table .col-stage{width:120px}'
        . '.upload-issue-table .col-size{width:105px}.upload-issue-table .col-count{width:80px}.upload-issue-table .col-date{width:170px}'
        . '.upload-issue-path,.upload-issue-error{overflow-wrap:anywhere}'
        . '.upload-issue-error{color:#fecdd3}'
        . '.upload-issue-pill{display:inline-block;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700}'
        . '.upload-issue-pill-open{color:#fecdd3;border-color:rgba(255,107,122,.75)}'
        . '.upload-issue-pill-resolved{color:#a7f3d0;border-color:rgba(50,213,131,.75)}'
        . '.upload-issue-pill-ignored{color:#fde68a;border-color:rgba(246,196,83,.75)}'
        . '.upload-issue-pagination{justify-content:space-between;margin-top:12px}'
        . '.processing-issues{margin-top:18px}'
        . '@media(max-width:900px){.upload-issue-cards{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Upload Issues',
        'Persistent failures from Upload Bucket v2 are retained here after the browser page is closed. Downstream processing failures remain authoritative Background Job records and are shown below.',
        [
            'Upload Bucket (New)' => 'upload-bucket-v2.php',
            'Review Unverified Files' => 'unverified-files.php?source_game_id=-1',
            'Background Jobs' => 'background-jobs.php?queue=' . rawurlencode($processingQueues[0]),
        ]
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }
    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'Upload Issue storage is not installed. Run: php catalog/bin/migrate.php migrate followed by php catalog/bin/migrate.php verify',
            'Database migration required'
        );
    }

    echo '<div class="grid upload-issue-cards">';
    foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'] as $key => $label) {
        echo '<a class="stat tool-card" href="upload-issues.php?status=' . $key . '"><h2>' . (int)$counts[$key] . '</h2><p>' . catalog_h($label) . ' upload issues</p></a>';
    }
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Browser, validation and transfer issues</h2>'
        . '<p>Repeated failures for the same path and size update one record and increase its occurrence count.</p></div></div><div class="ui-section__body">';
    echo '<form method="get" class="upload-issue-toolbar">'
        . '<label>Status <select name="status">';
    foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></label>'
        . '<label class="search">Search <input type="search" name="q" value="' . catalog_h($search) . '" placeholder="File, path, stage or reason"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    if (!$available || $rows === []) {
        echo CatalogUi::emptyState('No matching Upload Issues', $available ? 'No persistent upload failures match the current filters.' : 'Apply the database migration to begin recording failures.');
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload_issues')) . '">';
        echo '<div class="upload-issue-actions">'
            . '<label><input type="checkbox" onclick="document.querySelectorAll(\'.upload-issue-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option><option value="resolve">Resolve</option><option value="ignore">Ignore</option><option value="reopen">Reopen</option></select>'
            . '<input name="resolution_note" maxlength="500" placeholder="Optional resolution note">'
            . '<button type="submit">Apply to selected</button></div>';
        echo '<div class="table-wrap"><table class="upload-issue-table"><colgroup>'
            . '<col class="col-select"><col class="col-status"><col><col class="col-stage"><col><col class="col-size"><col class="col-count"><col class="col-date">'
            . '</colgroup><thead><tr><th></th><th>Status</th><th>File</th><th>Stage</th><th>Reason</th><th>Size</th><th>Count</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            echo '<tr>';
            echo '<td><input class="upload-issue-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td>';
            echo '<td><span class="upload-issue-pill upload-issue-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td class="upload-issue-path"><strong>' . catalog_h((string)$row['original_name']) . '</strong><br><span class="mono small muted">' . catalog_h((string)$row['relative_path']) . '</span>';
            if ((string)($row['resolution_note'] ?? '') !== '') {
                echo '<br><span class="small muted">Resolution: ' . catalog_h((string)$row['resolution_note']) . '</span>';
            }
            echo '</td>';
            echo '<td class="mono">' . catalog_h((string)$row['stage']) . '</td>';
            echo '<td class="upload-issue-error">' . catalog_h((string)$row['error_message']) . '</td>';
            echo '<td class="mono">' . catalog_h((string)$row['file_size_text']) . '</td>';
            echo '<td>' . (int)$row['occurrence_count'] . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$row['last_seen_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></form>';

        $pages = max(1, (int)ceil($total / $perPage));
        $queryBase = ['status' => $status, 'q' => $search, 'per_page' => $perPage];
        echo '<div class="upload-issue-pagination"><span class="muted">' . number_format($total) . ' matching issue(s) · Page ' . $page . ' of ' . $pages . '</span><span>';
        if ($page > 1) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page - 1])) . '">Previous</a> ';
        }
        if ($page < $pages) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page + 1])) . '">Next</a>';
        }
        echo '</span></div>';
    }
    echo '</div></section>';

    echo '<section class="ui-section processing-issues"><div class="ui-section__header"><div><h2>Processing job failures</h2>'
        . '<p>These files reached the durable queue and then failed during decompression, duplicate inspection, inventory or package processing.</p></div>'
        . '<a class="button secondary" href="background-jobs.php?queue=' . rawurlencode($processingQueues[0]) . '">Open Background Jobs</a>'
        . '</div><div class="ui-section__body">';
    if ($processing === []) {
        echo CatalogUi::emptyState('No processing failures', 'No failed, dead-lettered or cancelled Upload Bucket processing jobs were found.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Job</th><th>Status</th><th>File</th><th>Reason</th><th>Attempts</th><th>Updated</th></tr></thead><tbody>';
        foreach ($processing as $job) {
            $file = upload_issue_payload_file($job);
            echo '<tr>';
            echo '<td><a href="background-jobs.php?queue=' . rawurlencode((string)$job['queue_name']) . '">#' . (int)$job['id'] . '</a><br><span class="mono small muted">' . catalog_h((string)$job['job_type']) . '</span></td>';
            echo '<td><span class="upload-issue-pill upload-issue-pill-open">' . catalog_h((string)$job['status']) . '</span></td>';
            echo '<td class="upload-issue-path">' . catalog_h($file['file'] !== '' ? $file['file'] : 'Unknown file') . ($file['size'] > 0 ? '<br><span class="muted small">' . catalog_h(catalog_bytes($file['size'])) . '</span>' : '') . '</td>';
            echo '<td class="upload-issue-error">' . catalog_h(trim((string)($job['last_error'] ?? '')) ?: 'No persisted error text.') . '</td>';
            echo '<td>' . (int)$job['attempts'] . ' / ' . (int)$job['max_attempts'] . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$job['updated_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB upload issues][' . catalog_request_id() . '] ' . get_class($error) . ': ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('Upload Issues Error');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'Upload Issues could not be loaded.');
    catalog_foot();
}
