<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns physical move/delete mutations for database-backed unverified queue items.
 * Why: Active unverified actions should not depend on procedural action functions or staging persistence helpers.
 * Role: Infrastructure filesystem/persistence adapter for the Application unverified mutation port.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Unverified\CatalogUnverifiedQueueMutation;

final class CatalogUnverifiedQueueMutationService implements CatalogUnverifiedQueueMutation
{
    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
    }

    /** @param array<string,mixed> $source @return array{original_name:string,source_game:string,target_game:string} */
    public function move(array $source, int $targetGameId): array
    {
        if ($this->isPak($source)) {
            throw new RuntimeException(
                'PAK containers cannot be moved as a single unverified package. Use Import selected so the retained PAK and all supported files inside it are assigned together.'
            );
        }

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

        $targetDir = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $target, true);
        $destination = CatalogUnverifiedQueueStorage::uniqueDestination(
            $targetDir,
            (string)$source['queue_name']
        );
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
        if ($this->isPak($source) && (int)($source['file_id'] ?? 0) > 0) {
            (new CatalogUnverifiedPakCleanupService($this->db, $this->config))->remove((int)$source['file_id']);
            return [
                'original_name' => (string)$source['original_name'],
                'source_game' => (string)$source['game']['name'],
            ];
        }

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

    /** @param array<string,mixed> $source */
    private function isPak(array $source): bool
    {
        return strtolower(trim((string)($source['extension'] ?? ''))) === 'pak'
            || strtolower((string)pathinfo((string)($source['original_name'] ?? ''), PATHINFO_EXTENSION)) === 'pak';
    }
}
