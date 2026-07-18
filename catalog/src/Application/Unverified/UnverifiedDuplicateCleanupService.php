<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Unverified;

use Throwable;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedFileSystem;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedQueueInventory;
use UnrealDb\Catalog\Application\Unverified\Contract\UnverifiedRecordStore;

/** Exact size + MD5 duplicate cleanup for physical unverified queues. */
final class UnverifiedDuplicateCleanupService
{
    public function __construct(
        private readonly UnverifiedQueueInventory $inventory,
        private readonly UnverifiedRecordStore $records,
        private readonly UnverifiedFileSystem $files
    ) {
    }

    /**
     * @return array{
     *   physical_files:int,
     *   hashed_files:int,
     *   groups:list<array<string,mixed>>,
     *   duplicate_groups:int,
     *   duplicate_files:int,
     *   duplicate_bytes:int
     * }
     */
    public function scan(): array
    {
        $indexedKeys = $this->records->indexedQueueKeys();
        $bySize = [];
        $physicalFiles = 0;

        foreach ($this->inventory->all() as $item) {
            $item['indexed'] = isset($indexedKeys[(string)$item['queue_key']]);
            $bySize[(string)$item['size']][] = $item;
            $physicalFiles++;
        }

        $hashedFiles = 0;
        $byIdentity = [];
        foreach ($bySize as $sameSize) {
            if (count($sameSize) < 2) {
                continue;
            }

            foreach ($sameSize as $item) {
                $md5 = $this->files->md5((string)$item['path']);
                if ($md5 === null || preg_match('/^[a-f0-9]{32}$/i', $md5) !== 1) {
                    continue;
                }

                $hashedFiles++;
                $item['md5'] = strtolower($md5);
                $identity = (string)$item['size'] . ':' . strtolower($md5);
                $byIdentity[$identity][] = $item;
            }
        }

        $groups = [];
        $duplicateFiles = 0;
        $duplicateBytes = 0;
        foreach ($byIdentity as $identity => $sameIdentity) {
            if (count($sameIdentity) < 2) {
                continue;
            }

            usort($sameIdentity, static function (array $left, array $right): int {
                return ((int)!empty($right['indexed']) <=> (int)!empty($left['indexed']))
                    ?: ((int)$left['modified_at'] <=> (int)$right['modified_at'])
                    ?: ((int)$left['queue_game_id'] <=> (int)$right['queue_game_id'])
                    ?: strcmp((string)$left['queue_name'], (string)$right['queue_name']);
            });

            $keeper = array_shift($sameIdentity);
            if (!is_array($keeper)) {
                continue;
            }

            $size = (int)($keeper['size'] ?? 0);
            $duplicates = array_values($sameIdentity);
            $duplicateFiles += count($duplicates);
            $duplicateBytes += $size * count($duplicates);
            $groups[] = [
                'identity' => $identity,
                'size' => $size,
                'md5' => (string)($keeper['md5'] ?? ''),
                'keeper' => $keeper,
                'duplicates' => $duplicates,
            ];
        }

        usort($groups, static function (array $left, array $right): int {
            return strcasecmp(
                (string)($left['keeper']['original_name'] ?? ''),
                (string)($right['keeper']['original_name'] ?? '')
            );
        });

        return [
            'physical_files' => $physicalFiles,
            'hashed_files' => $hashedFiles,
            'groups' => $groups,
            'duplicate_groups' => count($groups),
            'duplicate_files' => $duplicateFiles,
            'duplicate_bytes' => $duplicateBytes,
        ];
    }

    /** @return array<string,mixed> */
    public function deleteDuplicates(): array
    {
        $scan = $this->scan();
        $deleted = [];
        $errors = [];
        $deletedCount = 0;
        $deletedBytes = 0;

        foreach ($scan['groups'] as $group) {
            $expectedSize = (int)$group['size'];
            $expectedMd5 = strtolower((string)$group['md5']);
            $keeper = (array)$group['keeper'];

            foreach ((array)$group['duplicates'] as $duplicate) {
                $path = (string)$duplicate['path'];
                $label = (string)$duplicate['original_name'];
                if (!$this->files->exists($path)) {
                    $errors[] = $label . ': file disappeared before it could be deleted.';
                    continue;
                }

                $currentSize = $this->files->size($path);
                $currentMd5 = $this->files->md5($path);
                if (
                    $currentSize !== $expectedSize
                    || $currentMd5 === null
                    || strtolower($currentMd5) !== $expectedMd5
                ) {
                    $errors[] = $label . ': file changed during duplicate checking and was not deleted.';
                    continue;
                }

                if (!$this->files->delete($path)) {
                    $errors[] = $label . ': could not delete the duplicate queue file.';
                    continue;
                }

                $reasonPath = (string)$duplicate['reason_path'];
                if ($this->files->exists($reasonPath) && !$this->files->delete($reasonPath)) {
                    $errors[] = $label . ': duplicate file was deleted, but its queue-note file could not be removed.';
                }

                try {
                    $this->records->deleteByQueue(
                        (int)$duplicate['queue_game_id'],
                        (string)$duplicate['queue_name']
                    );
                } catch (Throwable $error) {
                    $errors[] = $label
                        . ': physical duplicate was deleted, but its unverified database row could not be removed: '
                        . $error->getMessage();
                }

                $deletedCount++;
                $deletedBytes += $expectedSize;
                if (count($deleted) < 100) {
                    $deleted[] = [
                        'name' => $label,
                        'queue' => (string)$duplicate['queue_name_label'],
                        'kept_name' => (string)($keeper['original_name'] ?? ''),
                        'kept_queue' => (string)($keeper['queue_name_label'] ?? ''),
                        'size' => $expectedSize,
                        'md5' => $expectedMd5,
                    ];
                }
            }
        }

        return [
            'physical_files' => (int)$scan['physical_files'],
            'hashed_files' => (int)$scan['hashed_files'],
            'duplicate_groups' => (int)$scan['duplicate_groups'],
            'duplicate_files_found' => (int)$scan['duplicate_files'],
            'deleted_files' => $deletedCount,
            'deleted_bytes' => $deletedBytes,
            'deleted' => $deleted,
            'deleted_list_truncated' => $deletedCount > count($deleted),
            'errors' => $errors,
        ];
    }
}
