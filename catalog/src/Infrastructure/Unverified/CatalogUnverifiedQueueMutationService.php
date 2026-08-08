<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns physical move/delete mutations for database-backed unverified queue items.
 * Why: Active unverified actions should not depend on procedural action functions or staging persistence helpers.
 * Role: Infrastructure filesystem/persistence service preserving existing queue move and discard semantics.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;

final class CatalogUnverifiedQueueMutationService
{
    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
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

        $this->staging->updateQueue(
            $source,
            $targetGameId,
            basename($destination),
            $destination
        );

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

        $this->staging->deleteDatabaseRow(
            (int)$source['game']['id'],
            (string)$source['queue_name']
        );

        return [
            'original_name' => (string)$source['original_name'],
            'source_game' => (string)$source['game']['name'],
        ];
    }
}
