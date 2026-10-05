<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut99SnapshotBuilder;

/** Bounded staged-SQL impact proof for the UE1 VerifyImport policy transition. */
final class PdoUe1VerifyImportImpactQuery
{
    private const CHUNK = 250;

    public function __construct(private readonly PDO $db) {}

    /**
     * @param list<int> $candidateFileIds
     * @return array{reasons_by_file:array<int,array<string,true>>,reason_counts:array<string,int>,total:int,candidate_count:int}
     */
    public function run(array $candidateFileIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$candidateFileIds),static fn(int $id):bool=>$id>0)));
        if($ids===[])return['reasons_by_file'=>[],'reason_counts'=>[],'total'=>0,'candidate_count'=>0];
        $rows=[];
        foreach(array_chunk($ids,self::CHUNK)as$chunk){
            $in=implode(',',array_fill(0,count($chunk),'?'));
            $sql='SELECT v.file_id,v.game_id,v.source_policy,f.package_version,f.licensee_version,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=1) has_import_edges,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=1 AND e.outcome IN (0,4)) has_changeable_edges '
                .'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
                .'JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                .'WHERE v.file_id IN ('.$in.') AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))="UE1"';
            $s=$this->db->prepare($sql);$s->execute($chunk);
            while($r=$s->fetch(PDO::FETCH_ASSOC))$rows[]=$r;
        }
        $reasons=[];
        foreach($rows as$r){
            $fid=(int)$r['file_id'];$policy=(string)$r['source_policy'];$version=(int)($r['package_version']??0);
            if($policy===Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY && $version>0 && $version<50){
                $reasons[$fid]['ue1_pre50_pass1_reparse']=true;
                continue;
            }
            $audited=in_array($policy,[Uedb5UnrealSnapshotBuilder::POLICY_V120_EARLY,Uedb5Ut99SnapshotBuilder::POLICY_RETAIL],true);
            if($audited){
                if((int)$r['has_changeable_edges']===1)$reasons[$fid]['ue1_verifyimport_outcome_change']=true;
                continue;
            }
            if((int)$r['has_import_edges']===1)$reasons[$fid]['ue1_source_implementation_unavailable']=true;
        }
        $counts=[];foreach($reasons as$set)foreach(array_keys($set)as$reason)$counts[$reason]=($counts[$reason]??0)+1;
        ksort($counts,SORT_STRING);
        return['reasons_by_file'=>$reasons,'reason_counts'=>$counts,'total'=>count($reasons),'candidate_count'=>count($ids)];
    }
}
