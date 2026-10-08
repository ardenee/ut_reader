<?php
/**
 * V5-only object/file drill-down query for missing dependencies.
 *
 * SQL narrows candidate files/packages; exact source-shaped object detail is
 * hydrated from UEDB5 dependency_results.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

final class PdoMissingDetailListQuery
{
    private ?Uedb5ParityV5ReadService $v5 = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public function fetchPackageObjects(
        string $packageName,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $statement = $this->db->prepare(
            'SELECT DISTINCT p.game_id,p.file_id,e.source_index,g.name game_name,'
            . 'f.package_name owner_package_name,f.original_name owner_original_name '
            . 'FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_uedb5_dependency_edges e ON e.file_id=p.file_id '
            . 'AND e.required_package_key_kind=p.package_key_kind AND e.required_package_key=p.package_key '
            . 'AND e.source_kind=1 AND e.outcome=0 '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=p.game_id '
            . 'WHERE p.required_package_name=? AND p.missing_count>0 '
            . 'ORDER BY g.name,f.package_name,f.original_name,p.file_id,e.source_index'
        );
        $statement->execute([$packageName]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byFile = [];
        foreach ($candidates as $candidate) {
            $fileId = (int)$candidate['file_id'];
            $byFile[$fileId]['meta'] = $candidate;
            $byFile[$fileId]['indexes'][] = (int)$candidate['source_index'];
        }
        $rows = [];
        foreach ($byFile as $entry) {
            $candidate = (array)$entry['meta'];
            $details = $this->reader()->dependenciesAtIndexes(
                (int)$candidate['game_id'],
                (int)$candidate['file_id'],
                array_values(array_unique((array)$entry['indexes']))
            );
            foreach ($details as $index => $dependency) {
                if ((string)($dependency['outcome'] ?? '') !== 'missing'
                    || strcasecmp((string)($dependency['required_package'] ?? ''), $packageName) !== 0) {
                    continue;
                }
                $rows[] = [
                    'dependency_id' => (int)$index + 1,
                    'required_object_path' => (string)($dependency['required_object_path'] ?? ''),
                    'required_package' => (string)($dependency['required_package'] ?? ''),
                    'file_id' => (int)$candidate['file_id'],
                    'owner_package_name' => (string)$candidate['owner_package_name'],
                    'owner_original_name' => (string)$candidate['owner_original_name'],
                    'game_id' => (int)$candidate['game_id'],
                    'game_name' => (string)$candidate['game_name'],
                    'class_package' => (string)($dependency['class_package'] ?? ''),
                    'class_name' => (string)($dependency['class_name'] ?? ''),
                    'import_full_path' => (string)($dependency['required_object_path'] ?? ''),
                ];
            }
        }

        return $this->paginate(
            $rows,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (string)$row['game_name'],
                (string)$row['owner_package_name'],
                (string)$row['owner_original_name'],
                (string)$row['required_object_path'],
                (int)$row['dependency_id'],
            ],
            static fn(array $a,array $b):int =>
                self::cmpText($a['game_name'],$b['game_name'])
                ?: self::cmpText($a['owner_package_name'],$b['owner_package_name'])
                ?: self::cmpText($a['owner_original_name'],$b['owner_original_name'])
                ?: self::cmpText($a['required_object_path'],$b['required_object_path'])
                ?: ((int)$a['dependency_id'] <=> (int)$b['dependency_id'])
        );
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public function fetchFileObjects(
        int $fileId,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $statement = $this->db->prepare(
            'SELECT f.id file_id,f.game_id,f.package_name owner_package_name,'
            . 'f.original_name owner_original_name,g.name game_name '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id AND v.format_version=5 '
            . 'WHERE f.id=? AND f.scan_status="verified"'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            return $this->emptyPage();
        }

        $edgeStatement = $this->db->prepare(
            'SELECT source_index FROM ue_uedb5_dependency_edges '
            . 'WHERE file_id=? AND source_kind=1 AND outcome=0 ORDER BY source_index'
        );
        $edgeStatement->execute([$fileId]);
        $indexes = array_map('intval', $edgeStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $details = $this->reader()->dependenciesAtIndexes((int)$file['game_id'], $fileId, $indexes);
        $rows = [];
        foreach ($details as $index => $dependency) {
            if ((string)($dependency['outcome'] ?? '') !== 'missing') {
                continue;
            }
            $rows[] = [
                'dependency_id' => (int)$index + 1,
                'required_package' => (string)($dependency['required_package'] ?? ''),
                'required_object_path' => (string)($dependency['required_object_path'] ?? ''),
                'file_id' => $fileId,
                'owner_package_name' => (string)$file['owner_package_name'],
                'owner_original_name' => (string)$file['owner_original_name'],
                'game_id' => (int)$file['game_id'],
                'game_name' => (string)$file['game_name'],
                'class_package' => (string)($dependency['class_package'] ?? ''),
                'class_name' => (string)($dependency['class_name'] ?? ''),
                'import_full_path' => (string)($dependency['required_object_path'] ?? ''),
            ];
        }

        return $this->paginate(
            $rows,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (string)$row['required_package'],
                (string)$row['required_object_path'],
                (int)$row['dependency_id'],
            ],
            static fn(array $a,array $b):int =>
                self::cmpText($a['required_package'],$b['required_package'])
                ?: self::cmpText($a['required_object_path'],$b['required_object_path'])
                ?: ((int)$a['dependency_id'] <=> (int)$b['dependency_id'])
        );
    }

    /** @param list<mixed>|null $cursor @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    public function fetchPackageFiles(
        string $packageName,
        int $limit,
        ?array $cursor,
        string $move
    ): array {
        $statement = $this->db->prepare(
            'SELECT f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,'
            . 'g.id game_id,g.name game_name,SUM(p.missing_count) missing_object_rows '
            . 'FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'JOIN ue_games g ON g.id=p.game_id '
            . 'WHERE p.required_package_name=? AND p.missing_count>0 '
            . 'GROUP BY f.id,f.package_name,f.original_name,g.id,g.name'
        );
        $statement->execute([$packageName]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->paginate(
            $rows,
            $limit,
            $cursor,
            $move,
            static fn(array $row): array => [
                (int)$row['missing_object_rows'],
                (string)$row['game_name'],
                (string)$row['owner_package_name'],
                (string)$row['owner_original_name'],
                (int)$row['file_id'],
            ],
            static fn(array $a,array $b):int =>
                ((int)$b['missing_object_rows'] <=> (int)$a['missing_object_rows'])
                ?: self::cmpText($a['game_name'],$b['game_name'])
                ?: self::cmpText($a['owner_package_name'],$b['owner_package_name'])
                ?: self::cmpText($a['owner_original_name'],$b['owner_original_name'])
                ?: ((int)$a['file_id'] <=> (int)$b['file_id'])
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<mixed>|null $cursor
     * @param callable(array<string,mixed>):list<mixed> $cursorValues
     * @param callable(array<string,mixed>,array<string,mixed>):int $sort
     * @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array}
     */
    private function paginate(
        array $rows,
        int $limit,
        ?array $cursor,
        string $move,
        callable $cursorValues,
        callable $sort
    ): array {
        $limit = max(1, min(500, $limit));
        $move = in_array($move, ['first','next','prev','last'], true) ? $move : 'first';
        usort($rows, $sort);
        $count = count($rows);
        $cursorIndex = null;
        if ($cursor !== null) {
            foreach ($rows as $index => $row) {
                if ($cursorValues($row) == $cursor) {
                    $cursorIndex = $index;
                    break;
                }
            }
        }

        $start = match ($move) {
            'last' => max(0, $count - $limit),
            'prev' => max(0, ($cursorIndex ?? 0) - $limit),
            'next' => min($count, ($cursorIndex ?? -1) + 1),
            default => 0,
        };
        $page = array_slice($rows, $start, $limit);
        $first = $page !== [] ? $cursorValues($page[0]) : null;
        $last = $page !== [] ? $cursorValues($page[count($page)-1]) : null;

        return [
            'rows' => $page,
            'has_previous' => $start > 0,
            'has_next' => ($start + count($page)) < $count,
            'first_cursor' => $first,
            'last_cursor' => $last,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,has_previous:bool,has_next:bool,first_cursor:?array,last_cursor:?array} */
    private function emptyPage(): array
    {
        return ['rows'=>[],'has_previous'=>false,'has_next'=>false,'first_cursor'=>null,'last_cursor'=>null];
    }

    private function reader(): Uedb5ParityV5ReadService
    {
        if ($this->v5 !== null) {
            return $this->v5;
        }
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        if (!is_array($config) || trim((string)($config['storage_path'] ?? '')) === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 missing-dependency reads.');
        }
        return $this->v5 = new Uedb5ParityV5ReadService($this->db, $config);
    }

    private static function cmpText(mixed $a,mixed $b): int
    {
        return strnatcasecmp((string)$a,(string)$b);
    }
}
