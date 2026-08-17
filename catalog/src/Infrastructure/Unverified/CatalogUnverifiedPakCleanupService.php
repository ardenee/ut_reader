<?php
/**
 * Removes a neutral Upload Bucket PAK parent and only the extracted child rows/files owned by it.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;

final class CatalogUnverifiedPakCleanupService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    /** @return array{children_removed:int,parent_removed:bool} */
    public function remove(int $parentFileId): array
    {
        if ($parentFileId < 1) {
            throw new \InvalidArgumentException('A positive PAK parent file ID is required.');
        }
        $parent = $this->row($parentFileId);
        if (!$parent || strtolower((string)($parent['extension'] ?? '')) !== 'pak') {
            throw new \RuntimeException('The selected Upload Bucket PAK container no longer exists.');
        }

        $childrenRemoved = 0;
        $statement = $this->db->prepare(
            'SELECT m.child_file_id FROM ue_unverified_pak_members m '
            . 'WHERE m.parent_file_id=? AND m.owns_child_file=1 AND m.child_file_id IS NOT NULL'
        );
        $statement->execute([$parentFileId]);
        foreach (array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []) as $childFileId) {
            if ($childFileId < 1 || $childFileId === $parentFileId) {
                continue;
            }
            $child = $this->row($childFileId);
            if (!$child
                || (string)($child['scan_status'] ?? '') !== 'unverified'
                || (int)($child['unverified_queue_game_id'] ?? -1) !== 0) {
                continue;
            }
            $this->removePhysical((string)($child['unverified_queue_name'] ?? ''));
            $delete = $this->db->prepare(
                'DELETE FROM ue_files WHERE id=? AND scan_status="unverified" AND unverified_queue_game_id=0'
            );
            $delete->execute([$childFileId]);
            $childrenRemoved += $delete->rowCount();
        }

        $this->removePhysical((string)($parent['unverified_queue_name'] ?? ''));
        $delete = $this->db->prepare(
            'DELETE FROM ue_files WHERE id=? AND scan_status="unverified" AND unverified_queue_game_id=0 AND LOWER(extension)="pak"'
        );
        $delete->execute([$parentFileId]);
        return ['children_removed' => $childrenRemoved, 'parent_removed' => $delete->rowCount() > 0];
    }

    /** @return array<string,mixed>|null */
    private function row(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id,extension,scan_status,unverified_queue_game_id,unverified_queue_name FROM ue_files WHERE id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function removePhysical(string $queueName): void
    {
        $queueName = basename(trim($queueName));
        if ($queueName === '') {
            return;
        }
        $root = CatalogUnverifiedQueueStorage::uploadBucketDirectory($this->config, false);
        $path = $root . DIRECTORY_SEPARATOR . $queueName;
        if (is_file($path) && CatalogUnverifiedQueueStorage::pathInside($path, $root)) {
            @unlink($path);
        }
        $note = $path . '.txt';
        if (is_file($note) && CatalogUnverifiedQueueStorage::pathInside($note, $root)) {
            @unlink($note);
        }
    }
}
