<?php
/**
 * Indexed read model for the per-game missing-dependency page.
 *
 * Page aggregates use the compact package summary projection. Package detail
 * resolves the requested package to its ue_terms identity first, then reads
 * ue_dependency_links directly so MySQL never has to materialize the textual
 * compatibility dependency view merely to filter one package.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoGameMissingDependencyQuery
{
    private const TERM_BATCH_SIZE = 300;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<string> */
    public function officialBaseGamePackageNames(int $gameId): array
    {
        if ($gameId < 1) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT b.package_name,b.original_name,'
            . 'f.package_name source_package_name,f.original_name source_original_name '
            . 'FROM ue_base_game_files b '
            . 'LEFT JOIN ue_files f ON f.id=b.source_file_id AND f.game_id=b.game_id '
            . 'WHERE b.game_id=?'
        );
        $statement->execute([$gameId]);

        $names = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ([
                (string)($row['package_name'] ?? ''),
                self::filenameStem((string)($row['original_name'] ?? '')),
                (string)($row['source_package_name'] ?? ''),
                self::filenameStem((string)($row['source_original_name'] ?? '')),
            ] as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $key = function_exists('mb_strtolower')
                    ? mb_strtolower($name, 'UTF-8')
                    : strtolower($name);
                $names[$key] ??= $name;
            }
        }

        natcasesort($names);
        return array_values($names);
    }

    /**
     * @param list<string>|null $packageNames null = all packages, [] = no packages
     * @return array{missing_objects:int,missing_packages:int,files_with_missing:int}
     */
    public function totals(int $gameId, ?array $packageNames): array
    {
        if ($gameId < 1 || $packageNames === []) {
            return ['missing_objects' => 0, 'missing_packages' => 0, 'files_with_missing' => 0];
        }

        if ((new PdoDependencyPackageSummary($this->db))->available()) {
            [$where, $args] = $this->summaryWhere('s', $gameId, $packageNames);
            $statement = $this->db->prepare(
                'SELECT COALESCE(SUM(s.missing_count),0) missing_objects,'
                . 'COUNT(DISTINCT s.required_package) missing_packages,'
                . 'COUNT(DISTINCT s.file_id) files_with_missing '
                . 'FROM ue_dependency_package_summaries s WHERE ' . $where
            );
            $statement->execute($args);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'missing_objects' => max(0, (int)($row['missing_objects'] ?? 0)),
                'missing_packages' => max(0, (int)($row['missing_packages'] ?? 0)),
                'files_with_missing' => max(0, (int)($row['files_with_missing'] ?? 0)),
            ];
        }

        $termIds = $packageNames === null ? null : $this->termIds($packageNames);
        if ($termIds === []) {
            return ['missing_objects' => 0, 'missing_packages' => 0, 'files_with_missing' => 0];
        }
        [$where, $args] = $this->linkWhere($gameId, $termIds);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) missing_objects,COUNT(DISTINCT l.required_package_term_id) missing_packages,'
            . 'COUNT(DISTINCT l.file_id) files_with_missing '
            . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id WHERE ' . $where
        );
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'missing_objects' => max(0, (int)($row['missing_objects'] ?? 0)),
            'missing_packages' => max(0, (int)($row['missing_packages'] ?? 0)),
            'files_with_missing' => max(0, (int)($row['files_with_missing'] ?? 0)),
        ];
    }

    /** @param list<string>|null $packageNames @return list<array<string,mixed>> */
    public function fileRows(int $gameId, ?array $packageNames, int $limit, int $offset): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        if ($gameId < 1 || $packageNames === []) {
            return [];
        }

        if ((new PdoDependencyPackageSummary($this->db))->available()) {
            [$where, $args] = $this->summaryWhere('s', $gameId, $packageNames);
            $statement = $this->db->prepare(
                'SELECT f.id file_id,f.package_name,f.original_name,g.name game_name,'
                . 'SUM(s.missing_count) missing_object_rows,COUNT(*) missing_package_count,'
                . 'GROUP_CONCAT(s.required_package ORDER BY s.required_package SEPARATOR ", ") missing_package_names '
                . 'FROM ue_dependency_package_summaries s '
                . 'JOIN ue_files f ON f.id=s.file_id JOIN ue_games g ON g.id=s.game_id '
                . 'WHERE ' . $where . ' '
                . 'GROUP BY f.id,f.package_name,f.original_name,g.name '
                . 'ORDER BY missing_object_rows DESC,missing_package_count DESC,f.id '
                . 'LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $statement->execute($args);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $termIds = $packageNames === null ? null : $this->termIds($packageNames);
        if ($termIds === []) {
            return [];
        }
        [$where, $args] = $this->linkWhere($gameId, $termIds);
        $statement = $this->db->prepare(
            'SELECT f.id file_id,f.package_name,f.original_name,g.name game_name,'
            . 'COUNT(*) missing_object_rows,COUNT(DISTINCT l.required_package_term_id) missing_package_count,'
            . 'GROUP_CONCAT(DISTINCT CONVERT(t.value_prefix USING utf8mb4) ORDER BY t.value_prefix SEPARATOR ", ") missing_package_names '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_files f ON f.id=l.file_id JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_terms t ON t.id=l.required_package_term_id '
            . 'WHERE ' . $where . ' '
            . 'GROUP BY f.id,f.package_name,f.original_name,g.name '
            . 'ORDER BY missing_object_rows DESC,missing_package_count DESC,f.id '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<string>|null $packageNames @return list<array<string,mixed>> */
    public function packageRows(int $gameId, ?array $packageNames, int $limit, int $offset): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        if ($gameId < 1 || $packageNames === []) {
            return [];
        }

        if ((new PdoDependencyPackageSummary($this->db))->available()) {
            [$where, $args] = $this->summaryWhere('s', $gameId, $packageNames);
            $statement = $this->db->prepare(
                'SELECT s.required_package,SUM(s.missing_count) missing_object_rows,COUNT(*) requiring_file_count '
                . 'FROM ue_dependency_package_summaries s WHERE ' . $where . ' '
                . 'GROUP BY s.required_package '
                . 'ORDER BY missing_object_rows DESC,requiring_file_count DESC,s.required_package '
                . 'LIMIT ' . $limit . ' OFFSET ' . $offset
            );
            $statement->execute($args);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $termIds = $packageNames === null ? null : $this->termIds($packageNames);
        if ($termIds === []) {
            return [];
        }
        [$where, $args] = $this->linkWhere($gameId, $termIds);
        $statement = $this->db->prepare(
            'SELECT CONVERT(t.value_prefix USING utf8mb4) required_package,'
            . 'COUNT(*) missing_object_rows,COUNT(DISTINCT l.file_id) requiring_file_count '
            . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            . 'JOIN ue_terms t ON t.id=l.required_package_term_id '
            . 'WHERE ' . $where . ' '
            . 'GROUP BY l.required_package_term_id,t.value_prefix '
            . 'ORDER BY missing_object_rows DESC,requiring_file_count DESC,l.required_package_term_id '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<string>|null $packageNames */
    public function detailTotal(int $gameId, string $packageName, ?array $packageNames): int
    {
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '' || !$this->scopeContains($packageName, $packageNames)) {
            return 0;
        }

        if ((new PdoDependencyPackageSummary($this->db))->available()) {
            $statement = $this->db->prepare(
                'SELECT COALESCE(SUM(missing_count),0) FROM ue_dependency_package_summaries '
                . 'WHERE game_id=? AND missing_count>0 AND required_package=?'
            );
            $statement->execute([$gameId, $packageName]);
            return max(0, (int)($statement->fetchColumn() ?: 0));
        }

        $termId = $this->termId($packageName);
        if ($termId < 1) {
            return 0;
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
            . 'AND l.required_package_term_id=?'
        );
        $statement->execute([$gameId, $termId]);
        return max(0, (int)($statement->fetchColumn() ?: 0));
    }

    /** @param list<string>|null $packageNames @return list<array<string,mixed>> */
    public function detailRows(
        int $gameId,
        string $packageName,
        ?array $packageNames,
        int $limit,
        int $offset
    ): array {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '' || !$this->scopeContains($packageName, $packageNames)) {
            return [];
        }

        $termId = $this->termId($packageName);
        if ($termId < 1) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT ? required_package,CONVERT(object_term.value_prefix USING utf8mb4) required_object_path,'
            . 'f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,g.name game_name,'
            . 'COALESCE(CONVERT(class_package_term.value_prefix USING utf8mb4),"") class_package,'
            . 'COALESCE(CONVERT(class_name_term.value_prefix USING utf8mb4),"") class_name,'
            . 'CONVERT(object_term.value_prefix USING utf8mb4) import_full_path '
            . 'FROM ue_dependency_links l '
            . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_terms object_term ON object_term.id=l.required_object_term_id '
            . 'LEFT JOIN ue_terms class_package_term ON class_package_term.id=l.import_class_package_term_id '
            . 'LEFT JOIN ue_terms class_name_term ON class_name_term.id=l.import_class_name_term_id '
            . 'WHERE f.game_id=? AND l.status=0 AND l.required_package_term_id=? '
            . 'ORDER BY f.id,l.import_index '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute([$packageName, $gameId, $termId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<string>|null $packageNames @return array{0:string,1:list<mixed>} */
    private function summaryWhere(string $alias, int $gameId, ?array $packageNames): array
    {
        $where = $alias . '.game_id=? AND ' . $alias . '.missing_count>0';
        $args = [$gameId];
        if ($packageNames !== null) {
            if ($packageNames === []) {
                return ['1=0', []];
            }
            $where .= ' AND ' . $alias . '.required_package IN ('
                . implode(',', array_fill(0, count($packageNames), '?')) . ')';
            array_push($args, ...$packageNames);
        }
        return [$where, $args];
    }

    /** @param list<int>|null $termIds @return array{0:string,1:list<mixed>} */
    private function linkWhere(int $gameId, ?array $termIds): array
    {
        $where = 'f.game_id=? AND f.scan_status="verified" AND l.status=0';
        $args = [$gameId];
        if ($termIds !== null) {
            if ($termIds === []) {
                return ['1=0', []];
            }
            $where .= ' AND l.required_package_term_id IN ('
                . implode(',', array_fill(0, count($termIds), '?')) . ')';
            array_push($args, ...$termIds);
        }
        return [$where, $args];
    }

    /** @param list<string>|null $packageNames */
    private function scopeContains(string $packageName, ?array $packageNames): bool
    {
        if ($packageNames === null) {
            return true;
        }
        $needle = function_exists('mb_strtolower')
            ? mb_strtolower(trim($packageName), 'UTF-8')
            : strtolower(trim($packageName));
        foreach ($packageNames as $candidate) {
            $candidate = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string)$candidate), 'UTF-8')
                : strtolower(trim((string)$candidate));
            if ($candidate !== '' && hash_equals($candidate, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $values @return list<int> */
    private function termIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = $this->termId((string)$value);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private function termId(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $statement = $this->db->prepare(
            'SELECT id,value_prefix FROM ue_terms WHERE value_hash=? AND value_length=? LIMIT 1'
        );
        $statement->execute([md5($value, true), strlen($value)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 0;
        }
        $stored = (string)($row['value_prefix'] ?? '');
        if (!hash_equals($stored, $value) && !hash_equals($stored, substr($value, 0, 200))) {
            return 0;
        }
        return max(0, (int)$row['id']);
    }

    private static function filenameStem(string $filename): string
    {
        $filename = trim(str_replace('\\', '/', $filename));
        $slash = strrpos($filename, '/');
        if ($slash !== false) {
            $filename = substr($filename, $slash + 1);
        }
        $dot = strrpos($filename, '.');
        return $dot !== false ? substr($filename, 0, $dot) : $filename;
    }
}
