<?php
/**
 * Bounded selector for bulk actions over the complete filtered Unverified set.
 *
 * Display pagination remains intentionally bounded. Bulk workflows snapshot the
 * current highest matching id and then walk that stable id range with a cursor,
 * so processing 100k+ rows never requires rendering or posting 100k checkboxes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class PdoUnverifiedBulkSelectionQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $filters @return array{total:int,max_id:int,filters:array<string,mixed>} */
    public function snapshot(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        [$whereSql, $args] = $this->where($filters);
        $statement = $this->db->prepare(
            'SELECT COUNT(*) c,COALESCE(MAX(f.id),0) max_id FROM ue_files f WHERE ' . $whereSql
        );
        $statement->execute($args);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => max(0, (int)($row['c'] ?? 0)),
            'max_id' => max(0, (int)($row['max_id'] ?? 0)),
            'filters' => $filters,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{id:int,token:string,original_name:string}>
     */
    public function page(array $filters, int $afterId, int $maxId, int $limit = 1000): array
    {
        $filters = $this->normalizeFilters($filters);
        $afterId = max(0, $afterId);
        $maxId = max(0, $maxId);
        $limit = max(1, min(5000, $limit));
        if ($maxId < 1 || $afterId >= $maxId) {
            return [];
        }

        [$whereSql, $args] = $this->where($filters);
        $whereSql .= ' AND f.id>? AND f.id<=?';
        $args[] = $afterId;
        $args[] = $maxId;
        $statement = $this->db->prepare(
            'SELECT f.id,f.unverified_queue_game_id,f.unverified_queue_name,f.stored_name,f.original_name '
            . 'FROM ue_files f WHERE ' . $whereSql
            . ' ORDER BY f.id ASC LIMIT ' . $limit
        );
        $statement->execute($args);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            $gameId = max(0, (int)($row['unverified_queue_game_id'] ?? 0));
            $queueName = basename(trim((string)($row['unverified_queue_name'] ?? '')));
            if ($queueName === '') {
                $queueName = basename((string)($row['stored_name'] ?? $row['original_name'] ?? ''));
            }
            if ($id < 1 || $queueName === '') {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'token' => CatalogUnverifiedQueueStorage::token($gameId, $queueName),
                'original_name' => trim((string)($row['original_name'] ?? '')) ?: $queueName,
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function normalizeFilters(array $filters): array
    {
        $sourceGameId = (int)($filters['source_game_id'] ?? 0);
        $extension = strtolower(trim((string)($filters['extension'] ?? '')));
        $engine = strtoupper(trim((string)($filters['engine'] ?? '')));
        $version = trim((string)($filters['version'] ?? ''));
        $licensee = trim((string)($filters['licensee'] ?? ''));
        if (strlen($extension) > 32) {
            $extension = substr($extension, 0, 32);
        }
        if (strlen($engine) > 32) {
            $engine = substr($engine, 0, 32);
        }
        if (strlen($version) > 32) {
            $version = substr($version, 0, 32);
        }
        if (strlen($licensee) > 32) {
            $licensee = substr($licensee, 0, 32);
        }
        return [
            'source_game_id' => $sourceGameId,
            'extension' => $extension,
            'engine' => $engine,
            'version' => $version,
            'licensee' => $licensee,
        ];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function where(array $filters): array
    {
        $where = ['f.scan_status="unverified"'];
        $args = [];
        $sourceGameId = (int)$filters['source_game_id'];
        if ($sourceGameId === -1) {
            $where[] = 'f.unverified_queue_game_id=0';
        } elseif ($sourceGameId > 0) {
            $where[] = 'f.unverified_queue_game_id=?';
            $args[] = $sourceGameId;
        }

        $extension = (string)$filters['extension'];
        if ($extension !== '') {
            $where[] = 'f.extension=?';
            $args[] = $extension;
        }

        $engine = (string)$filters['engine'];
        if ($engine !== '') {
            if ($engine === 'UNKNOWN') {
                $where[] = '(f.detected_engine_key IS NULL OR f.detected_engine_key="")';
            } else {
                $where[] = 'f.detected_engine_key=?';
                $args[] = $engine;
            }
        }

        foreach (['version' => 'detected_package_version', 'licensee' => 'detected_licensee_version'] as $key => $column) {
            $value = (string)$filters[$key];
            if ($value === '') {
                continue;
            }
            if (strtolower($value) === 'unknown') {
                $where[] = 'f.' . $column . ' IS NULL';
            } elseif (preg_match('/^-?\d+$/', $value) === 1) {
                $where[] = 'f.' . $column . '=?';
                $args[] = (int)$value;
            } else {
                $where[] = '1=0';
            }
        }

        return [implode(' AND ', $where), $args];
    }
}
