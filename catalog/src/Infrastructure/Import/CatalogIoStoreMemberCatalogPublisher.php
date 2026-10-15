<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

/**
 * Creates a real, hash-verified catalog source file for one retained Zen member
 * and publishes its UEDB5 snapshot using the existing guarded publisher.
 * Source bytes remain in a .zenpkg file; they are never labeled as .uasset.
 */
final class CatalogIoStoreMemberCatalogPublisher
{
    public function __construct(private readonly PDO $db,private readonly string $storageRoot) {}

    /** @return array<string,mixed> */
    public function publish(Uedb5IoStoreTocReader $toc,int $containerId,int $tocIndex):array
    {
        if($this->db->inTransaction())throw new RuntimeException('IoStore member publisher requires its own SQL transaction.');
        $query=$this->db->prepare(
          'SELECT m.id member_id,m.file_id,m.publication_status,HEX(m.package_id) package_id,m.logical_size,'
         .'c.game_id,c.utoc_sha256,c.status container_status,g.slug '
         .'FROM ue_iostore_packages m JOIN ue_iostore_containers c ON c.id=m.container_id '
         .'JOIN ue_games g ON g.id=c.game_id WHERE m.container_id=? AND m.toc_index=?'
        );
        $query->execute([$containerId,$tocIndex]);$member=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($member)||$member['container_status']!=='ready')throw new RuntimeException('IoStore container/member is not ready.');
        if(!hash_equals(strtolower((string)$member['utoc_sha256']),strtolower($toc->tocSha256())))throw new RuntimeException('IoStore source TOC differs from retained container.');
        $chunk=$toc->chunks()[$tocIndex]??null;
        $packageId=(string)$member['package_id'];
        if(!is_array($chunk)||strtoupper((string)$chunk['package_id'])!==$packageId
           ||(int)$chunk['chunk_type']!==Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA)throw new RuntimeException('IoStore TOC member identity disagrees with the index.');
        $gameId=(int)$member['game_id'];
        $memberId=(int)$member['member_id'];
        $slug=(string)$member['slug'];
        $root=rtrim(str_replace(chr(92),'/',$this->storageRoot),'/');
        $relative='games/'.$slug.'/iostore-members/'.strtolower((string)$member['utoc_sha256']).'/'.$packageId.'.zenpkg';
        $destination=$root.'/'.$relative;
        $fileId=(int)($member['file_id']??0);
        if($fileId>0){
            $response=(new CatalogZenIoStorePackagePublisher($this->db,$this->storageRoot))
                ->publish($toc,$packageId,$gameId,$fileId);
            return $response+['member_id'=>$memberId,'source_relative_path'=>$relative,'status'=>'existing'];
        }
        $bytes=$toc->readChunk($tocIndex);
        if(strlen($bytes)!==(int)$member['logical_size'])throw new RuntimeException('Decoded Zen member has incorrect logical size.');
        $md5=md5($bytes);$sha1=sha1($bytes);
        $collision=$this->db->prepare('SELECT id FROM ue_files WHERE game_id=? AND md5=? LIMIT 1');
        $collision->execute([$gameId,$md5]);
        if($collision->fetchColumn()!==false)throw new RuntimeException('A different catalog identity already owns this content MD5; FPackageId must not be merged.');
        $dir=dirname($destination);
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create Zen member source directory.');
        $created=false;
        if(is_file($destination)){
            if(hash_file('sha256',$destination)!==hash('sha256',$bytes))throw new RuntimeException('Existing Zen member source file differs from TOC.');
        }else{
            $temp=$destination.'.tmp.'.bin2hex(random_bytes(8));
            try{
                if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Could not write complete Zen source member.');
                if(hash_file('sha256',$temp)!==hash('sha256',$bytes))throw new RuntimeException('Zen member retained bytes failed verification.');
                if(!rename($temp,$destination))throw new RuntimeException('Zen member atomic retention failed.');
                $created=true;
            }finally{if(is_file($temp))@unlink($temp);}
        }
        $fileId=0;
        try{
            $this->db->beginTransaction();
            $insert=$this->db->prepare(
                'INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,'
                .'detected_engine_key,detection_confidence,file_size,md5,sha1,scan_status,scan_notes) '
                .'VALUES(?,?,?,?,?,?,"UE5","high",?,?,?,"verified",?)'
            );
            $displayName=substr($packageId,0,16);
            $insert->execute([$gameId,$displayName,$packageId.'.zenpkg',basename($destination),'storage/'.$relative,'zenpkg',
                strlen($bytes),$md5,$sha1,'Indexed Zen FPackageId '.$packageId.' in IoStore container #'.$containerId]);
            $fileId=(int)$this->db->lastInsertId();
            $link=$this->db->prepare('UPDATE ue_iostore_packages SET file_id=?,publication_status="indexed" WHERE id=? AND file_id IS NULL');
            $link->execute([$fileId,$memberId]);
            if($link->rowCount()!==1)throw new RuntimeException('IoStore member was claimed by another publisher.');
            $this->db->commit();
            $response=(new CatalogZenIoStorePackagePublisher($this->db,$this->storageRoot))
                ->publish($toc,$packageId,$gameId,$fileId);
            $this->db->prepare('UPDATE ue_iostore_packages SET publication_status="published" WHERE id=? AND file_id=?')
                ->execute([$memberId,$fileId]);
            return $response+['member_id'=>$memberId,'source_relative_path'=>$relative,'file_id'=>$fileId];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            // Once committed, preserve both catalog identity and source bytes for a safe retry.
            if($fileId===0&&$created&&is_file($destination))@unlink($destination);
            throw $e;
        }
    }
}
