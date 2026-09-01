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

use UnrealDb\Catalog\Application\Jobs\JobFailureRetryPolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;
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

/** @return array{file:string,original_name:string,size:int,sha256:string,archive_source:string,archive_entry:string} */
function upload_issue_payload_file(array $row): array
{
    $payload = [];
    try {
        $decoded = json_decode((string)($row['payload_json'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        $payload = is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        $payload = [];
    }

    $originalName = trim((string)($payload['original_name'] ?? $payload['file'] ?? ''));
    $archiveSource = trim((string)($payload['archive_source_name'] ?? ''));
    $archiveEntry = trim((string)($payload['archive_entry_path'] ?? ''));
    $relativePath = trim((string)($payload['source_relative_path'] ?? ''));
    if ($relativePath === '' && $archiveSource !== '' && $archiveEntry !== '') {
        $relativePath = rtrim($archiveSource, '/\\') . '/' . ltrim($archiveEntry, '/\\');
    } elseif ($relativePath === '') {
        $relativePath = $originalName;
    }

    return [
        'file' => $relativePath,
        'original_name' => $originalName,
        'size' => max(0, (int)($payload['size'] ?? $payload['expected_size'] ?? 0)),
        'sha256' => trim((string)($payload['sha256'] ?? '')),
        'archive_source' => $archiveSource,
        'archive_entry' => $archiveEntry,
    ];
}

function upload_issue_job_reason(array $row): string
{
    $lastError = trim((string)($row['last_error'] ?? ''));
    if ($lastError !== '') {
        return $lastError;
    }

    foreach (['result_json', 'progress_json'] as $column) {
        try {
            $decoded = json_decode((string)($row[$column] ?? ''), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }
        if (!is_array($decoded)) {
            continue;
        }
        $message = trim((string)($decoded['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }
        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        $parts = [];
        foreach (array_slice($errors, 0, 3) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $file = trim((string)($error['file'] ?? ''));
            $text = trim((string)($error['error'] ?? ''));
            if ($file !== '' || $text !== '') {
                $parts[] = ($file !== '' ? $file . ' — ' : '') . $text;
            }
        }
        if ($parts !== []) {
            return implode(' | ', $parts);
        }
    }

    return 'The file did not complete processing, but no detailed reason was persisted.';
}

function upload_issue_attention_label(array $job, string $reason): string
{
    if (JobFailureRetryPolicy::isDeterministicFailureText((string)($job['job_type'] ?? ''), $reason)) {
        return 'Replace / fix source';
    }
    $displayStatus = strtolower(trim((string)($job['display_status'] ?? '')));
    if ($displayStatus === 'partial') {
        return 'Inspect archive';
    }
    if (in_array($displayStatus, ['rejected', 'unverified'], true)) {
        return 'Review source';
    }
    return 'Review / retry';
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
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        if ($action === 'purge_closed') {
            $days = (int)($_POST['purge_days'] ?? 30);
            $deleted = $store->purgeClosedOlderThan($days);
            $message = $deleted . ' resolved/ignored Upload Issue record(s) older than ' . $days . ' day(s) deleted.';
        } else {
            $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
            if ($action === 'delete') {
                $deleted = $store->deleteSelected($ids);
                $message = $deleted . ' selected Upload Issue record(s) permanently deleted.';
            } else {
                $targetStatus = match ($action) {
                    'resolve' => 'resolved',
                    'ignore' => 'ignored',
                    'reopen' => 'open',
                    default => '',
                };
                if ($targetStatus === '') {
                    throw new RuntimeException('Choose Resolve, Ignore, Reopen or Delete selected.');
                }
                $note = trim((string)($_POST['resolution_note'] ?? ''));
                $updated = $store->setStatus($ids, $targetStatus, (int)($_SESSION['user']['id'] ?? 0), $note);
                $message = $updated . ' Upload Issue record(s) updated.';
            }
        }
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

    // Background jobs all execute on the configured durable queue. Resource class
    // controls concurrency; it is not a queue name. Older Upload Issues code looked
    // for synthetic queue names such as catalog:bucket-processing, so current
    // processing failures were invisible here even though the jobs still existed.
    $baseQueue = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $processingTypes = [
        JobType::PREPARE_BUCKET_REDIRECT,
        JobType::PROCESS_BUCKET_UPLOAD,
        JobType::PROCESS_BUCKET_STAGED_PACKAGE,
        JobType::PROCESS_BUCKET_ARCHIVE,
        JobType::IMPORT_STAGED_PACKAGE,
        JobType::IMPORT_STAGED_PAK,
        JobType::IMPORT_STAGED_PAK_ENTRY,
        JobType::IMPORT_STAGED_ARCHIVE,
    ];
    $processingTypeSql = implode(',', array_fill(0, count($processingTypes), '?'));
    $processingWhere = 'j.queue_name=? AND j.job_type IN (' . $processingTypeSql . ') AND ('
        . 'j.status IN ("failed","dead_letter") OR '
        . '(j.status="completed" AND j.display_status IN ("failed","rejected","unverified","partial","error"))'
        . ')';
    $processingArgs = array_merge([$baseQueue], $processingTypes);
    if ($search !== '') {
        $processingWhere .= ' AND (j.payload_json LIKE ? OR COALESCE(j.last_error,"") LIKE ? OR COALESCE(j.result_json,"") LIKE ?)';
        $processingLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($processingArgs, $processingLike, $processingLike, $processingLike);
    }

    $processingCount = $db->prepare('SELECT COUNT(*) FROM ue_background_jobs j WHERE ' . $processingWhere);
    $processingCount->execute($processingArgs);
    $processingTotal = max(0, (int)$processingCount->fetchColumn());
    $processingPages = max(1, (int)ceil($processingTotal / $perPage));
    $processingPage = min(max(1, (int)($_GET['job_p'] ?? 1)), $processingPages);
    $processingOffset = ($processingPage - 1) * $perPage;
    $processingStatement = $db->prepare(
        'SELECT j.id,j.parent_job_id,j.queue_name,j.job_type,j.resource_class,j.status,j.display_status,'
        . 'j.payload_json,j.progress_json,j.result_json,j.last_error,j.attempts,j.max_attempts,j.updated_at '
        . 'FROM ue_background_jobs j WHERE ' . $processingWhere
        . ' ORDER BY j.updated_at DESC,j.id DESC LIMIT ' . $perPage . ' OFFSET ' . $processingOffset
    );
    $processingStatement->execute($processingArgs);
    $processing = $processingStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    catalog_head('Upload Issues');
    echo '<style>'
        . '.upload-issue-cards{grid-template-columns:repeat(4,minmax(130px,1fr));margin-bottom:14px}'
        . '.upload-issue-toolbar,.upload-issue-actions,.upload-issue-pagination,.upload-issue-cleanup{display:flex;gap:9px;align-items:center;flex-wrap:wrap}'
        . '.upload-issue-toolbar,.upload-issue-actions{margin-bottom:12px}'
        . '.upload-issue-toolbar .search{min-width:280px;flex:1}'
        . '.upload-issue-cleanup{padding:10px 12px;margin:0 0 14px;border:1px solid var(--line);border-radius:10px}'
        . '.upload-issue-cleanup .muted{margin-right:auto}'
        . '.upload-issue-table{table-layout:fixed;min-width:1250px}'
        . '.upload-issue-table .col-select{width:42px}.upload-issue-table .col-status{width:92px}.upload-issue-table .col-stage{width:120px}'
        . '.upload-issue-table .col-size{width:105px}.upload-issue-table .col-count{width:80px}.upload-issue-table .col-date{width:170px}'
        . '.upload-issue-path,.upload-issue-error{overflow-wrap:anywhere}'
        . '.upload-issue-error{color:#fecdd3}'
        . '.upload-issue-pill{display:inline-block;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-weight:700}'
        . '.upload-issue-pill-open{color:#fecdd3;border-color:rgba(255,107,122,.75)}'
        . '.upload-issue-pill-resolved{color:#a7f3d0;border-color:rgba(50,213,131,.75)}'
        . '.upload-issue-pill-ignored{color:#fde68a;border-color:rgba(246,196,83,.75)}'
        . '.upload-issue-pill-review{color:#fde68a;border-color:rgba(246,196,83,.75)}'
        . '.upload-issue-pagination{justify-content:space-between;margin-top:12px}'
        . '.processing-issues{margin-top:18px}'
        . '.processing-file-meta{display:block;margin-top:4px}'
        . '.processing-action{font-weight:700}'
        . '@media(max-width:900px){.upload-issue-cards{grid-template-columns:1fr 1fr}}'
        . '</style>';

    catalog_page_header(
        'Upload Issues',
        'Use this page as the operator list for files that did not make it through upload or package processing. Browser/transfer issues are persistent records; current processing failures are listed below with the exact file path and reason.',
        [
            'Upload Bucket' => 'upload-bucket-v2.php',
            'Review Unverified Files' => 'unverified-files.php?source_game_id=-1',
            'Background Jobs' => 'background-jobs.php?queue=' . rawurlencode($baseQueue),
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

    if ($available) {
        echo '<form method="post" class="upload-issue-cleanup" onsubmit="return confirm(\'Delete resolved/ignored Upload Issue records older than the selected age? Open issues will not be touched.\')">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload_issues')) . '">'
            . '<input type="hidden" name="action" value="purge_closed">'
            . '<span class="muted"><strong>Old log cleanup:</strong> removes resolved/ignored records only.</span>'
            . '<label>Older than <select name="purge_days">';
        foreach ([7, 30, 90, 180, 365] as $days) {
            echo '<option value="' . $days . '"' . ($days === 30 ? ' selected' : '') . '>' . $days . ' days</option>';
        }
        echo '</select></label><button class="secondary" type="submit">Purge old closed logs</button></form>';
    }

    if (!$available || $rows === []) {
        echo CatalogUi::emptyState('No matching Upload Issues', $available ? 'No persistent upload failures match the current filters.' : 'Apply the database migration to begin recording failures.');
    } else {
        echo '<form method="post" onsubmit="if(this.elements.action.value===\'delete\'){return confirm(\'Permanently delete the selected Upload Issue records?\');}return true;">'
            . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('upload_issues')) . '">';
        echo '<div class="upload-issue-actions">'
            . '<label><input type="checkbox" onclick="document.querySelectorAll(\'.upload-issue-check\').forEach(c=>c.checked=this.checked)"> Select page</label>'
            . '<select name="action" required><option value="">Choose action</option><option value="resolve">Resolve</option><option value="ignore">Ignore</option><option value="reopen">Reopen</option><option value="delete">Delete selected</option></select>'
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
        $queryBase = ['status' => $status, 'q' => $search, 'per_page' => $perPage, 'job_p' => $processingPage];
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

    echo '<section class="ui-section processing-issues"><div class="ui-section__header"><div><h2>Files needing attention (' . number_format($processingTotal) . ')</h2>'
        . '<p>These files reached the durable queue but did not complete package/archive processing. Use the full source path and reason below to locate a bad file, replace it, repair it, or decide whether it should be ignored. Cancelled jobs are not included.</p></div>'
        . '<a class="button secondary" href="background-jobs.php?queue=' . rawurlencode($baseQueue) . '">Open Background Jobs</a>'
        . '</div><div class="ui-section__body">';
    if ($processing === []) {
        echo CatalogUi::emptyState('No files need attention', 'No failed, rejected, unverified or partial upload/import jobs match the current search.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Job</th><th>Status</th><th>File / source</th><th>Reason</th><th>Action needed</th><th>Updated</th></tr></thead><tbody>';
        foreach ($processing as $job) {
            $file = upload_issue_payload_file($job);
            $reason = upload_issue_job_reason($job);
            $attention = upload_issue_attention_label($job, $reason);
            $displayStatus = trim((string)($job['display_status'] ?? ''));
            $statusLabel = $displayStatus !== '' ? $displayStatus : (string)$job['status'];
            if (strtolower($statusLabel) === 'dead_letter') {
                $statusLabel = 'failed';
            }
            $jobSearchUrl = 'background-jobs.php?queue=' . rawurlencode((string)$job['queue_name']) . '&search=' . (int)$job['id'];

            echo '<tr>';
            echo '<td><a href="' . catalog_h($jobSearchUrl) . '">#' . (int)$job['id'] . '</a><br><span class="mono small muted">' . catalog_h((string)$job['job_type']) . '</span></td>';
            echo '<td><span class="upload-issue-pill upload-issue-pill-open">' . catalog_h($statusLabel) . '</span><br><span class="small muted">' . (int)$job['attempts'] . ' / ' . (int)$job['max_attempts'] . ' attempts</span></td>';
            echo '<td class="upload-issue-path"><strong>' . catalog_h($file['file'] !== '' ? $file['file'] : ($file['original_name'] !== '' ? $file['original_name'] : 'Unknown file')) . '</strong>';
            if ($file['archive_source'] !== '' || $file['archive_entry'] !== '') {
                echo '<span class="processing-file-meta small muted">Archive: ' . catalog_h($file['archive_source'] !== '' ? $file['archive_source'] : 'unknown')
                    . ($file['archive_entry'] !== '' ? '<br>Member: ' . catalog_h($file['archive_entry']) : '') . '</span>';
            }
            if ($file['size'] > 0) {
                echo '<span class="processing-file-meta small muted">Size: ' . catalog_h(catalog_bytes($file['size'])) . '</span>';
            }
            if ($file['sha256'] !== '') {
                echo '<span class="processing-file-meta mono small muted">SHA-256: ' . catalog_h($file['sha256']) . '</span>';
            }
            if ((int)($job['parent_job_id'] ?? 0) > 0) {
                echo '<span class="processing-file-meta small muted">Archive workflow parent job #' . (int)$job['parent_job_id'] . '</span>';
            }
            echo '</td>';
            echo '<td class="upload-issue-error">' . catalog_h($reason) . '</td>';
            echo '<td><span class="processing-action">' . catalog_h($attention) . '</span></td>';
            echo '<td class="mono small">' . catalog_h((string)$job['updated_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        $processingQueryBase = ['status' => $status, 'q' => $search, 'per_page' => $perPage, 'p' => $page];
        echo '<div class="upload-issue-pagination"><span class="muted">' . number_format($processingTotal) . ' problem file/job(s) · Page ' . $processingPage . ' of ' . $processingPages . '</span><span>';
        if ($processingPage > 1) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($processingQueryBase + ['job_p' => $processingPage - 1])) . '">Previous</a> ';
        }
        if ($processingPage < $processingPages) {
            echo '<a class="button secondary" href="?' . catalog_h(http_build_query($processingQueryBase + ['job_p' => $processingPage + 1])) . '">Next</a>';
        }
        echo '</span></div>';
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
