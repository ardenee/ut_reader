<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerStorage;

/**
 * Durable verified retention + TOC index for an IoStore container family.
 * It does not fabricate classic ue_files rows or infer mounted-store context.
 */
final class CatalogIoStoreImportJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {}

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::IMPORT_IOSTORE_CONTAINER;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if (!$this->supports($job->type)) {
            throw new RuntimeException('Unsupported IoStore import job type.');
        }
        $gameId=(int)($job->payload['game_id']??0);
        $source=(string)($job->payload['local_utoc_path']??'');
        if ($gameId<1||$source===''||!str_ends_with(strtolower($source),'.utoc')) {
            throw new RuntimeException('IoStore job needs a valid game and local .utoc path.');
        }
        $real=realpath($source);
        if ($real===false||!is_file($real)||is_link($source)||!is_readable($real)) {
            throw new RuntimeException('Selected IoStore TOC is no longer a readable regular file.');
        }
        $expectedTocHash=strtolower(trim((string)($job->payload['toc_sha256']??'')));
        if($expectedTocHash!=='' && (!preg_match('/^[a-f0-9]{64}$/',$expectedTocHash)
            ||!hash_equals($expectedTocHash,(string)hash_file('sha256',$real)))) {
            throw new RuntimeException('IoStore TOC changed after the job was queued.');
        }
        $q=$this->db->prepare(
            'SELECT g.slug,p.engine_key FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?'
        );
        $q->execute([$gameId]);
        $game=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($game)||!str_starts_with(strtoupper((string)$game['engine_key']),'UE5')) {
            throw new RuntimeException('IoStore ingestion requires an active UE5 game profile.');
        }
        $context->checkpoint(['stage'=>'retaining_container','percent'=>1,'message'=>'Verifying IoStore source family']);
        $store=new CatalogIoStoreContainerStorage($this->db,(string)($this->config['storage_path']??''));
        $result=$store->import(
            $gameId,
            (string)$game['slug'],
            $real,
            null,
            ($library=getenv('UNREALDB_OODLE_LIBRARY'))!==false&&$library!==''?$library:null
        );
        $context->checkpoint(['stage'=>'container_indexed','percent'=>100,'container_id'=>$result['container_id'],'package_count'=>$result['package_count']]);
        return [
            'status'=>'indexed',
            'container_id'=>(int)$result['container_id'],
            'package_count'=>(int)$result['package_count'],
            'retained_parts'=>(int)$result['retained_parts'],
            'retention_status'=>(string)$result['retention_status'],
            'note'=>'Container safely retained and indexed; package publication is a separate workflow.'
        ];
    }
}
