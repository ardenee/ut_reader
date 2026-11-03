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
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

/**
 * One low-priority, bounded dependency Pass 2 workflow per retained IoStore
 * container. Does not create one queue job per package or invent mounted-store
 * authority: the Zen resolver records unresolved outcomes where evidence ends.
 */
final class CatalogIoStoreDependencyPassJobHandler implements JobHandler
{
    private const FILES_PER_CLAIM = 20;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    public function supports(string $type): bool
    {
        return $type === JobType::INDEX_IOSTORE_DEPENDENCIES;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if (!$this->supports($job->type)) {
            throw new RuntimeException('Invalid IoStore dependency job type.');
        }
        $containerId = (int)($job->payload['container_id'] ?? 0);
        if ($containerId < 1) {
            throw new RuntimeException('IoStore dependency pass requires a retained container ID.');
        }
        $q = $this->db->prepare(
            'SELECT c.game_id,c.status,p.engine_key FROM ue_iostore_containers c '
            . 'JOIN ue_games g ON g.id=c.game_id '
            . 'JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE c.id=?'
        );
        $q->execute([$containerId]);
        $container = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($container) || (string)$container['status'] !== 'ready'
            || !str_starts_with(strtoupper((string)$container['engine_key']), 'UE5')) {
            throw new RuntimeException('IoStore dependency pass requires a retained ready UE5 container.');
        }
        $gameId = (int)$container['game_id'];
        $members = $this->db->prepare(
            'SELECT COUNT(*) total, SUM(CASE WHEN metadata_status="ready" AND '
            . 'publication_status="published" AND file_id IS NOT NULL THEN 1 ELSE 0 END) published '
            . 'FROM ue_iostore_packages WHERE container_id=? AND chunk_index=0'
        );
        $members->execute([$containerId]);
        $counts = $members->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($counts['total'] ?? 0);
        $published = (int)($counts['published'] ?? 0);
        if ($total === 0) {
            return ['status'=>'no_package_members','container_id'=>$containerId,'total'=>0];
        }
        if ($published !== $total) {
            $context->defer(30, [
                'stage'=>'waiting_for_package_publication','container_id'=>$containerId,
                'percent'=>0,'published'=>$published,'total'=>$total,
                'message'=>'IoStore dependency processing is waiting for every package to be published.',
            ], false);
        }

        // Only one game-level dependency pass at a time, regardless of how many
        // IoStore containers are retained in the game. The same file-scoped
        // source hash/policy gates make replay idempotent.
        $lockName = 'iostore-dependency-game-'.$gameId;
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            $context->defer(10, [
                'stage'=>'waiting_for_dependency_lock','container_id'=>$containerId,
                'message'=>'Waiting for the game-level IoStore dependency worker.',
            ], false);
        }
        try {
            $pass = new Uedb5GameDependencyPassService($this->db, $this->config);
            $preflight = $pass->preflight($gameId);
            if (empty($preflight['pass2_ready'])) {
                $context->defer(30, [
                    'stage'=>'waiting_for_staged_game','container_id'=>$containerId,
                    'percent'=>0,'message'=>'Waiting for complete verified UEDB5 staged/provider coverage.',
                    'preflight'=>$preflight,
                ], false);
            }
            $started = hrtime(true);
            // Use the engine's existing source-labelled Zen dependency resolver
            // and authoritative UEDB5 persistence path; no bespoke resolver.
            $result = $pass->run(
                $gameId, true, self::FILES_PER_CLAIM, false, self::FILES_PER_CLAIM,
                null, 1, 0, true, false
            );
            if ((int)$result['failed'] > 0) {
                $reason = (string)($result['failures'][0]['error'] ?? 'unknown cause');
                throw new RuntimeException('IoStore dependency pass failed for '
                    . (int)$result['failed'].' file(s): '.$reason);
            }
            $after = $pass->preflight($gameId);
            $remaining = (int)$after['dependency_remaining_count'];
            $all = (int)$after['staged_count'];
            $done = max(0, $all - $remaining);
            $progress = [
                'stage'=>'publishing_iostore_dependencies',
                'container_id'=>$containerId,
                'game_id'=>$gameId,
                'processed_in_batch'=>(int)$result['processed'],
                'batch_ms'=>round((hrtime(true)-$started)/1e6,2),
                'done'=>$done,'remaining'=>$remaining,'total'=>$all,
                'percent'=>$all > 0 ? (int)floor(100*$done/$all) : 100,
                'resolution_context'=>'mounted store authority is required for definitive provider results',
                'message'=>'Dependency Pass 2: '.$done.'/'.$all
                    .' source-backed files processed. Unproven mounted providers remain unresolved.',
            ];
            $context->checkpoint($progress);
            if ($remaining > 0) {
                if ((int)$result['processed'] === 0) {
                    throw new RuntimeException('Dependency pass has remaining work but made no progress.');
                }
                $context->defer(3, $progress, false);
            }
            (new PdoGameCatalogStats($this->db))->rebuildGame($gameId);
            return $progress + ['status'=>'completed','percent'=>100];
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
