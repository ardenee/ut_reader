<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoPackageCoverageCache
{
    private const TEXT_COLLATION = 'utf8mb4_unicode_ci';

    public function __construct(private readonly PDO $db) {}

    /** @return array<string,mixed>|null */
    public function read(int $gameId, string $packageName): ?array
    {
        $head = \catalog_one($this->db, 'SELECT * FROM ue_package_coverage_cache WHERE game_id=? AND package_name=?', [$gameId, $packageName]);
        if (!$head) return null;
        $rows = \catalog_all($this->db, 'SELECT * FROM ue_package_provider_coverage_cache WHERE game_id=? AND package_name=? ORDER BY fully_satisfies DESC,matched_count DESC,file_id', [$gameId, $packageName]);
        foreach ($rows as &$row) {
            $row['status'] = (int)$row['fully_satisfies'] === 1 ? 'fully_satisfies' : ((int)$row['matched_count'] > 0 ? 'partially_satisfies' : 'does_not_satisfy');
            $row['required_count'] = (int)$head['required_object_count'];
            $row['missing_paths'] = json_decode((string)($row['missing_paths_json'] ?? '[]'), true) ?: [];
        }
        unset($row);
        return ['game_id'=>$gameId,'package_name'=>$packageName,'consumer_count'=>(int)$head['consumer_count'],'required_object_count'=>(int)$head['required_object_count'],'providers'=>$rows,'updated_at'=>(string)$head['updated_at']];
    }

    /** @return array<string,mixed> */
    public function rebuildPackage(int $gameId, string $packageName): array
    {
        $result = PdoPackageSupersetAnalyzer::analyze($this->db, $gameId, $packageName);
        $this->persistResult($gameId, $packageName, $result);
        return $result;
    }

    /**
     * Reconcile one cached logical package after providers or consumers change.
     *
     * Package Coverage is intentionally a multi-provider report. If the current
     * v4 resolver sees fewer than two eligible providers, or no required object
     * set remains, remove the old cache entry instead of preserving stale state.
     *
     * @return array<string,mixed>
     */
    public function reconcilePackage(int $gameId, string $packageName): array
    {
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '') {
            return [
                'game_id' => $gameId,
                'package_name' => $packageName,
                'consumer_count' => 0,
                'required_object_count' => 0,
                'providers' => [],
                'cached' => false,
            ];
        }

        $result = PdoPackageSupersetAnalyzer::analyze($this->db, $gameId, $packageName);
        $providers = array_values((array)($result['providers'] ?? []));
        $requiredCount = max(0, (int)($result['required_object_count'] ?? 0));

        if (count($providers) < 2 || $requiredCount < 1) {
            $this->deletePackage($gameId, $packageName);
            $result['cached'] = false;
            return $result;
        }

        $this->persistResult($gameId, $packageName, $result);
        $result['cached'] = true;
        return $result;
    }

    private function deletePackage(int $gameId, string $packageName): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'DELETE FROM ue_package_provider_coverage_cache WHERE game_id=? AND package_name=?'
            )->execute([$gameId, $packageName]);
            $this->db->prepare(
                'DELETE FROM ue_package_coverage_cache WHERE game_id=? AND package_name=?'
            )->execute([$gameId, $packageName]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $result */
    private function persistResult(int $gameId, string $packageName, array $result): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'DELETE FROM ue_package_provider_coverage_cache WHERE game_id=? AND package_name=?'
            )->execute([$gameId, $packageName]);
            $this->db->prepare(
                'INSERT INTO ue_package_coverage_cache('
                . 'game_id,package_name,consumer_count,required_object_count,updated_at'
                . ') VALUES(?,?,?,?,NOW(6)) '
                . 'ON DUPLICATE KEY UPDATE consumer_count=VALUES(consumer_count),'
                . 'required_object_count=VALUES(required_object_count),updated_at=VALUES(updated_at)'
            )->execute([
                $gameId,
                $packageName,
                (int)($result['consumer_count'] ?? 0),
                (int)($result['required_object_count'] ?? 0),
            ]);
            $insert = $this->db->prepare(
                'INSERT INTO ue_package_provider_coverage_cache('
                . 'game_id,package_name,file_id,matched_count,missing_count,'
                . 'fully_satisfies,missing_paths_json,updated_at'
                . ') VALUES(?,?,?,?,?,?,?,NOW(6))'
            );
            foreach ((array)($result['providers'] ?? []) as $provider) {
                $insert->execute([
                    $gameId,
                    $packageName,
                    (int)$provider['file_id'],
                    (int)$provider['matched_count'],
                    (int)$provider['missing_count'],
                    (string)$provider['status'] === 'fully_satisfies' ? 1 : 0,
                    json_encode(
                        array_values((array)($provider['missing_paths'] ?? [])),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param null|callable(int,int,string):void $progress */
    public function rebuildGame(int $gameId, mixed $progress=null): array
    {
        // Revisit both the current multi-provider set and anything already
        // cached. Including old cache names is what prunes packages that ceased
        // to have multiple eligible providers after deletion/demotion.
        $collation = self::TEXT_COLLATION;
        $rows = \catalog_all(
            $this->db,
            'SELECT package_name FROM ('
            . 'SELECT CONVERT(CASE WHEN p.source_kind=2 THEN a.package_name ELSE f.package_name END USING utf8mb4) '
            . 'COLLATE ' . $collation . ' package_name '
            . 'FROM ue_uedb5_provider_keys p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind=2 AND a.id=p.source_id '
            . 'AND a.file_id=p.file_id AND a.game_id=p.game_id '
            . 'WHERE p.game_id=? '
            . 'AND CASE WHEN p.source_kind=2 THEN COALESCE(a.package_name,"") ELSE COALESCE(f.package_name,"") END<>"" '
            . 'GROUP BY package_name HAVING COUNT(DISTINCT p.file_id)>1 '
            . 'UNION '
            . 'SELECT CONVERT(package_name USING utf8mb4) COLLATE ' . $collation . ' package_name '
            . 'FROM ue_package_coverage_cache WHERE game_id=?'
            . ') packages ORDER BY package_name',
            [$gameId, $gameId]
        );
        $done = 0;
        $cached = 0;
        $pruned = 0;
        $total = count($rows);
        foreach ($rows as $row) {
            $packageName = (string)$row['package_name'];
            $result = $this->reconcilePackage($gameId, $packageName);
            $done++;
            if (!empty($result['cached'])) {
                $cached++;
            } else {
                $pruned++;
            }
            if ($progress !== null) {
                $progress($done, $total, $packageName);
            }
        }
        return ['packages' => $done, 'cached' => $cached, 'pruned' => $pruned];
    }
}
