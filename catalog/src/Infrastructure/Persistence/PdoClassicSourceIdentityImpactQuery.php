<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;

/** Bounded SQL-only impact proof for source-identity dependency-policy transitions. */
final class PdoClassicSourceIdentityImpactQuery
{
    private const CHUNK = 250;
    private const OLD_POLICIES = ['uedb5-dependency-pass-v1','uedb5-dependency-pass-v2','uedb5-dependency-pass-v3','uedb5-dependency-pass-v4','uedb5-dependency-pass-v5','uedb5-dependency-pass-v6','uedb5-dependency-pass-v7','uedb5-dependency-pass-v8'];
    private const TRIM_HEX = ['20','09','0A','0D','00','0B'];
    private const UE5_CLASSIC_FAMILY = 'classic-linkerload';

    public function __construct(private readonly PDO $db) {}

    /** @return list<array{file_id:int,game_id:int,dependency_policy:string,engine_key:string,package_family:string}> */
    public function currentOldPolicyFiles(int $gameId = 0): array
    {
        $in=implode(',',array_fill(0,count(self::OLD_POLICIES),'?'));
        $sql='SELECT s.file_id,s.game_id,s.dependency_policy,UPPER(COALESCE(p.engine_key,"")) engine_key,v.package_family '
            .'FROM ue_uedb5_migration_status s '
            .'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
            .'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
            .'JOIN ue_games g ON g.id=s.game_id '
            .'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            .'WHERE f.scan_status="verified" AND s.dependency_policy IN ('.$in.') '
            .'AND s.dependency_payload_sha256=v.payload_sha256';
        $args=self::OLD_POLICIES;
        if($gameId>0){$sql.=' AND s.game_id=?';$args[]=$gameId;}
        $sql.=' ORDER BY s.game_id,s.file_id';
        $s=$this->db->prepare($sql);$s->execute($args);$out=[];
        while($r=$s->fetch(PDO::FETCH_ASSOC))$out[]=[
            'file_id'=>(int)$r['file_id'],'game_id'=>(int)$r['game_id'],
            'dependency_policy'=>(string)$r['dependency_policy'],'engine_key'=>(string)$r['engine_key'],
            'package_family'=>(string)($r['package_family']??''),
        ];
        return$out;
    }

    /**
     * @param list<int> $candidateFileIds
     * @return array{file_ids_by_game:array<int,list<int>>,reasons_by_file:array<int,array<string,true>>,reason_counts:array<string,int>,total:int,candidate_count:int}
     */
    public function run(int $gameId = 0, array $candidateFileIds = []): array
    {
        $rows=$this->currentOldPolicyFiles($gameId);
        if($candidateFileIds!==[]){$wanted=array_fill_keys(array_map('intval',$candidateFileIds),true);$rows=array_values(array_filter($rows,static fn(array$r):bool=>isset($wanted[(int)$r['file_id']])));}
        $fileMeta=[];foreach($rows as$r)$fileMeta[(int)$r['file_id']]=['game_id'=>(int)$r['game_id'],'engine_key'=>(string)$r['engine_key'],'package_family'=>(string)$r['package_family']];
        $candidateIds=array_map('intval',array_keys($fileMeta));
        if($candidateIds===[])return['file_ids_by_game'=>[],'reasons_by_file'=>[],'reason_counts'=>[],'total'=>0,'candidate_count'=>0];

        $relations=$this->providerRelations($candidateIds);$reasons=[];
        // Section 1 provider-environment ambiguity applies to every package family.
        $byConsumerKey=[];foreach($relations as$r){$fid=(int)$r['consumer_file_id'];$key=(int)$r['package_key_kind'].':'.(string)$r['package_key_hex'];$byConsumerKey[$fid][$key][(int)$r['provider_file_id']]=true;}
        foreach($byConsumerKey as$fid=>$keys){foreach($keys as$providers){if(count($providers)>1){$reasons[(int)$fid]['provider_environment_ambiguity']=true;break;}}}

        $classicIds=[];$modernIds=[];
        foreach($fileMeta as$fid=>$meta){
            if(!self::isClassicEngine($meta['engine_key'],$meta['package_family']))continue;
            $classicIds[]=(int)$fid;
            if(self::isModernClassic($meta['engine_key'],$meta['package_family']))$modernIds[]=(int)$fid;
        }
        // Raw projected import identity catches ObjectName/ClassName/ClassPackage differences for all classic engines.
        foreach($this->sensitiveConsumerFiles($classicIds)as$fid)$reasons[$fid]['consumer_fname_normalization']=true;
        // UEDB4 does not project UE4/UE5 explicit PackageName. Any trim-sensitive NameMap term in a modern consumer is conservatively impacted.
        foreach($this->sensitiveNameMapFiles($modernIds)as$fid)$reasons[$fid]['consumer_modern_namemap_normalization']=true;

        $classicSet=array_fill_keys($classicIds,true);$providerIds=[];$providerNameSensitive=[];$consumerProviders=[];
        foreach($relations as$r){$consumer=(int)$r['consumer_file_id'];if(!isset($classicSet[$consumer]))continue;$provider=(int)$r['provider_file_id'];$providerIds[$provider]=true;$consumerProviders[$consumer][$provider]=true;if(self::trimWouldChange((string)$r['provider_name']))$providerNameSensitive[$provider]=true;}
        $providerSensitive=$this->sensitiveNameMapFiles(array_map('intval',array_keys($providerIds)));$providerSensitiveSet=array_fill_keys($providerSensitive,true)+$providerNameSensitive;
        foreach($consumerProviders as$consumer=>$providers){foreach(array_keys($providers)as$provider){if(isset($providerSensitiveSet[(int)$provider])){$reasons[(int)$consumer]['provider_fname_normalization']=true;break;}}}

        $byGame=[];$reasonCounts=[];foreach($reasons as$fid=>$set){if(!isset($fileMeta[$fid]))continue;$gid=(int)$fileMeta[$fid]['game_id'];$byGame[$gid][]=(int)$fid;foreach(array_keys($set)as$reason)$reasonCounts[$reason]=($reasonCounts[$reason]??0)+1;}
        foreach($byGame as&$ids){$ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);}unset($ids);ksort($byGame,SORT_NUMERIC);ksort($reasonCounts,SORT_STRING);
        return['file_ids_by_game'=>$byGame,'reasons_by_file'=>$reasons,'reason_counts'=>$reasonCounts,'total'=>count($reasons),'candidate_count'=>count($candidateIds)];
    }

    public static function isModernClassic(string $engineKey,string $packageFamily):bool
    {
        $engineKey=strtoupper($engineKey);
        return $engineKey==='UE4'||($engineKey==='UE5'&&$packageFamily===self::UE5_CLASSIC_FAMILY);
    }
    public static function isClassicEngine(string $engineKey,string $packageFamily):bool
    {
        $engineKey=strtoupper($engineKey);
        return in_array($engineKey,['UE1','UE2','UE3','UE4'],true)||($engineKey==='UE5'&&$packageFamily===self::UE5_CLASSIC_FAMILY);
    }

    /** @param list<int> $fileIds @return list<array<string,mixed>> */
    private function providerRelations(array $fileIds): array
    {
        $out=[];foreach(array_chunk($fileIds,self::CHUNK)as$chunk){if($chunk===[])continue;$in=implode(',',array_fill(0,count($chunk),'?'));
            $sql='SELECT DISTINCT e.file_id consumer_file_id,cf.game_id,e.required_package_key_kind,HEX(e.required_package_key) package_key_hex,'
                .'p.file_id provider_file_id,p.source_kind,p.source_id,CASE WHEN p.source_kind=2 THEN COALESCE(a.package_name,"") ELSE pv.package_name END provider_name '
                .'FROM ue_uedb5_dependency_edges e JOIN ue_uedb5_files cf ON cf.file_id=e.file_id '
                .'JOIN ue_uedb5_provider_keys p ON p.game_id=cf.game_id AND p.package_key_kind=e.required_package_key_kind AND p.package_key=e.required_package_key '
                .'JOIN ue_uedb5_files pv ON pv.file_id=p.file_id AND pv.game_id=p.game_id JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id AND pf.scan_status="verified" '
                .'LEFT JOIN ue_file_package_aliases a ON p.source_kind=2 AND a.id=p.source_id AND a.file_id=p.file_id AND a.game_id=p.game_id '
                .'WHERE e.file_id IN ('.$in.') AND e.required_package_key IS NOT NULL '
                .'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad WHERE bad.file_size=pf.file_size AND bad.md5=LOWER(pf.md5) AND bad.sha1=LOWER(pf.sha1)) '
                .'AND ((p.source_kind=1 AND p.source_id=p.file_id) OR (p.source_kind=2 AND a.id IS NOT NULL))';
            $s=$this->db->prepare($sql);$s->execute($chunk);while($r=$s->fetch(PDO::FETCH_ASSOC))$out[]=$r;
        }return$out;
    }
    /** @param list<int> $fileIds @return list<int> */
    private function sensitiveConsumerFiles(array $fileIds): array
    {
        if($fileIds===[])return[];$hit=[];$expr=self::trimSensitiveSql('t.value_prefix');foreach(array_chunk($fileIds,self::CHUNK)as$chunk){$in=implode(',',array_fill(0,count($chunk),'?'));foreach(['import_object_term_id','import_class_name_term_id','import_class_package_term_id']as$column){$sql='SELECT DISTINCT l.file_id FROM ue_dependency_links l JOIN ue_terms t ON t.id=l.'.$column.' WHERE l.file_id IN ('.$in.') AND '.$expr;$s=$this->db->prepare($sql);$s->execute($chunk);while(($fid=$s->fetchColumn())!==false)$hit[(int)$fid]=true;}}return array_map('intval',array_keys($hit));
    }
    /** @param list<int> $fileIds @return list<int> */
    private function sensitiveNameMapFiles(array $fileIds): array
    {
        if($fileIds===[])return[];$hit=[];$expr=self::trimSensitiveSql('t.value_prefix');foreach(array_chunk($fileIds,self::CHUNK)as$chunk){$in=implode(',',array_fill(0,count($chunk),'?'));$sql='SELECT DISTINCT n.file_id FROM ue_name_lookup n JOIN ue_terms t ON t.id=n.name_term_id WHERE n.file_id IN ('.$in.') AND '.$expr;$s=$this->db->prepare($sql);$s->execute($chunk);while(($fid=$s->fetchColumn())!==false)$hit[(int)$fid]=true;}return array_map('intval',array_keys($hit));
    }
    private static function trimSensitiveSql(string $column): string
    {
        $hex=implode(',',array_map(static fn(string$h):string=>"'".$h."'",self::TRIM_HEX));return 'OCTET_LENGTH('.$column.')>0 AND (HEX(LEFT('.$column.',1)) IN ('.$hex.') OR HEX(RIGHT('.$column.',1)) IN ('.$hex.') OR (t.is_overflow=1 AND OCTET_LENGTH('.$column.')<>t.value_length))';
    }
    private static function trimWouldChange(string $value): bool{return trim($value)!==$value;}
}
