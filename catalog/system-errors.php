<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for system errors.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function system_error_filter(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function system_error_search(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 200 ? mb_substr($value, 0, 200, 'UTF-8') : $value;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('System Errors')) {
        exit;
    }

    $tableStatement = $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_system_errors"'
    );
    $available = (int)$tableStatement->fetchColumn() === 1;
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('system_errors');
        if (!$available) {
            throw new RuntimeException('Run the pending database migration before updating System Errors.');
        }
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('Select one or more error records.');
        }
        if (count($ids) > 1000) {
            throw new RuntimeException('Update no more than 1,000 errors at once.');
        }
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if (!in_array($action, ['resolve', 'ignore', 'reopen', 'delete'], true)) {
            throw new RuntimeException('Choose Resolve, Ignore, Reopen or Delete.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'delete') {
            $statement = $db->prepare('DELETE FROM ue_system_errors WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $message = $statement->rowCount() . ' system error record(s) deleted.';
        } else {
            $targetStatus = match ($action) {
                'resolve' => 'resolved',
                'ignore' => 'ignored',
                'reopen' => 'open',
            };
            $note = trim((string)($_POST['resolution_note'] ?? ''));
            if (mb_strlen($note, 'UTF-8') > 500) {
                $note = mb_substr($note, 0, 500, 'UTF-8');
            }
            if ($targetStatus === 'open') {
                $sql = 'UPDATE ue_system_errors SET status="open",resolved_at=NULL,resolved_by=NULL,resolution_note=NULL '
                    . 'WHERE id IN (' . $placeholders . ')';
                $args = $ids;
            } else {
                $sql = 'UPDATE ue_system_errors SET status=?,resolved_at=?,resolved_by=?,resolution_note=? '
                    . 'WHERE id IN (' . $placeholders . ')';
                $args = [$targetStatus, gmdate('Y-m-d H:i:s'), (int)($_SESSION['user']['id'] ?? 0) ?: null, $note];
                array_push($args, ...$ids);
            }
            $statement = $db->prepare($sql);
            $statement->execute($args);
            $message = $statement->rowCount() . ' system error record(s) updated.';
        }
    }

    $status = system_error_filter((string)($_GET['status'] ?? 'open'), ['open', 'resolved', 'ignored', 'all'], 'open');
    $severity = system_error_filter((string)($_GET['severity'] ?? 'all'), ['debug', 'info', 'warning', 'error', 'critical', 'all'], 'all');
    $source = preg_replace('/[^a-z0-9._:-]+/', '', strtolower(trim((string)($_GET['source'] ?? 'all')))) ?: 'all';
    $errorType = preg_replace('/[^A-Za-z0-9._:-]+/', '', trim((string)($_GET['type'] ?? 'all'))) ?: 'all';
    $search = system_error_search((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $page = max(1, (int)($_GET['p'] ?? 1));

    $counts = ['open' => 0, 'resolved' => 0, 'ignored' => 0, 'all' => 0];
    $sourceOptions = [];
    $typeOptions = [];
    $rows = [];
    $total = 0;
    $pages = 1;

    if ($available) {
        foreach (catalog_all($db, 'SELECT status,COUNT(*) c FROM ue_system_errors GROUP BY status') as $row) {
            $key = strtolower((string)($row['status'] ?? ''));
            if (isset($counts[$key])) {
                $counts[$key] = (int)$row['c'];
                $counts['all'] += (int)$row['c'];
            }
        }
        foreach (catalog_all($db, 'SELECT source_kind,COUNT(*) c FROM ue_system_errors GROUP BY source_kind ORDER BY source_kind') as $row) {
            $key = (string)($row['source_kind'] ?? '');
            if ($key !== '') {
                $sourceOptions[$key] = (int)$row['c'];
            }
        }
        foreach (catalog_all($db, 'SELECT error_type,COUNT(*) c FROM ue_system_errors GROUP BY error_type ORDER BY error_type') as $row) {
            $key = trim((string)($row['error_type'] ?? ''));
            if ($key !== '') {
                $typeOptions[$key] = (int)$row['c'];
            }
        }

        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'status=?';
            $args[] = $status;
        }
        if ($severity !== 'all') {
            $where[] = 'severity=?';
            $args[] = $severity;
        }
        if ($source !== 'all') {
            $where[] = 'source_kind=?';
            $args[] = $source;
        }
        if ($errorType !== 'all') {
            $where[] = 'error_type=?';
            $args[] = $errorType;
        }
        if ($search !== '') {
            $where[] = '(message LIKE ? OR error_type LIKE ? OR route LIKE ? OR source_file LIKE ? OR request_id LIKE ? '
                . 'OR COALESCE(context_json,"") LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = catalog_count($db, 'SELECT COUNT(*) c FROM ue_system_errors' . $whereSql, $args);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = $db->prepare(
            'SELECT * FROM ue_system_errors' . $whereSql
            . ' ORDER BY last_seen_at DESC,id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    catalog_head('System Errors');
    echo '<style>'
        . '.system-error-cards{grid-template-columns:repeat(4,minmax(130px,1fr));margin-bottom:14px}'
        . '.system-error-toolbar,.system-error-actions,.system-error-pagination{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.system-error-toolbar,.system-error-actions{margin-bottom:12px}'
        . '.system-error-toolbar .search{min-width:280px;flex:1}'
        . '.system-error-table{table-layout:fixed;min-width:1450px}'
        . '.system-error-table .col-select{width:42px}.system-error-table .col-status{width:92px}.system-error-table .col-severity{width:95px}'
        . '.system-error-table .col-source{width:115px}.system-error-table .col-count{width:85px}.system-error-table .col-date{width:170px}'
        . '.system-error-message,.system-error-location{overflow-wrap:anywhere}'
        . '.system-error-message{color:#fecdd3}'
        . '.system-error-pill{display:inline-block;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700}'
        . '.system-error-pill-open,.system-error-pill-critical,.system-error-pill-error{color:#fecdd3;border-color:rgba(255,107,122,.75)}'
        . '.system-error-pill-resolved{color:#a7f3d0;border-color:rgba(50,213,131,.75)}'
        . '.system-error-pill-ignored,.system-error-pill-warning{color:#fde68a;border-color:rgba(246,196,83,.75)}'
        . '.system-error-pill-info,.system-error-pill-debug{color:#bfdbfe;border-color:rgba(96,165,250,.75)}'
        . '.system-error-details{margin-top:6px}.system-error-details summary{cursor:pointer}'
        . '.system-error-details pre{max-height:320px;overflow:auto;white-space:pre-wrap}'
        . '.system-error-pagination{justify-content:space-between;margin-top:12px}'
        . '@media(max-width:900px){.system-error-cards{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'System Errors',
        'Central persistent errors from PHP/runtime handlers, APIs, browser resources and Unreal file validation. Repeated identical failures increase the occurrence count instead of flooding the table.',
        [
            'Upload Issues' => 'upload-issues.php',
            'Background Jobs' => 'background-jobs.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }
    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'System Error storage is not installed. Run: php catalog/bin/migrate.php migrate followed by php catalog/bin/migrate.php verify',
            'Database migration required'
        );
    }

    echo '<div class="grid system-error-cards">';
    foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'] as $key => $label) {
        echo '<a class="stat tool-card" href="system-errors.php?status=' . $key . '"><h2>' . (int)$counts[$key] . '</h2><p>' . catalog_h($label) . ' errors</p></a>';
    }
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Error records</h2>'
        . '<p>Request bodies, passwords, cookies and uploaded file contents are not stored in this table.</p></div></div><div class="ui-section__body">';
    echo '<form method="get" class="system-error-toolbar">'
        . '<label>Status <select name="status">';
    foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></label><label>Severity <select name="severity">';
    foreach (['all' => 'All', 'critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info', 'debug' => 'Debug'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($severity === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></label><label>Source <select name="source"><option value="all">All</option>';
    foreach ($sourceOptions as $value => $count) {
        echo '<option value="' . catalog_h($value) . '"' . ($source === $value ? ' selected' : '') . '>'
            . catalog_h($value) . ' (' . $count . ')</option>';
    }
    echo '</select></label><label>Type <select name="type"><option value="all">All</option>';
    foreach ($typeOptions as $value => $count) {
        echo '<option value="' . catalog_h($value) . '"' . ($errorType === $value ? ' selected' : '') . '>'
            . catalog_h($value) . ' (' . $count . ')</option>';
    }
    echo '</select></label>'
        . '<label class="search">Search <input type="search" name="q" value="' . catalog_h($search) . '" placeholder="Message, type, file, MD5, archive, job or reference"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button></form>';

    if (!$available || $rows === []) {
        echo CatalogUi::emptyState('No matching system errors', $available ? 'No persistent errors match the current filters.' : 'Apply the database migration to begin recording errors.');
    } else {
        echo '<form method="post" onsubmit="return this.elements[\'action\'].value!==\'delete\' || confirm(\'Permanently delete the selected System Error records?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('system_errors')) . '">';
        echo '<div class="system-error-actions">'
            . '<label><input type="checkbox" onclick="document.querySelectorAll(\'.system-error-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option><option value="resolve">Resolve</option><option value="ignore">Ignore</option><option value="reopen">Reopen</option><option value="delete">Delete permanently</option></select>'
            . '<input name="resolution_note" maxlength="500" placeholder="Optional resolution note">'
            . '<button type="submit">Apply to selected</button></div>';
        echo '<div class="table-wrap"><table class="system-error-table"><colgroup>'
            . '<col class="col-select"><col class="col-status"><col class="col-severity"><col class="col-source"><col><col><col class="col-count"><col class="col-date">'
            . '</colgroup><thead><tr><th></th><th>Status</th><th>Severity</th><th>Source</th><th>Error</th><th>Location / request</th><th>Count</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $rowStatus = strtolower((string)$row['status']);
            $rowSeverity = strtolower((string)$row['severity']);
            echo '<tr><td><input class="system-error-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td>';
            echo '<td><span class="system-error-pill system-error-pill-' . catalog_h($rowStatus) . '">' . catalog_h($rowStatus) . '</span></td>';
            echo '<td><span class="system-error-pill system-error-pill-' . catalog_h($rowSeverity) . '">' . catalog_h($rowSeverity) . '</span></td>';
            echo '<td class="mono">' . catalog_h((string)$row['source_kind']) . '</td>';
            echo '<td class="system-error-message"><strong>' . catalog_h((string)$row['error_type']) . '</strong><br>' . catalog_h((string)$row['message']);
            if ((string)($row['trace_text'] ?? '') !== '' || (string)($row['context_json'] ?? '') !== '') {
                echo '<details class="system-error-details"><summary>Trace / context</summary>';
                if ((string)($row['trace_text'] ?? '') !== '') {
                    echo '<pre>' . catalog_h((string)$row['trace_text']) . '</pre>';
                }
                if ((string)($row['context_json'] ?? '') !== '') {
                    echo '<pre>' . catalog_h((string)$row['context_json']) . '</pre>';
                }
                echo '</details>';
            }
            if ((string)($row['resolution_note'] ?? '') !== '') {
                echo '<br><span class="muted small">Resolution: ' . catalog_h((string)$row['resolution_note']) . '</span>';
            }
            echo '</td>';
            echo '<td class="system-error-location"><span class="mono small">' . catalog_h((string)$row['request_method']) . ' ' . catalog_h((string)$row['route']) . '</span>';
            if ((string)$row['source_file'] !== '') {
                echo '<br><span class="mono small muted">' . catalog_h((string)$row['source_file']) . ':' . (int)$row['source_line'] . '</span>';
            }
            echo '<br><span class="mono small muted">HTTP ' . (int)$row['http_status'] . ' · ' . catalog_h((string)$row['request_id']) . '</span></td>';
            echo '<td>' . number_format((int)$row['occurrence_count']) . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$row['last_seen_at']) . '</td></tr>';
        }
        echo '</tbody></table></div></form>';

        $queryBase = ['status' => $status, 'severity' => $severity, 'source' => $source, 'type' => $errorType, 'q' => $search, 'per_page' => $perPage];
        echo '<div class="system-error-pagination"><span class="muted">' . number_format($total) . ' matching error(s) · Page ' . $page . ' of ' . $pages . '</span><span>';
        if ($page > 1) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page - 1])) . '">Previous</a> ';
        }
        if ($page < $pages) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page + 1])) . '">Next</a>';
        }
        echo '</span></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (function_exists('catalog_system_error_record_exception')) {
        catalog_system_error_record_exception($error, 'system_error_review');
    }
    error_log('[UnrealDB system errors][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('System Errors Failed');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'System Errors could not be loaded.');
    catalog_foot();
}
