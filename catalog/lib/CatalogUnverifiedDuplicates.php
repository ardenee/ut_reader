<?php
declare(strict_types=1);

require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUnverifiedIndex.php';

/** @return list<array<string,mixed>> */
function catalog_unverified_duplicate_queues(PDO $db): array
{
    $queues = [uvf_bucket_game()];
    foreach (catalog_all($db, 'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name') as $game) {
        $queues[] = $game;
    }
    return $queues;
}

/**
 * Inventory all physical unverified queues and identify exact duplicate groups.
 * Files are grouped by size first, so MD5 is calculated only for size collisions.
 *
 * @return array{physical_files:int,hashed_files:int,groups:list<array<string,mixed>>,duplicate_groups:int,duplicate_files:int,duplicate_bytes:int}
 */
function catalog_unverified_duplicate_scan(PDO $db, array $config): array
{
    catalog_unverified_schema_ensure($db);

    $indexedKeys = [];
    foreach (catalog_all($db, 'SELECT unverified_queue_key FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key IS NOT NULL') as $row) {
        $key = trim((string)($row['unverified_queue_key'] ?? ''));
        if ($key !== '') {
            $indexedKeys[$key] = true;
        }
    }

    $bySize = [];
    $physicalFiles = 0;
    foreach (catalog_unverified_duplicate_queues($db) as $queue) {
        $queueGameId = (int)($queue['id'] ?? 0);
        $dir = uvf_unverified_dir($config, $queue, false);
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || str_starts_with($entry, '.')
                || str_ends_with(strtolower($entry), '.txt')
            ) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || is_link($path) || !uvf_path_inside($path, $dir)) {
                continue;
            }

            $size = (int)(filesize($path) ?: 0);
            $queueKey = catalog_unverified_queue_key($queueGameId, $entry);
            $item = [
                'queue_game_id' => $queueGameId,
                'queue_name' => $entry,
                'queue_name_label' => (string)($queue['name'] ?? 'Upload Bucket'),
                'queue_key' => $queueKey,
                'original_name' => uvf_original_name_from_queue_name($entry),
                'path' => $path,
                'reason_path' => $path . '.txt',
                'size' => $size,
                'modified_at' => (int)(filemtime($path) ?: 0),
                'indexed' => isset($indexedKeys[$queueKey]),
            ];
            $bySize[(string)$size][] = $item;
            $physicalFiles++;
        }
    }

    $hashedFiles = 0;
    $byIdentity = [];
    foreach ($bySize as $sameSize) {
        if (count($sameSize) < 2) {
            continue;
        }
        foreach ($sameSize as $item) {
            $md5 = @md5_file((string)$item['path']);
            if (!is_string($md5) || preg_match('/^[a-f0-9]{32}$/i', $md5) !== 1) {
                continue;
            }
            $hashedFiles++;
            $item['md5'] = strtolower($md5);
            $identity = (string)$item['size'] . ':' . strtolower($md5);
            $byIdentity[$identity][] = $item;
        }
    }

    $groups = [];
    $duplicateFiles = 0;
    $duplicateBytes = 0;
    foreach ($byIdentity as $identity => $sameIdentity) {
        if (count($sameIdentity) < 2) {
            continue;
        }

        usort($sameIdentity, static function (array $left, array $right): int {
            return ((int)!empty($right['indexed']) <=> (int)!empty($left['indexed']))
                ?: ((int)$left['modified_at'] <=> (int)$right['modified_at'])
                ?: ((int)$left['queue_game_id'] <=> (int)$right['queue_game_id'])
                ?: strcmp((string)$left['queue_name'], (string)$right['queue_name']);
        });

        $keeper = array_shift($sameIdentity);
        $size = (int)($keeper['size'] ?? 0);
        $duplicates = array_values($sameIdentity);
        $duplicateFiles += count($duplicates);
        $duplicateBytes += $size * count($duplicates);
        $groups[] = [
            'identity' => $identity,
            'size' => $size,
            'md5' => (string)($keeper['md5'] ?? ''),
            'keeper' => $keeper,
            'duplicates' => $duplicates,
        ];
    }

    usort($groups, static function (array $left, array $right): int {
        return strcasecmp(
            (string)($left['keeper']['original_name'] ?? ''),
            (string)($right['keeper']['original_name'] ?? '')
        );
    });

    return [
        'physical_files' => $physicalFiles,
        'hashed_files' => $hashedFiles,
        'groups' => $groups,
        'duplicate_groups' => count($groups),
        'duplicate_files' => $duplicateFiles,
        'duplicate_bytes' => $duplicateBytes,
    ];
}

/**
 * Delete all duplicate physical queue files while retaining one copy per size+MD5 group.
 * An indexed copy is retained when available; otherwise the oldest queue copy is retained.
 *
 * @return array<string,mixed>
 */
function catalog_unverified_delete_duplicates(PDO $db, array $config): array
{
    $scan = catalog_unverified_duplicate_scan($db, $config);
    $deleted = [];
    $errors = [];
    $deletedCount = 0;
    $deletedBytes = 0;

    foreach ($scan['groups'] as $group) {
        $expectedSize = (int)$group['size'];
        $expectedMd5 = strtolower((string)$group['md5']);
        $keeper = (array)$group['keeper'];

        foreach ((array)$group['duplicates'] as $duplicate) {
            $path = (string)$duplicate['path'];
            $label = (string)$duplicate['original_name'];
            if (!is_file($path)) {
                $errors[] = $label . ': file disappeared before it could be deleted.';
                continue;
            }

            $currentSize = (int)(filesize($path) ?: 0);
            $currentMd5 = @md5_file($path);
            if ($currentSize !== $expectedSize || !is_string($currentMd5) || strtolower($currentMd5) !== $expectedMd5) {
                $errors[] = $label . ': file changed during duplicate checking and was not deleted.';
                continue;
            }

            if (!@unlink($path)) {
                $errors[] = $label . ': could not delete the duplicate queue file.';
                continue;
            }

            $reasonPath = (string)$duplicate['reason_path'];
            if (is_file($reasonPath) && !@unlink($reasonPath)) {
                $errors[] = $label . ': duplicate file was deleted, but its queue-note file could not be removed.';
            }

            try {
                catalog_unverified_delete_database_row(
                    $db,
                    (int)$duplicate['queue_game_id'],
                    (string)$duplicate['queue_name']
                );
            } catch (Throwable $error) {
                $errors[] = $label . ': physical duplicate was deleted, but its unverified database row could not be removed: ' . $error->getMessage();
            }

            $deletedCount++;
            $deletedBytes += $expectedSize;
            if (count($deleted) < 100) {
                $deleted[] = [
                    'name' => $label,
                    'queue' => (string)$duplicate['queue_name_label'],
                    'kept_name' => (string)($keeper['original_name'] ?? ''),
                    'kept_queue' => (string)($keeper['queue_name_label'] ?? ''),
                    'size' => $expectedSize,
                    'md5' => $expectedMd5,
                ];
            }
        }
    }

    return [
        'physical_files' => (int)$scan['physical_files'],
        'hashed_files' => (int)$scan['hashed_files'],
        'duplicate_groups' => (int)$scan['duplicate_groups'],
        'duplicate_files_found' => (int)$scan['duplicate_files'],
        'deleted_files' => $deletedCount,
        'deleted_bytes' => $deletedBytes,
        'deleted_bytes_text' => catalog_bytes($deletedBytes),
        'deleted' => $deleted,
        'deleted_list_truncated' => $deletedCount > count($deleted),
        'errors' => $errors,
    ];
}
