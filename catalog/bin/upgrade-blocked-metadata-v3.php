#!/usr/bin/env php
<?php
/**
 * Reparse verified packages whose compact metadata predates format 3.
 *
 * This migration deliberately reparses the authoritative Unreal package. Format 2
 * discarded serialized FName/object-reference indexes, so those values cannot be
 * reconstructed safely from the old .uedb2 container.
 *
 * Examples:
 *   php catalog/bin/upgrade-blocked-metadata-v3.php
 *   php catalog/bin/upgrade-blocked-metadata-v3.php --apply --limit=500
 *   php catalog/bin/upgrade-blocked-metadata-v3.php --apply --after-id=120000 --limit=500
 *   php catalog/bin/upgrade-blocked-metadata-v3.php --apply --game-id=4 --all
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;

$options = getopt('', ['apply', 'all', 'limit:', 'after-id:', 'game-id:', 'file-ids:', 'stop-on-error']);
$apply = array_key_exists('apply', $options);
$all = array_key_exists('all', $options);
$limit = max(1, min(10000, (int)($options['limit'] ?? 500)));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$gameId = max(0, (int)($options['game-id'] ?? 0));
$stopOnError = array_key_exists('stop-on-error', $options);
$rawIds = trim((string)($options['file-ids'] ?? ''));

$config = catalog_config();
$db = catalog_db($config);
$targetVersion = BlockedCompressedMetadataContainer::FORMAT_VERSION;
if ($targetVersion !== 3) {
    fwrite(STDERR, "This migration requires compact metadata FORMAT_VERSION=3; current={$targetVersion}.\n");
    exit(2);
}

$where = ['f.scan_status="verified"', '(m.file_id IS NULL OR m.format_version<?)', 'f.id>?'];
$args = [$targetVersion, $afterId];
if ($gameId > 0) {
    $where[] = 'f.game_id=?';
    $args[] = $gameId;
}
if ($rawIds !== '') {
    $ids = [];
    foreach (preg_split('/[\\s,;]+/', $rawIds) ?: [] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        fwrite(STDERR, "No positive --file-ids were supplied.\n");
        exit(2);
    }
    $where[] = 'f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    array_push($args, ...array_values($ids));
}

$countSql = 'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id WHERE ' . implode(' AND ', $where);
$count = $db->prepare($countSql);
$count->execute($args);
$remaining = (int)$count->fetchColumn();

$sql = 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.md5,f.package_guid,COALESCE(m.format_version,0) format_version'
    . ' FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id'
    . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id';
if (!$all) {
    $sql .= ' LIMIT ' . $limit;
}
$statement = $db->prepare($sql);
$statement->execute($args);
$files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$apply) {
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'target_version' => $targetVersion,
        'remaining_matching' => $remaining,
        'selected' => count($files),
        'first_file_id' => isset($files[0]) ? (int)$files[0]['id'] : 0,
        'last_file_id' => $files !== [] ? (int)$files[array_key_last($files)]['id'] : 0,
        'next_command' => 'php catalog/bin/upgrade-blocked-metadata-v3.php --apply --limit=' . $limit
            . ($afterId > 0 ? ' --after-id=' . $afterId : '')
            . ($gameId > 0 ? ' --game-id=' . $gameId : ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    fwrite(STDERR, "catalog storage_path is not configured.\n");
    exit(2);
}

$started = microtime(true);
$completed = 0;
$failed = 0;
$lastCompletedId = $afterId;
$errors = [];

foreach ($files as $position => $file) {
    $fileId = (int)$file['id'];
    $label = (string)$file['original_name'];
    $ordinal = $position + 1;
    fwrite(STDOUT, "[{$ordinal}/" . count($files) . "] #{$fileId} {$label} ... ");

    try {
        $maintenance = new CatalogFileMaintenanceActionService($db, $config, null);
        $maintenance->execute('sync_reimport', [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'package_name' => (string)$file['package_name'],
            'md5' => (string)$file['md5'],
            'package_guid' => (string)($file['package_guid'] ?? ''),
        ]);

        $row = catalog_one($db, 'SELECT format_version FROM ue_file_metadata WHERE file_id=?', [$fileId]);
        if ((int)($row['format_version'] ?? 0) !== $targetVersion) {
            throw new RuntimeException('Reparse completed without publishing format-' . $targetVersion . ' metadata.');
        }
        $verified = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
        if (empty($verified['verified']) || (int)($verified['format_version'] ?? 0) !== $targetVersion) {
            throw new RuntimeException('Published format-' . $targetVersion . ' metadata did not verify.');
        }

        $completed++;
        $lastCompletedId = $fileId;
        fwrite(STDOUT, "v{$targetVersion} OK\n");
    } catch (Throwable $error) {
        $failed++;
        $errors[] = ['file_id' => $fileId, 'file' => $label, 'error' => $error->getMessage()];
        fwrite(STDOUT, "FAILED: " . $error->getMessage() . "\n");
        if ($stopOnError) {
            break;
        }
    }
}

$elapsed = max(0.001, microtime(true) - $started);
$remainingStatement = $db->prepare(
    'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id'
    . ' WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<?)'
);
$remainingStatement->execute([$targetVersion]);
$remainingAll = (int)$remainingStatement->fetchColumn();

echo json_encode([
    'ok' => $failed === 0,
    'target_version' => $targetVersion,
    'selected' => count($files),
    'completed' => $completed,
    'failed' => $failed,
    'elapsed_seconds' => round($elapsed, 2),
    'files_per_second' => round($completed / $elapsed, 3),
    'last_completed_id' => $lastCompletedId,
    'remaining_all_games' => $remainingAll,
    'resume_command' => $remainingAll > 0
        ? 'php catalog/bin/upgrade-blocked-metadata-v3.php --apply --after-id=' . ($failed > 0 ? $afterId : $lastCompletedId) . ' --limit=' . $limit
        : null,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === 0 ? 0 : 3);
