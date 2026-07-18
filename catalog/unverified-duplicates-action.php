<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUnverifiedDuplicates.php';

function unverified_duplicates_reply(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    if (!catalog_support_is_admin()) {
        unverified_duplicates_reply(['ok' => false, 'error' => 'Administrator login is required.'], 403);
    }

    catalog_check_csrf('unverified-files');
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_unverified_schema_ensure($db);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $result = catalog_unverified_delete_duplicates($db, $config);
    unverified_duplicates_reply(['ok' => true] + $result);
} catch (Throwable $error) {
    error_log('[UnrealDB unverified duplicates] ' . $error->getMessage());
    unverified_duplicates_reply(['ok' => false, 'error' => $error->getMessage()], 400);
}
