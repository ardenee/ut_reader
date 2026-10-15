<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerBackupService;
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;

final class CatalogIoStoreBackupJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db,private readonly array $config) {}

    public function supports(string $type):bool
    {
        return in_array($type,[JobType::BACKUP_IOSTORE_CONTAINER,JobType::RESTORE_IOSTORE_CONTAINER],true);
    }

    public function handle(ClaimedJob $job,JobExecutionContext $context):array
    {
        if(!$this->supports($job->type))throw new RuntimeException('Unrecognized IoStore backup job.');
        $key=(string)($job->payload['backup_key']??'');
        if(preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}$/D',$key)!==1)
            throw new RuntimeException('Invalid IoStore backup key.');
        $store=new GameBackupStore($this->config);
        $backupPath=$store->backupPath($key);
        $root=(string)($this->config['storage_path']??'');
        if($root==='')throw new RuntimeException('IoStore backup requires catalog storage.');
        $service=new CatalogIoStoreContainerBackupService($this->db,$root);
        if($job->type===JobType::BACKUP_IOSTORE_CONTAINER){
            $containerId=(int)($job->payload['container_id']??0);
            if($containerId<1)throw new RuntimeException('Valid container ID required.');
            $context->checkpoint(['stage'=>'exporting_iostore','container_id'=>$containerId,'percent'=>2]);
            if(!is_dir($backupPath))mkdir($backupPath,0750,true);
            $result=$service->export($containerId,$backupPath);
            $context->checkpoint(['stage'=>'complete','container_id'=>$containerId,'percent'=>100]);
            return $result+['operation'=>'backup_iostore_container','backup_key'=>$key];
        }
        $gameId=(int)($job->payload['game_id']??0);
        $sha=strtolower((string)($job->payload['utoc_sha256']??''));
        if($gameId<1||preg_match('/^[a-f0-9]{64}$/D',$sha)!==1)
            throw new RuntimeException('Valid target game and backed-up TOC SHA required.');
        $q=$this->db->prepare('SELECT slug FROM ue_games WHERE id=?');
        $q->execute([$gameId]);
        $slug=$q->fetchColumn();
        if(!is_string($slug)||$slug==='')throw new RuntimeException('Backup restore destination game not found.');
        $context->checkpoint(['stage'=>'restoring_iostore','game_id'=>$gameId,'percent'=>2]);
        $result=$service->restore($backupPath.'/iostore-'.$sha,$gameId,$slug);
        $context->checkpoint(['stage'=>'complete','game_id'=>$gameId,'container_id'=>$result['container_id'],'percent'=>100]);
        return $result+['operation'=>'restore_iostore_container','backup_key'=>$key];
    }
}
