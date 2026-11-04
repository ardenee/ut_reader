<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

/**
 * Bounded, resumable source-verifying projection of existing Zen packages.
 * One claim performs at most 100 isolated file transactions. Canonical
 * .uedb5 is verified ONCE here; HTTP requests use source-bound SQL rows.
 */
final class CatalogZenExamBackfillJobHandler implements JobHandler
{
    private const LIMIT = 100;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    public function supports(string $type): bool
    {
        return $type === JobType::BACKFILL_ZEN_EXAM_SQL;
    }

    /** @return array<string,mixed> */
    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        if (!$this->supports($job->type)) throw new RuntimeException('Invalid Zen SQL backfill job.');
        $gameId = (int)($job->payload['game_id'] ?? 0);
        if ($gameId < 1) throw new RuntimeException('A target game is required.');
        $lock = 'zen-exam-sql-backfill-game-'.$gameId;
        $q=$this->db->prepare('SELECT GET_LOCK(?,0)');
        $q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) {
            $context->defer(20, [
                'stage'=>'waiting_for_zen_exam_lock','game_id'=>$gameId,
                'message'=>'Waiting for the existing Zen examination backfill worker.',
            ], false);
        }
        try {
            $sql='SELECT f.id,v.payload_sha256 FROM ue_files f '
                .'JOIN ue_uedb5_files v ON v.file_id=f.id '
                .'JOIN ue_iostore_packages m ON m.file_id=f.id AND m.chunk_index=0 '
                .'LEFT JOIN ue_uedb5_zen_exam_files e ON e.file_id=f.id '
                .'WHERE f.game_id=? AND f.scan_status="verified" '
                .'AND v.format_version=5 AND v.package_family="zen-iostore" '
                .'AND m.publication_status="published" '
                .'AND (e.file_id IS NULL OR e.source_sha256<>v.payload_sha256) ';
            $q=$this->db->prepare($sql.'ORDER BY f.id LIMIT '.self::LIMIT);
            $q->execute([$gameId]);
            $files=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            $started=hrtime(true);
            $processed=0;$indexedRows=0;
            $reader=new Uedb5MetadataReader((string)$this->config['storage_path']);
            $projection=new PdoZenExamProjection($this->db);
            foreach($files as $file){
                $id=(int)$file['id'];
                $hash=(string)$file['payload_sha256'];
                // No fabricated metadata: verify against the authoritative
                // source SHA before materializing the SQL display index.
                $reader->verify($gameId,$id,$hash);
                $snapshot=$reader->snapshot($gameId,$id);
                $this->db->beginTransaction();
                try {
                    $projection->publish($snapshot,$hash);
                    $this->db->commit();
                } catch(Throwable $error) {
                    if($this->db->inTransaction()) $this->db->rollBack();
                    throw $error;
                }
                $indexedRows+=count((array)($snapshot['sections']['name_map']??[]))
                    +count((array)($snapshot['sections']['imports']??[]))
                    +count((array)($snapshot['sections']['exports']??[]));
                $processed++;
                $context->heartbeatIfDue();
            }
            // Count the remaining source-verified files without loading rows.
            $remainingQ=$this->db->prepare('SELECT COUNT(*) FROM ue_files f '
                .'JOIN ue_uedb5_files v ON v.file_id=f.id '
                .'JOIN ue_iostore_packages m ON m.file_id=f.id AND m.chunk_index=0 '
                .'LEFT JOIN ue_uedb5_zen_exam_files e ON e.file_id=f.id '
                .'WHERE f.game_id=? AND f.scan_status="verified" '
                .'AND v.format_version=5 AND v.package_family="zen-iostore" '
                .'AND m.publication_status="published" '
                .'AND (e.file_id IS NULL OR e.source_sha256<>v.payload_sha256)');
            $remainingQ->execute([$gameId]);
            $remaining=(int)$remainingQ->fetchColumn();
            $totalQ=$this->db->prepare('SELECT COUNT(*) FROM ue_files f '
                .'JOIN ue_uedb5_files v ON v.file_id=f.id '
                .'JOIN ue_iostore_packages m ON m.file_id=f.id AND m.chunk_index=0 '
                .'WHERE f.game_id=? AND f.scan_status="verified" '
                .'AND v.format_version=5 AND v.package_family="zen-iostore" '
                .'AND m.publication_status="published"');
            $totalQ->execute([$gameId]);
            $total=(int)$totalQ->fetchColumn();
            $done=max(0,$total-$remaining);
            $state=[
                'stage'=>'backfilling_zen_sql_exam',
                'game_id'=>$gameId,
                'done'=>$done,
                'remaining'=>$remaining,
                'total'=>$total,
                'percent'=>$total>0?(int)floor(100*$done/$total):100,
                'batch_files'=>$processed,
                'batch_rows'=>$indexedRows,
                'batch_ms'=>round((hrtime(true)-$started)/1e6,2),
                'message'=>'Zen examination SQL projection: '.$done.'/'.$total.' packages indexed.',
            ];
            $context->checkpoint($state);
            if($remaining>0) {
                if($processed===0)throw new RuntimeException('Zen SQL backfill made no progress.');
                $context->defer(1,$state,false);
            }
            return $state+['status'=>'completed'];
        } finally {
            $q=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
        }
    }
}
