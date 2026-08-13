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
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class PdoCompactCaseInsensitiveExportResolver
{
    private const PAGE_SIZE = 5000;

    /** @var array<int,true> */
    private static array $reportedUnreadableProviders = [];

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
                $matches,
                $preferredFileId
            );
            if ($pendingPaths !== []) {
                self::matchProviderFiles(
                    $reader,
                    self::aliasProviderFileIds($db, $gameId, $preferredFileId, $packageName),
                    $pendingPaths,
                    'exact_object_alias',
                    $matches,
                    $preferredFileId
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
        array &$matches,
        int $preferredFileId
    ): void {
        foreach ($fileIds as $fileId) {
            for ($start = 0; ; $start += self::PAGE_SIZE) {
                try {
                    // Long-lived workers may have seen this stable path before a
                    // concurrent/earlier format-2 replacement. Never let PHP's stat
                    // cache manufacture a provider size mismatch.
                    clearstatcache();
                    $page = $reader->page($fileId, 'exports', $start, self::PAGE_SIZE);
                } catch (Throwable $error) {
                    // One damaged provider is an issue with that provider, not with
                    // every consumer that happens to reference it. Skip it and try
                    // the next provider. The preferred file is the package currently
                    // being finalized/repaired; its previous container may legitimately
                    // be absent until this publication completes, so do not create a
                    // transient operator error for that self-provider case. If the
                    // final publication itself fails, that failure is reported by the
                    // importer/repair workflow instead.
                    if ($fileId !== $preferredFileId) {
                        self::reportUnreadableProvider($fileId, $error);
                    }
                    break;
                }
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

    private static function reportUnreadableProvider(int $fileId, Throwable $error): void
    {
        if ($fileId < 1 || isset(self::$reportedUnreadableProviders[$fileId])) {
            return;
        }
        self::$reportedUnreadableProviders[$fileId] = true;

        CatalogSystemErrorRecorder::record([
            'source_kind' => 'compact-metadata-provider',
            'severity' => 'error',
            'error_type' => 'UnreadableCompactMetadataProvider',
            'message' => 'Verified provider file #' . $fileId
                . ' has unreadable format-2 metadata and was skipped during dependency resolution: '
                . trim($error->getMessage()),
            'source_file' => $error->getFile(),
            'source_line' => $error->getLine(),
            'trace_text' => $error->getTraceAsString(),
            'context' => [
                'provider_file_id' => $fileId,
                'operation' => 'case_insensitive_export_resolution',
            ],
        ]);
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
