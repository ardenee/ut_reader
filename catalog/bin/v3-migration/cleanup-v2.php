#!/usr/bin/env php
<?php
/** Remove cold .uedb2 rollback files after a successful v3-only cutover. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/lib/CatalogSupport.php';
require_once __DIR__ . '/MetadataContainerV3.php';

use UnrealDb\Catalog\MigrationV3\MetadataContainerV3;

$options = getopt('', ['apply', 'confirm-delete-v2']);
$apply = array_key_exists('apply', $options);
if ($apply && !array_key_exists('confirm-delete-v2', $options)) {
    fwrite(STDERR, "--apply requires --confirm-delete-v2.\n");
    exit(2);
}
$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') {
    throw new RuntimeException('catalog storage_path is not configured.');
}
$running = (int)$db->query('SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"')->fetchColumn();
$notV3 = (int)$db->query(
    'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
    . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>3)'
)->fetchColumn();
if ($running > 0 || $notV3 > 0) {
    fwrite(STDERR, "Cleanup refused: running_jobs={$running}, verified_not_v3={$notV3}.\n");
    exit(3);
}

$statement = $db->query('SELECT id,game_id FROM ue_files WHERE scan_status="verified" ORDER BY id');
$found = 0;
$verifiedV3 = 0;
$deleted = 0;
$bytes = 0;
$errors = [];
while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
    $fileId = (int)$row['id'];
    $gameId = (int)$row['game_id'];
    $v3 = MetadataContainerV3::path($storageRoot, $gameId, $fileId);
    try {
        MetadataContainerV3::verifyFile($v3, $fileId);
        $verifiedV3++;
    } catch (Throwable $error) {
        $errors[] = ['file_id' => $fileId, 'error' => 'v3 verification failed: ' . $error->getMessage()];
        continue;
    }
    $v2 = preg_replace('/\\.uedb3$/', '.uedb2', $v3);
    if (!is_string($v2) || !is_file($v2)) {
        continue;
    }
    $found++;
    $size = @filesize($v2);
    $bytes += $size === false ? 0 : (int)$size;
    if ($apply) {
        if (!@unlink($v2)) {
            $errors[] = ['file_id' => $fileId, 'error' => 'could not delete ' . $v2];
            continue;
        }
        $deleted++;
    }
}
$result = [
    'ok' => $errors === [],
    'dry_run' => !$apply,
    'v3_verified' => $verifiedV3,
    'v2_files_found' => $found,
    'v2_bytes_found' => $bytes,
    'v2_files_deleted' => $deleted,
    'errors' => $errors,
];
if (!$apply && $errors === []) {
    $result['next_command'] = 'php catalog/bin/v3-migration/cleanup-v2.php --apply --confirm-delete-v2';
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 4);
