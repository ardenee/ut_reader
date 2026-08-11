<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates one unverified-file import, including partial-promotion recovery and result metadata.
 * Why: The HTTP action should not know promotion transaction details or how to recover dependency handoff after commit.
 * Role: Infrastructure use-case service for Unverified Files import.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Import\CatalogProfiledUploadQueue;

final class CatalogUnverifiedImportService
{
    private array $config;
    private readonly CatalogUnverifiedDependencyRecovery $dependencies;
    private readonly CatalogUnverifiedPromotion $promotion;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, array $config)
    {
        // A file already in trusted server-side unverified storage is not a new
        // HTTP upload and must not be rejected by the browser upload-size limit.
        $config['max_upload_bytes'] = PHP_INT_MAX;
        $this->config = $config;
        $this->dependencies = new CatalogUnverifiedDependencyRecovery($db, $config);
        $this->promotion = new CatalogUnverifiedPromotion($db, $config, $this->dependencies);
    }

    /**
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array{result:array<string,mixed>,details:array<string,mixed>,warning:string,recovery:?array<string,mixed>}
     */
    public function import(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array {
        $stagedFileId = (int)($source['file_id'] ?? 0);
        $warning = '';
        $recovery = null;

        try {
            $result = $this->promotion->promote(
                $source,
                $targetGameId,
                $userId,
                $allowProfileOverride,
                $emit
            );
        } catch (Throwable $promotionError) {
            // The filesystem/database promotion can commit successfully before
            // post-import queueing fails. Preserve the historical recovery path:
            // never re-promote an already verified row on retry.
            $verified = $stagedFileId > 0
                ? \catalog_one(
                    $this->db,
                    'SELECT id,original_name,game_id FROM ue_files WHERE id=? AND scan_status="verified"',
                    [$stagedFileId]
                )
                : null;
            if (!$verified) {
                throw $promotionError;
            }

            $target = \catalog_one($this->db, 'SELECT name FROM ue_games WHERE id=?', [(int)$verified['game_id']]) ?: [];
            $result = [
                'status' => 'verified',
                'file_id' => (int)$verified['id'],
                'original_name' => (string)$verified['original_name'],
                'target_game' => (string)($target['name'] ?? 'selected game'),
                'message' => 'The file was verified before dependency jobs could be queued.',
            ];

            $recovery = $this->dependencies->recover(
                (int)$verified['id'],
                $promotionError,
                $userId,
                $emit
            );
            if (empty($recovery['recovered'])) {
                $warning = 'File verification completed, but dependency jobs could not be queued: '
                    . (string)$recovery['message']
                    . ' Use File Maintenance to rebuild dependencies for file #'
                    . (int)$verified['id'] . '.';
            } elseif (is_array($recovery['jobs'] ?? null)) {
                $result['dependency_jobs'] = $recovery['jobs'];
            }
        }

        $details = \catalog_one(
            $this->db,
            'SELECT package_guid,name_count,import_count,export_count FROM ue_files WHERE id=?',
            [(int)$result['file_id']]
        ) ?: [];
        $guid = trim((string)($details['package_guid'] ?? ''));

        return [
            'result' => $result,
            'details' => [
                'name_count' => (int)($details['name_count'] ?? 0),
                'import_count' => (int)($details['import_count'] ?? 0),
                'export_count' => (int)($details['export_count'] ?? 0),
                'package_guid' => $guid,
            ],
            'warning' => $warning,
            'recovery' => $recovery,
        ];
    }

    /**
     * Import one staged package to the strongest exact compatible game now and
     * queue proper verified copies for every other exact compatible game.
     * Package-name-only evidence is deliberately excluded.
     *
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array{result:array<string,mixed>,details:array<string,mixed>,warning:string,recovery:?array<string,mixed>}
     */
    public function importExactCompatibleGames(
        array $source,
        ?int $userId,
        ?callable $emit = null
    ): array {
        $stagedFileId = (int)($source['file_id'] ?? 0);
        if ($stagedFileId < 1) {
            throw new \RuntimeException('Exact multi-game import requires an indexed unverified file.');
        }

        $matches = (new PdoUnverifiedGameMatchQuery($this->db))->one($stagedFileId);
        $eligible = [];
        foreach ($matches as $match) {
            if (empty($match['compatible'])
                || (int)($match['import_count'] ?? 0) < 1
                || (int)($match['exact_object_matches'] ?? 0) < 1) {
                continue;
            }
            $gameId = (int)($match['game_id'] ?? 0);
            if ($gameId > 0) {
                $eligible[$gameId] = $match;
            }
        }
        $eligible = array_values($eligible);
        if ($eligible === []) {
            throw new \RuntimeException(
                'No exact compatible games were found. Package-name-only references are not safe for automatic multi-game import.'
            );
        }

        // PdoUnverifiedGameMatchQuery already ranks exact compatible candidates
        // by exact object coverage, percentage and number of referencing files.
        $primary = array_shift($eligible);
        $primaryGameId = (int)$primary['game_id'];
        $primaryGameName = (string)$primary['game_name'];
        $sourcePath = (string)($source['path'] ?? '');
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('The queued physical package is unavailable for multi-game import.');
        }

        $row = is_array($source['row'] ?? null) ? $source['row'] : [];
        $originalName = trim((string)($source['original_name'] ?? $row['original_name'] ?? ''));
        if ($originalName === '') {
            throw new \RuntimeException('The queued package filename is unavailable.');
        }
        $sourceRelativePath = trim((string)($row['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $originalName;
        }

        $incoming = new CatalogIncomingFileStore($this->config);
        $secondaryStaged = null;
        if ($eligible !== []) {
            $this->emit(
                $emit,
                'multi_game_stage',
                5,
                'Preparing one durable secondary-game source for ' . count($eligible) . ' additional exact compatible game(s)'
            );
            // One durable staged copy can safely back all secondary jobs because
            // CatalogStagedImportJobHandler creates an isolated parser working
            // path and CatalogIncomingFileStore intentionally retains staged
            // sources for retry/recovery until normal pruning.
            $secondaryStaged = $incoming->stageLocalFile($sourcePath, $originalName);
        }

        $primaryEmit = $emit !== null
            ? static function (string $stage, int $percent, string $message) use ($emit, $primaryGameName): void {
                $mapped = 10 + (int)floor(max(0, min(100, $percent)) * 68 / 100);
                $emit('primary_' . $stage, min(78, $mapped), $primaryGameName . ': ' . $message);
            }
            : null;

        try {
            $primaryResult = $this->import(
                $source,
                $primaryGameId,
                $userId,
                false,
                $primaryEmit
            );
        } catch (Throwable $error) {
            if (is_array($secondaryStaged)) {
                $incoming->delete((string)$secondaryStaged['relative_path']);
            }
            throw $error;
        }

        $queued = [];
        $queueErrors = [];
        if ($eligible !== [] && is_array($secondaryStaged)) {
            $queue = new CatalogProfiledUploadQueue($this->db, $this->config);
            $total = count($eligible);
            $position = 0;
            foreach ($eligible as $match) {
                $position++;
                $gameId = (int)$match['game_id'];
                $gameName = (string)$match['game_name'];
                $this->emit(
                    $emit,
                    'multi_game_queue',
                    78 + (int)floor($position * 20 / max(1, $total)),
                    'Queueing verified copy for ' . $gameName
                );
                try {
                    $job = $queue->enqueueStaged(
                        $gameId,
                        $secondaryStaged,
                        $originalName,
                        $sourceRelativePath,
                        true,
                        $userId,
                        false
                    );
                    $queued[] = [
                        'game_id' => $gameId,
                        'game_name' => $gameName,
                        'job_id' => (int)$job['job_id'],
                        'exact_object_matches' => (int)($match['exact_object_matches'] ?? 0),
                        'dependency_references' => (int)($match['import_count'] ?? 0),
                    ];
                } catch (Throwable $queueError) {
                    $queueErrors[] = $gameName . ': ' . trim($queueError->getMessage());
                }
            }

            if ($queued === []) {
                $incoming->delete((string)$secondaryStaged['relative_path']);
            }
        }

        $allGames = [[
            'game_id' => $primaryGameId,
            'game_name' => $primaryGameName,
            'job_id' => null,
            'exact_object_matches' => (int)($primary['exact_object_matches'] ?? 0),
            'dependency_references' => (int)($primary['import_count'] ?? 0),
        ], ...$queued];

        $primaryResult['result']['multi_game'] = true;
        $primaryResult['result']['primary_game'] = $primaryGameName;
        $primaryResult['result']['target_games'] = $allGames;
        $primaryResult['result']['queued_game_jobs'] = $queued;
        $primaryResult['result']['exact_compatible_game_count'] = 1 + count($eligible);

        if ($queueErrors !== []) {
            $extra = 'Secondary game queue failures: ' . implode(' | ', $queueErrors);
            $primaryResult['warning'] = trim((string)$primaryResult['warning']) !== ''
                ? trim((string)$primaryResult['warning']) . ' ' . $extra
                : $extra;
        }

        $this->emit(
            $emit,
            'multi_game_done',
            100,
            'Imported primary game and queued ' . count($queued) . ' additional exact compatible game copy/copies'
        );
        return $primaryResult;
    }

    private function emit(?callable $emit, string $stage, int $percent, string $message): void
    {
        if ($emit !== null) {
            $emit($stage, max(0, min(100, $percent)), $message);
        }
    }
}
