<?php
/**
 * Resolve catalog file IDs through the same authoritative storage-path helper
 * used by maintenance/v3 staging and report database versus physical size.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$catalogRoot = dirname(__DIR__);
require_once $catalogRoot . '/lib/CatalogSupport.php';
require_once $catalogRoot . '/lib/CatalogFileMaintenance.php';

$config = catalog_config();
$db = catalog_db($config);
$ids = [];

foreach (array_slice($argv, 1) as $arg) {
    foreach (preg_split('/[\\s,;]+/', $arg) ?: [] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
}

if ($ids === []) {
    fwrite(STDERR, "Usage: php catalog/bin/check-file-size.php 137603,137604\n");
    exit(1);
}

$stmt = $db->prepare('SELECT * FROM ue_files WHERE id = ? LIMIT 1');

foreach ($ids as $id) {
    echo PHP_EOL . "============================================================\n";
    echo "File ID: {$id}\n";
    $stmt->execute([$id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        echo "Database row: NOT FOUND\n";
        continue;
    }

    echo "Original name: " . (string)($file['original_name'] ?? '') . "\n";
    echo "Package name:  " . (string)($file['package_name'] ?? '') . "\n";
    echo "Game ID:       " . (string)($file['game_id'] ?? '') . "\n";
    echo "Catalog MD5:   " . (string)($file['md5'] ?? '') . "\n";

    $path = catalog_file_maintenance_storage_path($config, $file);
    if ($path === null || $path === '') {
        echo "Resolved path:  NONE\n";
        continue;
    }

    echo "Resolved path:  {$path}\n";
    echo "Exists:         " . (is_file($path) ? 'YES' : 'NO') . "\n";
    if (!is_file($path)) {
        continue;
    }

    clearstatcache(true, $path);
    $bytes = filesize($path);
    if ($bytes === false) {
        echo "File size:      ERROR\n";
        continue;
    }

    echo "Bytes:          " . number_format($bytes) . "\n";
    echo "MiB:            " . number_format($bytes / 1048576, 2) . "\n";
    echo "GiB:            " . number_format($bytes / 1073741824, 3) . "\n";

    foreach (['file_size', 'size', 'size_bytes'] as $column) {
        if (array_key_exists($column, $file) && $file[$column] !== null) {
            $dbSize = (int)$file[$column];
            echo "DB {$column}: " . number_format($dbSize) . " bytes\n";
            echo "DB/physical:    " . ($dbSize === $bytes ? 'MATCH' : 'MISMATCH') . "\n";
            break;
        }
    }
}
