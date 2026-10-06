<?php
/**
 * Purpose: Normalizes parsed package tables for unverified staging and later resolves them into a current format-3 snapshot.
 * Why: Pre-game-selection metadata must be reusable without row-per-table legacy storage or reparsing the physical file.
 * Role: Pure metadata transformation boundary shared by unverified indexing, details, matching and promotion.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

final class CatalogUnverifiedMetadataSnapshotBuilder
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/Scanner/CatalogScannerPath.php';
        require_once $root . '/lib/Scanner/CatalogScannerSupport.php';
    }

    /**
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @param list<string> $commonPackages
     * @return array<string,mixed>
     */
    public function fromParsed(
        int $fileId,
        string $packageName,
        array $names,
        array $imports,
        array $exports,
        array $commonPackages = []
    ): array {
        if ($fileId < 1 || trim($packageName) === '') {
            throw new RuntimeException('Unverified metadata requires valid file and package identities.');
        }

        $nameRows = [];
        foreach ($names as $index => $name) {
            $row = is_array($name) ? $name : [];
            $nameRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'name_index' => (int)$index,
                'name_text' => (string)($row['name'] ?? $row['text'] ?? ''),
                'flags' => isset($row['flags']) ? (int)$row['flags'] : null,
            ];
        }

        $common = array_map('strtolower', array_values($commonPackages));
        $cache = [];
        $importRows = [];
        $importPaths = [];
        foreach ($imports as $index => $import) {
            $row = is_array($import) ? $import : [];
            $objectPackagePresent = array_key_exists('objectPackage', $row) && $row['objectPackage'] !== null;
            $objectPackage = $objectPackagePresent
                ? (string)($row['objectPackageText'] ?? ($row['ObjectPackage']['text'] ?? ''))
                : '';
            if ($objectPackagePresent) {
                $objectNameText = (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? ''));
                $rootPackage = strcasecmp($objectPackage, 'None') === 0 ? '' : $objectPackage;
                $relativeObjectPath = $objectNameText;
                $fullPath = $rootPackage !== ''
                    ? \scanner_join_path_parts([$rootPackage, $objectNameText])
                    : $objectNameText;
            } else {
                $fullPath = \scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
                $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
                $rootPackage = (string)($parts[0] ?? '');
                $relativeObjectPath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            }
            $importRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'import_index' => (int)$index,
                'class_package' => (string)($row['classPackageText'] ?? ($row['ClassPackage']['text'] ?? '')),
                'class_name' => (string)($row['classNameText'] ?? ($row['ClassName']['text'] ?? '')),
                'object_name' => (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? '')),
                'object_package_present' => $objectPackagePresent ? 1 : 0,
                'object_package' => $objectPackage,
                'outer_index' => (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0),
                'full_path' => $fullPath,
                'root_package' => $rootPackage,
                'relative_object_path' => $relativeObjectPath,
                'is_common' => in_array(strtolower($rootPackage), $common, true) ? 1 : 0,
            ];
            $importPaths[] = [
                'full' => $fullPath,
                'root' => $rootPackage,
                'relative' => $relativeObjectPath,
            ];
        }

        $exportRows = [];
        $exportPaths = [];
        foreach ($exports as $index => $export) {
            $row = is_array($export) ? $export : [];
            $localPath = \scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
            $classReference = (int)($row['classIndex'] ?? $row['class'] ?? 0);
            $className = $classReference !== 0
                ? \scanner_ref_path($classReference, $imports, $exports, $cache)
                : '';
            $fullPath = \scanner_join_path_parts([$packageName, $localPath]);
            $exportRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'export_index' => (int)$index,
                'class_name' => $className,
                'object_name' => (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? '')),
                'outer_index' => (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0),
                'local_path' => $localPath,
                'full_path' => $fullPath,
                'object_flags' => isset($row['objectFlags']) ? (int)$row['objectFlags'] : null,
                'serial_size' => isset($row['serialSize']) ? (int)$row['serialSize'] : null,
                'serial_offset' => isset($row['serialOffset']) ? (int)$row['serialOffset'] : null,
            ];
            $exportPaths[] = ['local' => $localPath, 'full' => $fullPath];
        }

        return [
            'file_id' => $fileId,
            'package_name' => $packageName,
            'names' => $nameRows,
            'imports' => $importRows,
            'exports' => $exportRows,
            'paths' => ['imports' => $importPaths, 'exports' => $exportPaths],
            'source_format' => 'unverified-staging-v1',
        ];
    }

    /**
     * @param array<string,mixed> $staging
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function forVerified(
        array $staging,
        array $config,
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName
    ): array {
        if ($fileId < 1 || $gameId < 1 || trim($packageName) === '') {
            throw new RuntimeException('Current metadata requires valid file, game and package identities.');
        }
        try {
            $sourceKey = \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry::sourceKey($gameId);
        } catch (\Throwable) {
            $sourceKey = '';
        }
        $ut4SourceProfile = $sourceKey === 'ut4';

        $names = [];
        foreach (array_values((array)($staging['names'] ?? [])) as $index => $source) {
            $row = is_array($source) ? $source : [];
            $row['id'] = $this->virtualId($fileId, $index);
            $row['file_id'] = $fileId;
            $row['name_index'] = $index;
            $names[] = $row;
        }

        $imports = [];
        $importPaths = [];
        $common = array_map('strtolower', array_values((array)($config['common_packages'] ?? [])));
        foreach (array_values((array)($staging['imports'] ?? [])) as $index => $source) {
            $row = is_array($source) ? $source : [];
            $row['id'] = $this->virtualId($fileId, $index);
            $row['file_id'] = $fileId;
            $row['import_index'] = $index;
            $root = (string)($row['root_package'] ?? '');
            $row['is_common'] = in_array(strtolower($root), $common, true) ? 1 : 0;
            $imports[] = $row;
            $importPaths[] = [
                'full' => (string)($row['full_path'] ?? ''),
                'root' => $root,
                'relative' => (string)($row['relative_object_path'] ?? ''),
            ];
        }

        $exports = [];
        $exportPaths = [];
        $localExports = [];
        foreach (array_values((array)($staging['exports'] ?? [])) as $index => $source) {
            $row = is_array($source) ? $source : [];
            $localPath = (string)($row['local_path'] ?? '');
            $fullPath = \scanner_join_path_parts([$packageName, $localPath]);
            $row['id'] = $this->virtualId($fileId, $index);
            $row['file_id'] = $fileId;
            $row['export_index'] = $index;
            $row['full_path'] = $fullPath;
            $exports[] = $row;
            $exportPaths[] = ['local' => $localPath, 'full' => $fullPath];
            $key = $this->lookupKey($fullPath);
            if ($key !== '' && !isset($localExports[$key])) {
                $localExports[$key] = $index;
            }
        }

        $resolutions = PdoDependencyResolver::resolve($this->db, $gameId, $fileId, $imports, null, $exports);
        $dependencies = [];
        foreach ($imports as $import) {
            $importId = (int)$import['id'];
            $resolution = $resolutions[$importId] ?? [
                'status' => 'missing',
                'resolved_file_id' => null,
                'resolved_export_index' => null,
                'source' => 'none',
                'confidence' => 'missing',
            ];
            $localExportIndex = $localExports[$this->lookupKey((string)($import['full_path'] ?? ''))] ?? null;
            if ($ut4SourceProfile
                && (int)($import['is_common'] ?? 0) !== 1
                && (int)($import['outer_index'] ?? 0) !== 0
                && $this->lookupKey((string)($import['root_package'] ?? '')) === $this->lookupKey($packageName)) {
                // unverified-staging-v1 does not retain UE4 export ClassIndex/provider
                // class-package identity, so a same-path local export is not source proof.
                $resolution = [
                    'status'=>'unresolved','resolved_file_id'=>null,'resolved_export_index'=>null,
                    'source'=>'ue4_unverified_local_verify_import_metadata_incomplete',
                    'confidence'=>'metadata_unresolved',
                ];
            } elseif ($localExportIndex !== null && (int)($import['is_common'] ?? 0) !== 1) {
                $resolution = [
                    'status' => 'resolved',
                    'resolved_file_id' => $fileId,
                    'resolved_export_index' => $localExportIndex,
                    'source' => 'exact_object',
                    'confidence' => 'exact',
                ];
            }
            $dependencies[] = [
                'file_id' => $fileId,
                'import_index' => (int)$import['import_index'],
                'required_package' => (string)($import['root_package'] ?? ''),
                'required_object_path' => (string)($import['full_path'] ?? ''),
                'resolved_file_id' => $resolution['resolved_file_id'] !== null
                    ? (int)$resolution['resolved_file_id'] : null,
                'resolved_export_index' => $resolution['resolved_export_index'] !== null
                    ? (int)$resolution['resolved_export_index'] : null,
                'status' => (string)($resolution['status'] ?? 'missing'),
                'resolution_source' => (string)($resolution['source'] ?? 'none'),
                'resolution_confidence' => (string)($resolution['confidence'] ?? 'missing'),
            ];
        }

        return [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'original_name' => $originalName,
                'name_count' => count($names),
                'import_count' => count($imports),
                'export_count' => count($exports),
                'scan_status' => 'verified',
            ],
            'names' => $names,
            'imports' => $imports,
            'exports' => $exports,
            'dependencies' => $dependencies,
            'paths' => ['imports' => $importPaths, 'exports' => $exportPaths],
            'source_format' => 'unverified-staging-promoted',
        ];
    }

    private function virtualId(int $fileId, int $index): int
    {
        return ($fileId * 4294967296) + $index + 1;
    }

    private function lookupKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
