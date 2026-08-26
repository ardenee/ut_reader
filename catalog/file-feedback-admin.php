<?php
/**
 * Administrator review/cleanup page for anonymous per-file feedback.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function file_feedback_admin_search(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 200 ? mb_substr($value, 0, 200, 'UTF-8') : $value;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();

    if (!catalog_require_admin_page('File Feedback')) {
        exit;
    }

    $tableStatement = $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_file_feedback"'
    );
    $available = (int)$tableStatement->fetchColumn() === 1;
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('file_feedback_admin');
        if (!$available) {
            throw new RuntimeException('Run the pending database migration before managing file feedback.');
        }

        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if ($action === 'delete_selected') {
            $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $ids),
                static fn(int $id): bool => $id > 0
            )));
            if ($ids === []) {
                throw new RuntimeException('Select one or more feedback records to delete.');
            }
            if (count($ids) > 1000) {
                throw new RuntimeException('Delete no more than 1,000 feedback records at once.');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare('DELETE FROM ue_file_feedback WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $message = $statement->rowCount() . ' feedback record(s) permanently deleted.';
        } elseif ($action === 'purge_older') {
            $days = (int)($_POST['purge_days'] ?? 90);
            if (!in_array($days, [7, 30, 90, 180, 365], true)) {
                throw new RuntimeException('Choose a valid age for old feedback cleanup.');
            }

            $statement = $db->prepare(
                'DELETE FROM ue_file_feedback WHERE submitted_at < DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL '
                . $days . ' DAY)'
            );
            $statement->execute();
            $message = $statement->rowCount() . ' feedback record(s) older than ' . $days . ' day(s) deleted.';
        } else {
            throw new RuntimeException('Choose a feedback cleanup action.');
        }
    }

    $search = file_feedback_admin_search((string)($_GET['q'] ?? ''));
    $perPage = (int)($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $page = max(1, (int)($_GET['p'] ?? 1));

    $counts = ['all' => 0, 'today' => 0, 'week' => 0, 'month' => 0];
    $rows = [];
    $total = 0;
    $pages = 1;

    if ($available) {
        $countRow = catalog_one(
            $db,
            'SELECT COUNT(*) all_count,'
            . 'SUM(submitted_at >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 DAY)) today_count,'
            . 'SUM(submitted_at >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 7 DAY)) week_count,'
            . 'SUM(submitted_at >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 30 DAY)) month_count '
            . 'FROM ue_file_feedback'
        ) ?? [];
        $counts = [
            'all' => (int)($countRow['all_count'] ?? 0),
            'today' => (int)($countRow['today_count'] ?? 0),
            'week' => (int)($countRow['week_count'] ?? 0),
            'month' => (int)($countRow['month_count'] ?? 0),
        ];

        $where = [];
        $args = [];
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where[] = '('
                . 'fb.feedback_text LIKE ? ESCAPE "\\\\" '
                . 'OR f.original_name LIKE ? ESCAPE "\\\\" '
                . 'OR f.package_name LIKE ? ESCAPE "\\\\" '
                . 'OR CAST(fb.file_id AS CHAR) = ? '
                . 'OR COALESCE(INET6_NTOA(fb.submitter_ip),"unknown") LIKE ? ESCAPE "\\\\"'
                . ')';
            array_push($args, $like, $like, $like, $search, $like);
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ue_file_feedback fb '
            . 'JOIN ue_files f ON f.id=fb.file_id' . $whereSql,
            $args
        );
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $statement = $db->prepare(
            'SELECT fb.id,fb.file_id,fb.feedback_text,fb.submitted_at,'
            . 'COALESCE(INET6_NTOA(fb.submitter_ip),"unknown") submitter_ip,'
            . 'f.original_name,f.package_name,f.extension,f.game_id,g.name game_name '
            . 'FROM ue_file_feedback fb '
            . 'JOIN ue_files f ON f.id=fb.file_id '
            . 'JOIN ue_games g ON g.id=f.game_id'
            . $whereSql
            . ' ORDER BY fb.submitted_at DESC,fb.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    catalog_head('File Feedback');
    echo '<style>'
        . '.file-feedback-stats{grid-template-columns:repeat(4,minmax(140px,1fr));margin-bottom:14px}'
        . '.file-feedback-toolbar,.file-feedback-actions,.file-feedback-purge,.file-feedback-pagination{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.file-feedback-toolbar,.file-feedback-actions{margin-bottom:12px}'
        . '.file-feedback-toolbar .search{min-width:280px;flex:1}'
        . '.file-feedback-table{min-width:1050px}'
        . '.file-feedback-table .col-select{width:42px}.file-feedback-table .col-id{width:75px}'
        . '.file-feedback-table .col-file{width:285px}.file-feedback-table .col-ip{width:155px}.file-feedback-table .col-date{width:170px}'
        . '.file-feedback-text{font-size:1.02rem;overflow-wrap:anywhere}'
        . '.file-feedback-file span{display:block}'
        . '.file-feedback-purge{padding-top:12px;margin-top:12px;border-top:1px solid var(--line)}'
        . '.file-feedback-pagination{justify-content:space-between;margin-top:12px}'
        . '@media(max-width:900px){.file-feedback-stats{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'File Feedback',
        'Anonymous correction notes submitted from File Info and File Examine pages. Each entry is tied to its file and records the submitter IP and submission time.',
        [
            'Public Access & Mail' => 'public-access-settings.php',
            'System Errors' => 'system-errors.php',
            'Dashboard' => 'dashboard.php',
        ]
    );

    if ($message !== '') {
        echo CatalogUi::alert('success', $message);
    }
    if (!$available) {
        echo CatalogUi::alert(
            'warning',
            'File feedback storage is not installed. Run: php catalog/bin/migrate.php migrate followed by php catalog/bin/migrate.php verify',
            'Database migration required'
        );
    }

    echo '<div class="grid file-feedback-stats">';
    foreach ([
        'all' => ['All', 'feedback entries'],
        'today' => ['Last 24 hours', 'feedback entries'],
        'week' => ['Last 7 days', 'feedback entries'],
        'month' => ['Last 30 days', 'feedback entries'],
    ] as $key => [$label, $suffix]) {
        echo '<div class="stat tool-card"><h2>' . number_format($counts[$key]) . '</h2><p>'
            . catalog_h($label) . ' ' . catalog_h($suffix) . '</p></div>';
    }
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Feedback records</h2>'
        . '<p>Use the file links to inspect the reported package before deleting the feedback.</p></div></div>'
        . '<div class="ui-section__body">';

    echo '<form method="get" class="file-feedback-toolbar">'
        . '<label class="search">Search <input type="search" name="q" value="' . catalog_h($search)
        . '" placeholder="Feedback, filename, package, file ID or IP"></label>'
        . '<label>Rows <select name="per_page">';
    foreach ([50, 100, 250, 500] as $value) {
        echo '<option value="' . $value . '"' . ($perPage === $value ? ' selected' : '') . '>' . $value . '</option>';
    }
    echo '</select></label><button type="submit">Apply</button>';
    if ($search !== '') {
        echo ' <a class="button secondary" href="file-feedback-admin.php">Clear</a>';
    }
    echo '</form>';

    if (!$available || $rows === []) {
        echo CatalogUi::emptyState(
            'No matching file feedback',
            $available ? 'No feedback records match the current search.' : 'Apply the database migration to begin recording file feedback.'
        );
    } else {
        echo '<form method="post" onsubmit="return confirm(\'Permanently delete the selected file feedback records?\')">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('file_feedback_admin')) . '">'
            . '<input type="hidden" name="action" value="delete_selected">'
            . '<div class="file-feedback-actions">'
            . '<label><input type="checkbox" onclick="document.querySelectorAll(\'.file-feedback-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<button type="submit">Delete selected</button></div>';

        echo '<div class="table-wrap"><table class="file-feedback-table"><colgroup>'
            . '<col class="col-select"><col class="col-id"><col><col class="col-file"><col class="col-ip"><col class="col-date">'
            . '</colgroup><thead><tr><th></th><th>ID</th><th>Feedback</th><th>File</th><th>IP</th><th>Submitted</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $fileId = (int)$row['file_id'];
            echo '<tr>';
            echo '<td><input class="file-feedback-check" type="checkbox" name="ids[]" value="' . (int)$row['id'] . '"></td>';
            echo '<td class="mono">' . (int)$row['id'] . '</td>';
            echo '<td class="file-feedback-text">' . catalog_h((string)$row['feedback_text']) . '</td>';
            echo '<td class="file-feedback-file"><a href="file-info.php?id=' . $fileId . '"><strong>'
                . catalog_h((string)$row['original_name']) . '</strong></a>'
                . '<span class="mono small muted">' . catalog_h((string)$row['package_name']) . ' · file #' . $fileId . '</span>'
                . '<span class="small muted">' . catalog_h((string)$row['game_name'])
                . ' · <a href="file-examine.php?id=' . $fileId . '">Examine</a></span></td>';
            echo '<td class="mono small">' . catalog_h((string)$row['submitter_ip']) . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$row['submitted_at']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></form>';

        $queryBase = ['q' => $search, 'per_page' => $perPage];
        echo '<div class="file-feedback-pagination"><span class="muted">'
            . number_format($total) . ' matching feedback record(s) · Page ' . $page . ' of ' . $pages . '</span><span>';
        if ($page > 1) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page - 1])) . '">Previous</a> ';
        }
        if ($page < $pages) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($queryBase + ['p' => $page + 1])) . '">Next</a>';
        }
        echo '</span></div>';
    }

    if ($available && $counts['all'] > 0) {
        echo '<form method="post" class="file-feedback-purge" onsubmit="return confirm(\'Permanently delete all file feedback older than the selected age?\')">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('file_feedback_admin')) . '">'
            . '<input type="hidden" name="action" value="purge_older">'
            . '<label>Delete feedback older than <select name="purge_days">';
        foreach ([7, 30, 90, 180, 365] as $days) {
            echo '<option value="' . $days . '"' . ($days === 90 ? ' selected' : '') . '>' . $days . ' days</option>';
        }
        echo '</select></label><button type="submit">Delete old feedback</button></form>';
    }

    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    error_log('[UnrealDB file feedback admin][' . catalog_request_id() . '] ' . $error->getMessage());
    if (!headers_sent()) {
        catalog_head('File Feedback Failed');
    }
    echo CatalogUi::alert('danger', catalog_public_error_message(), 'File Feedback could not be loaded.');
    catalog_foot();
}
