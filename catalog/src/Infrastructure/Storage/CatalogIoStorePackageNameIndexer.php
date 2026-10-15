<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;

/** Bounded authoritative Zen package-name and object-count enrichment. */
final class CatalogIoStorePackageNameIndexer
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot,
        private readonly ?string $oodleLibraryPath=null
    ) {}

    /** @return array{processed:int,failed:int,remaining:int,total:int} */
    public function indexBatch(int $containerId,int $limit=100,?callable $tick=null):array
    {
        if($containerId<1)throw new RuntimeException('IoStore container ID must be positive.');
        $limit=max(1,min(250,$limit));
        $q=$this->db->prepare('SELECT game_id,utoc_relative_path,utoc_sha256,status FROM ue_iostore_containers WHERE id=?');
        $q->execute([$containerId]);$container=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($container)||$container['status']!=='ready')throw new RuntimeException('IoStore container must be ready before name indexing.');
        $root=realpath($this->storageRoot);
        $relative=str_replace(chr(92),'/',(string)$container['utoc_relative_path']);
        if($root===false||!str_starts_with($relative,'games/')||str_contains($relative,'..')||str_contains($relative,':')) {
            throw new RuntimeException('Unsafe retained IoStore TOC path.');
        }
        $path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
        if($path===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$path)),strtolower(str_replace(chr(92),'/',$root)).'/')) {
            throw new RuntimeException('IoStore TOC escaped configured storage.');
        }
        $toc=new Uedb5IoStoreTocReader($path,null,$this->oodleLibraryPath);
        if(!hash_equals(strtolower((string)$container['utoc_sha256']),strtolower($toc->tocSha256()))) {
            throw new RuntimeException('Retained IoStore TOC integrity mismatch.');
        }
        $partQuery=$this->db->prepare('SELECT part_index,relative_path,file_size FROM ue_iostore_container_parts WHERE container_id=? ORDER BY part_index');
        $partQuery->execute([$containerId]);$parts=$partQuery->fetchAll(PDO::FETCH_ASSOC);
        if(count($parts)!==(int)$toc->header()['partition_count'])throw new RuntimeException('Incomplete IoStore data partition manifest.');
        foreach($parts as $i=>$part){
            $partRelative=str_replace(chr(92),'/',(string)$part['relative_path']);
            if((int)$part['part_index']!==$i||!str_starts_with($partRelative,'games/')
                ||str_contains($partRelative,'..')||str_contains($partRelative,':'))throw new RuntimeException('Unsafe IoStore part.');
            $resolved=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$partRelative));
            if($resolved===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$resolved)),strtolower(str_replace(chr(92),'/',$root)).'/')
                ||filesize($resolved)!==(int)$part['file_size'])throw new RuntimeException('IoStore partition is missing or its size changed.');
        }
        $members=$this->db->prepare('SELECT id,toc_index,HEX(package_id) package_id FROM ue_iostore_packages WHERE container_id=? AND metadata_status="indexed" ORDER BY toc_index LIMIT '.$limit);
        $members->execute([$containerId]);
        $pending=$members->fetchAll(PDO::FETCH_ASSOC);
        $ok=$this->db->prepare('UPDATE ue_iostore_packages SET package_name=?,name_count=?,import_count=?,export_count=?,dependency_count=?,metadata_error=NULL,metadata_status="ready" WHERE id=? AND container_id=? AND metadata_status="indexed"');
        $bad=$this->db->prepare('UPDATE ue_iostore_packages SET metadata_status="failed",metadata_error=? WHERE id=? AND container_id=? AND metadata_status="indexed"');
        $failures=0;$processed=0;
        foreach($pending as $item){
            try{
                $idx=(int)$item['toc_index'];$packageId=(string)$item['package_id'];
                $chunk=$toc->chunks()[$idx]??null;
                if(!is_array($chunk)||$chunk['package_id']!==$packageId||(int)$chunk['chunk_type']!==Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA) {
                    throw new RuntimeException('Zen index differs from verified TOC.');
                }
                $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$packageId,[
                    'id'=>(int)$item['id'],'game_id'=>(int)$container['game_id'],
                    'original_name'=>basename($path),'package_name'=>''
                ]);
                $name=(string)($snapshot['file']['package_name']??'');
                if($name===''||strlen($name)>1024)throw new RuntimeException('Missing or oversized Zen package name.');
                $sections=(array)($snapshot['sections']??[]);
                $ok->execute([$name,count($sections['name_map']??[]),count($sections['imports']??[]),
                    count($sections['exports']??[]),count($sections['dependency_bundle_entries']??[]),
                    (int)$item['id'],$containerId]);
            }catch(\Throwable $error){
                $failures++;
                $bad->execute([substr($error->getMessage(),0,1024),(int)$item['id'],$containerId]);
            }
            $processed++;
            if($tick!==null)$tick();
        }
        $st=$this->db->prepare('SELECT COUNT(*) total,SUM(metadata_status="indexed") remaining FROM ue_iostore_packages WHERE container_id=?');
        $st->execute([$containerId]);$counts=$st->fetch(PDO::FETCH_ASSOC);
        return ['processed'=>$processed,'failed'=>$failures,
            'remaining'=>(int)($counts['remaining']??0),'total'=>(int)($counts['total']??0)];
    }
}
