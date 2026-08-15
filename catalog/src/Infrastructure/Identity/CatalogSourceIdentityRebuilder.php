<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Rebuilds canonical UE4/UE5 package identity from mounted source paths.
 * Why: Alias derivation, identity mutation, compact publication, dependency refresh and reconciliation are one maintenance use case.
 * Role: Infrastructure maintenance service replacing the legacy CatalogSourceIdentity monolith.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Identity;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourcePathStore;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

final class CatalogSourceIdentityRebuilder
{
    private readonly CatalogSourceIdentityNaming $naming;
    private readonly PdoCatalogDependencyRebuilder $dependencies;
    private readonly PdoCatalogSourcePathStore $sourcePaths;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogPackageAliases.php';
        require_once $root . '/lib/CatalogCompactMetadataMutation.php';
        require_once $root . '/lib/Scanner/CatalogScannerSupport.php';
        $this->naming = new CatalogSourceIdentityNaming();
        $this->dependencies = new PdoCatalogDependencyRebuilder($db, $config);
        $this->sourcePaths = new PdoCatalogSourcePathStore($db);
    }

    /**
     * @param list<string> $previousPackageNames
     * @return array{changed:bool,file_id:int,old_package_name:string,new_package_name:string,alias_count:int,dependency_files_refreshed:int,reconciliation_job_id:int}
     */
    public function rebuild(
        int $fileId,
        ?callable $progress = null,
        bool $refreshDependencies = true,
        array $previousPackageNames = []
    ): array {
        $this->sourcePaths->ensureSchema();
        \catalog_package_aliases_ensure($this->db);

        $file = \catalog_one(
            $this->db,
            'SELECT f.*,p.engine_key profile_engine FROM ue_files f '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE f.id=?',
            [$fileId]
        );
        if (!$file) {
            throw new RuntimeException('File no longer exists in the catalog.');
        }

        $engineKey = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        if ($engineKey === '') {
            $engineKey = strtoupper(trim((string)($file['profile_engine'] ?? '')));
        }
        if (!in_array($engineKey, ['UE4', 'UE5'], true)) {
            throw new RuntimeException(
                'Mounted source identity repair is only valid for UE4/UE5 packages. '
                . 'Use the read-only UE1/UE2/UE3 Data Audit for legacy packages.'
            );
        }

        $locations = \catalog_all(
            $this->db,
            'SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id',
            [$fileId]
        );
        $primarySourcePath = $this->naming->path((string)($file['source_relative_path'] ?? ''));
        if ($primarySourcePath === '') {
            foreach ($locations as $location) {
                $candidate = $this->naming->path((string)($location['source_relative_path'] ?? ''));
                if ($candidate !== '') {
                    $primarySourcePath = $candidate;
                    break;
                }
            }
        }

        $primaryPackageName = $this->naming->packageName(
            $engineKey,
            $primarySourcePath,
            (string)$file['original_name']
        );
        if ($primaryPackageName === '') {
            throw new RuntimeException(
                'A canonical UE4 package identity could not be derived from the mounted source path. '
                . 'Re-import this file from a mounted source folder.'
            );
        }

        $primaryOriginalName = \scanner_original_name_from_source_relative($primarySourcePath);
        if ($primaryOriginalName === '') {
            $primaryOriginalName = (string)$file['original_name'];
        }

        $sourcePaths = [];
        if ($primarySourcePath !== '') {
            $sourcePaths[mb_strtolower($primarySourcePath, 'UTF-8')] = $primarySourcePath;
        }
        foreach ($locations as $location) {
            $candidate = $this->naming->path((string)($location['source_relative_path'] ?? ''));
            if ($candidate !== '') {
                $sourcePaths[mb_strtolower($candidate, 'UTF-8')] = $candidate;
            }
        }

        $derivedAliases = [];
        foreach ($sourcePaths as $sourcePath) {
            $packageName = $this->naming->packageName(
                $engineKey,
                $sourcePath,
                \scanner_original_name_from_source_relative($sourcePath)
            );
            if ($packageName === '' || strcasecmp($packageName, $primaryPackageName) === 0) {
                continue;
            }
            $derivedAliases[mb_strtolower($packageName, 'UTF-8')] = [
                'package_name' => $packageName,
                'original_name' => \scanner_original_name_from_source_relative($sourcePath),
            ];
        }
        ksort($derivedAliases);

        $oldAliases = \catalog_all(
            $this->db,
            'SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY package_name,id',
            [$fileId]
        );
        $oldPackageNames = $previousPackageNames;
        $oldPackageNames[] = (string)$file['package_name'];
        $oldAliasKeys = [];
        foreach ($oldAliases as $alias) {
            $name = trim((string)($alias['package_name'] ?? ''));
            if ($name !== '') {
                $oldPackageNames[] = $name;
                $oldAliasKeys[] = mb_strtolower($name, 'UTF-8');
            }
        }
        sort($oldAliasKeys);
        $newAliasKeys = array_keys($derivedAliases);
        sort($newAliasKeys);

        $primaryChanged = strcasecmp((string)$file['package_name'], $primaryPackageName) !== 0;
        $sourceChanged = $this->naming->path((string)($file['source_relative_path'] ?? '')) !== $primarySourcePath;
        $originalChanged = (string)$file['original_name'] !== $primaryOriginalName;
        $aliasesChanged = $oldAliasKeys !== $newAliasKeys;
        $changed = $primaryChanged || $sourceChanged || $originalChanged || $aliasesChanged;

        $newPackageNames = [$primaryPackageName];
        foreach ($derivedAliases as $alias) {
            $newPackageNames[] = $alias['package_name'];
        }
        $allPackageNames = $this->naming->normalizedNames(array_merge($oldPackageNames, $newPackageNames));

        $referringFileIds = $this->referringFileIds(
            (int)$file['game_id'],
            $fileId,
            $allPackageNames
        );

        if ($changed) {
            \scanner_emit_percent(
                $progress,
                'identity',
                10,
                'Rebuilding canonical UE4 package identity from mounted source path'
            );
            $this->persistIdentity(
                $file,
                $fileId,
                $primaryPackageName,
                $primaryOriginalName,
                $primarySourcePath,
                $derivedAliases
            );
            try {
                \catalog_compact_metadata_rewrite_package_identity(
                    $this->db,
                    $this->config,
                    $fileId,
                    $primaryPackageName
                );
            } catch (Throwable $error) {
                $this->db->prepare(
                    'UPDATE ue_files SET scan_notes=CONCAT_WS("\n",NULLIF(scan_notes,""),?) WHERE id=?'
                )->execute([
                    'Compact source identity publication failed: ' . $error->getMessage(),
                    $fileId,
                ]);
                throw $error;
            }
        }

        $refreshed = $this->refreshDependencies(
            $refreshDependencies,
            $fileId,
            $referringFileIds,
            $changed,
            $previousPackageNames,
            $progress
        );

        $reconciliationJobId = 0;
        if ($changed || $previousPackageNames !== []) {
            $reconciliationJobId = CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                $fileId,
                [(int)$file['game_id']],
                $allPackageNames,
                $this->config
            );
        }

        return [
            'changed' => $changed,
            'file_id' => $fileId,
            'old_package_name' => (string)$file['package_name'],
            'new_package_name' => $primaryPackageName,
            'alias_count' => count($derivedAliases),
            'dependency_files_refreshed' => $refreshed,
            'reconciliation_job_id' => $reconciliationJobId,
        ];
    }

    /** @param list<string> $packageNames @return list<int> */
    private function referringFileIds(int $gameId, int $fileId, array $packageNames): array
    {
        if ($packageNames === []) {
            return [];
        }
        $dependencySource = PdoDependencyReadSource::sql($this->db);
        $sql = 'SELECT DISTINCT d.file_id FROM ' . $dependencySource . ' d '
            . 'JOIN ue_files owner ON owner.id=d.file_id '
            . 'WHERE owner.game_id=? AND d.file_id<>? AND d.required_package IN ('
            . implode(',', array_fill(0, count($packageNames), '?')) . ')';
        $ids = [];
        foreach (\catalog_all($this->db, $sql, [$gameId, $fileId, ...$packageNames]) as $row) {
            $ids[] = (int)$row['file_id'];
        }
        return $ids;
    }

    /** @param array<string,mixed> $file @param array<string,array<string,string>> $derivedAliases */
    private function persistIdentity(
        array $file,
        int $fileId,
        string $packageName,
        string $originalName,
        string $sourcePath,
        array $derivedAliases
    ): void {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE ue_files SET package_name=?,original_name=?,source_relative_path=? WHERE id=?'
            )->execute([
                $packageName,
                $originalName,
                $sourcePath !== '' ? $sourcePath : null,
                $fileId,
            ]);

            $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            $insertAlias = $this->db->prepare(
                'INSERT INTO ue_file_package_aliases('
                . 'file_id,game_id,package_name,original_name,package_guid,md5,file_size'
                . ') VALUES(?,?,?,?,?,?,?)'
            );
            foreach ($derivedAliases as $alias) {
                $insertAlias->execute([
                    $fileId,
                    (int)$file['game_id'],
                    $alias['package_name'],
                    $alias['original_name'] !== '' ? $alias['original_name'] : basename($alias['package_name']),
                    (string)($file['package_guid'] ?? '') !== '' ? (string)$file['package_guid'] : null,
                    (string)$file['md5'],
                    (int)$file['file_size'],
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $referringFileIds @param list<string> $previousPackageNames */
    private function refreshDependencies(
        bool $refreshDependencies,
        int $fileId,
        array $referringFileIds,
        bool $changed,
        array $previousPackageNames,
        ?callable $progress
    ): int {
        if (!$refreshDependencies) {
            \scanner_emit_percent(
                $progress,
                'identity',
                100,
                $changed
                    ? 'Canonical UE4 package identity rebuilt'
                    : 'Package identity already matches its mounted source path'
            );
            return 0;
        }
        if (!$changed && $previousPackageNames === []) {
            \scanner_emit_percent(
                $progress,
                'identity',
                100,
                'Package identity already matches its mounted source path'
            );
            return 0;
        }

        $dependencyFileIds = array_values(array_unique(array_merge([$fileId], $referringFileIds)));
        $dependencyFileIds = array_values(array_filter(
            $dependencyFileIds,
            fn(int $id): bool => $id > 0
                && (bool)\catalog_one($this->db, 'SELECT id FROM ue_files WHERE id=?', [$id])
        ));
        $total = max(1, count($dependencyFileIds));
        foreach ($dependencyFileIds as $index => $dependencyFileId) {
            $this->dependencies->rebuild(
                $dependencyFileId,
                $progress,
                \scanner_range_percent(20, 100, $index, $total),
                \scanner_range_percent(20, 100, $index + 1, $total),
                'Refreshing dependencies after canonical UE4 identity rebuild '
                    . ($index + 1) . '/' . count($dependencyFileIds)
            );
        }
        return count($dependencyFileIds);
    }
}
