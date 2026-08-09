<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Finds verified catalog packages that reference candidate unverified package names.
 * Why: Dependency evidence belongs to the current package-summary projection, not physical queue storage.
 * Role: Infrastructure read query used by unverified-file presentation and compatibility callers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class PdoUnverifiedReferenceMatchQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param list<string> $packageNames
     * @return array<string,list<array{game_id:int,game_name:string,owner_count:int,import_count:int}>>
     */
    public function fetch(array $packageNames): array
    {
        $keys = [];
        foreach ($packageNames as $packageName) {
            $packageName = trim((string)$packageName);
            if ($packageName !== '') {
                $keys[strtolower($packageName)] = $packageName;
            }
        }
        if ($keys === []) {
            return [];
        }

        $values = array_values($keys);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $rows = \catalog_all(
            $this->db,
            'SELECT LOWER(s.required_package) package_key,g.id game_id,g.name game_name,'
            . 'COUNT(DISTINCT s.file_id) owner_count,COALESCE(SUM(s.dependency_count),0) import_count'
            . ' FROM ue_dependency_package_summaries s'
            . ' JOIN ue_games g ON g.id=s.game_id'
            . ' WHERE s.required_package IN (' . $placeholders . ')'
            . ' GROUP BY LOWER(s.required_package),g.id,g.name'
            . ' ORDER BY g.name',
            $values
        );

        $out = [];
        foreach ($rows as $row) {
            $key = strtolower((string)$row['package_key']);
            $out[$key] ??= [];
            $out[$key][] = [
                'game_id' => (int)$row['game_id'],
                'game_name' => (string)$row['game_name'],
                'owner_count' => (int)$row['owner_count'],
                'import_count' => (int)$row['import_count'],
            ];
        }
        return $out;
    }
}
