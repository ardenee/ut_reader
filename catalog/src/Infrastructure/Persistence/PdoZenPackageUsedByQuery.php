<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;

/**
 * UE5 Zen reverse package references from the original imported FPackageIds.
 *
 * Dependency results can be unresolved for engine mount/loader reasons even
 * where the catalogue has an exact indexed FPackageId. This read-only query
 * deliberately describes source-backed references to a unique physical file,
 * not runtime dependency resolution. All consumer projection rows are gated
 * by current UE5 source policy and exact UEDB5 payload hash.
 */
final class PdoZenPackageUsedByQuery
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @return array{status:string,total:int,page:int,page_size:int,rows:list<array<string,mixed>>}
     */
    public function forFile(int $gameId,int $fileId,int $requestedPage,int $pageSize,bool $includeRows):array
    {
        $empty=['status'=>'not_indexed','total'=>0,'page'=>1,
            'page_size'=>max(1,min(250,$pageSize)),'rows'=>[]];
        if($gameId<1||$fileId<1)return $empty;
        $target=$this->db->prepare(
            'SELECT p.package_id FROM ue_iostore_packages p '
            .'JOIN ue_iostore_containers c ON c.id=p.container_id '
            .'AND c.game_id=? AND c.status="ready" '
            .'JOIN ue_files f ON f.id=p.file_id AND f.game_id=? '
            .'AND f.scan_status="verified" '
            .'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            .'AND v.format_version=5 AND v.package_family="zen-iostore" '
            .'JOIN ue_uedb5_zen_exam_files e ON e.file_id=v.file_id '
            .'AND e.source_sha256=v.payload_sha256 '
            .'WHERE p.file_id=? AND p.chunk_index=0 '
            .'AND p.publication_status="published" LIMIT 1'
        );
        $target->execute([$gameId,$gameId,$fileId]);
        $binaryKey=$target->fetchColumn();
        if(!is_string($binaryKey)||strlen($binaryKey)!==8)return $empty;

        // A duplicate physical provider must not be attributed to this one
        // package when authoritative mount order is unavailable.
        $owners=$this->db->prepare(
            'SELECT COUNT(DISTINCT v.file_id) FROM ue_uedb5_files v '
            .'JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
            .'AND f.scan_status="verified" '
            .'JOIN ue_iostore_packages p ON p.file_id=v.file_id '
            .'AND p.chunk_index=0 AND p.publication_status="published" '
            .'JOIN ue_iostore_containers c ON c.id=p.container_id '
            .'AND c.game_id=v.game_id AND c.status="ready" '
            .'JOIN ue_uedb5_zen_exam_files e ON e.file_id=v.file_id '
            .'AND e.source_sha256=v.payload_sha256 '
            .'WHERE v.game_id=? AND v.package_family="zen-iostore" '
            .'AND v.format_version=5 AND v.package_key_kind=2 '
            .'AND v.package_key=?'
        );
        $owners->execute([$gameId,$binaryKey]);
        if((int)$owners->fetchColumn()!==1){
            $empty['status']='ambiguous';
            return $empty;
        }

        $base='FROM ue_uedb5_dependency_packages d '
            .Uedb5DependencyPublicationScope::joinCurrentPackages('d')
            .'JOIN ue_files f ON f.id=d.file_id '
            .'WHERE d.package_key_kind=2 AND d.package_key=? '
            .'AND d.game_id=? AND f.game_id=? AND d.file_id<>? ';
        $args=[$binaryKey,$gameId,$gameId,$fileId];
        $countQuery=$this->db->prepare('SELECT COUNT(*) '.$base);
        $countQuery->execute($args);
        $total=(int)$countQuery->fetchColumn();
        $size=max(1,min(250,$pageSize));
        $page=max(1,min($requestedPage,(int)max(1,ceil($total/$size))));
        $rows=[];
        if($includeRows&&$total>0){
            $query=$this->db->prepare(
                'SELECT d.file_id, f.original_name,f.package_name,f.file_size,'
                .'f.package_guid,f.md5,f.sha1,d.dependency_count reference_count '
                .$base.' ORDER BY f.package_name,f.id '
                .'LIMIT '.$size.' OFFSET '.(($page-1)*$size)
            );
            $query->execute($args);
            $rows=$query->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        return ['status'=>'current','total'=>$total,'page'=>$page,
            'page_size'=>$size,'rows'=>$rows];
    }
}
