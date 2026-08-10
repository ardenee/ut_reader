<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Builds the authoritative format-2 metadata snapshot directly from parser output.
 * Why: Newly verified packages already have Names/Imports/Exports in memory and must not write those rows only to read
 *      them back through the retired SQL metadata tables before publishing compact metadata.
 * Role: Infrastructure metadata builder shared by verified import and compact publication.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

final class CatalogParsedPackageMetadataSnapshotBuilder
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/Scanner/CatalogScannerPath.php';
        require_once $root . '/lib/Scanner/CatalogScannerSupport.php';
    }

    /**
     * Build a complete compact snapshot, including dependency resolution.
     *
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @return array<string,mixed>
     */
    public function build(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        array $names,
        array $imports,
        array $exports
    ): array {
        return $this->withDependencies($this->buildParsedSections(
            $fileId,
            $gameId,
            $packageName,
            $originalName,
            $names,
            $imports,
            $exports
        ));
    }

    /**
     * Normalize only the package-owned parser output.
     *
     * Full Sync can compare these sections with its already validated compact
     * snapshot before doing dependency resolution or rewriting the .uedb2 file.
     * Dependencies are deliberately excluded because their resolution can change
     * independently and Full Sync owns a separate bounded dependency phase.
     *
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @return array<string,mixed>
     */
    public function buildParsedSections(
        int $fileId,
        int $gameId,
        string $packageName,
        string $originalName,
        array $names,
        array $imports,
        array $exports
    ): array {
        if ($fileId < 1 || $gameId < 1 || trim($packageName) === '') {
            throw new RuntimeException('Parsed compact metadata requires valid file, game and package identities.');
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

        $common = array_map(
            'strtolower',
            array_values((array)($this->config['common_packages'] ?? []))
        );
        $cache = [];
        $importRows = [];
        $importPaths = [];
        foreach ($imports as $index => $import) {
            $row = is_array($import) ? $import : [];
            $fullPath = \scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
            $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
            $rootPackage = (string)($parts[0] ?? '');
            $relativeObjectPath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $importRows[] = [
                'id' => $this->virtualId($fileId, (int)$index),
                'file_id' => $fileId,
                'import_index' => (int)$index,
                'class_package' => (string)($row['classPackageText'] ?? ($row['ClassPackage']['text'] ?? '')),
                'class_name' => (string)($row['classNameText'] ?? ($row['ClassName']['text'] ?? '')),
                'object_name' => (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? '')),
                'outer_index' => (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0),
                'full_path' => $fullPath,
                'root_package' => $rootPackage,
                'relative_object_path' => $relativeObjectPath,
                'is_common' => in_array(strtolower($rootPackage), $common, true) ? 1 : 0,
            ];
            $importPaths[(int)$index] = [
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
                'object_name' => (string)($row['objectNameText'] ?? ''),
                'outer_index' => (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0),
                'local_path' => $localPath,
                'full_path' => $fullPath,
                'object_flags' => isset($row['objectFlags']) ? (int)$row['objectFlags'] : null,
                'serial_size' => isset($row['serialSize']) ? (int)$row['serialSize'] : null,
                'serial_offset' => isset($row['serialOffset']) ? (int)$row['serialOffset'] : null,
            ];
            $exportPaths[(int)$index] = [
                'local' => $localPath,
                'full' => $fullPath,
            ];
        }

        return [
            'file' => [
                'id' => $fileId,
                'game_id' => $gameId,
                'package_name' => $packageName,
                'original_name' => $originalName,
                'name_count' => count($nameRows),
                'import_count' => count($importRows),
                'export_count' => count($exportRows),
                'scan_status' => 'verified',
            ],
            'names' => $nameRows,
            'imports' => $importRows,
            'exports' => $exportRows,
            'dependencies' => [],
            'paths' => [
                'imports' => $importPaths,
                'exports' => $exportPaths,
            ],
            'source_format' => 'parsed-package-current-no-dependencies',
        ];
    }

    /**
     * Resolve dependencies for an already normalized parsed snapshot.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function withDependencies(array $snapshot): array
    {
        $file = (array)($snapshot['file'] ?? []);
        $fileId = (int)($file['id'] ?? 0);
        $gameId = (int)($file['game_id'] ?? 0);
        $importRows = array_values((array)($snapshot['imports'] ?? []));
        $exportRows = array_values((array)($snapshot['exports'] ?? []));
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('Parsed compact dependency resolution requires valid file and game identities.');
        }

        $localExports = [];
        foreach ($exportRows as $export) {
            if (!is_array($export)) {
                continue;
            }
            $lookupKey = $this->lookupKey((string)($export['full_path'] ?? ''));
            if ($lookupKey !== '' && !isset($localExports[$lookupKey])) {
                $localExports[$lookupKey] = (int)($export['export_index'] ?? 0);
            }
        }

        $resolutions = PdoDependencyResolver::resolve($this->db, $gameId, $fileId, $importRows);
        $dependencies = [];
        foreach ($importRows as $import) {
            if (!is_array($import)) {
                continue;
            }
            $importId = (int)$import['id'];
            $resolution = $resolutions[$importId] ?? [
                'status' => 'missing',
                'resolved_file_id' => null,
                'resolved_export_index' => null,
                'source' => 'none',
                'confidence' => 'missing',
            ];

            // The legacy resolver preferred the newly imported file itself when
            // an Import matched one of its own Exports. The current export lookup
            // is not published until after this snapshot is built, so preserve
            // that ordering explicitly from the in-memory parsed Export table.
            $localExportIndex = $localExports[$this->lookupKey((string)$import['full_path'])] ?? null;
            if ($localExportIndex !== null && (int)$import['is_common'] !== 1) {
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
                'required_package' => (string)$import['root_package'],
                'required_object_path' => (string)$import['full_path'],
                'resolved_file_id' => $resolution['resolved_file_id'] !== null
                    ? (int)$resolution['resolved_file_id']
                    : null,
                'resolved_export_index' => $resolution['resolved_export_index'] !== null
                    ? (int)$resolution['resolved_export_index']
                    : null,
                'status' => (string)($resolution['status'] ?? 'missing'),
                'resolution_source' => (string)($resolution['source'] ?? 'none'),
                'resolution_confidence' => (string)($resolution['confidence'] ?? 'missing'),
            ];
        }

        if (count($dependencies) !== count($importRows)) {
            throw new RuntimeException('Parsed compact metadata did not produce one dependency row per Import.');
        }

        $snapshot['dependencies'] = $dependencies;
        $snapshot['source_format'] = 'parsed-package-current';
        return $snapshot;
    }

    /**
     * Binary-safe semantic fingerprint of the package-owned metadata sections.
     *
     * Virtual/physical row IDs and dependency resolution are intentionally omitted.
     * Loader JSON numeric strings are normalized to the same representation as
     * current parser integers so an unchanged package compares equal without a
     * rewrite.
     *
     * @param array<string,mixed> $snapshot
     */
    public static function parsedContentFingerprint(array $snapshot): string
    {
        $file = (array)($snapshot['file'] ?? []);
        $canonical = [
            'file' => [
                (int)($file['id'] ?? 0),
                (int)($file['game_id'] ?? 0),
                (string)($file['package_name'] ?? ''),
                (string)($file['original_name'] ?? ''),
                (int)($file['name_count'] ?? count((array)($snapshot['names'] ?? []))),
                (int)($file['import_count'] ?? count((array)($snapshot['imports'] ?? []))),
                (int)($file['export_count'] ?? count((array)($snapshot['exports'] ?? []))),
                (string)($file['scan_status'] ?? 'verified'),
            ],
            'names' => [],
            'imports' => [],
            'exports' => [],
        ];

        foreach ((array)($snapshot['names'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['names'][] = [
                (int)($row['name_index'] ?? 0),
                (string)($row['name_text'] ?? ''),
                self::nullableScalarString($row['flags'] ?? null),
            ];
        }
        foreach ((array)($snapshot['imports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['imports'][] = [
                (int)($row['import_index'] ?? 0),
                trim((string)($row['class_package'] ?? '')),
                trim((string)($row['class_name'] ?? '')),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['full_path'] ?? ''),
                (string)($row['root_package'] ?? ''),
                (string)($row['relative_object_path'] ?? ''),
                (int)($row['is_common'] ?? 0),
            ];
        }
        foreach ((array)($snapshot['exports'] ?? []) as $row) {
            $row = is_array($row) ? $row : [];
            $canonical['exports'][] = [
                (int)($row['export_index'] ?? 0),
                trim((string)($row['class_name'] ?? '')),
                (string)($row['object_name'] ?? ''),
                (int)($row['outer_index'] ?? 0),
                (string)($row['local_path'] ?? ''),
                (string)($row['full_path'] ?? ''),
                self::nullableScalarString($row['object_flags'] ?? null),
                self::nullableScalarString($row['serial_size'] ?? null),
                self::nullableScalarString($row['serial_offset'] ?? null),
            ];
        }

        return hash('sha256', serialize($canonical));
    }

    private static function nullableScalarString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
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
