<?php
/**
 * Catalogue package-identity coverage, separate from native VerifyImport.
 * A candidate in an indexed, verified file proves existence in this catalogue,
 * NOT an engine runtime mount, compatible class export or loader selection.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;

final class PdoGameIndexedPackageCoverageQuery
{
    public function __construct(private readonly PDO $db) {}

    public static function hasIndexedCandidateSql(string $summaryAlias = 's'): string
    {
        if (!in_array($summaryAlias, ['s','p'], true)) {
            throw new RuntimeException('Unexpected summary SQL alias.');
        }
        return 'EXISTS (SELECT 1 FROM ue_uedb5_provider_keys k '
            . 'JOIN ue_files provider ON provider.id=k.file_id '
            . 'AND provider.game_id=k.game_id AND provider.scan_status="verified" '
            . 'WHERE k.game_id=' . $summaryAlias . '.game_id '
            . 'AND k.package_key_kind=' . $summaryAlias . '.package_key_kind '
            . 'AND k.package_key=' . $summaryAlias . '.package_key '
            . 'AND k.file_id<>' . $summaryAlias . '.file_id)';
    }

    /** @return array<string,int> */
    public function forGame(int $gameId, bool $ue4ScriptModules = false): array
    {
        $available = self::hasIndexedCandidateSql('s');
        $script = $ue4ScriptModules
            ? "s.required_package_name LIKE '/Script/%'" : '0=1';
        $sql = 'SELECT COUNT(*) files_with_dependencies,'
            . 'COALESCE(SUM(x.indexed_refs>0),0) indexed_files,'
            . 'COALESCE(SUM(x.indexed_refs+x.native_refs=x.total_refs),0) no_gaps_files,'
            . 'COALESCE(SUM(x.indexed_refs+x.native_refs<x.total_refs),0) unindexed_files,'
            . 'COALESCE(SUM(x.native_refs>0),0) native_script_files,'
            . 'COALESCE(SUM(x.total_refs),0) total_references,'
            . 'COALESCE(SUM(x.indexed_refs),0) indexed_references,'
            . 'COALESCE(SUM(x.native_refs),0) native_script_references '
            . 'FROM (SELECT s.file_id,SUM(s.dependency_count) total_refs,'
            . 'SUM(CASE WHEN NOT (' . $script . ') AND ' . $available
            . ' THEN s.dependency_count ELSE 0 END) indexed_refs,'
            . 'SUM(CASE WHEN ' . $script
            . ' THEN s.dependency_count ELSE 0 END) native_refs '
            . 'FROM ue_uedb5_dependency_packages s '
            . 'JOIN ue_files consumer ON consumer.id=s.file_id '
            . 'AND consumer.game_id=s.game_id AND consumer.scan_status="verified" '
            . 'WHERE s.game_id=? GROUP BY s.file_id) x';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$gameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach (['files_with_dependencies','indexed_files','no_gaps_files',
            'unindexed_files','native_script_files','total_references',
            'indexed_references','native_script_references'] as $field) {
            $result[$field] = max(0, (int)($row[$field] ?? 0));
        }
        $result['unindexed_references'] =
            max(0, $result['total_references'] - $result['indexed_references']
                - $result['native_script_references']);
        if ($result['indexed_references'] + $result['native_script_references']
                + $result['unindexed_references'] !== $result['total_references']
            || $result['no_gaps_files'] + $result['unindexed_files'] !== $result['files_with_dependencies']) {
            throw new RuntimeException('Invalid classic catalogue-coverage cardinality.');
        }
        return $result;
    }

    /**
     * Read only the currently visible files; do not materialise another
     * projection or accidentally claim these candidates are mounted.
     * @param list<int> $fileIds
     * @return array<int,array{total:int,indexed:int,native_script:int,unindexed:int}>
     */
    public function forFiles(int $gameId, array $fileIds, bool $ue4ScriptModules = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        if (count($ids) > 500) throw new RuntimeException('Unbounded package coverage request.');
        $available = self::hasIndexedCandidateSql('s');
        $script = $ue4ScriptModules
            ? "s.required_package_name LIKE '/Script/%'" : '0=1';
        $sql = 'SELECT s.file_id,SUM(s.dependency_count) total,'
            . 'SUM(CASE WHEN NOT (' . $script . ') AND ' . $available
            . ' THEN s.dependency_count ELSE 0 END) indexed,'
            . 'SUM(CASE WHEN ' . $script
            . ' THEN s.dependency_count ELSE 0 END) native_script '
            . 'FROM ue_uedb5_dependency_packages s '
            . 'JOIN ue_files consumer ON consumer.id=s.file_id '
            . 'AND consumer.game_id=s.game_id AND consumer.scan_status="verified" '
            . 'WHERE s.game_id=? AND s.file_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY s.file_id';
        $stmt=$this->db->prepare($sql);
        $stmt->execute(array_merge([$gameId], $ids));
        $rows=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $total=(int)$row['total'];$indexed=(int)$row['indexed'];
            $native=(int)$row['native_script'];
            if ($total < 0 || $indexed < 0 || $native < 0
                || $indexed+$native > $total) {
                throw new RuntimeException('Invalid indexed-package file counts.');
            }
            $rows[(int)$row['file_id']] = [
                'total'=>$total,'indexed'=>$indexed,'native_script'=>$native,
                'unindexed'=>$total-$indexed-$native,
            ];
        }
        return $rows;
    }

    public static function fileFilterPredicate(string $kind, bool $ue4ScriptModules = false): string
    {
        $indexed = self::hasIndexedCandidateSql('s');
        $script = $ue4ScriptModules
            ? "s.required_package_name LIKE '/Script/%'" : '0=1';
        $packageIndexed = 'NOT (' . $script . ') AND ' . $indexed;
        $gap = 'NOT (' . $script . ') AND NOT ' . $indexed;
        $prefix='SELECT 1 FROM ue_uedb5_dependency_packages s WHERE s.file_id=f.id '
            . 'AND s.game_id=f.game_id AND s.dependency_count>0 AND ';
        return match ($kind) {
            'indexed' => 'EXISTS (' . $prefix . $packageIndexed . ')',
            'unindexed' => 'EXISTS (' . $prefix . $gap . ')',
            'native_script' => 'EXISTS (' . $prefix . $script . ')',
            'no_gaps', 'fully_indexed' => 'EXISTS (' . $prefix . '1=1) AND NOT EXISTS ('
                . $prefix . $gap . ')',
            default => throw new RuntimeException('Unknown indexed package coverage filter.'),
        };
    }
}
