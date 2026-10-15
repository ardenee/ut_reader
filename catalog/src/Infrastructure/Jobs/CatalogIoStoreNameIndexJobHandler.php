<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStorePackageNameIndexer;

/** Resumable bounded extraction of Zen package names/counts from retained UCAS. */
final class CatalogIoStoreNameIndexJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db,private readonly array $config) {}

    public function supports(string $jobType):bool
    {
        return $jobType===JobType::INDEX_IOSTORE_PACKAGE_NAMES;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job,JobExecutionContext $context):array
    {
        if(!$this->supports($job->type))throw new RuntimeException('Unsupported IoStore name-index job type.');
        $containerId=(int)($job->payload['container_id']??0);
        if($containerId<1)throw new RuntimeException('IoStore name indexing needs a valid container ID.');
        $library=getenv('UNREALDB_OODLE_LIBRARY');
        $service=new CatalogIoStorePackageNameIndexer(
            $this->db,
            (string)($this->config['storage_path']??''),
            $library!==false&&$library!==''?$library:null
        );
        $result=$service->indexBatch($containerId,100,static function()use($context):void{
            $context->heartbeatIfDue();
        });
        $done=$result['total']-$result['remaining'];
        $progress=[
            'stage'=>'indexing_zen_names',
            'container_id'=>$containerId,
            'done'=>$done,
            'total'=>$result['total'],
            'percent'=>$result['total']>0?(int)floor(100*$done/$result['total']):100,
            'remaining'=>$result['remaining'],
            'failed_in_last_batch'=>$result['failed']
        ];
        $context->checkpoint($progress);
        if($result['remaining']>0){
            $context->defer(1,$progress,false);
        }
        $failedQuery=$this->db->prepare('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=? AND metadata_status="failed"');
        $failedQuery->execute([$containerId]);
        return $result+['container_id'=>$containerId,'failed_total'=>(int)$failedQuery->fetchColumn(),'status'=>'indexed'];
    }
}
