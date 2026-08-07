<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for file PAK sources.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = (int)($_GET['id'] ?? 0);
    if ($fileId < 1) {
        throw new InvalidArgumentException('A valid file ID is required.');
    }

    $file = catalog_one($db, 'SELECT id,game_id FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        throw new RuntimeException('File not found.');
    }

    $paks = [];
    if (CatalogPakArchiveStore::schemaInstalled($db)) {
        $rows = catalog_all(
            $db,
            'SELECT p.id,p.original_name,p.file_size,p.md5,p.sha256,p.pak_version,p.mount_point,p.status,'
            . 'e.id entry_id,e.entry_index,e.entry_path,e.import_status '
            . 'FROM ue_pak_entries e JOIN ue_pak_archives p ON p.id=e.pak_id '
            . 'WHERE e.file_id=? ORDER BY p.original_name,e.entry_index',
            [$fileId]
        );
        foreach ($rows as $row) {
            $paks[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['original_name'],
                'size' => (int)$row['file_size'],
                'size_text' => catalog_bytes((int)$row['file_size']),
                'md5' => (string)$row['md5'],
                'sha256' => (string)$row['sha256'],
                'version' => (int)$row['pak_version'],
                'mount_point' => (string)$row['mount_point'],
                'status' => (string)$row['status'],
                'entry_id' => (int)$row['entry_id'],
                'entry_index' => (int)$row['entry_index'],
                'entry_path' => (string)$row['entry_path'],
                'import_status' => (string)$row['import_status'],
            ];
        }
    }

    echo json_encode(['ok' => true, 'file_id' => $fileId, 'paks' => $paks], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 404);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
