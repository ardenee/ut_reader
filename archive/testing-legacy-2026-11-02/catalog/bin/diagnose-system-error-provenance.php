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

        $jobId = max(0, (int)($context['job_id'] ?? 0));
        if ($jobId > 0 && max(0, (int)($context['file_id'] ?? 0)) < 1) {
            try {
                $jobStatement = $db->prepare(
                    'SELECT job_type,payload_json FROM ue_background_jobs WHERE id=? LIMIT 1'
                );
                $jobStatement->execute([$jobId]);
                $job = $jobStatement->fetch(PDO::FETCH_ASSOC);
                if (is_array($job)) {
                    $payload = json_decode((string)($job['payload_json'] ?? ''), true);
                    $payload = is_array($payload) ? $payload : [];
                    $fileId = max(
                        0,
                        (int)($payload['file_id'] ?? 0),
                        (int)($payload['affected_file_id'] ?? 0)
                    );
                    if ($fileId > 0) {
                        $fileStatement = $db->prepare(
                            'SELECT f.id,f.game_id,f.original_name,f.source_relative_path,f.relative_path,f.file_size,'
                            . 'f.md5,f.sha1,f.package_version,f.licensee_version,f.detected_engine_key,'
                            . 'f.detected_package_version,f.detected_licensee_version,g.name game_name '
                            . 'FROM ue_files f LEFT JOIN ue_games g ON g.id=f.game_id WHERE f.id=? LIMIT 1'
                        );
                        $fileStatement->execute([$fileId]);
                        $file = $fileStatement->fetch(PDO::FETCH_ASSOC);
                        if (is_array($file)) {
                            $context['file_id'] = (int)$file['id'];
                            $context['game_id'] = (int)$file['game_id'];
                            $context['game_name'] = (string)($file['game_name'] ?? '');
                            $context['file_name'] = (string)$file['original_name'];
                            $context['original_name'] = (string)$file['original_name'];
                            $context['source_relative_path'] = (string)($file['source_relative_path'] ?? '');
                            $context['canonical_relative_path'] = (string)($file['relative_path'] ?? '');
                            $context['file_size'] = max(0, (int)($file['file_size'] ?? 0));
                            $context['md5'] = (string)($file['md5'] ?? '');
                            $context['sha1'] = (string)($file['sha1'] ?? '');
                            $context['package_version'] = (int)($file['package_version'] ?? 0);
                            $context['licensee_version'] = (int)($file['licensee_version'] ?? 0);
                            $context['detected_engine_key'] = (string)($file['detected_engine_key'] ?? '');
                            $context['detected_package_version'] = (int)($file['detected_package_version'] ?? 0);
                            $context['detected_licensee_version'] = (int)($file['detected_licensee_version'] ?? 0);
                        }
                    }
                }
            } catch (Throwable) {
                // Diagnostic enrichment is best-effort.
            }
        }

        $out[] = [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'severity' => (string)$row['severity'],
            'source_kind' => (string)$row['source_kind'],
            'error_type' => (string)$row['error_type'],
            'file_id' => (int)($context['file_id'] ?? 0),
            'game_id' => (int)($context['game_id'] ?? 0),
            'game_name' => (string)($context['game_name'] ?? ''),
            'file_name' => (string)($context['file_name'] ?? $context['original_name'] ?? $context['job_original_name'] ?? ''),
            'source_relative_path' => (string)($context['source_relative_path'] ?? $context['job_source_relative_path'] ?? ''),
            'canonical_relative_path' => (string)($context['canonical_relative_path'] ?? ''),
            'file_size' => (int)($context['file_size'] ?? 0),
            'package_version' => (int)($context['package_version'] ?? 0),
            'licensee_version' => (int)($context['licensee_version'] ?? 0),
            'detected_engine_key' => (string)($context['detected_engine_key'] ?? ''),
            'detected_package_version' => (int)($context['detected_package_version'] ?? 0),
            'detected_licensee_version' => (int)($context['detected_licensee_version'] ?? 0),
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
