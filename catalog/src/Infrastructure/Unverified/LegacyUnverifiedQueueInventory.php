<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the application inventory port for the established unverified queue layout.
 * Why: Queue discovery is namespaced infrastructure and should not re-enter procedural compatibility facades.
 * Role: Filesystem inventory adapter for Upload Bucket and per-game unverified queues.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedQueueInventory;

final class LegacyUnverifiedQueueInventory implements UnverifiedQueueInventory
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    public function all(): array
    {
        $items = [];
        foreach ($this->queues() as $queue) {
            $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $queue, false);
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }
            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                $item = $this->itemFromQueue($queue, $directory, $entry);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * Resolve exactly one current queue file without rescanning every game queue.
     * Child background jobs use this to revalidate their filesystem target from
     * durable queue identity rather than trusting a persisted absolute path.
     *
     * @return array<string,mixed>|null
     */
    public function one(int $queueGameId, string $queueName): ?array
    {
        $paths = $this->paths($queueGameId, $queueName);
        if ($paths === null || !is_file($paths['path']) || is_link($paths['path'])) {
            return null;
        }
        $queue = $paths['queue'];
        return $this->itemFromQueue($queue, $paths['directory'], $paths['queue_name']);
    }

    /**
     * Resolve the controlled physical path for a durable queue identity even if
     * the data file has already disappeared. This lets idempotent delete jobs
     * finish note/DB cleanup after a crash that occurred just after unlink().
     *
     * @return array{queue:array<string,mixed>,directory:string,queue_name:string,path:string,reason_path:string}|null
     */
    public function paths(int $queueGameId, string $queueName): ?array
    {
        $queueName = basename(str_replace('\\', '/', trim($queueName)));
        if ($queueName === '' || $queueName === '.' || $queueName === '..' || str_starts_with($queueName, '.')) {
            return null;
        }
        $queue = $queueGameId === 0
            ? CatalogUnverifiedQueueStorage::bucketGame()
            : $this->game($queueGameId);
        if ($queue === null) {
            return null;
        }
        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $queue, false);
        if (!is_dir($directory)) {
            return null;
        }
        $root = realpath($directory);
        if ($root === false) {
            return null;
        }
        $path = $root . DIRECTORY_SEPARATOR . $queueName;
        // basename() above prevents traversal; existing targets get the stronger
        // canonical containment check as an additional guard.
        if (file_exists($path) && !CatalogUnverifiedQueueStorage::pathInside($path, $root)) {
            return null;
        }
        return [
            'queue' => $queue,
            'directory' => $root,
            'queue_name' => $queueName,
            'path' => $path,
            'reason_path' => $path . '.txt',
        ];
    }

    /** @param array<string,mixed> $queue @return array<string,mixed>|null */
    private function itemFromQueue(array $queue, string $directory, string $entry): ?array
    {
        if ($entry === '.'
            || $entry === '..'
            || str_starts_with($entry, '.')
            || str_ends_with(strtolower($entry), '.txt')) {
            return null;
        }
        if (!is_dir($directory) || !is_readable($directory)) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . basename($entry);
        if (!is_file($path)
            || is_link($path)
            || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
            return null;
        }

        $queueGameId = (int)($queue['id'] ?? 0);
        return [
            'queue_game_id' => $queueGameId,
            'queue_name' => basename($entry),
            'queue_name_label' => (string)($queue['name'] ?? 'Upload Bucket'),
            'queue_key' => CatalogUnverifiedStagingIndex::queueKey($queueGameId, basename($entry)),
            'original_name' => CatalogUnverifiedQueueStorage::originalNameFromQueueName(basename($entry)),
            'path' => $path,
            'reason_path' => $path . '.txt',
            'size' => (int)(filesize($path) ?: 0),
            'modified_at' => (int)(filemtime($path) ?: 0),
        ];
    }

    /** @return array<string,mixed>|null */
    private function game(int $gameId): ?array
    {
        if ($gameId < 1) {
            return null;
        }
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1');
        $statement->execute([$gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function queues(): array
    {
        $queues = [CatalogUnverifiedQueueStorage::bucketGame()];
        $statement = $this->db->query('SELECT id,name,slug,profile_id FROM ue_games ORDER BY name');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $game) {
            $queues[] = $game;
        }
        return $queues;
    }
}
