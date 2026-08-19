#!/usr/bin/env php
<?php
/**
 * Read-only end-to-end trace for one archive ingestion job.
 *
 * Shows the archive parent, direct extracted-member jobs, their terminal result
 * or failure, and any ue_files row/physical Upload Bucket file produced by each
 * member. This intentionally changes no queue or catalog state.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

/** @return array<string,mixed> */
function trace_decode_json(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<string,mixed> */
function trace_job_summary(array $row): array
{
    $payload = trace_decode_json($row['payload_json'] ?? null);
    $progress = trace_decode_json($row['progress_json'] ?? null);
    $result = trace_decode_json($row['result_json'] ?? null);

    $summary = [
        'id' => (int)($row['id'] ?? 0),
        'parent_job_id' => isset($row['parent_job_id']) ? (int)$row['parent_job_id'] : null,
        'queue_name' => (string)($row['queue_name'] ?? ''),
        'job_type' => (string)($row['job_type'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'workflow_unit_key' => (string)($row['workflow_unit_key'] ?? ''),
        'attempts' => isset($row['attempts']) ? (int)$row['attempts'] : null,
        'max_attempts' => isset($row['max_attempts']) ? (int)$row['max_attempts'] : null,
        'available_at' => $row['available_at'] ?? null,
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? ($row['completed_at'] ?? null),
        'last_error' => trim((string)($row['last_error'] ?? '')) ?: null,
        'payload' => [
            'original_name' => $payload['original_name'] ?? null,
            'source_relative_path' => $payload['source_relative_path'] ?? null,
            'staged_path' => $payload['staged_path'] ?? null,
            'archive_source_name' => $payload['archive_source_name'] ?? null,
            'archive_entry_path' => $payload['archive_entry_path'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
        ],
        'progress' => [
            'stage' => $progress['stage'] ?? null,
            'percent' => $progress['percent'] ?? null,
            'status' => $progress['status'] ?? null,
            'message' => $progress['message'] ?? null,
            'file_id' => $progress['file_id'] ?? null,
            'queue_name' => $progress['queue_name'] ?? null,
        ],
        'result' => [
            'operation' => $result['operation'] ?? null,
            'status' => $result['status'] ?? null,
            'message' => $result['message'] ?? null,
            'file_id' => $result['file_id'] ?? null,
            'queue_name' => $result['queue_name'] ?? null,
            'original_name' => $result['original_name'] ?? null,
            'source_relative_path' => $result['source_relative_path'] ?? null,
            'md5' => $result['md5'] ?? null,
            'sha1' => $result['sha1'] ?? null,
            'decoder' => $result['decoder'] ?? null,
        ],
    ];

    return $summary;
}

/** @return array<string,mixed>|null */
function trace_file(PDO $db, array $config, int $fileId): ?array
{
    if ($fileId < 1) {
        return null;
    }
    $statement = $db->prepare(
        'SELECT id,game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
        . 'detected_engine_key,scan_status,compatibility_status,file_size,md5,sha1,package_guid,'
        . 'unverified_queue_key,unverified_queue_game_id,unverified_queue_name,unverified_reason '
        . 'FROM ue_files WHERE id=? LIMIT 1'
    );
    $statement->execute([$fileId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
    $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($row['relative_path'] ?? '')), DIRECTORY_SEPARATOR);
    $relativePhysical = $storageRoot !== '' && $relativePath !== ''
        ? $storageRoot . DIRECTORY_SEPARATOR . $relativePath
        : '';

    $queueGameId = (int)($row['unverified_queue_game_id'] ?? -1);
    $queueName = basename(trim((string)($row['unverified_queue_name'] ?? '')));
    $queuePhysical = '';
    if ($storageRoot !== '' && $queueName !== '') {
        if ($queueGameId === 0) {
            $queuePhysical = $storageRoot . DIRECTORY_SEPARATOR . 'upload-bucket' . DIRECTORY_SEPARATOR . $queueName;
        } elseif ($queueGameId > 0) {
            $gameStatement = $db->prepare('SELECT slug FROM ue_games WHERE id=? LIMIT 1');
            $gameStatement->execute([$queueGameId]);
            $slug = trim((string)($gameStatement->fetchColumn() ?: ''));
            if ($slug !== '') {
                $queuePhysical = $storageRoot . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . $slug
                    . DIRECTORY_SEPARATOR . 'unverified' . DIRECTORY_SEPARATOR . $queueName;
            }
        }
    }

    return [
        'id' => (int)$row['id'],
        'game_id' => $row['game_id'] === null ? null : (int)$row['game_id'],
        'package_name' => (string)($row['package_name'] ?? ''),
        'original_name' => (string)($row['original_name'] ?? ''),
        'source_relative_path' => (string)($row['source_relative_path'] ?? ''),
        'stored_name' => (string)($row['stored_name'] ?? ''),
        'relative_path' => (string)($row['relative_path'] ?? ''),
        'extension' => (string)($row['extension'] ?? ''),
        'detected_engine_key' => (string)($row['detected_engine_key'] ?? ''),
        'scan_status' => (string)($row['scan_status'] ?? ''),
        'compatibility_status' => (string)($row['compatibility_status'] ?? ''),
        'file_size' => (int)($row['file_size'] ?? 0),
        'md5' => (string)($row['md5'] ?? ''),
        'sha1' => (string)($row['sha1'] ?? ''),
        'package_guid' => $row['package_guid'] ?? null,
        'unverified_queue_key' => $row['unverified_queue_key'] ?? null,
        'unverified_queue_game_id' => $row['unverified_queue_game_id'] === null ? null : (int)$row['unverified_queue_game_id'],
        'unverified_queue_name' => (string)($row['unverified_queue_name'] ?? ''),
        'unverified_reason' => (string)($row['unverified_reason'] ?? ''),
        'relative_physical_path' => $relativePhysical,
        'relative_physical_exists' => $relativePhysical !== '' && is_file($relativePhysical),
        'queue_physical_path' => $queuePhysical,
        'queue_physical_exists' => $queuePhysical !== '' && is_file($queuePhysical),
    ];
}

try {
    $application = catalog_bootstrap(false);
    $db = $application->db;
    $config = $application->config;

    $requestedId = 0;
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--job=(\d+)$/', $argument, $match) === 1) {
            $requestedId = (int)$match[1];
            break;
        }
        if (ctype_digit($argument)) {
            $requestedId = (int)$argument;
            break;
        }
    }

    if ($requestedId > 0) {
        $parentStatement = $db->prepare('SELECT * FROM ue_background_jobs WHERE id=? LIMIT 1');
        $parentStatement->execute([$requestedId]);
    } else {
        $parentStatement = $db->query(
            'SELECT * FROM ue_background_jobs '
            . 'WHERE job_type IN ("catalog.process_bucket_archive","catalog.import_staged_archive") '
            . 'ORDER BY id DESC LIMIT 1'
        );
    }
    $parent = $parentStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($parent)) {
        throw new RuntimeException($requestedId > 0
            ? 'Background job #' . $requestedId . ' was not found.'
            : 'No archive background job was found.');
    }

    $parentId = (int)$parent['id'];
    $childStatement = $db->prepare('SELECT * FROM ue_background_jobs WHERE parent_job_id=? ORDER BY id ASC');
    $childStatement->execute([$parentId]);
    $childRows = $childStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $children = [];
    $outcomes = [
        'queued' => 0,
        'running' => 0,
        'bucketed' => 0,
        'duplicate' => 0,
        'failed' => 0,
        'other_terminal' => 0,
    ];

    foreach ($childRows as $childRow) {
        $summary = trace_job_summary($childRow);
        $payload = trace_decode_json($childRow['payload_json'] ?? null);
        $progress = trace_decode_json($childRow['progress_json'] ?? null);
        $result = trace_decode_json($childRow['result_json'] ?? null);

        $fileId = max(
            0,
            (int)($result['file_id'] ?? 0),
            (int)($progress['file_id'] ?? 0)
        );
        $summary['file'] = trace_file($db, $config, $fileId);

        $stagedPath = trim((string)($payload['staged_path'] ?? ''));
        $staged = ['reference' => $stagedPath, 'resolvable' => false, 'path' => null, 'exists' => false];
        if ($stagedPath !== '') {
            try {
                $resolved = (new CatalogIncomingFileStore($config))->resolve($stagedPath);
                $staged['resolvable'] = true;
                $staged['path'] = $resolved;
                $staged['exists'] = is_file($resolved);
            } catch (Throwable $error) {
                $staged['error'] = $error->getMessage();
            }
        }
        $summary['staged_source'] = $staged;

        $status = strtolower((string)($childRow['status'] ?? ''));
        $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
        if ($status === 'queued') {
            $outcomes['queued']++;
            $summary['diagnosis'] = 'Extracted member is still queued and has not reached Upload Bucket indexing.';
        } elseif ($status === 'running') {
            $outcomes['running']++;
            $summary['diagnosis'] = 'Extracted member is currently being processed.';
        } elseif (in_array($status, ['failed', 'dead_letter', 'cancelled'], true)) {
            $outcomes['failed']++;
            $summary['diagnosis'] = 'Extracted member did not reach Unverified Files. Check last_error and the last durable progress stage.';
        } elseif ($resultStatus === 'duplicate') {
            $outcomes['duplicate']++;
            $summary['diagnosis'] = 'Extracted member was intentionally discarded as a physical duplicate; file_id identifies the existing package.';
        } elseif ($resultStatus === 'bucketed') {
            $outcomes['bucketed']++;
            if (is_array($summary['file'])) {
                $scanStatus = strtolower((string)($summary['file']['scan_status'] ?? ''));
                $queueExists = !empty($summary['file']['queue_physical_exists']);
                if ($scanStatus === 'unverified' && $queueExists) {
                    $summary['diagnosis'] = 'Member is indexed and physically present in Upload Bucket. If absent from the page, the remaining problem is Unverified Files page filtering/query state.';
                } elseif ($scanStatus === 'unverified') {
                    $summary['diagnosis'] = 'Member has an unverified database row but its expected physical Upload Bucket file is missing.';
                } else {
                    $summary['diagnosis'] = 'Member produced a catalog file row, but it is no longer in unverified state.';
                }
            } else {
                $summary['diagnosis'] = 'Child reports bucketed but its file_id row is missing; this is an ingestion persistence inconsistency.';
            }
        } else {
            $outcomes['other_terminal']++;
            $summary['diagnosis'] = 'Child is terminal but did not report bucketed/duplicate. Inspect result and last_error.';
        }

        $children[] = $summary;
    }

    $parentSummary = trace_job_summary($parent);
    $result = [
        'ok' => true,
        'archive_job_id' => $parentId,
        'parent' => $parentSummary,
        'direct_child_count' => count($children),
        'child_outcomes' => $outcomes,
        'children' => $children,
        'interpretation' => count($children) === 0
            ? 'Archive parent has no direct extracted-member job. The extraction/enqueue boundary is the failure point.'
            : 'Follow each child diagnosis. A completed archive parent only proves extraction/enqueue completed; the child result determines whether a file appears in Unverified Files.',
        'read_only' => true,
    ];

    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
