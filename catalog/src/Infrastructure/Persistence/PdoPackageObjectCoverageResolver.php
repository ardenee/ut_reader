<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

/**
 * Evaluates candidate physical providers against required package-relative
 * object paths using authoritative UEDB5 source-shaped exports.
 */
final class PdoPackageObjectCoverageResolver
{
    /**
     * @param list<string> $requiredObjectPaths
     * @param array<string,mixed> $requiredClassesByPath
     * @return list<array<string,mixed>>
     */
    public static function evaluate(
        PDO $db,
        int $gameId,
        string $packageName,
        array $requiredObjectPaths,
        int $preferredFileId = 0,
        array $requiredClassesByPath = [],
        string $engineKey = ''
    ): array {
        $engineKey = strtoupper(trim($engineKey));
        $packageName = trim($packageName);
        if ($gameId < 1 || $packageName === '') {
            return [];
        }

        $requirements = self::requirements($packageName, $requiredObjectPaths);
        $requiredClasses = self::requiredClasses($packageName, $requiredClassesByPath);
        $providers = self::providers($db, $gameId, $packageName, $preferredFileId);
        if ($providers === []) {
            return [];
        }

        $reader = new Uedb5MetadataReader(self::storageRoot());
        $matched = [];
        $matchedExports = [];
        foreach (array_keys($providers) as $fileId) {
            $matched[$fileId] = [];
            $matchedExports[$fileId] = [];
            if ($requirements === []) {
                continue;
            }
            try {
                $snapshot = $reader->snapshot($gameId, (int)$fileId);
                $exports = Uedb5ClassicDependencyResolver::exportCoverageRows($snapshot);
            } catch (\Throwable $error) {
                error_log(
                    '[UnrealDB UEDB5 provider coverage] file_id=' . (int)$fileId
                    . ' error=' . $error->getMessage()
                );
                continue;
            } finally {
                $reader->clearCache($gameId, (int)$fileId);
            }

            foreach ($exports as $export) {
                $localPath = trim((string)($export['local_path'] ?? ''), '. ');
                $requiredKey = self::key($localPath);
                if ($requiredKey === '' || !isset($requirements[$requiredKey])) {
                    continue;
                }

                $requiredClass = $requiredClasses[$requiredKey] ?? null;
                if ($engineKey === 'UE3') {
                    if (!is_array($requiredClass)) {
                        continue;
                    }
                    $requiredName = self::key((string)($requiredClass['class_name'] ?? ''));
                    $requiredPackage = self::key((string)($requiredClass['class_package'] ?? ''));
                    $actualName = self::key((string)($export['class_name'] ?? ''));
                    $actualPackage = self::key((string)($export['class_package'] ?? ''));
                    if ($requiredName === '' || $requiredPackage === ''
                        || $actualName !== $requiredName || $actualPackage !== $requiredPackage) {
                        continue;
                    }
                    // UE3 RF_Public is bit 34 in the source-width 64-bit object flags.
                    if ((((int)($export['object_flags'] ?? 0)) & 0x0000000400000000) === 0) {
                        continue;
                    }
                } elseif (is_array($requiredClass)) {
                    $actualName = self::key((string)($export['class_name'] ?? ''));
                    $actualPackage = self::key((string)($export['class_package'] ?? ''));
                    $requiredName = self::key((string)($requiredClass['class_name'] ?? ''));
                    $requiredPackage = self::key((string)($requiredClass['class_package'] ?? ''));
                    if ($requiredName !== '' && $actualName !== $requiredName) {
                        continue;
                    }
                    if ($requiredPackage !== '' && $actualPackage !== ''
                        && $actualPackage !== $requiredPackage) {
                        continue;
                    }
                }

                if (!isset($matched[$fileId][$requiredKey])) {
                    $matched[$fileId][$requiredKey] = true;
                    $matchedExports[$fileId][$requiredKey] = (int)($export['export_index'] ?? -1);
                }
            }
        }

        $providerOrder = [];
        foreach (array_keys($providers) as $position => $providerFileId) {
            $providerOrder[(int)$providerFileId] = $position;
        }

        $result = [];
        foreach ($providers as $fileId => $provider) {
            $matchedPaths = [];
            $missingPaths = [];
            foreach ($requirements as $key => $path) {
                if (isset($matched[$fileId][$key])) {
                    $matchedPaths[] = $path;
                } else {
                    $missingPaths[] = $path;
                }
            }
            $requiredCount = count($requirements);
            $matchedCount = count($matchedPaths);
            $missingCount = count($missingPaths);
            $status = $requiredCount === 0 || $missingCount === 0
                ? 'fully_satisfies'
                : ($matchedCount > 0 ? 'partially_satisfies' : 'does_not_satisfy');

            $result[] = [
                'file_id' => (int)$fileId,
                'source' => (string)$provider['source'],
                'required_count' => $requiredCount,
                'matched_count' => $matchedCount,
                'missing_count' => $missingCount,
                'status' => $status,
                'matched_paths' => $matchedPaths,
                'missing_paths' => $missingPaths,
                'matched_exports' => $matchedExports[$fileId],
            ];
        }

        usort($result, static function (array $a, array $b) use ($preferredFileId, $providerOrder): int {
            $rank = ['fully_satisfies'=>0,'partially_satisfies'=>1,'does_not_satisfy'=>2];
            $status = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            if ($status !== 0) {
                return $status;
            }
            if ($preferredFileId > 0) {
                $preferred = ((int)$b['file_id'] === $preferredFileId)
                    <=> ((int)$a['file_id'] === $preferredFileId);
                if ($preferred !== 0) {
                    return $preferred;
                }
            }
            return ($providerOrder[(int)$a['file_id']] ?? PHP_INT_MAX)
                <=> ($providerOrder[(int)$b['file_id']] ?? PHP_INT_MAX);
        });
        return $result;
    }

    /** @param list<string> $paths @return array<string,string> */
    private static function requirements(string $packageName, array $paths): array
    {
        $requirements = [];
        $packagePrefix = self::key($packageName) . '.';
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '') {
                continue;
            }
            $key = self::key($path);
            if (str_starts_with($key, $packagePrefix)) {
                $path = substr($path, strlen($packageName) + 1);
            }
            $path = trim($path, '.');
            $key = self::key($path);
            if ($key !== '' && !isset($requirements[$key])) {
                $requirements[$key] = $path;
            }
        }
        return $requirements;
    }

    /** @param array<string,mixed> $classesByPath @return array<string,array{class_package:string,class_name:string}> */
    private static function requiredClasses(string $packageName, array $classesByPath): array
    {
        $result = [];
        foreach ($classesByPath as $path => $class) {
            $requirements = self::requirements($packageName, [(string)$path]);
            $key = array_key_first($requirements);
            if ($key === null || !is_array($class)) {
                continue;
            }
            $className = trim((string)($class['class_name'] ?? ''));
            if ($className === '') {
                continue;
            }
            $result[(string)$key] = [
                'class_package' => trim((string)($class['class_package'] ?? '')),
                'class_name' => $className,
            ];
        }
        return $result;
    }

    /** @return array<int,array{source:string}> */
    private static function providers(PDO $db, int $gameId, string $packageName, int $preferredFileId): array
    {
        $exactKind = Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME;
        $legacyKind = Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME;
        $exactKey = Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $exactKind);
        $legacyKey = Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $legacyKind);
        $statement = $db->prepare(
            'SELECT p.file_id,p.source_kind,p.source_id,v.package_name,f.uploaded_at,a.package_name alias_name '
            . 'FROM ue_uedb5_provider_keys p '
            . 'JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_file_package_aliases a ON p.source_kind=2 AND a.id=p.source_id '
            . 'WHERE p.game_id=? AND ('
            . '(p.package_key_kind=? AND p.package_key=?) OR '
            . '(p.package_key_kind=? AND p.package_key=?)) '
            . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
            . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
            . 'ORDER BY (p.file_id=?) DESC,(p.source_kind=1) DESC,f.uploaded_at DESC,p.source_id ASC,p.file_id ASC'
        );
        $statement->execute([
            $gameId,$exactKind,$exactKey,$legacyKind,$legacyKey,$preferredFileId
        ]);

        $wanted = CatalogUnrealIdentityHash::fnameKey($packageName);
        $providers = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sourceName = (int)$row['source_kind'] === 2
                ? (string)($row['alias_name'] ?? '')
                : (string)($row['package_name'] ?? '');
            if (CatalogUnrealIdentityHash::fnameKey($sourceName) !== $wanted) {
                continue;
            }
            $fileId = (int)$row['file_id'];
            if ($fileId > 0 && !isset($providers[$fileId])) {
                $providers[$fileId] = [
                    'source' => (int)$row['source_kind'] === 2 ? 'package_alias' : 'package_primary',
                ];
            }
        }
        return $providers;
    }

    private static function storageRoot(): string
    {
        $config = function_exists('catalog_config') ? \catalog_config() : require dirname(__DIR__, 3) . '/config.php';
        $path = is_array($config) ? trim((string)($config['storage_path'] ?? '')) : '';
        if ($path === '') {
            throw new \RuntimeException('Catalog storage_path is required for UEDB5 provider coverage.');
        }
        return $path;
    }

    private static function key(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
