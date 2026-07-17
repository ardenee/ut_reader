<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = $id === false || $id === null ? 0 : max(0, (int)$id);
if ($id > 0) {
    try {
        $db = catalog_db(catalog_config());
        $row = catalog_one($db, 'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1', [$id]);
        if ($row && (string)$row['scan_status'] === 'unverified') {
            header('Location: unverified-file-details.php?id=' . $id, true, 302);
            exit;
        }
    } catch (Throwable $error) {
        error_log('[UnrealDB file examiner routing] ' . $error->getMessage());
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
require __DIR__ . '/file-examine-core.php';
