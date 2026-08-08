<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns administrator CRUD/list behavior for protected official/base-game package GUIDs.
 * Why: Base-game policy mutations and list SQL should not be implemented in the rendering page.
 * Role: Infrastructure/application service over the existing BaseGameProtection contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;

final class CatalogBaseGameProtectionAdminService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/BaseGameProtection.php';
        \base_game_ensure($db);
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all($this->db, 'SELECT id,name FROM ue_games ORDER BY name');
    }

    public function normalizeGameId(int $gameId): int
    {
        if ($gameId <= 0) {
            return 0;
        }
        foreach ($this->games() as $game) {
            if ((int)$game['id'] === $gameId) {
                return $gameId;
            }
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{message:string,game_id:int}
     */
    public function handle(string $action, array $input, int $currentGameId, ?int $userId): array
    {
        $gameId = (int)($input['game_id'] ?? $currentGameId);

        if ($action === 'seed_current_game') {
            if ($gameId <= 0) {
                throw new RuntimeException('Choose a game before seeding base-game GUIDs.');
            }
            $result = \base_game_seed_from_current_files($this->db, $gameId, $userId);
            return [
                'message' => 'Seed complete. Scanned=' . $result['scanned'] . ', inserted=' . $result['inserted'] . ', updated=' . $result['updated'] . '.',
                'game_id' => $gameId,
            ];
        }

        if ($action === 'add') {
            if ($gameId <= 0) {
                throw new RuntimeException('Choose a game before adding a base-game GUID.');
            }
            $guid = \base_game_normalize_guid((string)($input['package_guid'] ?? ''));
            if (!\base_game_guid_is_usable($guid)) {
                throw new RuntimeException('Enter a non-zero package GUID.');
            }
            $packageName = \catalog_clean_unreal_package_stem((string)($input['package_name'] ?? ''));
            $originalName = \catalog_clean_unreal_filename((string)($input['original_name'] ?? $packageName));
            $notes = trim((string)($input['notes'] ?? ''));
            $stmt = $this->db->prepare(
                'INSERT INTO ue_base_game_files(game_id,package_guid,package_name,original_name,notes) '
                . 'VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE '
                . 'package_name=VALUES(package_name),original_name=VALUES(original_name),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([$gameId, $guid, $packageName, $originalName, $notes]);
            return ['message' => 'Base-game GUID saved: ' . $guid, 'game_id' => $gameId];
        }

        if ($action === 'save_visible') {
            $ids = array_values(array_unique(array_map('intval', is_array($input['ids'] ?? null) ? $input['ids'] : [])));
            $packageNames = is_array($input['package_name'] ?? null) ? $input['package_name'] : [];
            $originalNames = is_array($input['original_name'] ?? null) ? $input['original_name'] : [];
            $notes = is_array($input['notes'] ?? null) ? $input['notes'] : [];
            $stmt = $this->db->prepare('UPDATE ue_base_game_files SET package_name=?,original_name=?,notes=? WHERE id=?');
            $saved = 0;
            foreach ($ids as $id) {
                if ($id <= 0) {
                    continue;
                }
                $stmt->execute([
                    \catalog_clean_unreal_package_stem((string)($packageNames[$id] ?? '')),
                    \catalog_clean_unreal_filename((string)($originalNames[$id] ?? '')),
                    trim((string)($notes[$id] ?? '')),
                    $id,
                ]);
                $saved++;
            }
            return ['message' => 'Saved ' . $saved . ' visible base-game row(s).', 'game_id' => $currentGameId];
        }

        if ($action === 'delete_selected') {
            $ids = array_values(array_filter(
                array_unique(array_map('intval', is_array($input['delete_ids'] ?? null) ? $input['delete_ids'] : [])),
                static fn(int $id): bool => $id > 0
            ));
            if ($ids === []) {
                throw new RuntimeException('Select at least one base-game row to remove.');
            }
            $stmt = $this->db->prepare('DELETE FROM ue_base_game_files WHERE id=?');
            foreach ($ids as $id) {
                $stmt->execute([$id]);
            }
            return ['message' => 'Removed ' . count($ids) . ' base-game GUID row(s).', 'game_id' => $currentGameId];
        }

        throw new RuntimeException('Unknown base-game protection action.');
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total_rows:int,total_pages:int,page:int,offset:int}
     */
    public function page(int $gameId, string $query, int $limit, int $page): array
    {
        $where = 'WHERE 1=1';
        $args = [];
        if ($gameId > 0) {
            $where .= ' AND b.game_id=?';
            $args[] = $gameId;
        }
        if ($query !== '') {
            $where .= ' AND (b.package_guid LIKE ? OR b.package_name LIKE ? OR b.original_name LIKE ? OR g.name LIKE ? OR b.notes LIKE ?)';
            $like = '%' . $query . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }

        $totalRows = (int)(\catalog_one(
            $this->db,
            'SELECT COUNT(*) c FROM ue_base_game_files b JOIN ue_games g ON g.id=b.game_id ' . $where,
            $args
        )['c'] ?? 0);
        $totalPages = max(1, (int)ceil($totalRows / $limit));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $limit;

        $rows = \catalog_all(
            $this->db,
            'SELECT b.*,g.name game_name,f.current_file_id '
            . 'FROM ue_base_game_files b '
            . 'JOIN ue_games g ON g.id=b.game_id '
            . 'LEFT JOIN ('
            . ' SELECT game_id,package_guid,MIN(id) current_file_id FROM ue_files '
            . ' WHERE scan_status="verified" GROUP BY game_id,package_guid'
            . ') f ON f.game_id=b.game_id AND f.package_guid=b.package_guid '
            . $where
            . ' ORDER BY g.name,b.package_name,b.original_name,b.id '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $args
        );

        return [
            'rows' => $rows,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'offset' => $offset,
        ];
    }
}
