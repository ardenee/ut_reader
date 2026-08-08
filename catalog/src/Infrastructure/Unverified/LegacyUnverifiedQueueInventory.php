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
            $queueGameId = (int)($queue['id'] ?? 0);
            $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory(
                $this->config,
                $queue,
                false
            );
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.'
                    || $entry === '..'
                    || str_starts_with($entry, '.')
                    || str_ends_with(strtolower($entry), '.txt')) {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($path)
                    || is_link($path)
                    || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
                    continue;
                }

                $items[] = [
                    'queue_game_id' => $queueGameId,
                    'queue_name' => $entry,
                    'queue_name_label' => (string)($queue['name'] ?? 'Upload Bucket'),
                    'queue_key' => CatalogUnverifiedStagingIndex::queueKey($queueGameId, $entry),
                    'original_name' => CatalogUnverifiedQueueStorage::originalNameFromQueueName($entry),
                    'path' => $path,
                    'reason_path' => $path . '.txt',
                    'size' => (int)(filesize($path) ?: 0),
                    'modified_at' => (int)(filemtime($path) ?: 0),
                ];
            }
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function queues(): array
    {
        $queues = [CatalogUnverifiedQueueStorage::bucketGame()];
        $statement = $this->db->query(
            'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name'
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $game) {
            $queues[] = $game;
        }
        return $queues;
    }
}
