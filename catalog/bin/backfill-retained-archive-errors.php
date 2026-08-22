#!/usr/bin/env php
<?php
/** Backfills System Errors for archive jobs that already completed as retained/partial. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

/** @return array<string,mixed> */
function retained_archive_decode_json(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

$options = getopt('', ['job::', 'limit::']);
$jobId = max(0, (int)($options['job'] ?? 0));
$limit = max(1, min(100000, (int)($options['limit'] ?? 10000)));

try {
    $application = catalog_bootstrap(false);
    $db = $application->db;

    $sql = 'SELECT id,queue_name,job_type,status,attempts,max_attempts,resource_class,concurrency_key,'
        . 'payload_json,progress_json,result_json,completed_at '
        . 'FROM ue_background_jobs '
        . 'WHERE status="completed" '
        . 'AND job_type IN ("catalog.process_bucket_archive","catalog.import_staged_archive") ';
    $parameters = [];
    if ($jobId > 0) {
        $sql .= 'AND id=? ';
        $parameters[] = $jobId;
    }
    $sql .= 'ORDER BY id DESC LIMIT ' . $limit;

    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recorded = 0;
    $ignored = 0;
    foreach ($rows as $row) {
        $payload = retained_archive_decode_json($row['payload_json'] ?? null);
        $progress = retained_archive_decode_json($row['progress_json'] ?? null);
        $result = retained_archive_decode_json($row['result_json'] ?? null);
        $displayStatus = strtolower(trim((string)($result['status'] ?? $progress['status'] ?? '')));
        if ($displayStatus !== 'partial') {
            $ignored++;
            continue;
        }

        $errors = is_array($result['errors'] ?? null)
            ? array_values($result['errors'])
            : (is_array($progress['errors'] ?? null) ? array_values($progress['errors']) : []);
        $failed = max(0, (int)($result['failed_files'] ?? $progress['failed'] ?? count($errors)));
        if ($failed < 1 && $errors === []) {
            $ignored++;
            continue;
        }
        $failed = max(1, $failed);

        $originalName = trim((string)($result['original_name'] ?? $payload['original_name'] ?? 'archive'));
        $sourceRelativePath = trim((string)(
            $result['source_relative_path'] ?? $payload['source_relative_path'] ?? $originalName
        ));
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $firstFile = trim((string)($first['file'] ?? ''));
        $firstError = trim((string)($first['error'] ?? ''));
        $message = (string)$row['job_type'] . ' #' . (int)$row['id'] . ' partial_archive: '
            . ($sourceRelativePath !== '' ? $sourceRelativePath : $originalName)
            . ' retained with ' . number_format($failed) . ' failed archive member(s).';
        if ($firstFile !== '' || $firstError !== '') {
            $message .= ' First failure: ' . ($firstFile !== '' ? $firstFile . ' — ' : '') . $firstError;
        }

        CatalogSystemErrorRecorder::record([
            'source_kind' => 'background-job',
            'severity' => 'error',
            'error_type' => 'ArchivePartialFailure',
            'message' => $message,
            'source_file' => dirname(__DIR__) . '/src/Infrastructure/Jobs/CatalogArchiveImportJobHandler.php',
            'source_line' => 0,
            'context' => [
                'job_id' => (int)$row['id'],
                'job_type' => (string)$row['job_type'],
                'attempt' => (int)($row['attempts'] ?? 0),
                'max_attempts' => (int)($row['max_attempts'] ?? 0),
                'disposition' => 'partial_archive',
                'resource_class' => (string)($row['resource_class'] ?? ''),
                'concurrency_key' => (string)($row['concurrency_key'] ?? ''),
                'original_name' => $originalName,
                'source_relative_path' => $sourceRelativePath,
                'archive_entries' => max(0, (int)($result['archive_entries'] ?? $progress['entry_cursor'] ?? 0)),
                'queued_files' => max(0, (int)($result['queued_files'] ?? $progress['queued'] ?? 0)),
                'skipped_files' => max(0, (int)($result['skipped_files'] ?? $progress['skipped'] ?? 0)),
                'failed_files' => $failed,
                'errors' => $errors,
                'result_message' => trim((string)($result['message'] ?? $progress['message'] ?? '')),
                'backfilled' => true,
                'completed_at' => $row['completed_at'] ?? null,
            ],
        ]);
        $recorded++;
        fwrite(STDOUT, '[RECORDED] job #' . (int)$row['id'] . ' — ' . $sourceRelativePath . PHP_EOL);
    }

    fwrite(STDOUT, PHP_EOL . 'Retained archive error backfill complete: '
        . number_format($recorded) . ' recorded, ' . number_format($ignored) . ' non-partial/empty skipped.' . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
