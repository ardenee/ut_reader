<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns official base-game package protection, matching and seeding policy.
 * Why: Schema verification, GUID normalization, dependency SQL fragments, protected-file lookup and seeding are one
 *      game-domain concern and should not live as mixed procedural helpers.
 * Role: Infrastructure games service preserving the existing base-game protection contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;

final class CatalogBaseGameProtectionService
{
    /** @var array<int,bool> */
    private static array $ensuredConnections = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        $connectionId = spl_object_id($this->db);
        if (isset(self::$ensuredConnections[$connectionId])) {
            return;
        }

        // Runtime code is verification-only. Schema ownership belongs to install.sql
        // and incremental migrations; normal requests/workers must never execute DDL.
        $exists = \catalog_one(
            $this->db,
            'SELECT 1 AS present FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name="ue_base_game_files" LIMIT 1'
        );
        if (!$exists) {
            throw new RuntimeException(
                'Base-game protection table is missing. Run the database migrations before processing protected files or transfers.'
            );
        }

        self::$ensuredConnections[$connectionId] = true;
    }

    public static function normalizeGuid(string $guid): string
    {
        $guid = strtoupper(trim($guid));
        return preg_replace('/[^A-F0-9-]+/', '', $guid) ?? '';
    }

    public static function guidIsUsable(string $guid): bool
    {
        $guid = self::normalizeGuid($guid);
        return $guid !== '' && $guid !== '00000000-00000000-00000000-00000000';
    }

    /**
     * SQL EXISTS expression matching a package name against the official base-game
     * package list. Names can come from the stored package name, original filename,
     * or the currently linked source file. Matching is scoped to a game when given.
     */
    public static function packageExistsSql(string $packageSql, ?string $gameIdSql = null): string
    {
        $baseStem = '(CASE WHEN LOCATE(".",COALESCE(base_dep_bg.original_name,""))>0 '
            . 'THEN LEFT(base_dep_bg.original_name,CHAR_LENGTH(base_dep_bg.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(base_dep_bg.original_name,".",-1))-1) '
            . 'ELSE COALESCE(base_dep_bg.original_name,"") END)';
        $sourceStem = '(CASE WHEN LOCATE(".",COALESCE(base_dep_src.original_name,""))>0 '
            . 'THEN LEFT(base_dep_src.original_name,CHAR_LENGTH(base_dep_src.original_name)-CHAR_LENGTH(SUBSTRING_INDEX(base_dep_src.original_name,".",-1))-1) '
            . 'ELSE COALESCE(base_dep_src.original_name,"") END)';
        $gameSql = $gameIdSql !== null && trim($gameIdSql) !== ''
            ? ' AND base_dep_bg.game_id=' . $gameIdSql
            : '';

        return 'EXISTS (
        SELECT 1
        FROM ue_base_game_files base_dep_bg
        LEFT JOIN ue_files base_dep_src ON base_dep_src.id=base_dep_bg.source_file_id
        WHERE (
            LOWER(TRIM(COALESCE(base_dep_bg.package_name,"")))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(' . $baseStem . '))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(COALESCE(base_dep_src.package_name,"")))=LOWER(TRIM(' . $packageSql . '))
            OR LOWER(TRIM(' . $sourceStem . '))=LOWER(TRIM(' . $packageSql . '))
        )' . $gameSql . '
    )';
    }

    public static function dependencyIsOfficialSql(string $fileAlias = 'f', string $dependencyAlias = 'd'): string
    {
        return self::packageExistsSql($dependencyAlias . '.required_package', $fileAlias . '.game_id');
    }

    /** @return array<string,mixed>|null */
    public function lookup(int $gameId, string $packageGuid): ?array
    {
        $this->ensureSchema();
        $guid = self::normalizeGuid($packageGuid);
        if (!self::guidIsUsable($guid)) {
            return null;
        }
        return \catalog_one(
            $this->db,
            'SELECT b.*, g.name AS game_name FROM ue_base_game_files b '
            . 'JOIN ue_games g ON g.id=b.game_id WHERE b.game_id=? AND b.package_guid=? LIMIT 1',
            [$gameId, $guid]
        );
    }

    /** @param array<string,mixed> $file */
    public function fileIsProtected(array $file): bool
    {
        return $this->lookup(
            (int)($file['game_id'] ?? 0),
            (string)($file['package_guid'] ?? '')
        ) !== null;
    }

    /** @return array{file:array<string,mixed>,base:array<string,mixed>}|null */
    public function fileProtection(int $fileId): ?array
    {
        $this->ensureSchema();
        $file = \catalog_one(
            $this->db,
            'SELECT f.*, g.name AS game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',
            [$fileId]
        );
        if (!$file) {
            return null;
        }
        $base = $this->lookup((int)$file['game_id'], (string)$file['package_guid']);
        if (!$base) {
            return null;
        }
        return ['file' => $file, 'base' => $base];
    }

    /** @param array<string,mixed>|null $file */
    public static function blockMessage(?array $file = null): string
    {
        $name = $file
            ? \catalog_clean_unreal_filename(
                (string)($file['original_name'] ?? $file['package_name'] ?? 'this package')
            )
            : 'this package';
        return $name . ' is an official base-game package. UnrealDB keeps its exports indexed so custom maps/mods can resolve dependencies, but the original game file remains excluded from public downloads, external mirrors, and bundle packaging. Ordinary federation inventory and parent-pull visibility follows the parent-controlled Ignore base-game files setting. An approved missing-dependency transfer remains allowed even when ordinary base-game files are ignored.';
    }

    /** @param array<string,mixed> $file */
    public function requireTransferAllowed(array $file, bool $dependencyException = false): void
    {
        if ($this->fileIsProtected($file) && !$dependencyException) {
            throw new RuntimeException(self::blockMessage($file));
        }
    }

    /** @return array{scanned:int,inserted:int,updated:int} */
    public function seedFromCurrentFiles(int $gameId, ?int $userId = null): array
    {
        $this->ensureSchema();
        $game = \catalog_one($this->db, 'SELECT id, name FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('Game not found.');
        }

        $rows = \catalog_all(
            $this->db,
            'SELECT id, game_id, package_guid, package_name, original_name '
            . 'FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" AND package_guid IS NOT NULL '
            . 'AND package_guid<>"" AND package_guid<>"00000000-00000000-00000000-00000000" '
            . 'ORDER BY package_name, original_name, id',
            [$gameId]
        );

        $inserted = 0;
        $updated = 0;
        $statement = $this->db->prepare(
            'INSERT INTO ue_base_game_files(game_id, package_guid, package_name, original_name, source_file_id, notes) '
            . 'VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE package_name=VALUES(package_name), '
            . 'original_name=VALUES(original_name), source_file_id=VALUES(source_file_id), updated_at=CURRENT_TIMESTAMP'
        );
        foreach ($rows as $row) {
            $guid = self::normalizeGuid((string)$row['package_guid']);
            if (!self::guidIsUsable($guid)) {
                continue;
            }
            $statement->execute([
                $gameId,
                $guid,
                (string)$row['package_name'],
                \catalog_clean_unreal_filename((string)$row['original_name']),
                (int)$row['id'],
                'Seeded from verified catalog files for ' . (string)$game['name'] . '.',
            ]);
            $statement->rowCount() === 1 ? $inserted++ : $updated++;
        }

        return ['scanned' => count($rows), 'inserted' => $inserted, 'updated' => $updated];
    }
}
