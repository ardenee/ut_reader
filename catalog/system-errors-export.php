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
    return "````text\n" . rtrim($value) . "\n````\n";
}

/** @return array<string,mixed> */
function system_error_export_context(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? system_error_export_redact($decoded) : [];
    } catch (Throwable) {
        return [];
    }
}

/** @param array<string,mixed> $context */
function system_error_export_location(array $context): string
{
    foreach ([
        'source_relative_path',
        'job_source_relative_path',
        'archive_source_relative_path',
        'parent_source_relative_path',
    ] as $key) {
        $value = trim((string)($context[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $archive = trim((string)($context['archive_source_name'] ?? ''));
    $entry = trim((string)($context['archive_entry_path'] ?? ''));
    if ($archive !== '' && $entry !== '') {
        return rtrim($archive, '/\\') . '/' . ltrim($entry, '/\\');
    }
    if ($archive !== '') {
        return $archive;
    }

    foreach (['file_name', 'original_name', 'job_original_name'] as $key) {
        $value = trim((string)($context[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Unknown';
}

/** @param array<string,mixed> $context */
function system_error_export_title(array $row, array $context): string
{
    foreach (['file_name', 'original_name', 'job_original_name', 'archive_source_name'] as $key) {
        $value = trim((string)($context[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return trim((string)($row['error_type'] ?? 'System error')) ?: 'System error';
}

/** @param array<string,mixed> $context */
function system_error_export_reason(array $row, array $context): string
{
    $message = trim((string)($row['message'] ?? ''));
    $fileName = trim((string)($context['file_name'] ?? ''));
    if ($fileName !== '' && str_starts_with($message, $fileName . ':')) {
        $message = trim(substr($message, strlen($fileName) + 1));
    }

    $errors = is_array($context['errors'] ?? null) ? $context['errors'] : [];
    if ($errors !== []) {
        $parts = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $member = trim((string)($error['file'] ?? ''));
            $detail = trim((string)($error['error'] ?? ''));
            if ($member !== '' || $detail !== '') {
                $parts[] = ($member !== '' ? $member . ': ' : '') . $detail;
            }
        }
        if ($parts !== []) {
            return implode(' | ', $parts);
        }
    }

    if (preg_match('/UMOD-family archive CRC does not match its footer/i', $message) === 1) {
        return 'UMOD CRC mismatch';
    }
    if (preg_match('/ZIP decompression failed/i', $message) === 1) {
        return 'ZIP decompression failed';
    }

    $message = preg_replace('/^catalog\.[^ ]+\s+#\d+\s+[a-z_]+:\s*/i', '', $message) ?? $message;
    $message = preg_replace('/\s+Archive:\s+.+?(?:\s+Entry:\s+.+)?$/i', '', $message) ?? $message;
    return trim($message) !== '' ? trim($message) : (string)($row['error_type'] ?? 'System error');
}

/**
 * @param array<string,mixed> $context
 * @return array<string,string|int|float|bool>
 */
function system_error_export_values(array $row, array $context): array
{
    $values = [];
    $arguments = is_array($context['validation_arguments'] ?? null)
        ? $context['validation_arguments']
        : [];
    foreach ($arguments as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $values[(string)$key] = $value === null ? '' : $value;
        }
    }

    if ($values === [] && (string)($row['error_type'] ?? '') === 'ArchivePartialFailure') {
        foreach (['archive_entries', 'queued_files', 'skipped_files', 'failed_files'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $values[$key] = $context[$key];
            }
        }
    }

    $message = (string)($row['message'] ?? '');
    if (preg_match(
        '/expected=([0-9A-F]+);\s*actual=([0-9A-F]+);\s*checked_bytes=([0-9,]+)/i',
        $message,
        $match
    ) === 1) {
        $values['expected_crc'] = strtoupper($match[1]);
        $values['actual_crc'] = strtoupper($match[2]);
        $values['checked_bytes'] = (int)str_replace(',', '', $match[3]);
    }
    if (preg_match('/ZIP decompression failed\s*\((-?\d+)\)/i', $message, $match) === 1) {
        $values['decoder_code'] = (int)$match[1];
    }
    if (preg_match('/archive member "([^"]+)"/i', $message, $match) === 1) {
        $values['member'] = $match[1];
    }

    return $values;
}

/** @param array<string,string|int|float|bool> $values */
function system_error_export_values_text(array $values): string
{
    if ($values === []) {
        return 'none';
    }
    $parts = [];
    foreach ($values as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $parts[] = $key . '=' . (string)$value;
    }
    return implode(', ', $parts);
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
        $where[] = '(message LIKE ? OR error_type LIKE ? OR route LIKE ? OR source_file LIKE ? OR request_id LIKE ? '
            . 'OR COALESCE(context_json,"") LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($args, $like, $like, $like, $like, $like, $like);
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
        $context = system_error_export_context((string)($row['context_json'] ?? ''));
        $title = system_error_export_title($row, $context);
        $reason = system_error_export_reason($row, $context);
        $values = system_error_export_values($row, $context);
        $location = system_error_export_location($context);

        echo '## ' . str_replace(["\r", "\n"], ' ', $title) . "\n\n";
        echo '**Error:** ' . str_replace(["\r", "\n"], ' ', $reason) . "\n\n";
        echo '**Values:** ' . system_error_export_values_text($values) . "\n\n";
        echo '**Location:** `' . str_replace('`', '\\`', $location) . "`\n\n";
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
