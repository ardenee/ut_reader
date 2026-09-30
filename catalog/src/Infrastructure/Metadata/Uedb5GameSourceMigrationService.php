<?php
/**
 * Rebuilds staged UEDB5 metadata from original verified Unreal package bytes, game by game.
 * UEDB4 is never used as a migration source.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5GameSourceMigrationService
{
    private Uedb5MetadataSnapshotWriter $writer;
    private PdoUedb5StagingRegistrationRepository $registration;
    private PdoUedb5BaseProjectionPublisher $publisher;
    private ?PdoUedb5MigrationStatusRepository $statuses = null;
    private Uedb5SourceSnapshotFactory $sourceSnapshots;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config)
    {
        $storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
        if ($storage === '') { throw new RuntimeException('Catalog storage_path is required for UEDB5 migration.'); }
        $this->writer = new Uedb5MetadataSnapshotWriter($storage);
        $this->registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
        $this->publisher = new PdoUedb5BaseProjectionPublisher($db);
        $this->sourceSnapshots = new Uedb5SourceSnapshotFactory($db, $config);
        if ($this->tableExists('ue_uedb5_migration_status')) {
            $this->statuses = new PdoUedb5MigrationStatusRepository($db);
        }
    }
    /** @return array<string,mixed> */
    public function preflight(string $gameSlug): array
    {
        $game = $this->game($gameSlug);
        foreach ([
            'ue_file_metadata','ue_uedb5_files','ue_uedb5_provider_keys','ue_uedb5_search_keys',
            'ue_uedb5_name_candidates','ue_uedb5_object_candidates','ue_uedb5_dependency_edges',
            'ue_uedb5_dependency_packages',
        ] as $table) {
            if (!$this->tableExists($table)) { throw new RuntimeException('Required Step 5 table is missing: ' . $table); }
        }
        $sourceDirectory = $this->verifiedDirectory((string)$game['slug']);
        if (!is_dir($sourceDirectory)) {
            throw new RuntimeException('Verified source directory is not accessible: ' . $sourceDirectory);
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) verified_count,'
            . 'SUM(CASE WHEN m.format_version=4 THEN 1 ELSE 0 END) v4_count,'
            . 'SUM(CASE WHEN v.file_id IS NOT NULL THEN 1 ELSE 0 END) staged_count '
            . 'FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified"'
        );
        $statement->execute([(int)$game['id']]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $verified = (int)($counts['verified_count'] ?? 0);
        $v4 = (int)($counts['v4_count'] ?? 0);
        $missingV4 = [];
        if ($v4 !== $verified) {
            $missingStatement = $this->db->prepare(
                'SELECT f.id,f.original_name,f.package_version FROM ue_files f '
                . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
                . 'WHERE f.game_id=? AND f.scan_status="verified" AND m.file_id IS NULL ORDER BY f.id LIMIT 50'
            );
            $missingStatement->execute([(int)$game['id']]);
            $missingV4 = $missingStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $contract = $this->sourceContract((string)$game['slug']);
        $unsupported = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified" '
            . 'AND (package_version IS NULL OR package_version < ? OR package_version > ?)'
        );
        $unsupported->execute([(int)$game['id'], (int)$contract['min_version'], (int)$contract['max_version']]);
        $unsupportedCount = (int)$unsupported->fetchColumn();
        $distribution = $this->db->prepare(
            'SELECT package_version,COUNT(*) file_count FROM ue_files '
            . 'WHERE game_id=? AND scan_status="verified" GROUP BY package_version ORDER BY package_version'
        );
        $distribution->execute([(int)$game['id']]);
        $versionDistribution = $distribution->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'game' => $game,
            'verified_count' => $verified,
            'v4_count' => $v4,
            'v4_ready' => $v4 === $verified,
            'missing_v4_files' => $missingV4,
            'staged_count' => (int)($counts['staged_count'] ?? 0),
            'unsupported_source_version_count' => $unsupportedCount,
            'source_version_range' => [(int)$contract['min_version'], (int)$contract['max_version']],
            'package_version_distribution' => $versionDistribution,
            'verified_directory' => $sourceDirectory,
            'durable_status_tracking' => $this->statuses !== null,
        ];
    }
    /** @return array<string,mixed> */
    public function migrate(
        string $gameSlug,
        bool $apply,
        int $limit = 1000,
        bool $continuous = false,
        int $progressEvery = 100,
        ?callable $emit = null,
        int $workerCount = 1,
        int $workerIndex = 0
    ): array {
        $preflight = $this->preflight($gameSlug);
        if (empty($preflight['v4_ready'])) {
            $ids = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), (array)($preflight['missing_v4_files'] ?? []));
            throw new RuntimeException('Every verified file must retain a live UEDB4 registration before staging V5. Missing V4 file IDs: ' . implode(',', array_filter($ids)));
        }
        $game = (array)$preflight['game'];
        $contract = $this->sourceContract((string)$game['slug']);
        $limit = max(1, min(5000, $limit));
        $progressEvery = max(1, $progressEvery);
        $workerCount = max(1, min(8, $workerCount));
        if ($workerIndex < 0 || $workerIndex >= $workerCount) {
            throw new RuntimeException('UEDB5 migration worker index is outside the configured worker count.');
        }
        $cursor = 0;
        $processed = $succeeded = $failed = 0;
        $failures = [];
        do {
            $rows = $this->batch(
                (int)$game['id'], $cursor, $limit,
                (int)$contract['min_version'], (int)$contract['max_version'],
                $workerCount, $workerIndex
            );
            if ($rows === []) { break; }
            foreach ($rows as $file) {
                $file = (array)$file;
                $cursor = (int)$file['id'];
                $processed++;
                try {
                    $result = $this->migrateFile($game, $file, $apply);
                    $succeeded++;
                    if ($emit && ($processed % $progressEvery === 0 || !$continuous)) {
                        $emit(['status'=>'ok','processed'=>$processed,'file_id'=>$cursor,'result'=>$result]);
                    }
                } catch (Throwable $error) {
                    $failed++;
                    if ($apply) {
                        $this->registration->remove($cursor);
                        $this->markStageFailure($cursor, (int)$game['id'], $error);
                    }
                    if (count($failures) < 50) {
                        $failures[] = ['file_id'=>$cursor,'error'=>$error->getMessage()];
                    }
                    if ($emit) {
                        $emit(['status'=>'failed','processed'=>$processed,'file_id'=>$cursor,'error'=>$error->getMessage()]);
                    }
                }
            }
        } while ($continuous && count($rows) === $limit);

        return [
            'apply' => $apply,
            'game' => $game,
            'preflight' => $preflight,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'last_file_id' => $cursor,
            'worker_count' => $workerCount,
            'worker_index' => $workerIndex,
            'failures' => $failures,
        ];
    }

    /** @param array<string,mixed> $game @param array<string,mixed> $file @return array<string,mixed> */
    private function migrateFile(array $game, array $file, bool $apply): array
    {
        $path = $this->sourcePath((string)$game['slug'], (string)$file['stored_name']);
        $this->assertSourceIdentity($path, $file);
        $snapshot = $this->snapshot((string)$game['slug'], $path, $file);
        $sectionCounts = [];
        foreach ((array)$snapshot['sections'] as $section => $rows) {
            $sectionCounts[(string)$section] = count((array)$rows);
        }
        if (!$apply) {
            return ['source_path'=>$path,'source_policy'=>$snapshot['source_policy'],'section_counts'=>$sectionCounts];
        }

        $written = $this->writer->write($snapshot);
        $registration = $this->registration->register((int)$file['game_id'], (int)$file['id']);
        try {
            $projection = $this->publisher->publish($snapshot, $registration);
            if ($this->statuses !== null) {
                $this->statuses->markStageSucceeded(
                    (int)$file['id'],
                    (int)$file['game_id']
                );
            }
        } catch (Throwable $error) {
            $this->registration->remove((int)$file['id']);
            throw $error;
        }
        return [
            'source_path'=>$path,
            'uedb5_path'=>(string)$written['path'],
            'source_policy'=>$snapshot['source_policy'],
            'section_counts'=>$sectionCounts,
            'projection'=>$projection,
        ];
    }


    private function markStageFailure(int $fileId, int $gameId, Throwable $error): void
    {
        if ($this->statuses === null) { return; }
        try {
            $this->statuses->markStageFailed($fileId, $gameId, 'source_stage_failed', $error->getMessage());
        } catch (Throwable) {
            // Preserve the original staging failure; status can be reconciled/retried later.
        }
    }

    /** @return list<array<string,mixed>> */
    private function batch(
        int $gameId,
        int $afterId,
        int $limit,
        int $minVersion,
        int $maxVersion,
        int $workerCount = 1,
        int $workerIndex = 0
    ): array {
        $sql = 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.stored_name,f.relative_path,'
            . 'f.file_size,f.md5,f.sha1,f.package_version,f.licensee_version '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND v.file_id IS NULL AND f.id>? '
            . 'AND f.package_version BETWEEN ? AND ? '
            . 'AND MOD(f.id,?)=? ORDER BY f.id LIMIT ' . $limit;
        $statement = $this->db->prepare($sql);
        $statement->execute([$gameId, $afterId, $minVersion, $maxVersion, $workerCount, $workerIndex]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function snapshot(string $gameSlug, string $path, array $file): array
    {
        return $this->sourceSnapshots->build($gameSlug, $path, $file);
    }

    /** @param array<string,mixed> $file */
    private function assertSourceIdentity(string $path, array $file): void
    {
        if (!is_file($path)) { throw new RuntimeException('Verified source bytes are missing: ' . $path); }
        $size = filesize($path);
        if ($size === false || (int)$size !== (int)($file['file_size'] ?? -1)) {
            throw new RuntimeException('Verified source byte size does not match catalogue identity.');
        }
        $md5 = md5_file($path);
        $sha1 = sha1_file($path);
        if (!is_string($md5) || !hash_equals(strtolower((string)($file['md5'] ?? '')), strtolower($md5))) {
            throw new RuntimeException('Verified source MD5 does not match catalogue identity.');
        }
        if (!is_string($sha1) || !hash_equals(strtolower((string)($file['sha1'] ?? '')), strtolower($sha1))) {
            throw new RuntimeException('Verified source SHA1 does not match catalogue identity.');
        }
    }

    /** @return array{engine_key:string,min_version:int,max_version:int} */
    private function sourceContract(string $slug): array
    {
        return $this->sourceSnapshots->contract($slug);
    }

    /** @return array<string,mixed> */
    private function game(string $slug): array
    {
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1');
        $statement->execute([trim($slug)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new RuntimeException('Unknown game slug: ' . $slug); }
        return $row;
    }

    private function verifiedDirectory(string $slug): string
    {
        return rtrim((string)$this->config['storage_path'], "\\/")
            . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'verified';
    }

    private function sourcePath(string $slug, string $storedName): string
    {
        if ($storedName === '' || basename($storedName) !== $storedName) {
            throw new RuntimeException('Invalid verified stored_name for Step 6 migration.');
        }
        return $this->verifiedDirectory($slug) . DIRECTORY_SEPARATOR . $storedName;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }
}
