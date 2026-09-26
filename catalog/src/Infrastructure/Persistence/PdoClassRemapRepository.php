<?php
/**
 * Stores administrator-configured Unreal ClassRemap rules by game.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class PdoClassRemapRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
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
        $statement = $this->db->query('SELECT id,name FROM ue_games ORDER BY name,id');
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function save(?int $id, int $gameId, string $mappingsText): int
    {
        $this->assertGameExists($gameId);
        self::parseMappings($mappingsText);
        $mappingsText = trim($mappingsText);

        if ($id !== null && $id > 0) {
            $statement = $this->db->prepare(
                'UPDATE ue_class_remaps SET game_id=?,mappings_text=?,updated_at=CURRENT_TIMESTAMP WHERE id=?'
            );
            $statement->execute([$gameId, $mappingsText, $id]);
            if ($statement->rowCount() < 1 && $this->find($id) === null) {
                throw new RuntimeException('ClassRemap entry not found.');
            }
            return $id;
        }

        $statement = $this->db->prepare('INSERT INTO ue_class_remaps(game_id,mappings_text) VALUES(?,?)');
        $statement->execute([$gameId, $mappingsText]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid ClassRemap entry.');
        }
        $statement = $this->db->prepare('DELETE FROM ue_class_remaps WHERE id=?');
        $statement->execute([$id]);
    }

    /** @return array<string,string> case-insensitive old-name key => replacement name */
    public function mappingsForGame(int $gameId): array
    {
        if ($gameId < 1) {
            return [];
        }
        $statement = $this->db->prepare('SELECT mappings_text FROM ue_class_remaps WHERE game_id=? ORDER BY id');
        $statement->execute([$gameId]);
        $result = [];
        while (($value = $statement->fetchColumn()) !== false) {
            foreach (self::parseMappings((string)$value) as $from => $to) {
                $result[$from] = $to;
            }
        }
        return $result;
    }

    /** @return array<string,string> */
    public static function parseMappings(string $text): array
    {
        $result = [];
        $entries = preg_split('/(?:\\r\\n|\\r|\\n|\\\\)+/', $text) ?: [];
        foreach ($entries as $offset => $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!str_contains($entry, '=')) {
                throw new RuntimeException('Invalid ClassRemap entry #' . ($offset + 1) . ': expected OldClass=NewClass.');
            }
            [$from, $to] = array_map('trim', explode('=', $entry, 2));
            if ($from === '' || $to === '' || str_contains($to, '=')) {
                throw new RuntimeException('Invalid ClassRemap entry #' . ($offset + 1) . ': expected OldClass=NewClass.');
            }
            $key = self::key($from);
            if (isset($result[$key]) && self::key($result[$key]) !== self::key($to)) {
                throw new RuntimeException('ClassRemap source class is configured more than once: ' . $from);
            }
            $result[$key] = $to;
        }
        if ($result === []) {
            throw new RuntimeException('Enter at least one ClassRemap as OldClass=NewClass.');
        }
        return $result;
    }

    private function assertGameExists(int $gameId): void
    {
        if ($gameId < 1) {
            throw new RuntimeException('Choose a game.');
        }
        $statement = $this->db->prepare('SELECT id FROM ue_games WHERE id=? LIMIT 1');
        $statement->execute([$gameId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Selected game does not exist.');
        }
    }

    private static function key(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
