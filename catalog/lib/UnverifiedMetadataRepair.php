<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUnverifiedIndex.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Lightweight inventory used to find only physical unverified files whose
 * database identity or package-table inventory is incomplete. No file content
 * is read while building this list.
 *
 * sourceGameId follows unverified-files.php semantics:
 *   0  = all queues
 *  -1  = Upload Bucket only
 *  >0  = one game's unverified queue
 *
 * @return list<array<string,mixed>>
 */
function catalog_unverified_metadata_inventory(PDO $db, array $config, int $sourceGameId = -1): array
{
    catalog_unverified_schema_ensure($db);

    $games = [];
    if ($sourceGameId === 0 || $sourceGameId === -1) {
        $games[] = uvf_bucket_game();
    }
    if ($sourceGameId !== -1) {
        $sql = 'SELECT id,name,slug,profile_id FROM ue_games';
        $args = [];
        if ($sourceGameId > 0) {
            $sql .= ' WHERE id=?';
            $args[] = $sourceGameId;
        }
        $sql .= ' ORDER BY name';
        foreach (catalog_all($db, $sql, $args) as $game) {
            $games[] = $game;
        }
    }

    $rowSql = 'SELECT f.*,'
        . ' (SELECT COUNT(*) FROM ue_names n WHERE n.file_id=f.id) actual_name_count,'
        . ' (SELECT COUNT(*) FROM ue_imports i WHERE i.file_id=f.id) actual_import_count,'
        . ' (SELECT COUNT(*) FROM ue_exports e WHERE e.file_id=f.id) actual_export_count'
        . ' FROM ue_files f WHERE f.scan_status="unverified"';
    $rowArgs = [];
    if ($sourceGameId === -1) {
        $rowSql .= ' AND f.unverified_queue_game_id=0';
    } elseif ($sourceGameId > 0) {
        $rowSql .= ' AND f.unverified_queue_game_id=?';
        $rowArgs[] = $sourceGameId;
    }

    $rowsByKey = [];
    foreach (catalog_all($db, $rowSql, $rowArgs) as $row) {
        $key = trim((string)($row['unverified_queue_key'] ?? ''));
        if ($key !== '') {
            $rowsByKey[$key] = $row;
        }
    }

    $items = [];
    foreach ($games as $game) {
        $gameId = (int)($game['id'] ?? 0);
        $directory = uvf_unverified_dir($config, $game, false);
        if (!is_dir($directory) || !is_readable($directory)) {
            continue;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_ends_with(strtolower($entry), '.txt')) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || is_link($path) || !uvf_path_inside($path, $directory)) {
                continue;
            }

            $size = (int)(filesize($path) ?: 0);
            $key = catalog_unverified_queue_key($gameId, $entry);
            $row = $rowsByKey[$key] ?? null;
            $reasons = catalog_unverified_metadata_missing_reasons($row, $size);
            $items[] = [
                'token' => uvf_token($gameId, $entry),
                'queue_game_id' => $gameId,
                'queue_name' => $entry,
                'queue_key' => $key,
                'queue_label' => (string)($game['name'] ?? ($gameId === 0 ? 'Upload Bucket' : 'Unknown queue')),
                'original_name' => $row
                    ? (string)($row['original_name'] ?? uvf_original_name_from_queue_name($entry))
                    : uvf_original_name_from_queue_name($entry),
                'path' => $path,
                'size' => $size,
                'file_id' => $row ? (int)$row['id'] : 0,
                'row' => $row,
                'missing_reasons' => $reasons,
                'needs_repair' => $reasons !== [],
            ];
        }
    }

    usort($items, static function (array $left, array $right): int {
        return strcasecmp((string)$left['original_name'], (string)$right['original_name']);
    });
    return $items;
}

/** @return list<string> */
function catalog_unverified_metadata_missing_reasons(?array $row, int $physicalSize): array
{
    if ($row === null) {
        return ['Missing database inventory row'];
    }

    $reasons = [];
    $md5 = strtolower(trim((string)($row['md5'] ?? '')));
    $sha1 = strtolower(trim((string)($row['sha1'] ?? '')));
    $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? '')));
    $version = $row['detected_package_version'] ?? null;
    $notes = (string)($row['scan_notes'] ?? '');
    $alreadyAttempted = str_contains($notes, 'Metadata repair attempted:');

    if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
        $reasons[] = 'MD5 is missing';
    }
    if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
        $reasons[] = 'SHA-1 is missing';
    }
    if ($physicalSize < 1 || (int)($row['file_size'] ?? 0) !== $physicalSize) {
        $reasons[] = 'Stored size does not match the physical file';
    }
    if (trim((string)($row['package_name'] ?? '')) === '') {
        $reasons[] = 'Package name is missing';
    }
    if (trim((string)($row['extension'] ?? '')) === '') {
        $reasons[] = 'File extension is missing';
    }

    // Retry unknown engine/version once with the current reader code. If the
    // package remains unreadable, retain that recorded result rather than
    // endlessly adding the same file back to the repair queue.
    if (!$alreadyAttempted) {
        if ($engine === '' || $engine === 'UNKNOWN') {
            $reasons[] = 'Detected engine is missing';
        }
        if ($version === null || $version === '') {
            $reasons[] = 'Detected package version is missing';
        }
        if (in_array($engine, ['UE1', 'UE2', 'UE3'], true)
            && is_numeric($version)
            && (int)$version >= 68
            && trim((string)($row['package_guid'] ?? '')) === '') {
            $reasons[] = 'Package GUID is missing';
        }
    }

    $actualNameCount = (int)($row['actual_name_count'] ?? 0);
    $actualImportCount = (int)($row['actual_import_count'] ?? 0);
    $actualExportCount = (int)($row['actual_export_count'] ?? 0);
    foreach (['name', 'import', 'export'] as $table) {
        $declared = (int)($row[$table . '_count'] ?? 0);
        $actual = (int)($row['actual_' . $table . '_count'] ?? 0);
        if ($declared !== $actual) {
            $reasons[] = ucfirst($table) . ' count does not match stored rows';
        }
    }
    if (!$alreadyAttempted
        && in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)
        && $actualNameCount === 0
        && $actualImportCount === 0
        && $actualExportCount === 0) {
        $reasons[] = 'Package table inventory is empty';
    }

    return array_values(array_unique($reasons));
}

/**
 * @return array{scope_count:int,candidate_count:int,job_ids:list<int>,queue:string}
 */
function catalog_queue_unverified_metadata_repairs(
    PDO $db,
    array $config,
    int $sourceGameId,
    ?int $createdBy = null
): array {
    $items = catalog_unverified_metadata_inventory($db, $config, $sourceGameId);
    $queueName = (new CatalogBucketBatchQueue($db, $config))->queueName();
    $queue = new PdoJobQueue($db);
    $jobIds = [];

    foreach ($items as $item) {
        if (empty($item['needs_repair'])) {
            continue;
        }
        $dedupeKey = 'unverified-metadata:' . substr(hash(
            'sha256',
            (int)$item['queue_game_id'] . "\0" . (string)$item['queue_name']
        ), 0, 48);
        $jobId = $queue->enqueue(
            $queueName,
            JobType::REPAIR_UNVERIFIED_METADATA,
            [
                'queue_game_id' => (int)$item['queue_game_id'],
                'queue_name' => (string)$item['queue_name'],
                'original_name' => (string)$item['original_name'],
                'expected_size' => (int)$item['size'],
                'missing_reasons' => array_values((array)$item['missing_reasons']),
                'requested_by' => $createdBy,
            ],
            7,
            null,
            $dedupeKey,
            $createdBy,
            3
        );
        $jobIds[$jobId] = $jobId;
    }

    return [
        'scope_count' => count($items),
        'candidate_count' => count($jobIds),
        'job_ids' => array_values($jobIds),
        'queue' => $queueName,
    ];
}
