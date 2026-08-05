<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;
use UnrealDb\Catalog\Presentation\Http\JsonResponse;

try {
    $application = catalog_api_application();
    catalog_api_require_admin(false);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        JsonResponse::error('method_not_allowed', 'Only GET is supported.', 405);
    }

    $stats = new PdoGameCatalogStats($application->db);
    if ($stats->available()) {
        /* Never rebuild all game projections from an interactive API request. */
        $rows = catalog_all(
            $application->db,
            'SELECT g.id game_id,COALESCE(s.missing_dependency_count,0) missing_dependency_count '
            . 'FROM ue_games g LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id ORDER BY g.id'
        );
    } else {
        $dependencySource = CatalogDependencyReadSource::sql($application->db);
        $rows = catalog_all(
            $application->db,
            'SELECT g.id game_id, COALESCE(md.missing_dependency_count,0) missing_dependency_count '
            . 'FROM ue_games g '
            . 'LEFT JOIN ('
            . '  SELECT f.game_id, COUNT(*) missing_dependency_count '
            . '  FROM ' . $dependencySource . ' d '
            . '  JOIN ue_files f ON f.id=d.file_id '
            . '  WHERE d.status="missing" '
            . '  GROUP BY f.game_id'
            . ') md ON md.game_id=g.id '
            . 'ORDER BY g.id'
        );
    }

    $counts = [];
    foreach ($rows as $row) {
        $counts[(string)(int)$row['game_id']] = (int)$row['missing_dependency_count'];
    }

    JsonResponse::send([
        'ok' => true,
        'counts' => $counts,
        'total' => array_sum($counts),
    ]);
} catch (Throwable $error) {
    $requestId = catalog_request_id();
    error_log('[UnrealDB][' . $requestId . '] game missing counts failed: ' . get_class($error) . ': ' . $error->getMessage());
    JsonResponse::error('game_missing_counts_failed', 'Missing dependency counts could not be loaded.', 500, ['request_id' => $requestId]);
}
