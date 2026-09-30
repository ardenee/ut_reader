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
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;

final class Uedb5GameSourceMigrationService
{
    private Uedb5MetadataSnapshotWriter $writer;
    private PdoUedb5StagingRegistrationRepository $registration;
    private PdoUedb5BaseProjectionPublisher $publisher;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config)
    {
        $storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
        if ($storage === '') { throw new RuntimeException('Catalog storage_path is required for UEDB5 migration.'); }
        $this->writer = new Uedb5MetadataSnapshotWriter($storage);
        $this->registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
        $this->publisher = new PdoUedb5BaseProjectionPublisher($db);
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
        if ($v4 !== $verified) {
            throw new RuntimeException('Every verified file must retain a live UEDB4 registration before staging V5: verified=' . $verified . ' v4=' . $v4);
        }
        $contract = $this->sourceContract((string)$game['slug']);
        $unsupported = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified" '
            . 'AND (package_version IS NULL OR package_version < ? OR package_version > ?)'
        );
        $unsupported->execute([(int)$game['id'], (int)$contract['min_version'], (int)$contract['max_version']]);
        $unsupportedCount = (int)$unsupported->fetchColumn();
        return [
            'game' => $game,
            'verified_count' => $verified,
            'v4_count' => $v4,
            'staged_count' => (int)($counts['staged_count'] ?? 0),
            'unsupported_source_version_count' => $unsupportedCount,
            'source_version_range' => [(int)$contract['min_version'], (int)$contract['max_version']],
            'verified_directory' => $sourceDirectory,
        ];
    }
    /** @return array<string,mixed> */
    public function migrate(
        string $gameSlug,
        bool $apply,
        int $limit = 1000,
        bool $continuous = false,
        int $progressEvery = 100,
        ?callable $emit = null
    ): array {
        $preflight = $this->preflight($gameSlug);
        $game = (array)$preflight['game'];
        $contract = $this->sourceContract((string)$game['slug']);
        $limit = max(1, min(5000, $limit));
        $progressEvery = max(1, $progressEvery);
        $cursor = 0;
        $processed = $succeeded = $failed = 0;
        $failures = [];
        do {
            $rows = $this->batch(
                (int)$game['id'], $cursor, $limit,
                (int)$contract['min_version'], (int)$contract['max_version']
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
                    if ($apply) { $this->registration->remove($cursor); }
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

    /** @return list<array<string,mixed>> */
    private function batch(
        int $gameId,
        int $afterId,
        int $limit,
        int $minVersion,
        int $maxVersion
    ): array {
        $sql = 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.stored_name,f.relative_path,'
            . 'f.file_size,f.md5,f.sha1,f.package_version,f.licensee_version '
            . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND v.file_id IS NULL AND f.id>? '
            . 'AND f.package_version BETWEEN ? AND ? ORDER BY f.id LIMIT ' . $limit;
        $statement = $this->db->prepare($sql);
        $statement->execute([$gameId, $afterId, $minVersion, $maxVersion]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function snapshot(string $gameSlug, string $path, array $file): array
    {
        $contract = $this->sourceContract($gameSlug);
        $engineKey = (string)$contract['engine_key'];
        $readerClass = CatalogReaderResolver::resolve(
            $this->config,
            $engineKey,
            'Reader not found for package engine',
            'Reader file loaded for package engine ',
            ['UE4','UE5']
        );
        if ($engineKey === 'UE4' || $engineKey === 'UE5') {
            if (!function_exists('gp_required_profile_for_game')) {
                throw new RuntimeException('Game parser-profile helper is unavailable for Step 6 migration.');
            }
            $game = $this->game($gameSlug);
            $profile = \gp_required_profile_for_game($this->db, (int)$file['game_id']);
            if ($engineKey === 'UE4') {
                if (!function_exists('catalog_ue4_reader_options') || !function_exists('catalog_ue4_set_next_reader_options')) {
                    throw new RuntimeException('UE4 parser-profile helpers are unavailable for Step 6 migration.');
                }
                \catalog_ue4_set_next_reader_options(\catalog_ue4_reader_options($this->config, $game, $profile));
            } else {
                if (!function_exists('catalog_ue5_reader_options') || !function_exists('catalog_ue5_set_next_reader_options')) {
                    throw new RuntimeException('UE5 parser-profile helpers are unavailable for Step 6 migration.');
                }
                \catalog_ue5_set_next_reader_options(\catalog_ue5_reader_options($this->config, $game, $profile));
            }
        }
        $reader = new $readerClass($path);

        return match ($gameSlug) {
            'ut99' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader
                ? Uedb5Ut99SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT99 migration did not resolve the canonical UE1 reader.'),
            'unrealgold' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader
                ? Uedb5UnrealSnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('Unreal migration did not resolve the canonical UE1 reader.'),
            'unreal2' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Unreal2SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('Unreal II migration did not resolve the canonical UE2 reader.'),
            'ut2003' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Ut2003SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT2003 migration did not resolve the canonical UE2 reader.'),
            'ut2004' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Ut2004SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT2004 migration did not resolve the canonical UE2 reader.'),
            'ut3' => $reader instanceof \CatalogUE3PackageReader
                ? Uedb5Ut3SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT3 migration did not resolve the canonical UE3 reader.'),
            'ut4' => $reader instanceof \UnrealPackageReader4
                ? Uedb5Ut4SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT4 migration did not resolve the canonical UE4 reader.'),
            'ue5' => $reader instanceof \UnrealPackageReader5
                ? Uedb5Ue5ClassicSnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UE5 classic migration did not resolve the canonical UE5 reader.'),
            default => throw new RuntimeException('No Step 6 source builder is registered for game ' . $gameSlug . '.'),
        };
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
        return match ($slug) {
            'ut99' => [
                'engine_key'=>'UE1',
                'min_version'=>Uedb5Ut99SnapshotBuilder::MIN_VERSION,
                'max_version'=>Uedb5Ut99SnapshotBuilder::MAX_VERSION,
            ],
            'unrealgold' => [
                'engine_key'=>'UE1',
                'min_version'=>Uedb5UnrealSnapshotBuilder::MIN_VERSION,
                'max_version'=>Uedb5UnrealSnapshotBuilder::MAX_VERSION,
            ],
            'unreal2' => [
                'engine_key'=>'UE2',
                'min_version'=>Uedb5Unreal2SnapshotBuilder::MIN_VERSION,
                'max_version'=>Uedb5Unreal2SnapshotBuilder::MAX_VERSION,
            ],
            'ut2003' => [
                'engine_key'=>'UE2',
                'min_version'=>Uedb5Ut2003SnapshotBuilder::MIN_VERSION,
                'max_version'=>Uedb5Ut2003SnapshotBuilder::MAX_VERSION,
            ],
            'ut2004' => [
                'engine_key'=>'UE2',
                'min_version'=>Uedb5Ut2004SnapshotBuilder::MIN_VERSION,
                'max_version'=>Uedb5Ut2004SnapshotBuilder::MAX_VERSION,
            ],
            'ut3' => [
                'engine_key'=>'UE3',
                'min_version'=>Uedb5Ut3SnapshotBuilder::PACKAGE_VERSION,
                'max_version'=>Uedb5Ut3SnapshotBuilder::PACKAGE_VERSION,
            ],
            'ut4' => [
                'engine_key'=>'UE4',
                'min_version'=>Uedb5Ut4SnapshotBuilder::PACKAGE_VERSION,
                'max_version'=>Uedb5Ut4SnapshotBuilder::PACKAGE_VERSION,
            ],
            'ue5' => [
                'engine_key'=>'UE5',
                'min_version'=>1000,
                'max_version'=>1018,
            ],
            default => throw new RuntimeException(
                'Step 6 source migration is not yet implemented for game ' . $slug . '.'
            ),
        };
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
