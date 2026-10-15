<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

/** Retains and hashes a complete IoStore TOC+data family before indexing. */
final class CatalogIoStoreContainerStorage
{
    public function __construct(private readonly PDO $db,private readonly string $storageRoot) {}

    /** @return array<string,mixed> */
    public function import(int $gameId,string $slug,string $sourceToc,?string $aesKey=null,?string $oodlePath=null):array
    {
        if($gameId<1||preg_match('/^[a-zA-Z0-9_-]+$/',$slug)!==1)throw new RuntimeException('Invalid game storage identity.');
        if($this->db->inTransaction())throw new RuntimeException('IoStore storage import must own SQL transactions.');
        if(!is_file($sourceToc)||is_link($sourceToc)||!str_ends_with(strtolower($sourceToc),'.utoc'))throw new RuntimeException('Valid .utoc is required.');
        $toc=new Uedb5IoStoreTocReader($sourceToc,$aesKey,$oodlePath);
        $head=$toc->header();$count=(int)$head['partition_count'];
        if($count<1||$count>128)throw new RuntimeException('Invalid IoStore partition count.');
        $base=substr($sourceToc,0,-5);
        $sources=[$sourceToc];$partNames=[];
        for($i=0;$i<$count;$i++){
            $path=$base.($i?'_s'.$i:'').'.ucas';
            if(!is_file($path)||is_link($path))throw new RuntimeException('Missing IoStore data partition '.$i);
            $sources[]=$path;$partNames[]=$path;
        }
        $sha=strtolower($toc->tocSha256());
        $root=rtrim(str_replace('\\','/',$this->storageRoot),'/');
        $relative='games/'.$slug.'/iostore/'.$sha;
        $destination=$root.'/'.$relative;
        if(!is_dir($destination)&&!mkdir($destination,0700,true)&&!is_dir($destination))throw new RuntimeException('Could not create IoStore storage.');
        $created=[];
        $indexedContainerId=0;
        try{
            foreach($sources as $path){
                $target=$destination.'/'.basename($path);
                $size=filesize($path);
                $digest=hash_file('sha256',$path);
                if($size===false||!is_string($digest))throw new RuntimeException('Source digest unavailable.');
                if(is_file($target)){
                    if(filesize($target)!==$size||hash_file('sha256',$target)!==$digest)throw new RuntimeException('Retained IoStore file differs from source.');
                    continue;
                }
                $temp=$target.'.tmp.'.bin2hex(random_bytes(8));
                if(!copy($path,$temp))throw new RuntimeException('IoStore retention copy failed.');
                $created[]=$temp;
                if(filesize($temp)!==$size||hash_file('sha256',$temp)!==$digest)throw new RuntimeException('IoStore retained copy failed SHA-256 verification.');
                if(!rename($temp,$target))throw new RuntimeException('IoStore retention final rename failed.');
                $created[]=$target;
            }
            $retainedToc=new Uedb5IoStoreTocReader($destination.'/'.basename($sourceToc),$aesKey,$oodlePath);
            if($retainedToc->tocSha256()!==$sha)throw new RuntimeException('Retained IoStore TOC hash mismatch.');
            $indexed=(new CatalogIoStoreContainerIndexer($this->db))->index($retainedToc,$gameId,$relative.'/'.basename($sourceToc));
            $containerId=(int)$indexed['container_id'];
            $indexedContainerId=$containerId;
            $existing=$this->db->prepare('SELECT COUNT(*) FROM ue_iostore_container_parts WHERE container_id=?');
            $existing->execute([$containerId]);$previous=(int)$existing->fetchColumn();
            if($previous!==0&&$previous!==$count)throw new RuntimeException('Existing IoStore part manifest is incomplete.');
            $this->db->beginTransaction();
            $insert=$this->db->prepare('INSERT INTO ue_iostore_container_parts (container_id,part_index,relative_path,file_size,sha256) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
            foreach($partNames as $index=>$path){
                $retained=$destination.'/'.basename($path);
                $expectedPath=$relative.'/'.basename($path);
                $expectedSize=(int)filesize($retained);
                $expectedHash=(string)hash_file('sha256',$retained);
                $insert->execute([$containerId,$index,$expectedPath,$expectedSize,$expectedHash]);
                $verify=$this->db->prepare('SELECT relative_path,file_size,sha256 FROM ue_iostore_container_parts WHERE container_id=? AND part_index=?');
                $verify->execute([$containerId,$index]);
                $actual=$verify->fetch(PDO::FETCH_ASSOC);
                if (!is_array($actual)||$actual['relative_path']!==$expectedPath||(int)$actual['file_size']!==$expectedSize||$actual['sha256']!==$expectedHash) {
                    throw new RuntimeException('IoStore retained part manifest differs from verified bytes.');
                }
            }
            $this->db->prepare('UPDATE ue_iostore_containers SET utoc_relative_path=?,status="ready",error_text=NULL WHERE id=?')
                ->execute([$relative.'/'.basename($sourceToc),$containerId]);
            $this->db->commit();
            return $indexed+['retained_parts'=>$count,'retention_status'=>'ready','utoc_relative_path'=>$relative.'/'.basename($sourceToc)];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            // Indexed containers retain their bytes for repair, even if part registration fails.
            if($indexedContainerId>0){
                $this->db->prepare('UPDATE ue_iostore_containers SET status="failed",error_text=? WHERE id=?')
                    ->execute([substr($e->getMessage(),0,2000),$indexedContainerId]);
            }else{
                foreach($created as $path)if(is_file($path))@unlink($path);
            }
            throw $e;
        }
    }
}
