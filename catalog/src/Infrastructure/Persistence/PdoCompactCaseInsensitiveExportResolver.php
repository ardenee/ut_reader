<?php
/**
 * Resolves rare case-only export path misses from authoritative UEDB5.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;

final class PdoCompactCaseInsensitiveExportResolver
{
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
            $localPath = trim((string)$lookup['local_path']);
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

        $reader = new Uedb5MetadataReader(self::storageRoot());
        foreach ($pendingByPackage as $group) {
            $packageName = (string)$group['package_name'];
            $pendingPaths = (array)$group['paths'];
            $providers = self::providerFileIds($db, $gameId, $preferredFileId, $packageName);
            self::matchProviderFiles(
                $db,
                $reader,
                $gameId,
                $providers,
                $pendingPaths,
                $matches,
                $preferredFileId
            );
        }
    }

    /**
     * @param list<string> $localPaths
     * @return array<string,int> normalized path => export index
     */
    public static function matchProviderPaths(PDO $db, int $fileId, array $localPaths): array
    {
        $pending = [];
        foreach ($localPaths as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $pending[self::key($path)] = true;
            }
        }
        if ($fileId < 1 || $pending === []) {
            return [];
        }

        $statement = $db->prepare(
            'SELECT game_id FROM ue_uedb5_files WHERE file_id=? AND format_version=5 LIMIT 1'
        );
        $statement->execute([$fileId]);
        $gameId = (int)($statement->fetchColumn() ?: 0);
        if ($gameId < 1) {
            return [];
        }

        $reader = new Uedb5MetadataReader(self::storageRoot());
        try {
            $snapshot = $reader->snapshot($gameId, $fileId);
            $exports = Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot);
        } catch (Throwable $error) {
            self::reportUnreadableProvider($db, $fileId, $error);
            return [];
        } finally {
            $reader->clearCache($gameId, $fileId);
        }

        $matches = [];
        foreach ($exports as $export) {
            $key = self::key((string)($export['local_path'] ?? ''));
            if (!isset($pending[$key])) {
                continue;
            }
            $matches[$key] = (int)($export['export_index'] ?? -1);
            unset($pending[$key]);
            if ($pending === []) {
                break;
            }
        }
        return $matches;
    }

    /**
     * @param list<array{file_id:int,source:string}> $providers
     * @param array<string,list<string>> $pendingPaths
     * @param array<string,array{file_id:int,export_index:int,source:string}> $matches
     */
    private static function matchProviderFiles(
        PDO $db,
        Uedb5MetadataReader $reader,
        int $gameId,
        array $providers,
        array &$pendingPaths,
        array &$matches,
        int $preferredFileId
    ): void {
        foreach ($providers as $provider) {
            $fileId = (int)$provider['file_id'];
            try {
                $snapshot = $reader->snapshot($gameId, $fileId);
                $exports = Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot);
            } catch (Throwable $error) {
                if ($fileId !== $preferredFileId) {
                    self::reportUnreadableProvider($db, $fileId, $error);
                }
                continue;
            } finally {
                $reader->clearCache($gameId, $fileId);
            }

            foreach ($exports as $export) {
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
                            'export_index' => (int)($export['export_index'] ?? -1),
                            'source' => (string)$provider['source'],
                        ];
                    }
                }
                unset($pendingPaths[$pathKey]);
                if ($pendingPaths === []) {
                    return;
                }
            }
        }
    }

    private static function reportUnreadableProvider(PDO $db, int $fileId, Throwable $error): void
    {
        if ($fileId < 1 || isset(self::$reportedUnreadableProviders[$fileId])) {
            return;
        }
        self::$reportedUnreadableProviders[$fileId] = true;

        CatalogSystemErrorRecorder::record([
            'source_kind' => 'uedb5-metadata-provider',
            'severity' => 'error',
            'error_type' => 'UnreadableUedb5MetadataProvider',
            'message' => 'Verified provider file #' . $fileId
                . ' has unreadable UEDB5 metadata and was skipped during dependency resolution: '
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

    /** @return list<array{file_id:int,source:string}> */
    private static function providerFileIds(
        PDO $db,
        int $gameId,
        int $preferredFileId,
        string $packageName
    ): array {
        $exactKind = Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME;
        $legacyKind = Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME;
        $exactKey = Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $exactKind);
        $legacyKey = Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $legacyKind);
        $statement = $db->prepare(
            'SELECT p.file_id,p.source_kind,p.source_id,v.package_name,a.package_name alias_name,f.uploaded_at '
            . 'FROM ue_uedb5_provider_keys p '
            . 'JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind=2 AND a.id=p.source_id '
            . 'WHERE p.game_id=? AND ('
            . '(p.package_key_kind=? AND p.package_key=?) OR '
            . '(p.package_key_kind=? AND p.package_key=?)) '
            . 'ORDER BY (p.file_id=?) DESC,(p.source_kind=1) DESC,f.uploaded_at DESC,p.source_id ASC,p.file_id ASC'
        );
        $statement->execute([$gameId,$exactKind,$exactKey,$legacyKind,$legacyKey,$preferredFileId]);

        $wanted = CatalogUnrealIdentityHash::fnameKey($packageName);
        $seen = [];
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sourceName = (int)$row['source_kind'] === 2
                ? (string)($row['alias_name'] ?? '')
                : (string)($row['package_name'] ?? '');
            if (CatalogUnrealIdentityHash::fnameKey($sourceName) !== $wanted) {
                continue;
            }
            $fileId = (int)$row['file_id'];
            if ($fileId < 1 || isset($seen[$fileId])) {
                continue;
            }
            $seen[$fileId] = true;
            $rows[] = [
                'file_id' => $fileId,
                'source' => (int)$row['source_kind'] === 2 ? 'exact_object_alias' : 'exact_object',
            ];
        }
        return $rows;
    }

    private static function storageRoot(): string
    {
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $storageRoot = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 export resolution.');
        }
        return $storageRoot;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
