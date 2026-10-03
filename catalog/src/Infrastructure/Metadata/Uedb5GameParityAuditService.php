<?php
/** Read-only Step 9 game-level behavioural parity audit between live V4 and staged V5. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameMissingDependencyQuery;
use UnrealDb\Catalog\Infrastructure\Search\PdoCatalogSearchRepository;

final class Uedb5GameParityAuditService
{
    private Uedb5ParityV5ReadService $v5;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db,private readonly array $config)
    {
        $this->v5=new Uedb5ParityV5ReadService($db,$config);
    }

    /** @return array<string,mixed> */
    public function preflight(string $slug):array
    {
        $game=$this->game($slug);$gameId=(int)$game['id'];
        foreach(['ue_file_metadata','ue_uedb5_files','ue_uedb5_provider_keys','ue_uedb5_migration_status','ue_invalid_file_identities','ue_dependency_links','ue_uedb5_dependency_edges'] as $table){
            if(!$this->tableExists($table))throw new RuntimeException('Required parity table is missing: '.$table);
        }
        $verified=$this->count('SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified"',[$gameId]);
        $v4=$this->count('SELECT COUNT(*) FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 WHERE f.game_id=? AND f.scan_status="verified"',[$gameId]);
        $v5=$this->count('SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?',[$gameId]);
        $status=array_fill_keys(Uedb5MigrationStatus::values(),0);
        $s=$this->db->prepare('SELECT status,COUNT(*) c FROM ue_uedb5_migration_status WHERE game_id=? GROUP BY status');
        $s->execute([$gameId]);foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$status[(string)$row['status']]=(int)$row['c'];}
        $validated=(int)($status[Uedb5MigrationStatus::VALIDATED]??0);
        $completed=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND s.dependency_policy=? '
            .'AND s.dependency_payload_sha256=v.payload_sha256',
            [$gameId,Uedb5GameDependencyPassService::DEPENDENCY_POLICY]
        );
        $missingPrimary=$this->count(
            'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_uedb5_provider_keys p '
            .'ON p.file_id=f.id AND p.source_kind=1 AND p.source_id=f.id '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND p.file_id IS NULL',[$gameId]
        );
        $invalidStaged=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'JOIN ue_invalid_file_identities bad ON bad.file_size=f.file_size '
            .'AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1) '
            .'WHERE f.game_id=? AND f.scan_status="verified"',[$gameId]
        );
        $ready=$verified>0&&$v4===$verified&&$v5===$verified&&$completed===$verified
            &&$missingPrimary===0&&$invalidStaged===0;
        return [
            'game'=>$game,'verified_count'=>$verified,'live_v4_count'=>$v4,
            'staged_v5_count'=>$v5,'migration_status'=>$status,'validated_count'=>$validated,
            'dependency_complete_count'=>$completed,'missing_primary_provider_count'=>$missingPrimary,
            'invalid_staged_count'=>$invalidStaged,'dependency_policy'=>Uedb5GameDependencyPassService::DEPENDENCY_POLICY,
            'ready'=>$ready,
            'ready_rule'=>'every verified file must retain V4, have staged V5, current Pass-2 dependency payload, primary provider coverage, and zero invalid staged identities',
            'expected_difference_rules'=>Uedb5GameParityExpectedDifferences::rules(),
        ];
    }

    /** @return array<string,mixed> */
    public function audit(string $slug,int $maxDetails=100,int $searchSamples=50,int $relationSamples=500):array
    {
        $preflight=$this->preflight($slug);
        if(empty($preflight['ready'])){
            throw new RuntimeException('Game is not ready for Step 9 parity audit; require full V4/V5 coverage and current completed V5 dependency Pass 2.');
        }
        $game=(array)$preflight['game'];$gameId=(int)$game['id'];
        $maxDetails=max(1,min(5000,$maxDetails));
        $categories=[];
        $categories['dependencies']=$this->auditDependencies($slug,$gameId,$maxDetails);
        $categories['requires_required_by']=$this->auditRelations($gameId,$maxDetails,$relationSamples);
        $categories['base_game_missing']=$this->auditBaseGameMissing($slug,$gameId,$maxDetails);
        $categories['package_aliases']=$this->auditAliases($gameId,$maxDetails);
        $categories['invalid_file_exclusions']=$this->auditInvalidExclusions($gameId,$maxDetails);
        $categories['duplicate_provider_handling']=$this->auditDuplicateProviders($slug,$gameId,$maxDetails);
        $categories['search_results']=$this->auditSearch($gameId,$searchSamples,$maxDetails);
        $categories['verify_import_decisions']=$this->auditVerifyImportDecisions($slug,$gameId,$maxDetails);
        $ok=true;foreach($categories as $result){if(empty($result['ok'])){$ok=false;break;}}
        return ['ok'=>$ok,'preflight'=>$preflight,'categories'=>$categories];
    }
    /** @return array<string,mixed> */
    private function auditDependencies(string $slug,int $gameId,int $maxDetails):array
    {
        $v4Counts=$this->dependencyCountsV4($gameId);
        $v5Counts=$this->dependencyCountsV5($gameId);
        $sql='SELECT f.id file_id,l.import_index source_index,l.status v4_outcome,l.resolved_file_id v4_provider,'
            .'l.resolved_export_index v4_object,e.outcome v5_outcome,e.resolved_file_id v5_provider,'
            .'e.resolved_object_index v5_object '
            .'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            .'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'LEFT JOIN ue_uedb5_dependency_edges e ON e.file_id=l.file_id AND e.source_kind=1 AND e.source_index=l.import_index '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND ('
            .'e.file_id IS NULL OR e.outcome<>l.status OR NOT(e.resolved_file_id<=>l.resolved_file_id) '
            .'OR NOT(e.resolved_object_index<=>l.resolved_export_index)) ORDER BY f.id,l.import_index';
        $s=$this->db->prepare($sql);$s->execute([$gameId]);
        $unexpected=0;$expected=0;$expectedMissingToUnresolved=0;$details=[];
        $providerMismatch=0;$providerUnexpected=0;$objectMismatch=0;$objectUnexpected=0;$privateRows=0;
        while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
            $fileId=(int)$row['file_id'];$index=(int)$row['source_index'];
            $v5=$this->v5->dependenciesByIndex($gameId,$fileId)[$index]??[];
            if(($v5['reason_code']??'')==='private_export_rejected')$privateRows++;
            $v4=['outcome'=>$this->outcomeLabel((int)$row['v4_outcome'])];
            $rule=Uedb5GameParityExpectedDifferences::classify($slug,'dependency_outcome',$v4,$v5);
            if($rule!==null){
                $expected++;$kind='expected_difference';
                if($v4['outcome']==='missing'&&($v5['outcome']??'')==='unresolved')$expectedMissingToUnresolved++;
            }else{$unexpected++;$kind='unexpected';}
            $providerDiff=($row['v4_provider']??null)!==($row['v5_provider']??null);
            $objectDiff=($row['v4_object']??null)!==($row['v5_object']??null);
            if($providerDiff){$providerMismatch++;if($rule===null)$providerUnexpected++;}
            if($objectDiff){$objectMismatch++;if($rule===null)$objectUnexpected++;}
            if(count($details)<$maxDetails){
                $details[]=[
                    'kind'=>$kind,'file_id'=>$fileId,'source_index'=>$index,
                    'v4_outcome'=>$v4['outcome'],'v5_outcome'=>(string)($v5['outcome']??$this->outcomeLabel((int)($row['v5_outcome']??-1))),
                    'v4_provider'=>$row['v4_provider']!==null?(int)$row['v4_provider']:null,
                    'v5_provider'=>$row['v5_provider']!==null?(int)$row['v5_provider']:null,
                    'v4_object'=>$row['v4_object']!==null?(int)$row['v4_object']:null,
                    'v5_object'=>$row['v5_object']!==null?(int)$row['v5_object']:null,
                    'v5_reason_code'=>(string)($v5['reason_code']??''),
                    'expected_rule'=>$rule['id']??null,
                ];
            }
        }
        $v5Only=$this->count(
            'SELECT COUNT(*) FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
            .'LEFT JOIN ue_dependency_links l ON l.file_id=e.file_id AND l.import_index=e.source_index '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND e.source_kind=1 AND l.file_id IS NULL',[$gameId]
        );
        $rowCountParity=($v4Counts['total']??0)===($v5Counts['total']??0);
        $countParity=$v4Counts===$v5Counts;
        $ok=$rowCountParity&&$v5Only===0&&$unexpected===0;
        return [
            'ok'=>$ok,
            'v4_counts'=>$v4Counts,'v5_counts'=>$v5Counts,'count_parity'=>$countParity,
            'expected_difference_count'=>$expected,'unexpected_mismatch_count'=>$unexpected,
             'v5_rows_without_v4_import'=>$v5Only,'provider_selection_mismatch_count'=>$providerMismatch,
            'unexpected_provider_selection_mismatch_count'=>$providerUnexpected,
            'object_coverage_mismatch_count'=>$objectMismatch,'unexpected_object_coverage_mismatch_count'=>$objectUnexpected,
            'expected_missing_to_unresolved_count'=>$expectedMissingToUnresolved,
            'private_verifyimport_difference_rows'=>$privateRows,
            'missing_dependencies'=>[
                'v4'=>(int)($v4Counts['missing']??0),'v5'=>(int)($v5Counts['missing']??0),
                'unresolved_v4'=>(int)($v4Counts['unresolved']??0),'unresolved_v5'=>(int)($v5Counts['unresolved']??0),
            ],
            'details'=>$details,
        ];
    }

    /** @return array<string,int> */
    private function dependencyCountsV4(int $gameId):array
    {
        $counts=$this->emptyOutcomeCounts();
        $s=$this->db->prepare(
            'SELECT l.status,COUNT(*) c FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            .'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'WHERE f.game_id=? AND f.scan_status="verified" GROUP BY l.status'
        );
        $s->execute([$gameId]);foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $label=$this->outcomeLabel((int)$row['status']);$counts[$label]=(int)$row['c'];$counts['total']+=(int)$row['c'];
        }
        return $counts;
    }
    /** @return array<string,int> */
    private function dependencyCountsV5(int $gameId):array
    {
        $counts=$this->emptyOutcomeCounts();
        $s=$this->db->prepare(
            'SELECT e.outcome,COUNT(*) c FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND e.source_kind=1 GROUP BY e.outcome'
        );
        $s->execute([$gameId]);foreach($s->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $label=$this->outcomeLabel((int)$row['outcome']);$counts[$label]=(int)$row['c'];$counts['total']+=(int)$row['c'];
        }
        return $counts;
    }

    /** @return array<string,int> */
    private function emptyOutcomeCounts():array
    {
        return ['total'=>0,'missing'=>0,'resolved'=>0,'package_only'=>0,'common'=>0,'unresolved'=>0];
    }

    private function outcomeLabel(int $code):string
    {
        return match($code){1=>'resolved',2=>'package_only',3=>'common',4=>'unresolved',default=>'missing'};
    }
    /** @return array<string,mixed> */
    private function auditRelations(int $gameId,int $maxDetails,int $relationSamples):array
    {
        $v4Pairs=$this->count(
            'SELECT COUNT(*) FROM (SELECT DISTINCT l.file_id,l.resolved_file_id FROM ue_dependency_links l '
            .'JOIN ue_files f ON f.id=l.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND l.resolved_file_id IS NOT NULL '
            .'AND l.resolved_file_id<>l.file_id AND l.status<>3) q',[$gameId]
        );
        $v5Pairs=$this->count(
            'SELECT COUNT(*) FROM (SELECT DISTINCT e.file_id,e.resolved_file_id FROM ue_uedb5_dependency_edges e '
            .'JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND f.scan_status="verified" '
            .'AND e.resolved_file_id IS NOT NULL AND e.resolved_file_id<>e.file_id AND e.outcome<>3) q',[$gameId]
        );
        $missingV5=$this->count(
            'SELECT COUNT(*) FROM (SELECT DISTINCT l.file_id,l.resolved_file_id FROM ue_dependency_links l '
            .'JOIN ue_files f ON f.id=l.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND l.resolved_file_id IS NOT NULL AND l.resolved_file_id<>l.file_id AND l.status<>3 '
            .'AND NOT EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=l.file_id AND e.resolved_file_id=l.resolved_file_id AND e.outcome<>3)) q',[$gameId]
        );
        $missingV4=$this->count(
            'SELECT COUNT(*) FROM (SELECT DISTINCT e.file_id,e.resolved_file_id FROM ue_uedb5_dependency_edges e '
            .'JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND f.scan_status="verified" '
            .'AND e.resolved_file_id IS NOT NULL AND e.resolved_file_id<>e.file_id AND e.outcome<>3 '
            .'AND NOT EXISTS(SELECT 1 FROM ue_dependency_links l JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=4 '
            .'WHERE l.file_id=e.file_id AND l.resolved_file_id=e.resolved_file_id AND l.status<>3)) q',[$gameId]
        );
        $detailLimit=max(1,min($maxDetails,$relationSamples));$details=[];
        if($missingV5>0){
            $rows=$this->rows(
                'SELECT DISTINCT l.file_id source_file_id,l.resolved_file_id target_file_id,"missing_in_v5" kind '
                .'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
                .'WHERE f.game_id=? AND f.scan_status="verified" AND l.resolved_file_id IS NOT NULL AND l.resolved_file_id<>l.file_id AND l.status<>3 '
                .'AND NOT EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e WHERE e.file_id=l.file_id AND e.resolved_file_id=l.resolved_file_id AND e.outcome<>3) '
                .'ORDER BY l.file_id,l.resolved_file_id LIMIT '.$detailLimit,[$gameId]
            );
            array_push($details,...$rows);
        }
        if($missingV4>0&&count($details)<$detailLimit){
            $remaining=$detailLimit-count($details);
            $rows=$this->rows(
                'SELECT DISTINCT e.file_id source_file_id,e.resolved_file_id target_file_id,"missing_in_v4" kind '
                .'FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
                .'WHERE f.game_id=? AND f.scan_status="verified" AND e.resolved_file_id IS NOT NULL AND e.resolved_file_id<>e.file_id AND e.outcome<>3 '
                .'AND NOT EXISTS(SELECT 1 FROM ue_dependency_links l JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=4 '
                .'WHERE l.file_id=e.file_id AND l.resolved_file_id=e.resolved_file_id AND l.status<>3) '
                .'ORDER BY e.file_id,e.resolved_file_id LIMIT '.$remaining,[$gameId]
            );
            array_push($details,...$rows);
        }
        $packageIdentityMismatch=$this->requiredPackageKeyMismatchCount($gameId);
        return [
            'ok'=>$missingV5===0&&$missingV4===0&&$packageIdentityMismatch===0,
            'v4_requires_pairs'=>$v4Pairs,'v5_requires_pairs'=>$v5Pairs,
            'missing_in_v5'=>$missingV5,'missing_in_v4'=>$missingV4,
            'required_package_identity_mismatch_count'=>$packageIdentityMismatch,
            'note'=>'Required By is the inverse of the same normalized source->target relationship graph; package/alias fallback identity is checked separately.',
            'details'=>$details,
        ];
    }

    private function requiredPackageKeyMismatchCount(int $gameId):int
    {
        return $this->count(
            'SELECT COUNT(*) FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            .'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'JOIN ue_terms t ON t.id=l.required_package_term_id '
            .'JOIN ue_uedb5_dependency_edges e ON e.file_id=l.file_id AND e.source_kind=1 AND e.source_index=l.import_index '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND e.required_package_key_kind=1 '
            .'AND e.required_package_key<>UNHEX(MD5(LOWER(TRIM(CONVERT(t.value_prefix USING utf8mb4)))))',[$gameId]
        );
    }
    /** @return array<string,mixed> */
    private function auditBaseGameMissing(string $slug,int $gameId,int $maxDetails):array
    {
        $names=(new PdoGameMissingDependencyQuery($this->db))->officialBaseGamePackageNames($gameId);
        $v4=$this->baseMissingMap('ue_dependency_package_summaries','required_package',$gameId,$names);
        $v5=$this->baseMissingMap('ue_uedb5_dependency_packages','required_package_name',$gameId,$names);
        $keys=[];foreach($names as $name)$keys[$this->nameKey((string)$name)]=(string)$name;
        $expected=$this->expectedMissingToUnresolvedByPackage($slug,$gameId);
        $mismatches=[];$v4Total=0;$v5Total=0;$expectedTotal=0;$mismatchCount=0;
        foreach($keys as $key=>$display){
            $left=(int)($v4[$key]??0);$right=(int)($v5[$key]??0);$allowed=(int)($expected[$key]??0);
            $v4Total+=$left;$v5Total+=$right;$expectedTotal+=$allowed;
            if($left!==$right+$allowed){
                $mismatchCount++;
                if(count($mismatches)<$maxDetails)$mismatches[]=['package'=>$display,'v4_missing'=>$left,'v5_missing'=>$right,'expected_missing_to_unresolved'=>$allowed];
            }
        }
        return [
            'ok'=>$mismatchCount===0,'official_base_package_count'=>count($keys),
            'v4_missing_imports'=>$v4Total,'v5_missing_imports'=>$v5Total,
            'expected_missing_to_unresolved'=>$expectedTotal,
            'package_mismatch_count'=>$mismatchCount,'details'=>$mismatches,
        ];
    }

    /** @return array<string,int> */
    private function baseMissingMap(string $table,string $column,int $gameId,array $names):array
    {
        $out=[];foreach(array_chunk(array_values($names),400) as $chunk){
            if($chunk===[])continue;$in=implode(',',array_fill(0,count($chunk),'?'));
            $sql='SELECT '.$column.' package_name,SUM(missing_count) c FROM '.$table.' WHERE game_id=? AND '.$column.' IN ('.$in.') GROUP BY '.$column;
            foreach($this->rows($sql,array_merge([$gameId],$chunk)) as $row){$out[$this->nameKey((string)$row['package_name'])]=(int)$row['c'];}
        }
        return $out;
    }
    /** @return array<string,mixed> */
    private function auditAliases(int $gameId,int $maxDetails):array
    {
        $s=$this->db->prepare(
            'SELECT a.id alias_id,a.file_id,a.package_name,p.source_id,p.game_id provider_game_id,p.package_key_kind,p.package_key,p.file_id provider_file_id '
            .'FROM ue_file_package_aliases a JOIN ue_uedb5_files v ON v.file_id=a.file_id AND v.game_id=a.game_id '
            .'LEFT JOIN ue_uedb5_provider_keys p ON p.source_kind=2 AND p.source_id=a.id '
            .'WHERE a.game_id=? ORDER BY a.id'
        );
        $s->execute([$gameId]);$total=0;$mismatch=0;$details=[];
        while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
            $total++;$expected=md5(CatalogUnrealIdentityHash::nameKey((string)$row['package_name']),true);
            $ok=$row['source_id']!==null&&(int)$row['provider_game_id']===$gameId
                &&(int)$row['provider_file_id']===(int)$row['file_id']
                &&(int)$row['package_key_kind']===Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME
                &&hash_equals($expected,(string)$row['package_key']);
            if(!$ok){$mismatch++;if(count($details)<$maxDetails)$details[]=['alias_id'=>(int)$row['alias_id'],'file_id'=>(int)$row['file_id'],'package_name'=>(string)$row['package_name']];}
        }
        $orphan=$this->count(
            'SELECT COUNT(*) FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id '
            .'LEFT JOIN ue_file_package_aliases a ON a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id '
            .'WHERE v.game_id=? AND p.source_kind=2 AND a.id IS NULL',[$gameId]
        );
        $primaryMismatch=$this->count(
            'SELECT COUNT(*) FROM ue_uedb5_files v LEFT JOIN ue_uedb5_provider_keys p '
            .'ON p.source_kind=1 AND p.source_id=v.file_id AND p.file_id=v.file_id '
            .'WHERE v.game_id=? AND (p.file_id IS NULL OR p.package_key_kind<>v.package_key_kind OR p.package_key<>v.package_key)',[$gameId]
        );
        return ['ok'=>$mismatch===0&&$orphan===0&&$primaryMismatch===0,'catalog_alias_count'=>$total,'alias_mismatch_count'=>$mismatch,'orphan_alias_key_count'=>$orphan,'primary_provider_mismatch_count'=>$primaryMismatch,'details'=>$details];
    }
    /** @return array<string,mixed> */
    private function auditInvalidExclusions(int $gameId,int $maxDetails):array
    {
        $base=' FROM ue_files f JOIN ue_invalid_file_identities bad '
            .'ON bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1) ';
        $verifiedInvalid=$this->count('SELECT COUNT(*)'.$base.'WHERE f.game_id=? AND f.scan_status="verified"',[$gameId]);
        $stagedInvalid=$this->count(
            'SELECT COUNT(*)'.$base.'JOIN ue_uedb5_files v ON v.file_id=f.id WHERE f.game_id=?',[$gameId]
        );
        $providerInvalid=$this->count(
            'SELECT COUNT(DISTINCT p.file_id)'.$base.'JOIN ue_uedb5_provider_keys p ON p.file_id=f.id WHERE f.game_id=?',[$gameId]
        );
        $details=[];
        if($verifiedInvalid+$stagedInvalid+$providerInvalid>0){
            $details=$this->rows(
                'SELECT DISTINCT f.id file_id,f.package_name,f.original_name,bad.reason '
                .$base.'WHERE f.game_id=? ORDER BY f.id LIMIT '.$maxDetails,[$gameId]
            );
        }
        return [
            'ok'=>$verifiedInvalid===0&&$stagedInvalid===0&&$providerInvalid===0,
            'verified_invalid_identity_count'=>$verifiedInvalid,
            'staged_invalid_identity_count'=>$stagedInvalid,
            'provider_invalid_identity_count'=>$providerInvalid,
            'details'=>$details,
        ];
    }
    /** @return array<string,mixed> */
    private function auditDuplicateProviders(string $slug,int $gameId,int $maxDetails):array
    {
        $duplicateKeys=$this->count(
            'SELECT COUNT(*) FROM (SELECT package_key_kind,package_key FROM ue_uedb5_provider_keys '
            .'WHERE game_id=? GROUP BY package_key_kind,package_key HAVING COUNT(DISTINCT file_id)>1) d',[$gameId]
        );
        $sql='SELECT e.file_id,e.source_index,e.outcome,e.resolved_file_id v5_provider,l.status v4_outcome,l.resolved_file_id v4_provider,'
            .'HEX(e.required_package_key) package_key_hex '
            .'FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
            .'JOIN (SELECT package_key_kind,package_key FROM ue_uedb5_provider_keys WHERE game_id=? '
            .'GROUP BY package_key_kind,package_key HAVING COUNT(DISTINCT file_id)>1) d '
            .'ON d.package_key_kind=e.required_package_key_kind AND d.package_key=e.required_package_key '
            .'LEFT JOIN ue_dependency_links l ON l.file_id=e.file_id AND l.import_index=e.source_index '
            .'LEFT JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=4 '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND e.source_kind=1 ORDER BY e.file_id,e.source_index';
        $s=$this->db->prepare($sql);$s->execute([$gameId,$gameId]);
        $dependencyRows=0;$mismatch=0;$expected=0;$details=[];$depCache=[];
        while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
            $dependencyRows++;$fileId=(int)$row['file_id'];$index=(int)$row['source_index'];
            $ok=$row['v4_outcome']!==null&&(int)$row['v4_outcome']===(int)$row['outcome']
                &&(($row['v4_provider']===null&&$row['v5_provider']===null)
                ||(int)$row['v4_provider']===(int)$row['v5_provider']);
            $rule=null;
            if(!$ok&&$row['v4_outcome']!==null){
                $depCache[$fileId]??=$this->v5->dependenciesByIndex($gameId,$fileId);
                $v5=(array)($depCache[$fileId][$index]??[]);
                $rule=Uedb5GameParityExpectedDifferences::classify($slug,'dependency_outcome',['outcome'=>$this->outcomeLabel((int)$row['v4_outcome'])],$v5);
            }
            if(!$ok&&$rule===null)$mismatch++;elseif(!$ok)$expected++;
            if(!$ok&&count($details)<$maxDetails){$row['expected_rule']=$rule['id']??null;$details[]=$row;}
        }
        return [
            'ok'=>$mismatch===0,'duplicate_provider_key_count'=>$duplicateKeys,
            'dependencies_using_duplicate_keys'=>$dependencyRows,'selection_mismatch_count'=>$mismatch,
            'expected_difference_count'=>$expected,
            'invariant'=>'one physical provider must independently satisfy a dependency; providers are never merged',
            'details'=>$details,
        ];
    }
    /** @return array<string,mixed> */
    private function auditSearch(int $gameId,int $sampleCount,int $maxDetails):array
    {
        $sampleCount=max(1,min(500,$sampleCount));$perScope=max(1,(int)ceil($sampleCount/3));
        $queries=$this->searchCorpus($gameId,$perScope*4);
        $v4Search=new PdoCatalogSearchRepository($this->db);
        $checked=0;$mismatch=0;$truncated=0;$details=[];$scopeCounts=['names'=>0,'imports'=>0,'exports'=>0];
        foreach($queries as $item){
            $query=(string)$item['query'];$scope=(string)$item['scope'];
            if(($scopeCounts[$scope]??0)>=$perScope)continue;
            $v4Rows=$v4Search->findFiles($query,500,$gameId,['fields'=>[$scope]]);
            $v4=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['id'],$v4Rows)));sort($v4,SORT_NUMERIC);
            $v5=$this->v5->exactMetadataSearch($gameId,$query,[$scope],500);
            if(count($v4)>=500||count($v5)>=500){$truncated++;continue;}
            $checked++;$scopeCounts[$scope]++;
            if($v4!==$v5){
                $mismatch++;
                if(count($details)<$maxDetails)$details[]=[
                    'scope'=>$scope,'query'=>$query,'v4_file_ids'=>$v4,'v5_file_ids'=>$v5,
                    'missing_in_v5'=>array_values(array_diff($v4,$v5)),'missing_in_v4'=>array_values(array_diff($v5,$v4)),
                ];
            }
        }
        $covered=count(array_filter($scopeCounts,static fn(int $n):bool=>$n>0));
        return [
            'ok'=>$covered===3&&$mismatch===0,'requested_samples_per_scope'=>$perScope,'checked_queries'=>$checked,
            'checked_by_scope'=>$scopeCounts,'truncated_queries_skipped'=>$truncated,'mismatch_query_count'=>$mismatch,
            'scope'=>'exact metadata search compared independently for names, imports and exports; candidates are hydrated from UEDB5',
            'details'=>$details,
        ];
    }

    /** @return list<array{scope:string,query:string}> */
    private function searchCorpus(int $gameId,int $perScope):array
    {
        $definitions=[
            'names'=>'SELECT CONVERT(t.value_prefix USING utf8mb4) q FROM ue_name_lookup x JOIN ue_files f ON f.id=x.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 JOIN ue_terms t ON t.id=x.name_term_id WHERE f.game_id=? AND f.scan_status="verified" AND t.value_length BETWEEN 3 AND 200 ORDER BY x.file_id,x.name_index LIMIT '.$perScope,
            'exports'=>'SELECT CONVERT(t.value_prefix USING utf8mb4) q FROM ue_export_lookup x JOIN ue_files f ON f.id=x.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 JOIN ue_terms t ON t.id=x.object_term_id WHERE f.game_id=? AND f.scan_status="verified" AND t.value_length BETWEEN 3 AND 200 ORDER BY x.file_id,x.export_index LIMIT '.$perScope,
            'imports'=>'SELECT CONVERT(t.value_prefix USING utf8mb4) q FROM ue_dependency_links x JOIN ue_files f ON f.id=x.file_id JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 JOIN ue_terms t ON t.id=x.import_object_term_id WHERE f.game_id=? AND f.scan_status="verified" AND t.value_length BETWEEN 3 AND 200 ORDER BY x.file_id,x.import_index LIMIT '.$perScope,
        ];
        $out=[];
        foreach($definitions as $scope=>$sql){
            $seen=[];$s=$this->db->prepare($sql);$s->execute([$gameId]);
            while(($value=$s->fetchColumn())!==false){
                $value=trim((string)$value);if($value==='')continue;$key=$this->nameKey($value);if(isset($seen[$key]))continue;
                $seen[$key]=true;$out[]=['scope'=>$scope,'query'=>$value];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function auditVerifyImportDecisions(string $slug,int $gameId,int $maxDetails):array
    {
        $files=$this->rows('SELECT file_id FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id',[$gameId]);
        $v4=$this->db->prepare('SELECT status,resolved_file_id,resolved_export_index FROM ue_dependency_links WHERE file_id=? AND import_index=? LIMIT 1');
        $private=0;$privateMismatch=0;$expected=0;$details=[];
        foreach($files as $file){
            $fileId=(int)$file['file_id'];
            foreach($this->v5->dependencies($gameId,$fileId) as $row){
                $reason=strtolower((string)($row['reason_code']??''));
                if(!str_contains($reason,'private'))continue;
                $private++;$v4->execute([$fileId,(int)$row['source_index']]);$old=$v4->fetch(PDO::FETCH_ASSOC);
                $oldOutcome=is_array($old)?$this->outcomeLabel((int)$old['status']):'absent';
                $rule=is_array($old)?Uedb5GameParityExpectedDifferences::classify($slug,'dependency_outcome',['outcome'=>$oldOutcome],$row):null;
                $same=is_array($old)&&$oldOutcome===(string)$row['outcome'];
                if(!$same&&$rule===null)$privateMismatch++;elseif(!$same)$expected++;
                if(!$same&&count($details)<$maxDetails)$details[]=[
                    'file_id'=>$fileId,'source_index'=>(int)$row['source_index'],'reason_code'=>$row['reason_code'],
                    'v4_outcome'=>$oldOutcome,'v5_outcome'=>$row['outcome'],'expected_rule'=>$rule['id']??null,
                ];
            }
        }
        $resolvedMismatch=$this->count(
            'SELECT COUNT(*) FROM ue_uedb5_dependency_edges e JOIN ue_files f ON f.id=e.file_id '
            .'LEFT JOIN ue_dependency_links l ON l.file_id=e.file_id AND l.import_index=e.source_index '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND e.source_kind=1 AND e.outcome=1 AND (l.file_id IS NULL OR l.status<>1)',[$gameId]
        );
        return [
            'ok'=>$privateMismatch===0&&$resolvedMismatch===0,'private_rejection_rows'=>$private,
            'private_unexpected_mismatch_count'=>$privateMismatch,'private_expected_difference_count'=>$expected,
            'resolved_acceptance_mismatch_count'=>$resolvedMismatch,'details'=>$details,
        ];
    }
    /** @return array<string,int> */
    private function expectedMissingToUnresolvedByPackage(string $slug,int $gameId):array
    {
        if($slug!=='ut3')return[];
        $sql='SELECT l.file_id,l.import_index,CONVERT(t.value_prefix USING utf8mb4) required_package '
            .'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            .'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            .'JOIN ue_terms t ON t.id=l.required_package_term_id '
            .'JOIN ue_uedb5_dependency_edges e ON e.file_id=l.file_id AND e.source_kind=1 AND e.source_index=l.import_index '
            .'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 AND e.outcome=4 ORDER BY l.file_id,l.import_index';
        $s=$this->db->prepare($sql);$s->execute([$gameId]);$counts=[];$cache=[];
        while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
            $fileId=(int)$row['file_id'];$index=(int)$row['import_index'];
            $cache[$fileId]??=$this->v5->dependenciesByIndex($gameId,$fileId);
            $v5=(array)($cache[$fileId][$index]??[]);
            $rule=Uedb5GameParityExpectedDifferences::classify($slug,'dependency_outcome',['outcome'=>'missing'],$v5);
            if($rule===null)continue;
            $key=$this->nameKey((string)$row['required_package']);
            if($key!=='')$counts[$key]=($counts[$key]??0)+1;
        }
        return $counts;
    }

    /** @return array<string,mixed> */
    private function game(string $slug):array
    {
        $s=$this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1');
        $s->execute([trim($slug)]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new RuntimeException('Unknown game slug: '.$slug);
        return $row;
    }

    /** @param list<mixed> $args */
    private function count(string $sql,array $args=[]):int
    {
        $s=$this->db->prepare($sql);$s->execute($args);return(int)$s->fetchColumn();
    }

    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private function rows(string $sql,array $args=[]):array
    {
        $s=$this->db->prepare($sql);$s->execute($args);return$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function tableExists(string $table):bool
    {
        $s=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $s->execute([$table]);return(int)$s->fetchColumn()>0;
    }

    private function nameKey(string $value):string
    {
        return CatalogUnrealIdentityHash::nameKey($value);
    }
}
