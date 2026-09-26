<?php
/**
 * Stores and parses administrator-configured, game-scoped Unreal ClassRemap entries.
 *
 * The persisted text intentionally mirrors Unreal's simple OldName=NewName configuration
 * syntax. Dependency resolution loads a normalized map once per game and only consults it
 * after the original serialized ObjectName fails to resolve.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

final class PdoClassRemapRepository
{
    /** @var array<int,array<string,string>> */
    private static array $mapCache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT r.id,r.game_id,r.mappings_text,r.created_at,r.updated_at,g.name game_name '
            . 'FROM ue_class_remaps r JOIN ue_games g ON g.id=r.game_id '
            . 'ORDER BY g.name,r.id'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $statement = $this->db->prepare(
            'SELECT r.id,r.game_id,r.mappings_text,r.created_at,r.updated_at,g.name game_name '
            . 'FROM ue_class_remaps r JOIN ue_games g ON g.id=r.game_id WHERE r.id=? LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array{id:int,name:string}> */
    public function games(): array
    {
        $statement = $this->db->query('SELECT id,name FROM ue_games ORDER BY name');
        /** @var list<array{id:int,name:string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    public function save(int $id, int $gameId, string $text): int
    {
        if (!$this->gameExists($gameId)) {
            throw new RuntimeException('Choose a valid game.');
        }
        $normalized = self::normalizeText($text);
        if ($normalized === '') {
            throw new RuntimeException('Enter at least one ClassRemap entry.');
        }

        if ($id > 0) {
            $statement = $this->db->prepare(
                'UPDATE ue_class_remaps SET game_id=?,mappings_text=? WHERE id=?'
            );
            $statement->execute([$gameId, $normalized, $id]);
            if ($statement->rowCount() === 0 && $this->find($id) === null) {
                throw new RuntimeException('ClassRemap row not found.');
            }
            unset(self::$mapCache[$gameId]);
            self::$mapCache = [];
            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_class_remaps(game_id,mappings_text) VALUES(?,?) '
            . 'ON DUPLICATE KEY UPDATE mappings_text=VALUES(mappings_text),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([$gameId, $normalized]);
        self::$mapCache = [];

        $lookup = $this->db->prepare('SELECT id FROM ue_class_remaps WHERE game_id=? LIMIT 1');
        $lookup->execute([$gameId]);
        return (int)$lookup->fetchColumn();
    }

    public function delete(int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('ClassRemap row not found.');
        }
        $statement = $this->db->prepare('DELETE FROM ue_class_remaps WHERE id=?');
        $statement->execute([$id]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('ClassRemap row not found.');
        }
        self::$mapCache = [];
    }

    /**
     * @return array<string,string> normalized Unreal name key => replacement ObjectName
     */
    public function mapForGame(int $gameId): array
    {
        if ($gameId < 1) {
            return [];
        }
        if (array_key_exists($gameId, self::$mapCache)) {
            return self::$mapCache[$gameId];
        }

        $statement = $this->db->prepare('SELECT mappings_text FROM ue_class_remaps WHERE game_id=? LIMIT 1');
        $statement->execute([$gameId]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return self::$mapCache[$gameId] = [];
        }
        return self::$mapCache[$gameId] = self::parse($value);
    }

    /** @return array<string,string> */
    public static function parse(string $text): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $equals = strpos($line, '=');
            if ($equals === false) {
                throw new RuntimeException('Invalid ClassRemap on line ' . ($lineNumber + 1) . ': expected OldName=NewName.');
            }
            $from = trim(substr($line, 0, $equals));
            $to = trim(substr($line, $equals + 1));
            if ($from === '' || $to === '') {
                throw new RuntimeException('Invalid ClassRemap on line ' . ($lineNumber + 1) . ': both names are required.');
            }
            if (str_contains($from, '=') || str_contains($to, '=')) {
                throw new RuntimeException('Invalid ClassRemap on line ' . ($lineNumber + 1) . ': use one = separator.');
            }
            $key = CatalogUnrealIdentityHash::nameKey($from);
            if (isset($result[$key])) {
                throw new RuntimeException('Duplicate ClassRemap source name on line ' . ($lineNumber + 1) . ': ' . $from . '.');
            }
            $result[$key] = $to;
        }
        return $result;
    }

    public static function normalizeText(string $text): string
    {
        $map = self::parse($text);
        if ($map === []) {
            return '';
        }

        $sourceNames = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $equals = strpos($line, '=');
            if ($equals === false) {
                continue;
            }
            $from = trim(substr($line, 0, $equals));
            $key = CatalogUnrealIdentityHash::nameKey($from);
            $sourceNames[$key] = $from;
        }

        $lines = [];
        foreach ($map as $key => $to) {
            $lines[] = ($sourceNames[$key] ?? $key) . '=' . $to;
        }
        return implode("\n", $lines);
    }

    private function gameExists(int $gameId): bool
    {
        if ($gameId < 1) {
            return false;
        }
        $statement = $this->db->prepare('SELECT 1 FROM ue_games WHERE id=? LIMIT 1');
        $statement->execute([$gameId]);
        return $statement->fetchColumn() !== false;
    }
}
