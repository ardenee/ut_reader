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

        $affected = array_merge($affected, $support->affectedIds(
            (int)$file['game_id'],
            $fileId,
            (string)$file['package_name']
        ));
        $statement = $db->prepare(
            'INSERT INTO ue_invalid_file_identities (file_size,md5,sha1,source_file_id,reason) VALUES (?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE source_file_id=VALUES(source_file_id),reason=VALUES(reason)'
        );
        $statement->execute([$size, $md5, $sha1, $fileId, $reason]);
        $support->deleteFileProjections($fileId);
        $marked++;
    }

    $affected = array_values(array_unique(array_filter(array_map('intval', $affected), static fn(int $id): bool => $id > 0)));
    if ($apply && $affected !== []) {
        $support->refreshIds($affected, null, 0, 100, 'Refreshing dependencies after invalid UE exclusion');
    }

    fwrite(STDOUT, json_encode([
        'ok' => $missing === [],
        'dry_run' => !$apply,
        'selected' => count($rows),
        'marked' => $marked,
        'affected_dependencies_refreshed' => count($affected),
        'missing_file_ids' => $missing,
        'files' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($missing === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
