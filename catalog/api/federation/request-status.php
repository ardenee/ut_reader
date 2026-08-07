<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the federation HTTP endpoint for request status.
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
require_once __DIR__ . '/../../lib/FederationRequestLifecycle.php';

use UnrealDb\Catalog\Application\Federation\CatalogFederationHistoryPageService;

/** @return list<array<string,mixed>> */
function request_status_items(PDO $db, int $requestId, bool $ignoreBaseGame): array
{
    $rows = catalog_all(
        $db,
        'SELECT i.*, f.package_name local_package, f.original_name local_file,
                f.file_size, f.md5, f.sha1, f.package_guid, f.game_id,
                CASE WHEN bg.id IS NOT NULL THEN 1 ELSE 0 END is_base_game
         FROM ue_federation_request_items i
         LEFT JOIN ue_files f ON f.id=i.local_file_id
         LEFT JOIN ue_base_game_files bg ON bg.game_id=f.game_id AND bg.package_guid=f.package_guid
         WHERE i.request_id=?
         ORDER BY i.updated_at DESC, i.id DESC',
        [$requestId]
    );

    $items = [];
    foreach ($rows as $row) {
        $isBaseGame = !empty($row['is_base_game']);
        if (!$isBaseGame && str_contains(strtolower((string)($row['status_message'] ?? '')), 'base-game')) {
            $isBaseGame = true;
        }
        if (!$isBaseGame && empty($row['local_file_id'])) {
            $isBaseGame = federation_base_game_package_match($db, (string)$row['required_package']) !== null;
        }
        if ($ignoreBaseGame && $isBaseGame) {
            continue;
        }

        $items[] = [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'required_package' => (string)$row['required_package'],
            'required_object_path' => (string)$row['required_object_path'],
            'local_file_id' => $row['local_file_id'] !== null ? (int)$row['local_file_id'] : null,
            'package_name' => (string)($row['local_package'] ?? ''),
            'original_name' => (string)($row['local_file'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
            'md5' => (string)($row['md5'] ?? ''),
            'sha1' => (string)($row['sha1'] ?? ''),
            'package_guid' => (string)($row['package_guid'] ?? ''),
            'is_base_game' => $isBaseGame,
            'dependency_exception' => false,
            'status_message' => (string)($row['status_message'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }
    return $items;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    base_game_ensure($db);
    $body = file_get_contents('php://input') ?: '';
    $peer = fed_require_signed_peer($db, $body);
    if ((string)$peer['peer_role'] !== 'child') {
        fed_json_response(['ok' => false, 'error' => 'Only a paired child may poll request status.'], 403);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        fed_json_response(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }
    $ignoreBaseGame = federation_ignore_base_game_files($db);

    if (!empty($payload['package_statuses'])) {
        $requestRows = catalog_all(
            $db,
            'SELECT * FROM ue_federation_requests
             WHERE peer_id=? AND direction="child_to_parent"
               AND status NOT IN ("cancelled","completed","denied")
             ORDER BY updated_at DESC,id DESC LIMIT 200',
            [(int)$peer['id']]
        );
        $statuses = [];
        foreach ($requestRows as $requestRow) {
            federation_refresh_request_matches($db, (int)$requestRow['id']);
            foreach (request_status_items($db, (int)$requestRow['id'], $ignoreBaseGame) as $item) {
                $package = strtolower(trim((string)($item['required_package'] ?? '')));
                if ($package === '' || isset($statuses[$package])) {
                    continue;
                }
                $statuses[$package] = [
                    'request_id' => (int)$requestRow['id'],
                    'request_status' => (string)$requestRow['status'],
                    'item_status' => (string)$item['status'],
                    'status_message' => (string)$item['status_message'],
                    'updated_at' => (string)$item['updated_at'],
                ];
            }
        }
        fed_json_response([
            'ok' => true,
            'policy' => federation_parent_base_game_policy($db),
            'packages' => $statuses,
        ]);
    }

    if (!empty($payload['list'])) {
        $closed = filter_var($payload['closed'] ?? false, FILTER_VALIDATE_BOOL);
        $pageSize = min(100, CatalogFederationHistoryPageService::normalizePageSize((int)($payload['page_size'] ?? 100)));
        $statusSql = $closed
            ? 'r.status IN ("completed","cancelled","denied")'
            : 'r.status NOT IN ("completed","cancelled","denied")';
        $requestPage = CatalogFederationHistoryPageService::fetch(
            $db,
            $config,
            'federation-request-status-api|peer=' . (int)$peer['id'] . '|closed=' . ($closed ? '1' : '0'),
            'SELECT r.*,r.created_at cursor_created_at,r.id cursor_id FROM ue_federation_requests r',
            'r.peer_id=? AND r.direction="child_to_parent" AND ' . $statusSql,
            [(int)$peer['id']],
            ['r.created_at', 'r.id'],
            ['cursor_created_at', 'cursor_id'],
            ['DESC', 'DESC'],
            $pageSize,
            (string)($payload['cursor'] ?? ''),
            (string)($payload['move'] ?? 'first')
        );

        $requests = [];
        foreach ($requestPage['rows'] as $request) {
            federation_refresh_request_matches($db, (int)$request['id']);
            $items = request_status_items($db, (int)$request['id'], $ignoreBaseGame);
            if (!$items) {
                continue;
            }
            $statusCounts = [];
            foreach ($items as $item) {
                $status = (string)$item['status'];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }
            $requests[] = [
                'id' => (int)$request['id'],
                'status' => (string)$request['status'],
                'title' => (string)$request['title'],
                'submitted_at' => (string)$request['submitted_at'],
                'updated_at' => (string)$request['updated_at'],
                'item_count' => count($items),
                'status_counts' => $statusCounts,
            ];
        }
        fed_json_response([
            'ok' => true,
            'policy' => federation_parent_base_game_policy($db),
            'requests' => $requests,
            'request_page' => [
                'has_previous' => (bool)$requestPage['has_previous'],
                'has_next' => (bool)$requestPage['has_next'],
                'previous_cursor' => (string)$requestPage['previous_cursor'],
                'next_cursor' => (string)$requestPage['next_cursor'],
                'page_size' => (int)$requestPage['page_size'],
            ],
        ]);
    }

    $requestId = (int)($payload['request_id'] ?? 0);
    $request = $requestId > 0
        ? catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=? AND peer_id=? AND direction="child_to_parent"', [$requestId, (int)$peer['id']])
        : catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE peer_id=? AND direction="child_to_parent" ORDER BY created_at DESC,id DESC LIMIT 1', [(int)$peer['id']]);

    if (!$request) {
        fed_json_response(['ok' => true, 'policy' => federation_parent_base_game_policy($db), 'request' => null, 'items' => []]);
    }

    $refresh = federation_refresh_request_matches($db, (int)$request['id']);
    $request = catalog_one($db, 'SELECT * FROM ue_federation_requests WHERE id=?', [(int)$request['id']]) ?: $request;
    $items = request_status_items($db, (int)$request['id'], $ignoreBaseGame);
    $visibleRequest = $items !== [] ? [
        'id' => (int)$request['id'],
        'status' => (string)$request['status'],
        'request_hash' => (string)$request['request_hash'],
        'title' => (string)$request['title'],
        'submitted_at' => (string)$request['submitted_at'],
        'approved_at' => (string)$request['approved_at'],
        'updated_at' => (string)$request['updated_at'],
    ] : null;

    fed_json_response([
        'ok' => true,
        'policy' => federation_parent_base_game_policy($db),
        'request' => $visibleRequest,
        'refresh' => $refresh,
        'items' => $items,
    ]);
} catch (Throwable $error) {
    fed_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
