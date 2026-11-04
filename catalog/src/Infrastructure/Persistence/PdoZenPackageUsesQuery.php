<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogZenStaticDependencyLookup;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;

/**
 * Bounded UE5 Zen catalogue Requires from serialized ImportedPackageIds.
 * No game runtime. Multiple physical candidates never become a chosen file.
 */
final class PdoZenPackageUsesQuery
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{status:string,source_total:int,resolved_count:int,rows:list<array<string,mixed>>,truncated:bool} */
    public function forFile(int $gameId,int $fileId,int $limit=200):array
    {
        $empty=['status'=>'stale','source_total'=>0,'resolved_count'=>0,'rows'=>[],'truncated'=>false];
        if($gameId<1||$fileId<1)return $empty;
        $limit=max(1,min(500,$limit));
        $gate=Uedb5DependencyPublicationScope::currentFilePredicate('f');
        $status=$this->db->prepare('SELECT '.$gate.' AS is_current FROM ue_files f '
            .'WHERE f.id=? AND f.game_id=? AND f.scan_status="verified"');
        $status->execute([$fileId,$gameId]);
        if((int)$status->fetchColumn()!==1)return $empty;
        $count=$this->db->prepare('SELECT COUNT(*) FROM ue_uedb5_dependency_packages '
            .'WHERE game_id=? AND file_id=? AND package_key_kind=2');
        $count->execute([$gameId,$fileId]);
        $total=(int)$count->fetchColumn();
        $sql='SELECT HEX(package_key) package_id FROM ue_uedb5_dependency_packages '
            .'WHERE game_id=? AND file_id=? AND package_key_kind=2 '
            .'ORDER BY package_key LIMIT '.$limit;
        $q=$this->db->prepare($sql);
        $q->execute([$gameId,$fileId]);
        $refs=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $matches=(new CatalogZenStaticDependencyLookup($this->db))
            ->forImportedPackages($gameId,$refs);
        $ids=[];
        foreach($matches as $match){
            if(($match['state']??'')==='package_found')$ids[(int)$match['file_id']]=true;
        }
        $rows=[];
        if($ids!==[]){
            $fileIds=array_keys($ids);
            $in=implode(',',array_fill(0,count($fileIds),'?'));
            $find=$this->db->prepare('SELECT id,original_name,package_name,file_size,'
                .'package_guid,md5,sha1 FROM ue_files WHERE game_id=? '
                .'AND scan_status="verified" AND id IN ('.$in.') ORDER BY package_name,id');
            $find->execute(array_merge([$gameId],$fileIds));
            $rows=$find->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        return ['status'=>'current','source_total'=>$total,
            'resolved_count'=>count($rows),'rows'=>$rows,
            'truncated'=>$total>count($refs)];
    }
}
