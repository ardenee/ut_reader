<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    $application->db->query('SELECT 1')->fetchColumn();

    JsonResponse::data([
        'status' => 'ok',
        'service' => 'unrealdb-catalog',
        'time' => gmdate('c'),
    ]);
} catch (Throwable $exception) {
    error_log('[UnrealDB health] ' . $exception->getMessage());
    JsonResponse::error('unavailable', 'Catalog service is temporarily unavailable.', 503);
}
