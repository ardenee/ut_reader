<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues cached dependency/game-match projection refreshes for unverified packages.
 * Why: Exact export/dependency matching is too expensive for the Unverified Files request path.
 * Role: Background-work enqueue service used after bucket staging and by manual bucket refresh.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogUnverifiedGameMatchRefreshQueue
{
    private readonly PdoUnverifiedGameMatchCache $cache;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->cache = new PdoUnverifiedGameMatchCache($db);
    }

    public function queueName(): string
    {
        return (new CatalogBucketBatchQueue($this->db, $this->config))->queueName();
    }

    public function enqueueFile(int $fileId, ?int $userId = null): int
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A positive unverified file ID is required.');
        }
        $row = $this->db->prepare(
            'SELECT id FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1'
        );
        $row->execute([$fileId]);
        if ((int)($row->fetchColumn() ?: 0) !== $fileId) {
            throw new \RuntimeException('The unverified file is no longer available for game-match refresh.');
        }

        $this->cache->markPending($fileId);
        return (new PdoJobQueue($this->db))->enqueue(
            $this->queueName(),
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES,
            ['file_id' => $fileId, 'scope' => 'file'],
            30,
            null,
            'unverified-game-match:file:' . $fileId . ':v' . PdoUnverifiedGameMatchCache::VERSION,
            $userId,
            3
        );
    }

    public function enqueueBucket(?int $userId = null): int
    {
        return (new PdoJobQueue($this->db))->enqueue(
            $this->queueName(),
            JobType::REFRESH_UNVERIFIED_GAME_MATCHES,
            ['scope' => 'bucket'],
            40,
            null,
            'unverified-game-match:bucket:v' . PdoUnverifiedGameMatchCache::VERSION,
            $userId,
            3
        );
    }
}
