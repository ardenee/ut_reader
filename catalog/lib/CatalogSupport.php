<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupportCore.php';
require_once __DIR__ . '/CatalogUnverifiedAutoIndex.php';

// Keep the normal file-info.php URL usable for database-staged files.
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'file-info.php') {
    $stagedFileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $stagedFileId = $stagedFileId === false || $stagedFileId === null ? 0 : (int)$stagedFileId;
    if ($stagedFileId > 0) {
        try {
            $stagedDb = catalog_db(catalog_config());
            $stagedRow = catalog_one($stagedDb, 'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1', [$stagedFileId]);
            if ($stagedRow && (string)$stagedRow['scan_status'] === 'unverified') {
                header('Location: unverified-file-details.php?id=' . $stagedFileId, true, 302);
                exit;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB file info routing] ' . $error->getMessage());
        }
    }
}
