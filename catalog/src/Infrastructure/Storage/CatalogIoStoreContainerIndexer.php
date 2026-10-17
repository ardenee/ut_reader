<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

/**
 * Indexes a previously retained IoStore TOC and its package IDs. No extraction,
 * publication, or ownership change is performed here.
 */
final class CatalogIoStoreContainerIndexer
{
    public function __construct(private readonly PDO $db) {}

    public static function schemaInstalled(PDO $db): bool
    {
        $q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ue_iostore_containers','ue_iostore_container_parts','ue_iostore_packages')");
        return (int)$q->fetchColumn()===3;
    }

    /** @return array{container_id:int,package_count:int,status:string} */
    public function index(Uedb5IoStoreTocReader $toc,int $gameId,string $relativeTocPath): array
    {
        if ($gameId<1 || !self::schemaInstalled($this->db)) {
            throw new RuntimeException('IoStore schema and a valid game identity are required.');
        }
        if (preg_match('~^(?:[a-zA-Z]:|/|\\\\)|(?:^|/)\.\.(?:/|$)~',str_replace('\\','/',$relativeTocPath))===1
            || !str_ends_with(strtolower($relativeTocPath),'.utoc')) {
            throw new RuntimeException('Retained IoStore TOC path must be relative and end in .utoc.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('IoStore indexer must own its database transaction.');
        }
        $head=$toc->header();$sha=strtolower($toc->tocSha256());
        $packages=[];
        foreach ($toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA) as $idx) {
            $chunk=$toc->chunks()[$idx];
            $id=strtoupper((string)$chunk['package_id']);
            if(preg_match('/^[0-9A-F]{16}$/',$id)!==1)throw new RuntimeException('Malformed IoStore FPackageId.');
            $packages[]=[$idx,hex2bin($id),(int)$chunk['chunk_index'],(int)$chunk['length']];
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT id,container_id,toc_version,container_flags,package_count FROM ue_iostore_containers WHERE game_id=? AND utoc_sha256=? FOR UPDATE');
            $q->execute([$gameId,$sha]);$found=$q->fetch(PDO::FETCH_ASSOC);
            if ($found!==false) {
                if ((string)$found['container_id']!==(string)$head['container_id']
                    ||(int)$found['toc_version']!==(int)$head['version']
                    ||(int)$found['container_flags']!==(int)$head['container_flags']
                    ||(int)$found['package_count']!==count($packages)) {
                    throw new RuntimeException('Existing IoStore container identity differs from the retained TOC; explicit repair required.');
                }
                // A matching SHA and row count do not prove that the stored
                // per-chunk identities still match that TOC. Lock and compare
                // every persisted member before accepting a retry.
                $members=$this->db->prepare('SELECT toc_index,package_id,chunk_index,logical_size FROM ue_iostore_packages WHERE container_id=? ORDER BY toc_index FOR UPDATE');
                $members->execute([(int)$found['id']]);
                foreach ($packages as [$idx,$id,$chunkIdx,$bytes]) {
                    $stored=$members->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($stored)||(int)$stored['toc_index']!==$idx
                        ||!hash_equals($id,(string)$stored['package_id'])
                        ||(int)$stored['chunk_index']!==$chunkIdx
                        ||(int)$stored['logical_size']!==$bytes) {
                        throw new RuntimeException('Existing IoStore member identity differs from the retained TOC; explicit repair required.');
                    }
                }
                if ($members->fetch(PDO::FETCH_ASSOC)!==false) {
                    throw new RuntimeException('Existing IoStore index has additional members; explicit repair required.');
                }
                $this->db->commit();
                return ['container_id'=>(int)$found['id'],'package_count'=>count($packages),'status'=>'existing'];
            }
            $insert=$this->db->prepare("INSERT INTO ue_iostore_containers (game_id,name,utoc_sha256,utoc_relative_path,container_id,toc_version,container_flags,package_count,status) VALUES (?,?,?,?,?,?,?,?, 'indexed')");
            $insert->execute([$gameId,$toc->utocFilename(),$sha,$relativeTocPath,(string)$head['container_id'],(int)$head['version'],(int)$head['container_flags'],count($packages)]);
            $containerId=(int)$this->db->lastInsertId();
            $entry=$this->db->prepare('INSERT INTO ue_iostore_packages (container_id,toc_index,package_id,chunk_index,logical_size) VALUES (?,?,?,?,?)');
            foreach ($packages as [$idx,$id,$chunkIdx,$bytes])$entry->execute([$containerId,$idx,$id,$chunkIdx,$bytes]);
            $this->db->commit();
            return ['container_id'=>$containerId,'package_count'=>count($packages),'status'=>'indexed'];
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
