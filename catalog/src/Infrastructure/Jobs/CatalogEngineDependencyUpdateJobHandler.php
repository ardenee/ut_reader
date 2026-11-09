<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Jobs;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ProviderKeyPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

/** Bounded, resumable, source-correct UEDB5 v14 Pass 2 for one game. */
final class CatalogEngineDependencyUpdateJobHandler implements JobHandler
{
    public const FILES_PER_CLAIM=75;
    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db,private readonly array $config){}
    public function supports(string $type):bool{return $type===JobType::UPDATE_ENGINE_GAME_DEPENDENCIES;}
    public static function assertProductionExecution(string $host,string $sapi):void
    {
        if(strtolower(trim($host))!=='lelly-pc'||$sapi!=='cli'){
            throw new RuntimeException('Engine dependency publication requires a local Lelly-pc PRD CLI worker.');
        }
    }
    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job,JobExecutionContext $context):array
    {
        if(!$this->supports($job->type))throw new RuntimeException('Invalid engine dependency job.');
        self::assertProductionExecution((string)gethostname(),PHP_SAPI);
        $id=(int)($job->payload['game_id']??0);
        if($id<1)throw new RuntimeException('A positive game ID is required.');
        $q=$this->db->prepare('SELECT g.id,g.name,p.engine_key FROM ue_games g '
            .'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?');
        $q->execute([$id]);$game=$q->fetch(PDO::FETCH_ASSOC);
        $engine=strtoupper((string)($game['engine_key']??''));
        if(!is_array($game)||!in_array($engine,['UE1','UE2','UE3','UE4','UE5'],true)){
            throw new RuntimeException('Game needs a supported active engine profile.');
        }
        $lockName='uedb5-engine-dependency-game-'.$id;
        $q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);
        if((int)$q->fetchColumn()!==1){
            $context->defer(10,['stage'=>'waiting_for_game_lock','game_id'=>$id],false);
        }
        try{
            $service=new Uedb5GameDependencyPassService($this->db,$this->config);
            $before=$service->preflight($id);
            // Pass 2 depends on provider-key projections that may be absent
            // after new UEDB5 registrations. Rebuild only verified, staged
            // registrations lacking a primary key, before publishing edges.
            // Never invent an identity or bypass missing/invalid staging.
            if ((int)($before['missing_primary_provider_count']??0)>0
                && (int)($before['missing_staged_count']??0)===0
                && (int)($before['invalid_staged_count']??0)===0) {
                $missing=$this->db->prepare(
                    'SELECT f.id FROM ue_files f '
                    .'JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
                    .'LEFT JOIN ue_uedb5_provider_keys p ON p.file_id=f.id '
                    .'AND p.source_kind=1 AND p.source_id=f.id '
                    .'WHERE f.game_id=? AND f.scan_status="verified" '
                    .'AND v.format_version=5 AND v.package_key_kind>0 '
                    .'AND v.package_key IS NOT NULL AND p.file_id IS NULL '
                    .'ORDER BY f.id'
                );
                $missing->execute([$id]);
                $publisher=new PdoUedb5ProviderKeyPublisher($this->db);
                $repaired=0;
                while(($file=$missing->fetch(PDO::FETCH_ASSOC))!==false) {
                    $fileId=(int)$file['id'];
                    $publisher->publish($fileId);
                    $repaired++;
                    $context->heartbeatIfDue([
                        'stage'=>'repair_primary_provider_keys',
                        'game_id'=>$id,'provider_keys_repaired'=>$repaired,
                    ]);
                }
                $before=$service->preflight($id);
            }
            if(empty($before['pass2_ready'])){
                throw new RuntimeException('Game #'.$id.' Pass 2 preflight failed: '
                    .(int)($before['missing_staged_count']??0).' unstaged, '
                    .(int)($before['missing_primary_provider_count']??0).' missing provider keys, '
                    .(int)($before['invalid_staged_count']??0).' invalid staged identities.');
            }
            $all=(int)$before['verified_count'];
            if((int)$before['dependency_remaining_count']===0){
                return $this->refreshFinalStats($id,$engine,$game,$before,$all);
            }
            $result=$service->run($id,true,self::FILES_PER_CLAIM,false,
                self::FILES_PER_CLAIM,
                static function(array $event) use($context):void {
                    $context->heartbeatIfDue(['stage'=>'engine_source_pass2']+$event);
                },1,0,true,false);
            if((int)$result['failed']>0){
                throw new RuntimeException('Engine '.$engine.' Pass 2 failed for '
                    .(int)$result['failed'].' files: '
                    .(string)($result['failures'][0]['error']??'unknown cause'));
            }
            $after=$service->preflight($id);
            $remaining=(int)$after['dependency_remaining_count'];
            $done=max(0,$all-$remaining);
            $progress=['stage'=>'engine_source_pass2','game_id'=>$id,
                'game_name'=>(string)$game['name'],'engine'=>$engine,
                'policy'=>(string)$after['dependency_policy'],'done'=>$done,
                'total'=>$all,'remaining'=>$remaining,
                'percent'=>$all>0?(int)floor(100*$done/$all):100,
                'message'=>$engine.' source dependency Pass 2: '.$done.'/'.$all.'.'];
            $context->checkpoint($progress);
            if($remaining>0){
                if((int)$result['processed']===0)throw new RuntimeException('Pass 2 stalled with remaining files.');
                $context->defer(2,$progress,false);
            }
            return $this->refreshFinalStats($id,$engine,$game,$after,$all);
        }finally{
            $q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
        }
    }
    /** @param array<string,mixed> $game @param array<string,mixed> $preflight @return array<string,mixed> */
    private function refreshFinalStats(int $id,string $engine,array $game,array $preflight,int $all):array
    {
        $result=(new PdoGameCatalogStats($this->db))->rebuildGame($id,5);
        if($result===null)throw new RuntimeException('Game statistics refresh failed.');
        if(!Uedb5DependencyPublicationScope::isGameStatsCurrent($this->db,$id,$engine)){
            throw new RuntimeException('Game statistics remain stale after source publication.');
        }
        return ['status'=>'complete','stage'=>'complete','percent'=>100,
            'game_id'=>$id,'game_name'=>(string)$game['name'],'engine'=>$engine,
            'policy'=>(string)$preflight['dependency_policy'],'done'=>$all,
            'total'=>$all,'remaining'=>0,'stats_refreshed'=>true];
    }
}
