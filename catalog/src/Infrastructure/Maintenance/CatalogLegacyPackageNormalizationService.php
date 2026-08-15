<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Audits and repairs legacy UE1/UE2/UE3 package-root identity metadata.
 * Why: Compact metadata inspection/mutation, file identity writes and affected dependency refresh are maintenance
 *      responsibilities and must not be implemented by the rendering page.
 * Role: Infrastructure maintenance service; UE4/UE5 mounted identity remains explicitly excluded.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

final class CatalogLegacyPackageNormalizationService
{
    private readonly BlockedCompressedMetadataSnapshotLoader $snapshotLoader;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/CatalogCompactMetadataMutation.php';

        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for package normalization.');
        }
        $this->snapshotLoader = new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot);
    }

    /** @return list<array<string,mixed>> */
    public function legacyGames(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,p.engine_key FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE UPPER(COALESCE(p.engine_key,"")) NOT IN ("UE4","UE5") ORDER BY g.name'
        );
    }

    public function normalizeGameId(int $gameId): int
    {
        if ($gameId <= 0) {
            return 0;
        }
        foreach ($this->legacyGames() as $game) {
            if ((int)$game['id'] === $gameId) {
                return $gameId;
            }
        }
        return 0;
    }

    /** @return list<array<string,mixed>> */
    public function dirtyRows(int $gameId, int $limit): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT f.id,f.game_id,g.name game_name,f.package_name,f.original_name,f.package_guid,f.md5,f.file_size,f.scan_status,'
            . 'f.detected_engine_key,p.engine_key profile_engine FROM ue_files f '
            . 'JOIN ue_games g ON g.id=f.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id '
            . 'WHERE f.scan_status<>"failed" '
            . 'AND UPPER(COALESCE(f.detected_engine_key,"")) NOT IN ("UE4","UE5") '
            . 'AND UPPER(COALESCE(p.engine_key,"")) NOT IN ("UE4","UE5")';
        $args = [];
        if ($gameId > 0) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY g.name,f.package_name,f.original_name,f.id';

        $dirty = [];
        foreach (\catalog_all($this->db, $sql, $args) as $row) {
            $this->assertLegacyEngine($row);
            $cleanPackage = $this->cleanPackage($row);
            $cleanOriginal = $this->cleanOriginal($row);
            $exportDirty = $this->exportDirtyCount((int)$row['id'], $cleanPackage);
            if ((string)$row['package_name'] === $cleanPackage
                && (string)$row['original_name'] === $cleanOriginal
                && $exportDirty === 0) {
                continue;
            }
            $row['clean_package_name'] = $cleanPackage;
            $row['clean_original_name'] = $cleanOriginal;
            $row['dirty_export_count'] = $exportDirty;
            $dirty[] = $row;
            if (count($dirty) >= $limit) {
                break;
            }
        }
        return $dirty;
    }

    /**
     * @param list<int> $ids
     * @return array{changed:int,affected_packages:int,rebuild_warnings:int,rebuild:bool}
     */
    public function normalize(array $ids, int $maxRows, bool $rebuildDependencies): array
    {
        $ids = array_values(array_filter(
            array_unique(array_map('intval', $ids)),
            static fn(int $id): bool => $id > 0
        ));
        if (count($ids) > $maxRows) {
            throw new RuntimeException('Too many files selected. Process at most ' . $maxRows . ' files at once.');
        }
        if ($ids === []) {
            throw new RuntimeException('Select at least one file to normalize.');
        }

        $changed = [];
        $affectedPackages = [];
        foreach ($ids as $fileId) {
            $result = $this->normalizeFile($fileId);
            if (!$result['changed']) {
                continue;
            }
            $changed[] = $result;
            foreach ([$result['old_package'], $result['new_package']] as $packageName) {
                $packageName = trim((string)$packageName);
                if ($packageName !== '') {
                    $affectedPackages[(int)$result['game_id'] . ':' . mb_strtolower($packageName, 'UTF-8')] = [
                        (int)$result['game_id'],
                        $packageName,
                    ];
                }
            }
        }

        foreach ($changed as $result) {
            CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                (int)$result['file_id'],
                [(int)$result['game_id']],
                [(string)$result['old_package'], (string)$result['new_package']],
                $this->config
            );
        }

        $warnings = 0;
        if ($rebuildDependencies) {
            foreach ($affectedPackages as [$affectedGameId, $packageName]) {
                try {
                    \scanner_rebuild_affected_dependencies_for_package(
                        $this->db,
                        $this->config,
                        (int)$affectedGameId,
                        (string)$packageName,
                        null,
                        0,
                        100
                    );
                } catch (Throwable $error) {
                    $warnings++;
                    error_log(
                        '[UnrealDB package normalize] dependency refresh failed for game=' . (int)$affectedGameId
                        . ' package=' . (string)$packageName . ': ' . $error->getMessage()
                    );
                }
            }
        }

        return [
            'changed' => count($changed),
            'affected_packages' => count($affectedPackages),
            'rebuild_warnings' => $warnings,
            'rebuild' => $rebuildDependencies,
        ];
    }

    /** @return array{changed:bool,file_id:int,game_id:int,old_package:string,new_package:string,old_original:string,new_original:string,export_rows:int} */
    private function normalizeFile(int $fileId): array
    {
        $file = \catalog_one(
            $this->db,
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.detected_engine_key,p.engine_key profile_engine '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE f.id=?',
            [$fileId]
        );
        if (!$file) {
            throw new RuntimeException('File ID ' . $fileId . ' no longer exists.');
        }
        $this->assertLegacyEngine($file);

        $cleanPackage = $this->cleanPackage($file);
        $cleanOriginal = $this->cleanOriginal($file);
        $oldPackage = (string)$file['package_name'];
        $oldOriginal = (string)$file['original_name'];
        $exportDirty = $this->exportDirtyCount($fileId, $cleanPackage);
        $changed = $oldPackage !== $cleanPackage || $oldOriginal !== $cleanOriginal || $exportDirty > 0;

        if ($changed) {
            $this->db->prepare('UPDATE ue_files SET package_name=?,original_name=? WHERE id=?')
                ->execute([$cleanPackage, $cleanOriginal, $fileId]);
            try {
                $exportDirty = \catalog_compact_metadata_rewrite_package_identity(
                    $this->db,
                    $this->config,
                    $fileId,
                    $cleanPackage
                );
            } catch (Throwable $error) {
                $this->db->prepare('UPDATE ue_files SET package_name=?,original_name=? WHERE id=?')
                    ->execute([$oldPackage, $oldOriginal, $fileId]);
                throw $error;
            }
        }

        return [
            'changed' => $changed,
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'old_package' => $oldPackage,
            'new_package' => $cleanPackage,
            'old_original' => $oldOriginal,
            'new_original' => $cleanOriginal,
            'export_rows' => $exportDirty,
        ];
    }

    private function isModernEngine(array $file): bool
    {
        $detected = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        $profile = strtoupper(trim((string)($file['profile_engine'] ?? '')));
        return in_array($detected, ['UE4', 'UE5'], true) || in_array($profile, ['UE4', 'UE5'], true);
    }

    private function assertLegacyEngine(array $file): void
    {
        if ($this->isModernEngine($file)) {
            throw new RuntimeException(
                'UE4/UE5 package identities must not be processed by the legacy package-root normalizer. '
                . 'Use Source Identity Repair so mounted paths such as /Engine/... and valid characters such as + are preserved.'
            );
        }
    }

    private function cleanPackage(array $file): string
    {
        $package = trim((string)($file['package_name'] ?? ''));
        if ($package === '') {
            $cleanFile = \catalog_clean_unreal_filename((string)($file['original_name'] ?? ''));
            $package = (string)pathinfo($cleanFile, PATHINFO_FILENAME);
        }
        return \catalog_clean_unreal_package_stem($package);
    }

    private function cleanOriginal(array $file): string
    {
        return \catalog_clean_unreal_filename((string)($file['original_name'] ?? ''));
    }

    private function exportDirtyCount(int $fileId, string $cleanPackage): int
    {
        $format = \catalog_one($this->db, 'SELECT format_version FROM ue_file_metadata WHERE file_id=?', [$fileId]);
        $formatVersion = (int)($format['format_version'] ?? 0);
        if ($formatVersion < 2) {
            throw new RuntimeException(
                'File #' . $fileId . ' has no current format-2 metadata; package normalization cannot use retired export rows.'
            );
        }

        $snapshot = $this->snapshotLoader->load($fileId);
        $dirty = 0;
        foreach ((array)($snapshot['exports'] ?? []) as $export) {
            $localPath = (string)($export['local_path'] ?? '');
            $expected = \catalog_compact_metadata_join_package_path($cleanPackage, $localPath);
            if ((string)($export['full_path'] ?? '') !== $expected) {
                $dirty++;
            }
        }
        return $dirty;
    }
}
