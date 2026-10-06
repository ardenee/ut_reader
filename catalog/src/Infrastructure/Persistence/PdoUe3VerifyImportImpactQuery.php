<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;

/** Bounded staged-SQL impact proof for the UT3 v512 VerifyImport transition. */
final class PdoUe3VerifyImportImpactQuery
{
    private const CHUNK = 250;
    public function __construct(private readonly PDO $db) {}

    /**
     * @param list<int> $candidateFileIds
     * @return array{reasons_by_file:array<int,array<string,true>>,reason_counts:array<string,int>,total:int,candidate_count:int}
     */
    public function run(array $candidateFileIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$candidateFileIds),static fn(int$id):bool=>$id>0)));
        if($ids===[])return['reasons_by_file'=>[],'reason_counts'=>[],'total'=>0,'candidate_count'=>0];
        $rows=[];
        foreach(array_chunk($ids,self::CHUNK)as$chunk){
            $in=implode(',',array_fill(0,count($chunk),'?'));
            $sql='SELECT v.file_id,v.source_policy,f.package_version,f.licensee_version,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=1 AND e.required_object_key IS NOT NULL) has_object_edges,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=1 AND e.required_object_key IS NOT NULL AND e.outcome IN (0,4)) has_changeable_edges '
                .'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
                .'JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                .'WHERE v.file_id IN ('.$in.') AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))="UE3" AND LOWER(COALESCE(g.slug,""))="ut3"';
            $s=$this->db->prepare($sql);$s->execute($chunk);
            while($r=$s->fetch(PDO::FETCH_ASSOC))$rows[]=$r;
        }
        $reasons=[];
        foreach($rows as$r){
            $fid=(int)$r['file_id'];
            if((int)$r['has_object_edges']!==1)continue;
            $version=(int)($r['package_version']??0);$licensee=(int)($r['licensee_version']??0);
            $policy=(string)($r['source_policy']??'');
            if($version===512&&$licensee===0&&$policy===Uedb5Ut3SnapshotBuilder::SOURCE_POLICY){
                if((int)$r['has_changeable_edges']===1)$reasons[$fid]['ue3_ut3_verifyimport_outcome_change']=true;
            }else{
                $reasons[$fid]['ue3_ut3_source_implementation_unavailable']=true;
            }
        }
        $counts=[];foreach($reasons as$set)foreach(array_keys($set)as$reason)$counts[$reason]=($counts[$reason]??0)+1;
        ksort($counts,SORT_STRING);
        return['reasons_by_file'=>$reasons,'reason_counts'=>$counts,'total'=>count($reasons),'candidate_count'=>count($ids)];
    }
}
