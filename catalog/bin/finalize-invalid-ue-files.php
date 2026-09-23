#!/usr/bin/env php
<?php
/** Finalize already-marked invalid UE identities by removing their verified bytes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
require_once dirname(__DIR__) . '/lib/CatalogFileMaintenanceCompactCore.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;

try {
    $options = getopt('', ['apply', 'file-ids:']);
    $apply = array_key_exists('apply', $options);
    $config = catalog_config();
    $db = catalog_db($config);
    $support = new CatalogFileMaintenanceSupport($db, $config);
    $requestedIds = [];
    foreach (preg_split('/[\\s,]+/', trim((string)($options['file-ids'] ?? ''))) ?: [] as $value) {
        $id = (int)$value;
        if ($id > 0) { $requestedIds[$id] = true; }
    }
    $whereIds = $requestedIds !== []
        ? ' AND f.id IN (' . implode(',', array_fill(0, count($requestedIds), '?')) . ')'
        : '';
    $rows = catalog_all(
        $db,
        'SELECT DISTINCT f.* FROM ue_files f JOIN ue_invalid_file_identities bad '
        . 'ON bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1) '
        . $whereIds . ' ORDER BY f.id',
        array_keys($requestedIds)
    );
    $finalized = 0; $files = [];
    foreach ($rows as $file) {
        $fileId = (int)$file['id'];
        $storedPath = CatalogFileMaintenanceSupport::storagePath($config, $file);
        $metadataPath = CatalogFileMaintenanceSupport::metadataPath($config, (int)$file['game_id'], $fileId);
        $files[] = [
            'file_id' => $fileId,
            'name' => (string)$file['original_name'],
            'scan_status' => (string)$file['scan_status'],
            'stored_file_exists' => $storedPath !== null && is_file($storedPath),
            'metadata_exists' => is_file($metadataPath),
        ];
        if (!$apply) continue;
        $support->deleteFileProjections($fileId);
        if ($storedPath !== null && is_file($storedPath) && !@unlink($storedPath)) {
            throw new RuntimeException('Could not remove invalid package #' . $fileId . ' from verified storage.');
        }
        if (is_file($metadataPath) && !@unlink($metadataPath)) {
            throw new RuntimeException('Could not remove compact metadata for invalid package #' . $fileId . '.');
        }
        $db->prepare(
            'UPDATE ue_files SET scan_status="failed",scan_notes=CASE '
            . 'WHEN scan_notes LIKE "invalid_ue_file:%" THEN scan_notes '
            . 'ELSE CONCAT("invalid_ue_file: ",COALESCE(NULLIF(scan_notes,""),"Confirmed invalid Unreal package.")) END '
            . 'WHERE id=?'
        )->execute([$fileId]);
        $finalized++;
    }
    fwrite(STDOUT, json_encode([
        'ok' => true, 'dry_run' => !$apply, 'selected' => count($rows),
        'finalized' => $finalized, 'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}
