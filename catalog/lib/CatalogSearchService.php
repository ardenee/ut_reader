<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/../src/Application/Search/CatalogSearchService.php';
require_once __DIR__ . '/../src/Application/Search/CatalogCompactSearchService.php';

class_alias('UnrealDb\\Catalog\\Application\\Search\\CatalogSearchUnavailableException', 'CatalogSearchUnavailableException');

/**
 * Legacy global facade. Broad administrator searches without a selected game
 * are split into bounded game-scoped searches so one request cannot launch a
 * catalogue-wide wildcard scan over every search document or parser table.
 */
final class CatalogSearchService
{
    private const MAX_GLOBAL_GAMES = 64;

    /** @return list<array<string,mixed>> */
    public static function findFiles(PDO $db, string $query, int $limit = 200, ?int $gameId = null): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 500));
        $gameId = $gameId !== null && $gameId > 0 ? $gameId : null;

        if ($gameId !== null || self::isExactIdentity($query)) {
            return \UnrealDb\Catalog\Application\Search\CatalogCompactSearchService::findFiles(
                $db,
                $query,
                $limit,
                $gameId
            );
        }

        $games = catalog_all(
            $db,
            'SELECT g.id FROM ue_games g WHERE EXISTS ('
            . 'SELECT 1 FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified"'
            . ') ORDER BY g.name,g.id LIMIT ' . self::MAX_GLOBAL_GAMES
        );
        if ($games === []) {
            return [];
        }

        $quota = max(10, (int)ceil($limit / max(1, count($games))));
        $results = [];
        foreach ($games as $game) {
            $remaining = $limit - count($results);
            if ($remaining <= 0) {
                break;
            }
            $gameLimit = min($remaining, $quota);
            foreach (\UnrealDb\Catalog\Application\Search\CatalogCompactSearchService::findFiles(
                $db,
                $query,
                $gameLimit,
                (int)$game['id']
            ) as $row) {
                $results[(int)$row['id']] = $row;
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return array_values($results);
    }

    private static function isExactIdentity(string $query): bool
    {
        return preg_match('/^[A-Fa-f0-9]{40}$/', $query) === 1
            || preg_match('/^[A-Fa-f0-9]{32}$/', $query) === 1
            || preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{8}){3}$/', $query) === 1;
    }
}
