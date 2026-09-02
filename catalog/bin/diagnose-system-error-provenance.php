#!/usr/bin/env php
<?php
/**
 * Read-only provenance diagnostic for open System Error records.
 *
 * Prints the fields that the compact Markdown export intentionally omits so an
 * operator can identify exactly which request/job path created each error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $status = strtolower(trim((string)($argv[1] ?? 'open')));
    if (!in_array($status, ['open', 'resolved', 'ignored', 'all'], true)) {
        throw new InvalidArgumentException('Status must be open, resolved, ignored or all.');
    }

    $where = $status === 'all' ? '' : ' WHERE status=?';
    $args = $status === 'all' ? [] : [$status];
    $statement = $db->prepare(
        'SELECT id,status,severity,source_kind,error_type,message,route,request_method,request_id,'
        . 'source_file,source_line,occurrence_count,first_seen_at,last_seen_at,context_json '
        . 'FROM ue_system_errors' . $where
        . ' ORDER BY last_seen_at DESC,id DESC'
    );
    $statement->execute($args);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $context = [];
        $raw = trim((string)($row['context_json'] ?? ''));
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $context = $decoded;
                }
            } catch (Throwable) {
                $context = [];
            }
        }

        $out[] = [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'severity' => (string)$row['severity'],
            'source_kind' => (string)$row['source_kind'],
            'error_type' => (string)$row['error_type'],
            'file_name' => (string)($context['file_name'] ?? $context['original_name'] ?? $context['job_original_name'] ?? ''),
            'source_relative_path' => (string)($context['source_relative_path'] ?? $context['job_source_relative_path'] ?? ''),
            'job_id' => (int)($context['job_id'] ?? 0),
            'parent_job_id' => (int)($context['parent_job_id'] ?? 0),
            'job_type' => (string)($context['job_type'] ?? ''),
            'archive_source_name' => (string)($context['archive_source_name'] ?? ''),
            'archive_entry_path' => (string)($context['archive_entry_path'] ?? ''),
            'md5' => (string)($context['md5'] ?? ''),
            'sha1' => (string)($context['sha1'] ?? ''),
            'validation_code' => (string)($context['validation_code'] ?? $context['error_code'] ?? ''),
            'route' => (string)$row['route'],
            'request_method' => (string)$row['request_method'],
            'request_id' => (string)$row['request_id'],
            'source_file' => (string)$row['source_file'],
            'source_line' => (int)$row['source_line'],
            'occurrence_count' => (int)$row['occurrence_count'],
            'first_seen_at' => (string)$row['first_seen_at'],
            'last_seen_at' => (string)$row['last_seen_at'],
            'message' => (string)$row['message'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'status' => $status,
        'count' => count($out),
        'errors' => $out,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
