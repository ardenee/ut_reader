<?php
/**
 * Downloads the current System Errors filter as a compact Markdown diagnostic report.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function system_error_export_filter(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function system_error_export_search(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    return mb_strlen($value, 'UTF-8') > 200 ? mb_substr($value, 0, 200, 'UTF-8') : $value;
}

/** @return mixed */
function system_error_export_redact(mixed $value, string $key = ''): mixed
{
    if ($key !== '' && preg_match('/(?:pass(?:word|wd)?|secret|token|authorization|cookie|api[_-]?key|private[_-]?key|session)/i', $key) === 1) {
        return '[REDACTED]';
    }
    if (!is_array($value)) {
        return $value;
    }
    $clean = [];
    foreach ($value as $childKey => $childValue) {
        $clean[$childKey] = system_error_export_redact($childValue, (string)$childKey);
    }
    return $clean;
}

function system_error_export_code_fence(string $value): string
{
    // A longer fence prevents trace/context content containing ``` from terminating the block.
    return "````text\n" . rtrim($value) . "\n````\n";
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    if (!catalog_require_admin_page('System Error Export')) {
        exit;
    }

    $exists = $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_system_errors"'
    );
    if ((int)$exists->fetchColumn() !== 1) {
        throw new RuntimeException('System Error storage is not installed.');
    }

    $status = system_error_export_filter(
        (string)($_GET['status'] ?? 'open'),
        ['open', 'resolved', 'ignored', 'all'],
        'open'
    );
    $severity = system_error_export_filter(
        (string)($_GET['severity'] ?? 'all'),
        ['debug', 'info', 'warning', 'error', 'critical', 'all'],
        'all'
    );
    $source = preg_replace('/[^a-z0-9._:-]+/', '', strtolower(trim((string)($_GET['source'] ?? 'all')))) ?: 'all';
    $errorType = preg_replace('/[^A-Za-z0-9._:-]+/', '', trim((string)($_GET['type'] ?? 'all'))) ?: 'all';
    $search = system_error_export_search((string)($_GET['q'] ?? ''));

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
        $where[] = '(message LIKE ? OR error_type LIKE ? OR route LIKE ? OR source_file LIKE ? OR request_id LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = catalog_count($db, 'SELECT COUNT(*) c FROM ue_system_errors' . $whereSql, $args);
    $limit = 20000;
    $statement = $db->prepare(
        'SELECT * FROM ue_system_errors' . $whereSql
        . ' ORDER BY last_seen_at DESC,id DESC LIMIT ' . $limit
    );
    $statement->execute($args);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $generated = gmdate('Y-m-d H:i:s') . ' UTC';
    $filename = 'unrealdb-errors-' . gmdate('Ymd-His') . '.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');

    echo "# UnrealDB System Error Export\n\n";
    echo '- Generated: ' . $generated . "\n";
    echo '- Status: `' . $status . "`\n";
    echo '- Severity: `' . $severity . "`\n";
    echo '- Source: `' . $source . "`\n";
    echo '- Type: `' . $errorType . "`\n";
    echo '- Search: ' . ($search !== '' ? '`' . str_replace('`', '\\`', $search) . '`' : 'none') . "\n";
    echo '- Matching records: ' . number_format($total) . "\n";
    echo '- Exported records: ' . number_format(count($rows)) . "\n";
    if ($total > $limit) {
        echo '- Warning: export limited to the newest ' . number_format($limit) . " matching records.\n";
    }
    echo "\n";

    foreach ($rows as $row) {
        echo '## #' . (int)$row['id'] . ' — ' . (string)$row['error_type'] . "\n\n";
        echo '- Status: `' . (string)$row['status'] . "`\n";
        echo '- Severity: `' . (string)$row['severity'] . "`\n";
        echo '- Source: `' . (string)$row['source_kind'] . "`\n";
        echo '- Occurrences: ' . number_format((int)$row['occurrence_count']) . "\n";
        echo '- First seen: ' . (string)$row['first_seen_at'] . "\n";
        echo '- Last seen: ' . (string)$row['last_seen_at'] . "\n";
        if ((string)($row['resolved_at'] ?? '') !== '') {
            echo '- Resolved: ' . (string)$row['resolved_at'] . "\n";
        }
        echo '- Request: `' . (string)$row['request_method'] . ' ' . (string)$row['route'] . "`\n";
        echo '- HTTP: ' . (int)$row['http_status'] . "\n";
        echo '- Request ID: `' . (string)$row['request_id'] . "`\n";
        if ((string)$row['source_file'] !== '') {
            echo '- Source location: `' . str_replace('`', '\\`', (string)$row['source_file']) . ':' . (int)$row['source_line'] . "`\n";
        }
        echo "\n**Message**\n\n" . trim((string)$row['message']) . "\n\n";

        $context = [];
        $rawContext = trim((string)($row['context_json'] ?? ''));
        if ($rawContext !== '') {
            try {
                $decoded = json_decode($rawContext, true, 512, JSON_THROW_ON_ERROR);
                $context = is_array($decoded) ? system_error_export_redact($decoded) : [];
            } catch (Throwable) {
                $context = ['unparsed_context' => '[context JSON could not be decoded]'];
            }
        }
        if ($context !== []) {
            $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                echo "**Context**\n\n" . system_error_export_code_fence($encoded) . "\n";
            }
        }

        $trace = trim((string)($row['trace_text'] ?? ''));
        if ($trace !== '') {
            echo "**Trace**\n\n" . system_error_export_code_fence($trace) . "\n";
        }
        $resolution = trim((string)($row['resolution_note'] ?? ''));
        if ($resolution !== '') {
            echo "**Resolution note**\n\n" . $resolution . "\n\n";
        }
        echo "---\n\n";
    }
} catch (Throwable $error) {
    if (function_exists('catalog_system_error_record_exception')) {
        catalog_system_error_record_exception($error, 'system_error_export');
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo 'System Error export failed: ' . catalog_public_error_message() . "\n";
}
