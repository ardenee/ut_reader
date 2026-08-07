<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `LegacyUnverifiedQueueInventory` for legacy unverified queue inventory.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedQueueInventory;

/** Filesystem inventory adapter for the established unverified queue layout. */
final class LegacyUnverifiedQueueInventory implements UnverifiedQueueInventory
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogUnverifiedIndex.php';
    }

    public function all(): array
    {
        $items = [];
        foreach ($this->queues() as $queue) {
            $queueGameId = (int)($queue['id'] ?? 0);
            $directory = \uvf_unverified_dir($this->config, $queue, false);
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if (
                    $entry === '.'
                    || $entry === '..'
                    || str_starts_with($entry, '.')
                    || str_ends_with(strtolower($entry), '.txt')
                ) {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($path) || is_link($path) || !\uvf_path_inside($path, $directory)) {
                    continue;
                }

                $items[] = [
                    'queue_game_id' => $queueGameId,
                    'queue_name' => $entry,
                    'queue_name_label' => (string)($queue['name'] ?? 'Upload Bucket'),
                    'queue_key' => \catalog_unverified_queue_key($queueGameId, $entry),
                    'original_name' => \uvf_original_name_from_queue_name($entry),
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
        $queues = [\uvf_bucket_game()];
        $statement = $this->db->query(
            'SELECT id,name,slug,profile_id FROM ue_games ORDER BY name'
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $game) {
            $queues[] = $game;
        }

        return $queues;
    }
}
