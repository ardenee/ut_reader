<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ClassicSnapshotBuilder;

/** Bounded staged-SQL impact proof for UE5 5.8.3 classic VerifyImport transition. */
final class PdoUe5ClassicVerifyImportImpactQuery
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
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.source_kind=? AND e.required_object_key IS NOT NULL AND e.outcome IN (?,?)) has_changeable_object_edges,'
                .'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id AND e.resolved_file_id IS NOT NULL AND e.outcome IN (?,?)) has_resolved_provider_edges '
                .'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
                .'JOIN ue_games g ON g.id=v.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
                .'WHERE v.file_id IN ('.$in.') AND f.scan_status="verified" AND UPPER(COALESCE(p.engine_key,""))="UE5"';
            $args=[Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,Uedb5SqlProjectionContract::OUTCOME_MISSING,Uedb5SqlProjectionContract::OUTCOME_UNRESOLVED,Uedb5SqlProjectionContract::OUTCOME_RESOLVED,Uedb5SqlProjectionContract::OUTCOME_PACKAGE_ONLY,...$chunk];
            $s=$this->db->prepare($sql);$s->execute($args);
            while($row=$s->fetch(PDO::FETCH_ASSOC)){
                $fid=(int)$row['file_id'];
                if((string)$row['package_family']!==Uedb5Ue5ClassicSnapshotBuilder::PACKAGE_FAMILY)continue;
                if((string)$row['source_policy']!==Uedb5Ue5ClassicSnapshotBuilder::SOURCE_POLICY){
                    $reasons[$fid]['ue5_classic_source_implementation_unavailable']=true;continue;
                }
                if((int)$row['has_changeable_object_edges']===1)$reasons[$fid]['ue5_classic_verifyimport_outcome_change']=true;
                // v10 adds IsPackageReferenceAllowed from provider package flags. Those flags are authoritative in
                // staged UEDB5 but intentionally not duplicated in the dependency SQL accelerator, so an already-
                // resolved provider relation must be reopened once to prove it remains legal.
                if((int)$row['has_resolved_provider_edges']===1)$reasons[$fid]['ue5_classic_private_package_access_recheck']=true;
            }
        }
        $counts=[];foreach($reasons as$set)foreach(array_keys($set)as$reason)$counts[$reason]=($counts[$reason]??0)+1;ksort($counts,SORT_STRING);
        return['reasons_by_file'=>$reasons,'reason_counts'=>$counts,'total'=>count($reasons),'candidate_count'=>count($ids)];
    }
}
