<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use RuntimeException;
use Throwable;

/** Full IoStore container-family backup and hash-verified restoration. */
final class CatalogIoStoreContainerBackupService
{
    public function __construct(private readonly PDO $db,private readonly string $storageRoot) {}

    /** @return array<string,mixed> */
    public function export(int $containerId,string $backupRoot):array
    {
        $q=$this->db->prepare('SELECT c.*,g.slug FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id WHERE c.id=?');
        $q->execute([$containerId]);$container=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($container)||$container['status']!=='ready')throw new RuntimeException('Only ready IoStore containers can be backed up.');
        $parts=$this->db->prepare('SELECT part_index,relative_path,file_size,sha256 FROM ue_iostore_container_parts WHERE container_id=? ORDER BY part_index');
        $parts->execute([$containerId]);$partRows=$parts->fetchAll(PDO::FETCH_ASSOC);
        if($partRows===[])throw new RuntimeException('IoStore backup requires at least one complete UCAS part.');
        $entry=[];
        $tocPath=$this->resolve((string)$container['utoc_relative_path']);
        $tocSha=hash_file('sha256',$tocPath);
        if(!hash_equals(strtolower((string)$container['utoc_sha256']),strtolower($tocSha)))
            throw new RuntimeException('Retained IoStore TOC integrity differs from database.');
        $entry[]=['role'=>'toc','index'=>-1,'filename'=>basename($tocPath),'sha256'=>$tocSha,'size'=>(int)filesize($tocPath),'source'=>$tocPath];
        foreach($partRows as $i=>$row){
            if((int)$row['part_index']!==$i)throw new RuntimeException('IoStore partition manifest has gaps.');
            $path=$this->resolve((string)$row['relative_path']);
            if(filesize($path)!==(int)$row['file_size']||hash_file('sha256',$path)!==(string)$row['sha256'])
                throw new RuntimeException('IoStore partition differs from retained hash.');
            $entry[]=['role'=>'part','index'=>$i,'filename'=>basename($path),
                'sha256'=>(string)$row['sha256'],'size'=>(int)$row['file_size'],'source'=>$path];
        }
        $backupRoot=rtrim(str_replace(chr(92),'/',$backupRoot),'/');
        if($backupRoot===''||!is_dir($backupRoot))throw new RuntimeException('Existing external backup directory is required.');
        $destination=$backupRoot.'/iostore-'.strtolower($tocSha);
        if(is_dir($destination)){
            $old=$this->loadManifest($destination.'/manifest.json');
            if($old['toc_sha256']!==$tocSha||count($old['files'])!==count($entry))
                throw new RuntimeException('Existing backup destination does not match this container.');
            foreach($old['files'] as $row){
                $file=$destination.'/'.(string)$row['filename'];
                if(!is_file($file)||hash_file('sha256',$file)!==$row['sha256'])
                    throw new RuntimeException('Existing IoStore backup is incomplete or corrupt.');
            }
            return ['status'=>'existing','backup_path'=>$destination,'file_count'=>count($entry)];
        }
        $temp=$destination.'.staging-'.bin2hex(random_bytes(7));
        if(!mkdir($temp,0700))throw new RuntimeException('Could not create IoStore backup staging.');
        $manifest=['format'=>'unrealdb-iostore-container-backup','version'=>1,
            'game_slug'=>(string)$container['slug'],'toc_sha256'=>$tocSha,
            'container_id'=>(string)$container['container_id'],'package_count'=>(int)$container['package_count'],
            'files'=>[]];
        try{
            foreach($entry as $item){
                $file=$temp.'/'.$item['filename'];
                if(!copy($item['source'],$file)
                    ||filesize($file)!==$item['size']
                    ||hash_file('sha256',$file)!==$item['sha256'])
                    throw new RuntimeException('IoStore backup file copy failed verification.');
                unset($item['source']);
                $manifest['files'][]=$item;
            }
            $payload=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(file_put_contents($temp.'/manifest.json',$payload,LOCK_EX)!==strlen($payload))
                throw new RuntimeException('Could not write IoStore backup manifest.');
            if(!rename($temp,$destination))throw new RuntimeException('Could not atomically publish IoStore backup.');
        }catch(Throwable $e){
            // Preserve incomplete source-independent backup staging for inspection.
            throw new RuntimeException('IoStore backup not completed; staging kept at '.$temp.': '.$e->getMessage(),0,$e);
        }
        return ['status'=>'exported','backup_path'=>$destination,
            'file_count'=>count($manifest['files']),'package_count'=>$manifest['package_count']];
    }

    /** @return array<string,mixed> */
    public function restore(string $backupDirectory,int $gameId,string $gameSlug):array
    {
        if($gameId<1||preg_match('/^[a-zA-Z0-9_-]+$/',$gameSlug)!==1)
            throw new RuntimeException('Valid destination game and slug are required.');
        $profile=$this->db->prepare('SELECT g.slug,p.engine_key FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?');
        $profile->execute([$gameId]);$game=$profile->fetch(PDO::FETCH_ASSOC);
        if(!is_array($game)||$game['slug']!==$gameSlug||!str_starts_with(strtoupper((string)$game['engine_key']),'UE5'))
            throw new RuntimeException('IoStore backup restoration requires an active UE5 game profile.');
        $manifest=$this->loadManifest(rtrim($backupDirectory,'/\\').'/manifest.json');
        $toc=null;$partCount=0;$seenNames=[];$seenParts=[];
        foreach($manifest['files'] as $row){
            $filename=(string)$row['filename'];
            if(isset($seenNames[$filename]))throw new RuntimeException('Duplicate IoStore backup filename.');
            $seenNames[$filename]=true;
            if($row['role']==='part'){
                $index=$row['index']??null;
                if(!is_int($index)||$index<0||$index>127||isset($seenParts[$index]))
                    throw new RuntimeException('Invalid or duplicate IoStore backup partition index.');
                $seenParts[$index]=true;
            }elseif(($row['index']??null)!==-1){
                throw new RuntimeException('Invalid IoStore backup TOC index.');
            }
            $file=rtrim($backupDirectory,'/\\').'/'.$filename;
            if(!is_file($file)||is_link($file)||(int)filesize($file)!==(int)$row['size']
               ||hash_file('sha256',$file)!==$row['sha256'])
                throw new RuntimeException('IoStore backup component changed: '.$row['filename']);
            if($row['role']==='toc'){
                if($toc!==null)throw new RuntimeException('Backup contains multiple TOC files.');
                $toc=$file;
            }else{$partCount++;}
        }
        for($index=0;$index<$partCount;$index++){
            if(!isset($seenParts[$index]))throw new RuntimeException('Incomplete IoStore backup partition inventory.');
        }
        if($toc===null||$partCount<1||hash_file('sha256',$toc)!==$manifest['toc_sha256'])
            throw new RuntimeException('IoStore backup has invalid TOC or partition inventory.');
        $result=(new CatalogIoStoreContainerStorage($this->db,$this->storageRoot))
            ->import($gameId,$gameSlug,$toc);
        return $result+['backup_restored'=>true,'backup_format'=>$manifest['format']];
    }

    /** @return array<string,mixed> */
    private function loadManifest(string $path):array
    {
        if(!is_file($path))throw new RuntimeException('IoStore backup manifest missing.');
        $manifest=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($manifest)||($manifest['format']??'')!=='unrealdb-iostore-container-backup'
           ||($manifest['version']??0)!==1||preg_match('/^[a-f0-9]{64}$/',(string)($manifest['toc_sha256']??''))!==1
           ||!is_array($manifest['files']??null))
            throw new RuntimeException('Invalid IoStore backup manifest.');
        foreach($manifest['files'] as $row){
            if(!is_array($row)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',(string)($row['filename']??''))!==1
               ||str_contains((string)$row['filename'],'..')
               ||preg_match('/^[a-f0-9]{64}$/',(string)($row['sha256']??''))!==1
               ||!in_array((string)($row['role']??''),['toc','part'],true)
               ||(int)($row['size']??0)<1)
                throw new RuntimeException('Invalid IoStore backup component metadata.');
        }
        return $manifest;
    }

    private function resolve(string $relative):string
    {
        $root=realpath($this->storageRoot);
        $relative=str_replace(chr(92),'/', $relative);
        if($root===false||!str_starts_with($relative,'games/')||str_contains($relative,'..')||str_contains($relative,':'))
            throw new RuntimeException('Unsafe IoStore storage relative path.');
        $path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
        if($path===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$path)),
           strtolower(str_replace(chr(92),'/',$root)).'/')||!is_file($path)||is_link($path))
            throw new RuntimeException('IoStore source escapes storage root or is missing.');
        return $path;
    }
}
