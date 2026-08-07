<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `UnverifiedDuplicateCleanupService` for unverified duplicate cleanup
 *          service.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
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
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{
     *   physical_files:int,
     *   hashed_files:int,
     *   groups:list<array<string,mixed>>,
     *   duplicate_groups:int,
     *   duplicate_files:int,
     *   duplicate_bytes:int
     * }
     */
    public function scan(?callable $progress = null): array
    {
        $indexedKeys = $this->records->indexedQueueKeys();
        $bySize = [];
        $physicalFiles = 0;

        $this->emit($progress, [
            'stage' => 'inventory',
            'done' => 0,
            'total' => 1,
            'percent' => 0,
            'message' => 'Inventorying physical unverified queues.',
        ]);

        foreach ($this->inventory->all() as $item) {
            $item['indexed'] = isset($indexedKeys[(string)$item['queue_key']]);
            $bySize[(string)$item['size']][] = $item;
            $physicalFiles++;
        }

        $hashCandidates = 0;
        foreach ($bySize as $sameSize) {
            if (count($sameSize) > 1) {
                $hashCandidates += count($sameSize);
            }
        }
        $this->emit($progress, [
            'stage' => 'hashing',
            'done' => 0,
            'total' => max(1, $hashCandidates),
            'percent' => $hashCandidates > 0 ? 10 : 70,
            'message' => $hashCandidates > 0
                ? 'Hashing ' . $hashCandidates . ' same-size duplicate candidates.'
                : 'No same-size duplicate candidates were found.',
            'physical_files' => $physicalFiles,
        ]);

        $hashedFiles = 0;
        $hashProcessed = 0;
        $byIdentity = [];
        foreach ($bySize as $sameSize) {
            if (count($sameSize) < 2) {
                continue;
            }

            foreach ($sameSize as $item) {
                $candidateNumber = $hashProcessed + 1;
                $md5 = $this->files->md5(
                    (string)$item['path'],
                    function (int $bytesRead, int $totalBytes) use (
                        $progress,
                        $physicalFiles,
                        $hashedFiles,
                        $hashProcessed,
                        $hashCandidates,
                        $candidateNumber,
                        $item
                    ): void {
                        $fraction = $totalBytes > 0 ? min(1, $bytesRead / $totalBytes) : 1;
                        $this->emit($progress, [
                            'stage' => 'hashing',
                            'done' => $hashProcessed,
                            'total' => max(1, $hashCandidates),
                            'percent' => 10 + (int)floor((($hashProcessed + $fraction) * 60) / max(1, $hashCandidates)),
                            'message' => 'Hashing candidate ' . $candidateNumber . '/' . $hashCandidates
                                . ': ' . (string)$item['original_name'],
                            'physical_files' => $physicalFiles,
                            'hashed_files' => $hashedFiles,
                            'current_bytes' => $bytesRead,
                            'current_size' => $totalBytes,
                        ]);
                    }
                );
                $hashProcessed++;
                if ($md5 !== null && preg_match('/^[a-f0-9]{32}$/i', $md5) === 1) {
                    $hashedFiles++;
                    $item['md5'] = strtolower($md5);
                    $identity = (string)$item['size'] . ':' . strtolower($md5);
                    $byIdentity[$identity][] = $item;
                }

                $this->emit($progress, [
                    'stage' => 'hashing',
                    'done' => $hashProcessed,
                    'total' => max(1, $hashCandidates),
                    'percent' => 10 + (int)floor(($hashProcessed * 60) / max(1, $hashCandidates)),
                    'message' => 'Hashed same-size candidates ' . $hashProcessed . '/' . $hashCandidates . '.',
                    'physical_files' => $physicalFiles,
                    'hashed_files' => $hashedFiles,
                ]);
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

        $this->emit($progress, [
            'stage' => 'scan_complete',
            'done' => max(1, $hashCandidates),
            'total' => max(1, $hashCandidates),
            'percent' => 70,
            'message' => count($groups) . ' exact duplicate group(s) found.',
            'physical_files' => $physicalFiles,
            'hashed_files' => $hashedFiles,
            'duplicate_groups' => count($groups),
            'duplicate_files' => $duplicateFiles,
            'duplicate_bytes' => $duplicateBytes,
        ]);

        return [
            'physical_files' => $physicalFiles,
            'hashed_files' => $hashedFiles,
            'groups' => $groups,
            'duplicate_groups' => count($groups),
            'duplicate_files' => $duplicateFiles,
            'duplicate_bytes' => $duplicateBytes,
        ];
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function deleteDuplicates(?callable $progress = null): array
    {
        $scan = $this->scan($progress);
        $deleted = [];
        $errors = [];
        $deletedCount = 0;
        $deletedBytes = 0;
        $processedCount = 0;
        $deleteTotal = max(1, (int)$scan['duplicate_files']);

        foreach ($scan['groups'] as $group) {
            $expectedSize = (int)$group['size'];
            $expectedMd5 = strtolower((string)$group['md5']);
            $keeper = (array)$group['keeper'];

            foreach ((array)$group['duplicates'] as $duplicate) {
                $path = (string)$duplicate['path'];
                $label = (string)$duplicate['original_name'];
                if (!$this->files->exists($path)) {
                    $errors[] = $label . ': file disappeared before it could be deleted.';
                    $processedCount++;
                    $this->emitDeleteProgress($progress, $processedCount, $deleteTotal, $scan, $deletedCount, $errors, $label);
                    continue;
                }

                $currentSize = $this->files->size($path);
                $currentMd5 = $this->files->md5(
                    $path,
                    function (int $bytesRead, int $totalBytes) use (
                        $progress,
                        $processedCount,
                        $deleteTotal,
                        $scan,
                        $deletedCount,
                        $errors,
                        $label
                    ): void {
                        $this->emit($progress, [
                            'stage' => 'verifying',
                            'done' => $processedCount,
                            'total' => $deleteTotal,
                            'percent' => 70 + (int)floor(($processedCount * 30) / $deleteTotal),
                            'message' => 'Rechecking size and MD5 before deleting: ' . $label,
                            'physical_files' => (int)$scan['physical_files'],
                            'hashed_files' => (int)$scan['hashed_files'],
                            'duplicate_groups' => (int)$scan['duplicate_groups'],
                            'deleted_files' => $deletedCount,
                            'errors' => count($errors),
                            'current_bytes' => $bytesRead,
                            'current_size' => $totalBytes,
                        ]);
                    }
                );
                if (
                    $currentSize !== $expectedSize
                    || $currentMd5 === null
                    || strtolower($currentMd5) !== $expectedMd5
                ) {
                    $errors[] = $label . ': file changed during duplicate checking and was not deleted.';
                    $processedCount++;
                    $this->emitDeleteProgress($progress, $processedCount, $deleteTotal, $scan, $deletedCount, $errors, $label);
                    continue;
                }

                if (!$this->files->delete($path)) {
                    $errors[] = $label . ': could not delete the duplicate queue file.';
                    $processedCount++;
                    $this->emitDeleteProgress($progress, $processedCount, $deleteTotal, $scan, $deletedCount, $errors, $label);
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
                $processedCount++;
                $this->emitDeleteProgress($progress, $processedCount, $deleteTotal, $scan, $deletedCount, $errors, $label);
            }
        }

        $this->emit($progress, [
            'stage' => 'complete',
            'done' => $deleteTotal,
            'total' => $deleteTotal,
            'percent' => 100,
            'message' => 'Duplicate cleanup complete.',
            'physical_files' => (int)$scan['physical_files'],
            'hashed_files' => (int)$scan['hashed_files'],
            'duplicate_groups' => (int)$scan['duplicate_groups'],
            'duplicate_files_found' => (int)$scan['duplicate_files'],
            'deleted_files' => $deletedCount,
            'deleted_bytes' => $deletedBytes,
            'errors' => count($errors),
        ]);

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

    /** @param null|callable(array<string,mixed>):void $progress */
    private function emitDeleteProgress(
        ?callable $progress,
        int $processedCount,
        int $deleteTotal,
        array $scan,
        int $deletedCount,
        array $errors,
        string $label
    ): void {
        $this->emit($progress, [
            'stage' => 'deleting',
            'done' => min($deleteTotal, $processedCount),
            'total' => $deleteTotal,
            'percent' => 70 + (int)floor((min($deleteTotal, $processedCount) * 30) / $deleteTotal),
            'message' => 'Processed duplicate queue file: ' . $label,
            'physical_files' => (int)$scan['physical_files'],
            'hashed_files' => (int)$scan['hashed_files'],
            'duplicate_groups' => (int)$scan['duplicate_groups'],
            'deleted_files' => $deletedCount,
            'errors' => count($errors),
        ]);
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function emit(?callable $progress, array $state): void
    {
        if ($progress !== null) {
            $progress($state);
        }
    }
}
