<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Resolves rare case-only Export path misses from current format-2 metadata containers.
 * Why: Historical MySQL text comparisons were case-insensitive while ue_export_lookup.path_hash is byte-sensitive.
 *      Exact hash matching remains the fast path; this bounded fallback preserves the historical lookup semantics
 *      entirely from current compact metadata.
 * Role: Infrastructure current-metadata compatibility resolver used only after compact hash lookup misses.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;

final class PdoCompactCaseInsensitiveExportResolver
{
    private const PAGE_SIZE = 5000;

    /**
     * @param list<array{lookup_value:string,package_name:string,local_path:string}> $objectLookups
     * @param array<string,array{file_id:int,export_index:int,source:string}> $matches
     */
    public static function fill(
        PDO $db,
        int $gameId,
        int $preferredFileId,
        array $objectLookups,
        array &$matches
    ): void {
        $pendingByPackage = [];
        foreach ($objectLookups as $lookup) {
            $lookupValue = (string)$lookup['lookup_value'];
            if (isset($matches[self::key($lookupValue)])) {
                continue;
            }
            $packageName = trim((string)$lookup['package_name']);
            $localPath = (string)$lookup['local_path'];
            if ($packageName === '' || $localPath === '') {
                continue;
            }
            $packageKey = self::key($packageName);
            $pathKey = self::key($localPath);
            $pendingByPackage[$packageKey]['package_name'] = $packageName;
            $pendingByPackage[$packageKey]['paths'][$pathKey][] = $lookupValue;
        }
        if ($pendingByPackage === []) {
            return;
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new RuntimeException(
                'Catalog storage_path is required for current-metadata case-insensitive Export resolution.'
            );
        }
        $reader = new BlockedCompressedMetadataReader($db, $storageRoot);

        foreach ($pendingByPackage as $group) {
            $packageName = (string)$group['package_name'];
            $pendingPaths = (array)$group['paths'];

            self::matchProviderFiles(
                $reader,
                self::primaryProviderFileIds($db, $gameId, $preferredFileId, $packageName),
                $pendingPaths,
                'exact_object',
                $matches
            );
            if ($pendingPaths !== []) {
                self::matchProviderFiles(
                    $reader,
                    self::aliasProviderFileIds($db, $gameId, $preferredFileId, $packageName),
                    $pendingPaths,
                    'exact_object_alias',
                    $matches
                );
            }
        }
    }

    /**
     * @param list<int> $fileIds
     * @param array<string,list<string>> $pendingPaths
     * @param array<string,array{file_id:int,export_index:int,source:string}> $matches
     */
    private static function matchProviderFiles(
        BlockedCompressedMetadataReader $reader,
        array $fileIds,
        array &$pendingPaths,
        string $source,
        array &$matches
    ): void {
        foreach ($fileIds as $fileId) {
            for ($start = 0; ; $start += self::PAGE_SIZE) {
                $page = $reader->page($fileId, 'exports', $start, self::PAGE_SIZE);
                foreach ($page as $export) {
                    $pathKey = self::key((string)($export['local_path'] ?? ''));
                    $lookupValues = $pendingPaths[$pathKey] ?? null;
                    if (!is_array($lookupValues)) {
                        continue;
                    }
                    foreach ($lookupValues as $lookupValue) {
                        $lookupKey = self::key($lookupValue);
                        if (!isset($matches[$lookupKey])) {
                            $matches[$lookupKey] = [
                                'file_id' => $fileId,
                                'export_index' => (int)$export['export_index'],
                                'source' => $source,
                            ];
                        }
                    }
                    unset($pendingPaths[$pathKey]);
                    if ($pendingPaths === []) {
                        return;
                    }
                }
                if (count($page) < self::PAGE_SIZE) {
                    break;
                }
            }
        }
    }

    /** @return list<int> */
    private static function primaryProviderFileIds(
        PDO $db,
        int $gameId,
        int $preferredFileId,
        string $packageName
    ): array {
        $statement = $db->prepare(
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.package_name=? '
            . 'ORDER BY (f.id=?) DESC,f.uploaded_at DESC,f.id DESC'
        );
        $statement->execute([$gameId, $packageName, $preferredFileId]);
        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /** @return list<int> */
    private static function aliasProviderFileIds(
        PDO $db,
        int $gameId,
        int $preferredFileId,
        string $packageName
    ): array {
        $statement = $db->prepare(
            'SELECT a.file_id FROM ue_file_package_aliases a '
            . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE a.game_id=? AND f.scan_status="verified" AND a.package_name=? '
            . 'ORDER BY (f.id=?) DESC,f.uploaded_at DESC,a.id ASC'
        );
        $statement->execute([$gameId, $packageName, $preferredFileId]);
        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
            $id = (int)$value;
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
