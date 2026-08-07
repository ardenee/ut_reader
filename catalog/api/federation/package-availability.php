<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for package availability.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/CatalogSupport.php';
require_once __DIR__ . '/../../lib/FederationAuth.php';
require_once __DIR__ . '/../../lib/BaseGameProtection.php';
require_once __DIR__ . '/../../lib/FederationBaseGamePolicy.php';
require_once __DIR__ . '/../../lib/FederationPackageAvailability.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);

    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);
    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may check parent package availability.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $items = $payload['items'] ?? [];
    if (!is_array($items) || !$items) {
        fed_json_response(['ok' => true, 'policy' => federation_parent_base_game_policy($db), 'items' => []]);
    }
    if (count($items) > 950) {
        fed_json_response(['ok' => false, 'error' => 'Availability checks are limited to 950 packages per request.'], 413);
    }

    $results = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = trim((string)($item['key'] ?? ''));
        $requiredPackage = trim((string)($item['required_package'] ?? ''));
        if ($key === '' || $requiredPackage === '') {
            continue;
        }
        $availability = federation_package_availability($db, $item);
        $results[] = ['key' => $key, 'required_package' => $requiredPackage] + $availability;
    }

    fed_json_response([
        'ok' => true,
        'policy' => federation_parent_base_game_policy($db),
        'items' => $results,
    ]);
} catch (Throwable $e) {
    fed_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
