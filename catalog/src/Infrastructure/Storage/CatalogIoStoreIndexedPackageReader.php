<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;

/**
 * Inspects one package directly from a safely retained IoStore container.
 * Does not require a published ue_files row or mutate catalog metadata.
 */
final class CatalogIoStoreIndexedPackageReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $storageRoot,
        private readonly ?string $oodleLibraryPath = null
    ) {}

    /** @return array<string,mixed> */
    public function inspect(int $containerId,int $tocIndex):array
    {
        if($containerId<1||$tocIndex<0)throw new RuntimeException('Invalid IoStore member selection.');
        $containerQ=$this->db->prepare('SELECT id,game_id,name,utoc_relative_path,utoc_sha256,status FROM ue_iostore_containers WHERE id=?');
        $containerQ->execute([$containerId]);
        $container=$containerQ->fetch(PDO::FETCH_ASSOC);
        if(!is_array($container)||(string)$container['status']!=='ready')throw new RuntimeException('IoStore container is not ready.');
        $memberQ=$this->db->prepare('SELECT id,toc_index,HEX(package_id) package_id,chunk_index,logical_size,file_id FROM ue_iostore_packages WHERE container_id=? AND toc_index=?');
        $memberQ->execute([$containerId,$tocIndex]);
        $member=$memberQ->fetch(PDO::FETCH_ASSOC);
        if(!is_array($member))throw new RuntimeException('IoStore member is missing.');
        $root=realpath($this->storageRoot);
        if($root===false)throw new RuntimeException('Configured IoStore storage is missing.');
        $safePath=static function(string $relative)use($root):string {
            $relative=str_replace('\\','/',$relative);
            if(!str_starts_with($relative,'games/')||str_contains($relative,'..')||str_contains($relative,':')) {
                throw new RuntimeException('Invalid retained IoStore relative path.');
            }
            $path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
            if($path===false||!str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower(str_replace('\\','/',$root)).'/')||!is_file($path)||is_link($path)){
                throw new RuntimeException('Retained IoStore file is outside configured storage.');
            }
            return $path;
        };
        $tocPath=$safePath((string)$container['utoc_relative_path']);
        $toc=new Uedb5IoStoreTocReader($tocPath,null,$this->oodleLibraryPath);
        if(!hash_equals(strtolower((string)$container['utoc_sha256']),strtolower($toc->tocSha256())))throw new RuntimeException('Retained IoStore TOC was modified.');
        $parts=$this->db->prepare('SELECT part_index,relative_path,file_size FROM ue_iostore_container_parts WHERE container_id=? ORDER BY part_index');
        $parts->execute([$containerId]);$partRows=$parts->fetchAll(PDO::FETCH_ASSOC);
        if(count($partRows)!==(int)$toc->header()['partition_count'])throw new RuntimeException('Incomplete IoStore part manifest.');
        foreach($partRows as $expectedIndex=>$row){
            if((int)$row['part_index']!==$expectedIndex)throw new RuntimeException('IoStore partitions are not contiguous.');
            $part=$safePath((string)$row['relative_path']);
            if(filesize($part)!==(int)$row['file_size'])throw new RuntimeException('Retained IoStore data partition size changed.');
        }
        $chunk=$toc->chunks()[$tocIndex]??null;
        if(!is_array($chunk)||((int)$chunk['chunk_type']!==Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA)
            ||strtoupper((string)$chunk['package_id'])!==(string)$member['package_id'])throw new RuntimeException('IoStore member does not match indexed TOC.');
        $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,(string)$member['package_id'],[
            'id'=>(int)($member['file_id']?:$member['id']),
            'game_id'=>(int)$container['game_id'],
            'original_name'=>(string)$container['name'],
            'package_name'=>''
        ]);
        return [
            'container_id'=>$containerId,
            'toc_index'=>$tocIndex,
            'package_id'=>(string)$member['package_id'],
            'package_name'=>(string)($snapshot['file']['package_name']??''),
            'file_id'=>$member['file_id']===null?null:(int)$member['file_id'],
            'name_count'=>count($snapshot['sections']['name_map']??[]),
            'imports'=>count($snapshot['sections']['imports']??[]),
            'exports'=>count($snapshot['sections']['exports']??[]),
            'dependency_bundles'=>count($snapshot['sections']['dependency_bundle_entries']??[]),
            'logical_size'=>(int)$member['logical_size']
        ];
    }
}
