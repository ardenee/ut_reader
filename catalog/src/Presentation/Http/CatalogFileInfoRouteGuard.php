<?php
/**
 * Routes unverified file detail requests to the unverified-file presentation.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Http;

use Throwable;

final class CatalogFileInfoRouteGuard
{
    public static function register(): void
    {
        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'file-info.php') {
            return;
        }

        $fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $fileId = $fileId === false || $fileId === null ? 0 : (int)$fileId;
        if ($fileId < 1) {
            return;
        }

        try {
            $db = \catalog_db(\catalog_config());
            $row = \catalog_one(
                $db,
                'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1',
                [$fileId]
            );
            if ($row && (string)$row['scan_status'] === 'unverified') {
                header('Location: unverified-file-details.php?id=' . $fileId, true, 302);
                exit;
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB file info routing] ' . $error->getMessage());
        }
    }
}
