#!/usr/bin/env php
<?php
/**
 * Mark existing catalog files as confirmed-invalid Unreal package bytes.
 *
 * Dry-run by default. --apply persists the byte identity, removes provider
 * export/dependency projections for the invalid file, and refreshes files that
 * were resolved against it. The ue_files row and physical package are retained
 * so provenance and exact-byte rejection remain available.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
require_once dirname(__DIR__) . '/lib/CatalogFileMaintenanceCompactCore.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;

try {
    $options = getopt('', ['file-ids:', 'apply', 'reason::']);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', preg_split('/[\s,]+/', trim((string)($options['file-ids'] ?? ''))) ?: []),
        static fn(int $id): bool => $id > 0
    )));
    if ($ids === []) {
        throw new InvalidArgumentException('Provide --file-ids=1,2,3.');
    }
    $apply = array_key_exists('apply', $options);
    $reason = trim((string)($options['reason'] ?? 'Confirmed invalid Unreal package.'));
    if ($reason === '') {
        $reason = 'Confirmed invalid Unreal package.';
    }
    $reason = substr($reason, 0, 500);

    $config = catalog_config();
    $db = catalog_db($config);
    $support = new CatalogFileMaintenanceSupport($db, $config);
    $rows = [];
    $missing = [];
    $marked = 0;
    $affected = [];

    foreach ($ids as $fileId) {
        $file = catalog_one(
            $db,
            'SELECT id,game_id,package_name,original_name,file_size,LOWER(md5) md5,LOWER(sha1) sha1,scan_status '
                . 'FROM ue_files WHERE id=?',
            [$fileId]
        );
        if (!$file) {
            $missing[] = $fileId;
            continue;
        }
        $md5 = strtolower(trim((string)($file['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($file['sha1'] ?? '')));
        $size = max(0, (int)($file['file_size'] ?? 0));
        if ($size < 1 || preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new RuntimeException('File #' . $fileId . ' does not have a complete size/MD5/SHA-1 identity.');
        }

        $rows[] = [
            'file_id' => $fileId,
            'name' => (string)($file['original_name'] ?? ''),
            'size' => $size,
            'md5' => $md5,
            'sha1' => $sha1,
            'scan_status' => (string)($file['scan_status'] ?? ''),
        ];
        if (!$apply) {
            continue;
        }

        // Invalid-file retirement must not depend on compact metadata belonging
        // to otherwise-good consumers. Record direct resolved consumers only;
        // a normal/full dependency rebuild can reconcile broader package-name
        // matches after the v3 cutover.
        $consumerRows = catalog_all(
            $db,
            'SELECT DISTINCT file_id FROM ue_dependency_links WHERE resolved_file_id=? AND file_id<>?',
            [$fileId, $fileId]
        );
        foreach ($consumerRows as $consumerRow) {
            $consumerId = (int)($consumerRow['file_id'] ?? 0);
            if ($consumerId > 0) {
                $affected[] = $consumerId;
            }
        }
        $statement = $db->prepare(
            'INSERT INTO ue_invalid_file_identities (file_size,md5,sha1,source_file_id,reason) VALUES (?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE source_file_id=VALUES(source_file_id),reason=VALUES(reason)'
        );
        $statement->execute([$size, $md5, $sha1, $fileId, $reason]);
        $storedPath = CatalogFileMaintenanceSupport::storagePath($config, $file);
        $metadataPath = CatalogFileMaintenanceSupport::metadataPath($config, (int)$file['game_id'], $fileId);
        $support->deleteFileProjections($fileId);
        if ($storedPath !== null && is_file($storedPath) && !@unlink($storedPath)) {
            throw new RuntimeException('Could not remove invalid package #' . $fileId . ' from verified storage.');
        }
        if (is_file($metadataPath) && !@unlink($metadataPath)) {
            throw new RuntimeException('Could not remove compact metadata for invalid package #' . $fileId . '.');
        }
        $db->prepare('UPDATE ue_files SET scan_status="failed",scan_notes=? WHERE id=?')
            ->execute(['invalid_ue_file: ' . $reason, $fileId]);
        $marked++;
    }

    $affected = array_values(array_unique(array_filter(array_map('intval', $affected), static fn(int $id): bool => $id > 0)));
    // Do not synchronously rebuild consumer metadata here. During a v2->v3
    // migration those otherwise-good verified consumers may not yet have active
    // supported metadata. Provider resolution already excludes invalid identities;
    // Full Sync / normal dependency refresh will rebuild consumers after cutover.

    fwrite(STDOUT, json_encode([
        'ok' => $missing === [],
        'dry_run' => !$apply,
        'selected' => count($rows),
        'marked' => $marked,
        'affected_dependencies_pending_refresh' => count($affected),
        'missing_file_ids' => $missing,
        'files' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($missing === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
