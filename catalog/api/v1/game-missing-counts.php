<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the catalog v1 HTTP endpoint for game missing counts.
 * Why: It exposes this operation as a narrowly scoped machine-readable request instead of mixing API behavior into
 *      HTML pages.
 * Role: HTTP API entry point; reusable work should be delegated to shared application/services rather than duplicated
 *       here.
 * Audit: Active API surface unless its callers/tests prove otherwise; preserve request/response compatibility when
 *        consolidating.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogListQuery;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $rows = (new PdoGameCatalogListQuery($application->db))->all();
    $counts = [];
    $pendingGameIds = [];
    $total = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $count = $row['missing_dependency_count'];
        if ($count === null) {
            $counts[(string)$id] = null;
            $pendingGameIds[] = $id;
        } else {
            $counts[(string)$id] = (int)$count;
            $total += (int)$count;
        }
    }
    if ($pendingGameIds !== []) {
        $total = null;
    }

    JsonResponse::send([
        'ok' => true,
        'counts' => $counts,
        'total' => $total,
        'stale_game_ids' => $pendingGameIds,
    ]);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] game missing counts failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('game_missing_counts_failed', 'Missing dependency counts could not be loaded.', 500, ['request_id' => $requestId]);
}
