#!/usr/bin/env php
<?php
/**
 * Verify selected verified-file format-2 containers and optionally queue one
 * durable repair workflow for each unreadable container.
 *
 * Examples:
 *   php catalog/bin/verify-compact-metadata-files.php --file-ids=180119,190014
 *   php catalog/bin/verify-compact-metadata-files.php --file-ids=180119,190014 --queue-repair
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

$options = getopt('', ['file-ids:', 'queue-repair']);
$rawIds = trim((string)($options['file-ids'] ?? ''));
if ($rawIds === '') {
    fwrite(STDERR, "Usage: php catalog/bin/verify-compact-metadata-files.php --file-ids=1,2,3 [--queue-repair]\n");
    exit(2);
}

$fileIds = [];
foreach (preg_split('/[\s,;]+/', $rawIds) ?: [] as $value) {
    $id = (int)$value;
    if ($id > 0) {
        $fileIds[$id] = $id;
    }
}
ksort($fileIds, SORT_NUMERIC);
$fileIds = array_values($fileIds);
if ($fileIds === []) {
    fwrite(STDERR, "No positive file IDs were supplied.\n");
    exit(2);
}
if (count($fileIds) > 1000) {
    fwrite(STDERR, "At most 1,000 file IDs may be checked at once.\n");
    exit(2);
}

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    fwrite(STDERR, "Catalog storage_path is not configured.\n");
    exit(2);
}
$queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
$queueRepair = array_key_exists('queue-repair', $options);
$reader = new BlockedCompressedMetadataReader($db, $storageRoot);
$queue = $queueRepair ? new PdoJobQueue($db) : null;

$rows = [];
$bad = 0;
$missing = 0;
$queued = 0;
foreach ($fileIds as $fileId) {
    $statement = $db->prepare(
        'SELECT id,game_id,package_name,original_name,scan_status FROM ue_files WHERE id=? LIMIT 1'
    );
    $statement->execute([$fileId]);
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) {
        $missing++;
        $rows[] = [
            'file_id' => $fileId,
            'status' => 'missing_file_row',
            'game_id' => 0,
            'package_name' => '',
            'original_name' => '',
            'error' => 'ue_files row does not exist.',
            'repair_job_id' => 0,
        ];
        continue;
    }
    if ((string)$file['scan_status'] !== 'verified') {
        $missing++;
        $rows[] = [
            'file_id' => $fileId,
            'status' => 'not_verified',
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'error' => 'File is not currently verified.',
            'repair_job_id' => 0,
        ];
        continue;
    }

    try {
        clearstatcache();
        $verified = $reader->verify($fileId);
        $rows[] = [
            'file_id' => $fileId,
            'status' => 'valid',
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'compressed_size' => (int)($verified['compressed_size'] ?? 0),
            'block_count' => (int)($verified['block_count'] ?? 0),
            'error' => '',
            'repair_job_id' => 0,
        ];
    } catch (Throwable $error) {
        $bad++;
        $repairJobId = 0;
        if ($queue instanceof PdoJobQueue) {
            $repairJobId = $queue->enqueue(
                $queueName,
                JobType::REPAIR_COMPACT_METADATA_FILE,
                [
                    'file_id' => $fileId,
                    'game_id' => (int)$file['game_id'],
                    'requested_by' => null,
                    'source_relative_path' => 'Compact metadata repair · ' . (string)$file['original_name'],
                ],
                15,
                null,
                'compact-metadata-repair:' . $fileId,
                null,
                5
            );
            $queued++;
        }
        $rows[] = [
            'file_id' => $fileId,
            'status' => $queueRepair ? 'repair_queued' : 'invalid',
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'error' => $error->getMessage(),
            'repair_job_id' => $repairJobId,
        ];
    }
}

$result = [
    'ok' => $bad === 0 && $missing === 0,
    'checked' => count($fileIds),
    'valid' => count($fileIds) - $bad - $missing,
    'invalid' => $bad,
    'missing_or_not_verified' => $missing,
    'repair_jobs_queued' => $queued,
    'queue' => $queueName,
    'rows' => $rows,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($bad === 0 && $missing === 0) || $queueRepair ? 0 : 3);
