<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns physical move/delete mutations for database-backed unverified queue items.
 * Why: Active unverified actions should not depend on procedural action functions in CatalogUnverifiedIndex.php.
 * Role: Infrastructure filesystem/persistence service preserving existing queue move and discard semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;

final class CatalogUnverifiedQueueMutationService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/UnverifiedFileManager.php';
        require_once $root . '/lib/CatalogUnverifiedIndex.php';
    }

    /** @param array<string,mixed> $source @return array{original_name:string,source_game:string,target_game:string} */
    public function move(array $source, int $targetGameId): array
    {
        $target = \catalog_one(
            $this->db,
            'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?',
            [$targetGameId]
        );
        if (!$target) {
            throw new RuntimeException('Target game not found.');
        }
        if ((int)($source['game']['id'] ?? 0) === $targetGameId) {
            throw new RuntimeException('The file is already in this game’s unverified queue.');
        }

        $targetDir = \uvf_unverified_dir($this->config, $target, true);
        $destination = \uvf_unique_destination($targetDir, (string)$source['queue_name']);
        if (!@rename((string)$source['path'], $destination)) {
            throw new RuntimeException('Could not move the unverified package to the target queue.');
        }
        if (is_file((string)$source['reason_path'])) {
            @rename((string)$source['reason_path'], $destination . '.txt');
        }

        $this->updateQueueRow($source, $targetGameId, basename($destination), $destination);
        return [
            'original_name' => (string)$source['original_name'],
            'source_game' => (string)$source['game']['name'],
            'target_game' => (string)$target['name'],
        ];
    }

    /** @param array<string,mixed> $source @return array{original_name:string,source_game:string} */
    public function discard(array $source): array
    {
        if (!@unlink((string)$source['path'])) {
            throw new RuntimeException('Could not remove the selected unverified package.');
        }
        if (is_file((string)$source['reason_path'])) {
            @unlink((string)$source['reason_path']);
        }

        \catalog_unverified_schema_ensure($this->db);
        $this->db->prepare(
            'DELETE FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=?'
        )->execute([
            \catalog_unverified_queue_key(
                (int)$source['game']['id'],
                (string)$source['queue_name']
            ),
        ]);

        return [
            'original_name' => (string)$source['original_name'],
            'source_game' => (string)$source['game']['name'],
        ];
    }

    /** @param array<string,mixed> $source */
    private function updateQueueRow(
        array $source,
        int $newQueueGameId,
        string $newQueueName,
        string $newPath
    ): void {
        \catalog_unverified_schema_ensure($this->db);
        $oldKey = \catalog_unverified_queue_key(
            (int)$source['game']['id'],
            (string)$source['queue_name']
        );
        $newKey = \catalog_unverified_queue_key($newQueueGameId, $newQueueName);
        $this->db->prepare(
            'UPDATE ue_files SET '
            . 'unverified_queue_key=?,unverified_queue_game_id=?,unverified_queue_name=?,'
            . 'stored_name=?,relative_path=? '
            . 'WHERE scan_status="unverified" AND unverified_queue_key=?'
        )->execute([
            $newKey,
            $newQueueGameId,
            $newQueueName,
            $newQueueName,
            \catalog_unverified_storage_relative($this->config, $newPath),
            $oldKey,
        ]);
    }
}
