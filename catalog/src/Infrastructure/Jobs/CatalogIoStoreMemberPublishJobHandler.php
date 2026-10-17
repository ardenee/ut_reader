<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreMemberCatalogPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

/** Publishes at most 10 Zen members per durable claim, with per-container serialization. */
final class CatalogIoStoreMemberPublishJobHandler implements JobHandler
{
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    public function supports(string $type):bool {return $type===JobType::PUBLISH_IOSTORE_PACKAGES;}

    public function handle(ClaimedJob $job, JobExecutionContext $context):array
    {
        if(!$this->supports($job->type))throw new RuntimeException('Invalid Zen publish job.');
        $id=(int)($job->payload['container_id']??0);
        if($id<1)throw new RuntimeException('Container ID is required.');
        $lockName='iostore-publish-'.$id;
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)$context->defer(5,['stage'=>'waiting_for_container_lock'],false);
        try {
            $q=$this->db->prepare('SELECT c.utoc_relative_path,c.utoc_sha256,c.status,p.engine_key '
              .'FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id '
              .'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE c.id=?');
            $q->execute([$id]);$container=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($container)||$container['status']!=='ready'
               ||!str_starts_with(strtoupper((string)$container['engine_key']),'UE5'))
                throw new RuntimeException('Ready container with active UE5 game required.');
            $root=realpath((string)($this->config['storage_path']??''));
            $relative=str_replace(chr(92),'/',(string)$container['utoc_relative_path']);
            if($root===false||!str_starts_with($relative,'games/')
               ||str_contains($relative,'..')||str_contains($relative,':'))
                throw new RuntimeException('Invalid retained TOC path.');
            $path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
            if($path===false||!str_starts_with(strtolower(str_replace(chr(92),'/',$path)),
                strtolower(str_replace(chr(92),'/',$root)).'/'))
                throw new RuntimeException('Retained TOC outside configured storage.');
            $lib=getenv('UNREALDB_OODLE_LIBRARY');
            $toc=new Uedb5IoStoreTocReader($path,null,$lib!==false&&$lib!==''?$lib:null);
            if(!hash_equals(strtolower((string)$container['utoc_sha256']),strtolower($toc->tocSha256())))
                throw new RuntimeException('Retained TOC checksum changed.');
            $rows=$this->db->prepare('SELECT toc_index FROM ue_iostore_packages '
                .'WHERE container_id=? AND chunk_index=0 AND metadata_status="ready" AND publication_status<>"published" '
                .'ORDER BY toc_index LIMIT 10');
            $rows->execute([$id]);
            $published=0;
            $publisher=new CatalogIoStoreMemberCatalogPublisher($this->db,(string)$this->config['storage_path']);
            foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $member){
                $publisher->publish($toc,$id,(int)$member['toc_index']);
                $published++;
                $context->heartbeatIfDue();
            }
            $counts=$this->db->prepare('SELECT COUNT(*) total, '
               .'SUM(publication_status="published") published, '
               .'SUM(metadata_status="ready" AND publication_status<>"published") remaining, '
               .'SUM(metadata_status="indexed") awaiting_names, '
               .'SUM(metadata_status="failed") failed_names '
               .'FROM ue_iostore_packages WHERE container_id=? AND chunk_index=0');
            $counts->execute([$id]);$c=$counts->fetch(PDO::FETCH_ASSOC);
            $state=['stage'=>'publishing_zen_members','container_id'=>$id,
                'total'=>(int)$c['total'],'published'=>(int)$c['published'],
                'remaining'=>(int)$c['remaining'],
                'awaiting_names'=>(int)$c['awaiting_names'],
                'failed_names'=>(int)$c['failed_names'],
                'processed_in_batch'=>$published];
            $context->checkpoint($state);
            if($state['remaining']>0 || $state['awaiting_names']>0){
                $context->defer($state['remaining']>0?1:30,$state,false);
            }
            return $state+['status'=>$state['failed_names']>0?'completed_with_failures':'bounded_complete'];
        }finally{
            $release=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
