<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5BaseProjectionPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5MigrationStatusRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;

/** Stable-ID Zen metadata repair from the authoritative retained IoStore bytes. */
final class CatalogIoStoreMemberReimportService
{
    public function __construct(private readonly PDO $db,private readonly string $storageRoot) {}

    /** @return array<string,mixed> */
    public function reimport(int $fileId):array
    {
        if($fileId<1||$this->db->inTransaction())throw new RuntimeException('Zen reimport requires a file ID and its own transaction.');
        $q=$this->db->prepare('SELECT f.game_id,f.package_name,f.original_name,f.relative_path,f.file_size,f.md5,f.sha1,'
            .'c.id container_id,c.utoc_relative_path,c.utoc_sha256,c.status container_status,g.slug,'
            .'m.toc_index,HEX(m.package_id) package_id,p.engine_key '
            .'FROM ue_files f JOIN ue_iostore_packages m ON m.file_id=f.id '
            .'JOIN ue_iostore_containers c ON c.id=m.container_id AND c.game_id=f.game_id '
            .'JOIN ue_games g ON g.id=f.game_id '
            .'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            .'WHERE f.id=? AND f.scan_status="verified" AND f.extension="zenpkg"');
        $q->execute([$fileId]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($matches)!==1)throw new RuntimeException('Zen catalog file must belong to exactly one indexed IoStore member.');
        $data=$matches[0];
        if($data['container_status']!=='ready'||!str_starts_with(strtoupper((string)$data['engine_key']),'UE5'))
            throw new RuntimeException('Zen reimport requires a retained ready container and active UE5 profile.');
        $root=realpath($this->storageRoot);
        $relative=str_replace(chr(92),'/',(string)$data['utoc_relative_path']);
        if($root===false||!str_starts_with($relative,'games/')||str_contains($relative,'..')||str_contains($relative,':'))
            throw new RuntimeException('Unsafe retained Zen source path.');
        $utoc=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
        if($utoc===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$utoc)),strtolower(str_replace(chr(92),'/',$root)).'/'))
            throw new RuntimeException('Retained Zen source escaped the storage root.');
        $lib=getenv('UNREALDB_OODLE_LIBRARY');
        $toc=new Uedb5IoStoreTocReader($utoc,null,$lib!==false&&$lib!==''?$lib:null);
        if(!hash_equals(strtolower((string)$data['utoc_sha256']),strtolower($toc->tocSha256())))
            throw new RuntimeException('Zen source container TOC hash changed.');
        $part=$toc->findPackageChunk((string)$data['package_id'],Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA,0);
        if($part===null||(int)$part!==(int)$data['toc_index'])throw new RuntimeException('Zen member package identity changed.');
        $decoded=$toc->readChunk($part);
        if(strlen($decoded)!==(int)$data['file_size']||md5($decoded)!==(string)$data['md5']
            ||sha1($decoded)!==(string)$data['sha1'])
            throw new RuntimeException('Decoded Zen source no longer matches original verified catalog hashes.');
        $fileRelative=str_replace(chr(92),'/',(string)$data['relative_path']);
        $expected='storage/games/'.(string)$data['slug'].'/iostore-members/'
            .strtolower((string)$data['utoc_sha256']).'/'.(string)$data['package_id'].'.zenpkg';
        if($fileRelative!==$expected)throw new RuntimeException('Zen catalog source path differs from authoritative member identity.');
        $target=$root.DIRECTORY_SEPARATOR.substr($expected,strlen('storage/'));
        $physical=realpath($target);
        if($physical===false){
            // A lost derived member source can be restored from the original
            // verified IoStore container without inventing a new file identity.
            $dir=dirname($target);
            if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))
                throw new RuntimeException('Could not create Zen source repair directory.');
            $temporary=$target.'.repair-'.bin2hex(random_bytes(8));
            try{
                if(file_put_contents($temporary,$decoded,LOCK_EX)!==strlen($decoded)
                    ||md5_file($temporary)!==(string)$data['md5'])
                    throw new RuntimeException('Decoded Zen source repair failed integrity check.');
                if(!rename($temporary,$target))throw new RuntimeException('Could not publish repaired Zen source file.');
            }finally{if(is_file($temporary))@unlink($temporary);}
            $physical=realpath($target);
        }
        if($physical===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$physical)),strtolower(str_replace(chr(92),'/',$root)).'/')
            ||md5_file($physical)!==(string)$data['md5']||sha1_file($physical)!==(string)$data['sha1'])
            throw new RuntimeException('The canonical Zen member source file is corrupted.');
        $gameId=(int)$data['game_id'];
        $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,(string)$data['package_id'],[
            'id'=>$fileId,'game_id'=>$gameId,
            'package_name'=>(string)$data['package_name'],
            'original_name'=>(string)$data['original_name']
        ]);
        $path=Uedb5MetadataContainer::path($this->storageRoot,$gameId,$fileId);
        $backup=null;
        if(is_file($path)){
            $backup=$path.'.reimport-backup-'.bin2hex(random_bytes(8));
            if(!copy($path,$backup))throw new RuntimeException('Cannot preserve pre-reimport V5 backup.');
        }
        $committed=false;
        try{
            $written=(new Uedb5MetadataSnapshotWriter($this->storageRoot))->write($snapshot);
            $this->db->beginTransaction();
            $registry=(new PdoUedb5StagingRegistrationRepository($this->db,$this->storageRoot))
                ->register($gameId,$fileId);
            (new PdoUedb5BaseProjectionPublisher($this->db))->publish($snapshot,$registry);
            $status=new PdoUedb5MigrationStatusRepository($this->db);
            $status->markStageSucceeded($fileId,$gameId);
            $validated=(new Uedb5MigrationValidator($this->db,['storage_path'=>$this->storageRoot]))
                ->validate($fileId,$snapshot);
            if(!empty($validated['ready']))$status->markValidated($fileId,$written['payload_sha256'],$validated);
            else $status->markStaged($fileId,$written['payload_sha256'],$validated);
            $this->db->commit();
            $committed=true;
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            if($backup!==null&&is_file($backup)){
                if(!rename($backup,$path)){
                    throw new RuntimeException('Zen reimport failed and V5 rollback must be repaired from '.$backup,0,$error);
                }
            }elseif(is_file($path))@unlink($path);
            throw $error;
        }finally{
            if($committed&&$backup!==null&&is_file($backup))@unlink($backup);
        }
        return ['game_id'=>$gameId,'file_id'=>$fileId,'old_file_id'=>$fileId,
            'old_package_name'=>(string)$data['package_name'],
            'new_package_name'=>(string)$data['package_name'],
            'original_name'=>(string)$data['original_name'],
            'reconciliation_job_id'=>null,
            'message'=>'Zen package rebuilt from retained IoStore container; stable file ID preserved; dependency pass must be refreshed'];
    }
}
