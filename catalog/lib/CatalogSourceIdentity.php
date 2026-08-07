<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog source identity.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogCompactMetadataMutation.php';

/**
 * UE4/UE5 canonical package identity is derived from the mounted source path.
 * Database package names, export full paths and alias rows are projections of
 * that source identity; they are never repaired in display code.
 */

function catalog_source_identity_path(string $sourceRelativePath): string
{
    $sourceRelativePath = scanner_normalize_source_relative_path($sourceRelativePath);
    if ($sourceRelativePath === '') {
        return '';
    }
    return preg_replace('/\.(uz|uz2|uz3)$/i', '', $sourceRelativePath) ?? $sourceRelativePath;
}

function catalog_source_identity_package_name(string $engineKey, string $sourceRelativePath, string $originalName = ''): string
{
    $engineKey = strtoupper(trim($engineKey));
    $sourceRelativePath = catalog_source_identity_path($sourceRelativePath);
    if (in_array($engineKey, ['UE4', 'UE5'], true)) {
        return scanner_ue_package_name_from_source_relative($sourceRelativePath);
    }
    $sourceOriginalName = scanner_original_name_from_source_relative($sourceRelativePath);
    return scanner_logical_package_name($sourceOriginalName !== '' ? $sourceOriginalName : $originalName);
}

/** @return list<string> */
function catalog_source_identity_normalized_names(array $names): array
{
    $normalized = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $normalized[mb_strtolower($name, 'UTF-8')] = $name;
        }
    }
    ksort($normalized);
    return array_values($normalized);
}

/**
 * @param list<string> $previousPackageNames
 * @return array{changed:bool,file_id:int,old_package_name:string,new_package_name:string,alias_count:int,dependency_files_refreshed:int,reconciliation_job_id:int}
 */
function catalog_source_identity_rebuild_file(
    PDO $db,
    array $config,
    int $fileId,
    ?callable $progress = null,
    bool $refreshDependencies = true,
    array $previousPackageNames = []
): array {
    scanner_source_path_schema_ensure($db);
    catalog_package_aliases_ensure($db);

    $file = catalog_one(
        $db,
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

    $locations = catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=? ORDER BY id', [$fileId]);
    $primarySourcePath = catalog_source_identity_path((string)($file['source_relative_path'] ?? ''));
    if ($primarySourcePath === '') {
        foreach ($locations as $location) {
            $candidate = catalog_source_identity_path((string)($location['source_relative_path'] ?? ''));
            if ($candidate !== '') {
                $primarySourcePath = $candidate;
                break;
            }
        }
    }

    $primaryPackageName = catalog_source_identity_package_name(
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

    $primaryOriginalName = scanner_original_name_from_source_relative($primarySourcePath);
    if ($primaryOriginalName === '') {
        $primaryOriginalName = (string)$file['original_name'];
    }

    $sourcePaths = [];
    if ($primarySourcePath !== '') {
        $sourcePaths[mb_strtolower($primarySourcePath, 'UTF-8')] = $primarySourcePath;
    }
    foreach ($locations as $location) {
        $candidate = catalog_source_identity_path((string)($location['source_relative_path'] ?? ''));
        if ($candidate !== '') {
            $sourcePaths[mb_strtolower($candidate, 'UTF-8')] = $candidate;
        }
    }

    $derivedAliases = [];
    foreach ($sourcePaths as $sourcePath) {
        $packageName = catalog_source_identity_package_name(
            $engineKey,
            $sourcePath,
            scanner_original_name_from_source_relative($sourcePath)
        );
        if ($packageName === '' || strcasecmp($packageName, $primaryPackageName) === 0) {
            continue;
        }
        $derivedAliases[mb_strtolower($packageName, 'UTF-8')] = [
            'package_name' => $packageName,
            'original_name' => scanner_original_name_from_source_relative($sourcePath),
        ];
    }
    ksort($derivedAliases);

    $oldAliases = catalog_all($db, 'SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY package_name,id', [$fileId]);
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
    $sourceChanged = catalog_source_identity_path((string)($file['source_relative_path'] ?? '')) !== $primarySourcePath;
    $originalChanged = (string)$file['original_name'] !== $primaryOriginalName;
    $aliasesChanged = $oldAliasKeys !== $newAliasKeys;
    $changed = $primaryChanged || $sourceChanged || $originalChanged || $aliasesChanged;

    $newPackageNames = [$primaryPackageName];
    foreach ($derivedAliases as $alias) {
        $newPackageNames[] = $alias['package_name'];
    }
    $allPackageNames = catalog_source_identity_normalized_names(array_merge($oldPackageNames, $newPackageNames));

    $referringFileIds = [];
    if ($allPackageNames !== []) {
        $sql = 'SELECT DISTINCT d.file_id FROM ue_dependencies d '
            . 'JOIN ue_files owner ON owner.id=d.file_id '
            . 'WHERE owner.game_id=? AND d.file_id<>? AND d.required_package IN ('
            . implode(',', array_fill(0, count($allPackageNames), '?')) . ')';
        foreach (catalog_all($db, $sql, [(int)$file['game_id'], $fileId, ...$allPackageNames]) as $row) {
            $referringFileIds[] = (int)$row['file_id'];
        }
    }

    if ($changed) {
        scanner_emit_percent($progress, 'identity', 10, 'Rebuilding canonical UE4 package identity from mounted source path');
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE ue_files SET package_name=?,original_name=?,source_relative_path=? WHERE id=?')
                ->execute([
                    $primaryPackageName,
                    $primaryOriginalName,
                    $primarySourcePath !== '' ? $primarySourcePath : null,
                    $fileId,
                ]);

            $db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            $insertAlias = $db->prepare(
                'INSERT INTO ue_file_package_aliases(file_id,game_id,package_name,original_name,package_guid,md5,file_size) '
                . 'VALUES(?,?,?,?,?,?,?)'
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
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        try {
            catalog_compact_metadata_rewrite_package_identity(
                $db,
                $config,
                $fileId,
                $primaryPackageName
            );
        } catch (Throwable $error) {
            $db->prepare(
                'UPDATE ue_files SET scan_notes=CONCAT_WS("\n",NULLIF(scan_notes,""),?) WHERE id=?'
            )->execute([
                'Compact source identity publication failed: ' . $error->getMessage(),
                $fileId,
            ]);
            throw $error;
        }
    }

    $refreshed = 0;
    if ($refreshDependencies && ($changed || $previousPackageNames !== [])) {
        $dependencyFileIds = array_values(array_unique(array_merge([$fileId], $referringFileIds)));
        $dependencyFileIds = array_values(array_filter(
            $dependencyFileIds,
            static fn(int $id): bool => $id > 0 && (bool)catalog_one($db, 'SELECT id FROM ue_files WHERE id=?', [$id])
        ));
        $total = max(1, count($dependencyFileIds));
        foreach ($dependencyFileIds as $index => $dependencyFileId) {
            scanner_rebuild_dependencies(
                $db,
                $config,
                $dependencyFileId,
                $progress,
                scanner_range_percent(20, 100, $index, $total),
                scanner_range_percent(20, 100, $index + 1, $total),
                'Refreshing dependencies after canonical UE4 identity rebuild ' . ($index + 1) . '/' . count($dependencyFileIds)
            );
            $refreshed++;
        }
    } else {
        scanner_emit_percent(
            $progress,
            'identity',
            100,
            $changed ? 'Canonical UE4 package identity rebuilt' : 'Package identity already matches its mounted source path'
        );
    }

    $reconciliationJobId = 0;
    if ($changed || $previousPackageNames !== []) {
        $reconciliationJobId = \UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue::enqueue(
            $db,
            $fileId,
            [(int)$file['game_id']],
            $allPackageNames,
            $config
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
