<?php
/**
 * Discovers only dependency owners affected by packages introduced by one PAK.
 *
 * PAK imports already publish compact dependency rows for each package as it is
 * parsed. Once all entries are present, only the files imported/aliased by that
 * PAK plus existing files that reference one of those provider package names
 * need to be re-resolved. A whole-game dependency rebuild is unnecessary.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;

final class CatalogPakDependencyTargetQuery
{
    private const FILE_BATCH_SIZE = 500;
    private const TERM_BATCH_SIZE = 250;
    private const LINK_BATCH_SIZE = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   source_file_ids:list<int>,
     *   provider_packages:list<string>,
     *   affected_file_ids:list<int>,
     *   target_file_ids:list<int>
     * }
     */
    public function discover(int $parentJobId, int $gameId): array
    {
        $sourceFileIds = $this->sourceFileIds($parentJobId, $gameId);
        if ($sourceFileIds === []) {
            return [
                'source_file_ids' => [],
                'provider_packages' => [],
                'affected_file_ids' => [],
                'target_file_ids' => [],
            ];
        }

        $packageNames = $this->providerPackageNames($sourceFileIds, $gameId);
        $termIds = $this->termIds($packageNames);
        $affectedFileIds = $this->affectedFileIds($termIds, $gameId);

        $targets = [];
        foreach (array_merge($sourceFileIds, $affectedFileIds) as $fileId) {
            $fileId = (int)$fileId;
            if ($fileId > 0) {
                $targets[$fileId] = true;
            }
        }

        $targetFileIds = array_map('intval', array_keys($targets));
        sort($targetFileIds, SORT_NUMERIC);

        return [
            'source_file_ids' => $sourceFileIds,
            'provider_packages' => $packageNames,
            'affected_file_ids' => $affectedFileIds,
            'target_file_ids' => $targetFileIds,
        ];
    }

    /** @return list<int> */
    private function sourceFileIds(int $parentJobId, int $gameId): array
    {
        $ids = [];
        $statement = $this->db->prepare(
            'SELECT result_json FROM ue_background_jobs '
            . 'WHERE parent_job_id=? AND workflow_unit_key LIKE "pak-entry:%" '
            . 'AND status="completed" ORDER BY id'
        );
        $statement->execute([$parentJobId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $json) {
            $result = json_decode((string)$json, true);
            if (!is_array($result)) {
                continue;
            }
            $outcome = (string)($result['outcome'] ?? '');
            if (!in_array($outcome, ['imported', 'alias'], true)) {
                continue;
            }
            $fileId = (int)($result['file_id'] ?? 0);
            if ($fileId > 0) {
                $ids[$fileId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        // Guard against stale/foreign IDs in a recovered result payload.
        $verified = [];
        foreach (array_chunk(array_map('intval', array_keys($ids)), self::FILE_BATCH_SIZE) as $chunk) {
            $statement = $this->db->prepare(
                'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute(array_merge([$gameId], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileId) {
                $verified[(int)$fileId] = true;
            }
        }
        $result = array_map('intval', array_keys($verified));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param list<int> $fileIds @return list<string> */
    private function providerPackageNames(array $fileIds, int $gameId): array
    {
        $names = [];
        foreach (array_chunk($fileIds, self::FILE_BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $primary = $this->db->prepare(
                'SELECT package_name FROM ue_files WHERE game_id=? AND scan_status="verified" '
                . 'AND id IN (' . $placeholders . ')'
            );
            $primary->execute(array_merge([$gameId], $chunk));
            foreach ($primary->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                $this->collectPackageName($names, (string)$name);
            }

            $aliases = $this->db->prepare(
                'SELECT a.package_name FROM ue_file_package_aliases a '
                . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
                . 'WHERE a.game_id=? AND f.scan_status="verified" '
                . 'AND a.file_id IN (' . $placeholders . ')'
            );
            $aliases->execute(array_merge([$gameId], $chunk));
            foreach ($aliases->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                $this->collectPackageName($names, (string)$name);
            }
        }

        ksort($names, SORT_STRING);
        return array_values($names);
    }

    /** @param array<string,string> $names */
    private function collectPackageName(array &$names, string $name): void
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 255) {
            return;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $names[$key] ??= $name;
    }

    /** @param list<string> $packageNames @return list<int> */
    private function termIds(array $packageNames): array
    {
        $ids = [];
        foreach (array_chunk($packageNames, self::TERM_BATCH_SIZE) as $chunk) {
            $predicates = [];
            $arguments = [];
            $expected = [];
            foreach ($chunk as $name) {
                $hash = md5($name, true);
                $length = strlen($name);
                $predicates[] = '(value_hash=? AND value_length=?)';
                $arguments[] = $hash;
                $arguments[] = $length;
                $expected[bin2hex($hash) . ':' . $length] = [
                    'prefix' => substr($name, 0, 200),
                    'overflow' => $length > 200 ? 1 : 0,
                ];
            }
            if ($predicates === []) {
                continue;
            }
            $statement = $this->db->prepare(
                'SELECT id,value_hash,value_length,value_prefix,is_overflow FROM ue_terms WHERE '
                . implode(' OR ', $predicates)
            );
            $statement->execute($arguments);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $key = bin2hex((string)$row['value_hash']) . ':' . (int)$row['value_length'];
                $match = $expected[$key] ?? null;
                if (!is_array($match)) {
                    continue;
                }
                $stored = (string)$row['value_prefix'];
                $prefix = (string)$match['prefix'];
                $prefixMatches = (int)$row['is_overflow'] === 1
                    ? str_starts_with($stored, $prefix)
                    : hash_equals($stored, $prefix);
                if ($prefixMatches && (int)$row['is_overflow'] === (int)$match['overflow']) {
                    $ids[(int)$row['id']] = true;
                }
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param list<int> $termIds @return list<int> */
    private function affectedFileIds(array $termIds, int $gameId): array
    {
        if ($termIds === []) {
            return [];
        }
        $ids = [];
        foreach (array_chunk($termIds, self::LINK_BATCH_SIZE) as $chunk) {
            $statement = $this->db->prepare(
                'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
                . 'JOIN ue_files f ON f.id=l.file_id '
                . 'WHERE f.game_id=? AND f.scan_status="verified" '
                . 'AND l.required_package_term_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $statement->execute(array_merge([$gameId], $chunk));
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fileId) {
                $ids[(int)$fileId] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }
}
