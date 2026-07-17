<?php
declare(strict_types=1);

/**
 * Queue-producing upload routes historically wrote valid Unreal packages to the
 * filesystem before an unverified database row existed. Record files created by
 * the current request at shutdown so every new queue item is immediately usable
 * by the database-backed review tools.
 */
function catalog_unverified_auto_index_enabled(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return false;
    }

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($script, [
        'profiled-upload.php',
        'upload-bucket.php',
        'pak-import.php',
        'source-scan.php',
        'http-source-scan.php',
        'upload-to-parent.php',
    ], true);
}

function catalog_unverified_register_auto_index(): void
{
    if (!catalog_unverified_auto_index_enabled()) {
        return;
    }

    $requestStartedAt = (int)floor((float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    register_shutdown_function(static function () use ($requestStartedAt): void {
        try {
            if (!catalog_support_is_admin()) {
                return;
            }

            require_once __DIR__ . '/UnverifiedFileManager.php';
            require_once __DIR__ . '/CatalogUnverifiedIndex.php';

            $config = catalog_config();
            $db = catalog_db($config);
            catalog_unverified_schema_ensure($db);
            $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

            foreach (uvf_list($db, $config, null) as $item) {
                if ((int)($item['modified_at'] ?? 0) < $requestStartedAt - 3) {
                    continue;
                }
                if (catalog_unverified_find($db, (int)$item['game']['id'], (string)$item['queue_name'])) {
                    continue;
                }

                try {
                    catalog_unverified_index_item($db, $config, $item, $userId, false);
                } catch (Throwable $error) {
                    error_log(
                        '[UnrealDB unverified auto-index] '
                        . (string)($item['original_name'] ?? $item['queue_name'] ?? 'queued file')
                        . ': ' . $error->getMessage()
                    );
                }
            }
        } catch (Throwable $error) {
            error_log('[UnrealDB unverified auto-index] shutdown failed: ' . $error->getMessage());
        }
    });
}

catalog_unverified_register_auto_index();
