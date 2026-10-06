<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;

/** Bounded SQL-only impact proof for the UE5 5.8.3 Zen dependency-v3 transition. */
final class PdoUe5ZenDependencyImpactQuery
{
    private const CHUNK = 250;
    public function __construct(private readonly PDO $db) {}

    /** @param list<int> $candidateFileIds */
    public function run(array $candidateFileIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$candidateFileIds),static fn(int$id):bool=>$id>0)));
        if($ids===[])return['reasons_by_file'=>[],'reason_counts'=>[],'total'=>0,'candidate_count'=>0];
        $reasons=[];
        foreach(array_chunk($ids,self::CHUNK)as$chunk){
            $in=implode(',',array_fill(0,count($chunk),'?'));
            $sql='SELECT v.file_id,v.package_family,v.source_policy,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id '
                .'AND e.required_package_key_kind=? AND e.required_package_key IS NOT NULL '
                .'AND e.source_kind IN (?,?,?,?,?,?)) has_package_import_edges '
                .'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
                .'JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                .'WHERE v.file_id IN ('.$in.') AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))="UE5"';
            $args=[
                Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID,
                Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,
                Uedb5SqlProjectionContract::DEP_SOURCE_CELL_IMPORT,
                Uedb5SqlProjectionContract::DEP_SOURCE_LOAD_ORDER,
                Uedb5SqlProjectionContract::DEP_SOURCE_OPTIONAL_IMPORT,
                Uedb5SqlProjectionContract::DEP_SOURCE_OPTIONAL_CELL_IMPORT,
                Uedb5SqlProjectionContract::DEP_SOURCE_OPTIONAL_LOAD_ORDER,
                ...$chunk,
            ];
            $s=$this->db->prepare($sql);$s->execute($args);
            while($row=$s->fetch(PDO::FETCH_ASSOC)){
                $fid=(int)$row['file_id'];
                if((string)$row['package_family']!==Uedb5ZenPackageReader::PACKAGE_FAMILY)continue;
                if((string)$row['source_policy']!==Uedb5ZenPackageReader::SOURCE_POLICY){
                    $reasons[$fid]['ue5_zen_source_implementation_unavailable']=true;continue;
                }
                if((int)$row['has_package_import_edges']===1){
                    // SQL deliberately omits mounted-container context, provider export class indices,
                    // and duplicate export-hash rows. Re-open only consumers whose dependency results
                    // actually contain a Zen PackageImport/cell/import-load-order edge.
                    $reasons[$fid]['ue5_zen_dependency_v3_outcome_change']=true;
                }
            }
        }
        $counts=[];foreach($reasons as$set)foreach(array_keys($set)as$reason)$counts[$reason]=($counts[$reason]??0)+1;ksort($counts,SORT_STRING);
        return['reasons_by_file'=>$reasons,'reason_counts'=>$counts,'total'=>count($reasons),'candidate_count'=>count($ids)];
    }
}
