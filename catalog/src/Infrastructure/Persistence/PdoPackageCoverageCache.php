<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

final class PdoPackageCoverageCache
{
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
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ue_package_provider_coverage_cache WHERE game_id=? AND package_name=?')->execute([$gameId,$packageName]);
            $this->db->prepare('INSERT INTO ue_package_coverage_cache(game_id,package_name,consumer_count,required_object_count,updated_at) VALUES(?,?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE consumer_count=VALUES(consumer_count),required_object_count=VALUES(required_object_count),updated_at=VALUES(updated_at)')
                ->execute([$gameId,$packageName,(int)$result['consumer_count'],(int)$result['required_object_count']]);
            $ins=$this->db->prepare('INSERT INTO ue_package_provider_coverage_cache(game_id,package_name,file_id,matched_count,missing_count,fully_satisfies,missing_paths_json,updated_at) VALUES(?,?,?,?,?,?,?,NOW(6))');
            foreach ((array)$result['providers'] as $p) {
                $ins->execute([$gameId,$packageName,(int)$p['file_id'],(int)$p['matched_count'],(int)$p['missing_count'],(string)$p['status']==='fully_satisfies'?1:0,json_encode(array_values((array)($p['missing_paths']??[])),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @param null|callable(int,int,string):void $progress */
    public function rebuildGame(int $gameId, mixed $progress=null): array
    {
        // Drive coverage from the authoritative provider projection rather than
        // ue_files.package_name alone. This includes alias-provided packages and
        // only analyzes logical packages that genuinely have competing files.
        $rows=\catalog_all(
            $this->db,
            'SELECT package_name,COUNT(DISTINCT file_id) providers'
            . ' FROM ue_package_providers WHERE game_id=? AND package_name<>""'
            . ' GROUP BY package_name HAVING COUNT(DISTINCT file_id)>1 ORDER BY package_name',
            [$gameId]
        );
        $done=0;$total=count($rows);
        foreach($rows as $row){
            $this->rebuildPackage($gameId,(string)$row['package_name']);$done++;
            if($progress!==null) $progress($done,$total,(string)$row['package_name']);
        }
        return ['packages'=>$done];
    }
}
