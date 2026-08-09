<?php
/**
 * Purpose: Performs read-only UE1/UE2/UE3 package projection audits against a fresh parse.
 * Why: Reader validation should compare fresh parser output with the authoritative format-2 metadata snapshot, not retired SQL tables.
 * Role: Infrastructure maintenance service preserving the Legacy Data Audit feature after physical legacy-table retirement.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

final class CatalogLegacyDataAuditService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/CatalogFileMaintenance.php';
    }

    /** @return list<array<string,mixed>> */
    public function eligibleGames(): array
    {
        return \catalog_all(
            $this->db,
            'SELECT g.id,g.name,p.engine_key,COUNT(f.id) file_count'
            . ' FROM ue_games g'
            . ' JOIN ue_game_profiles p ON p.id=g.profile_id'
            . ' LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified"'
            . ' WHERE UPPER(p.engine_key) IN ("UE1","UE2","UE3")'
            . ' GROUP BY g.id,g.name,p.engine_key ORDER BY p.engine_key,g.name'
        );
    }

    /** @return array{game:array<string,mixed>,files:list<array<string,mixed>>} */
    public function filesForGame(int $gameId): array
    {
        $game = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,p.engine_key FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
            [$gameId]
        );
        if (!$game || !in_array(strtoupper((string)$game['engine_key']), ['UE1', 'UE2', 'UE3'], true)) {
            throw new RuntimeException('Select a UE1, UE2 or UE3 game.');
        }

        $files = \catalog_all(
            $this->db,
            'SELECT id,original_name,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,original_name,id',
            [$gameId]
        );
        return ['game' => $game, 'files' => $files];
    }

    /**
     * Read-only verification of one UE1/UE2/UE3 package. It reparses the stored
     * bytes and compares the fresh reader output with the authoritative format-2 snapshot.
     *
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function auditFile(int $fileId, ?callable $progress = null): array
    {
        $file = \catalog_one(
            $this->db,
            'SELECT f.*,g.name game_name,p.engine_key profile_engine'
            . ' FROM ue_files f'
            . ' JOIN ue_games g ON g.id=f.game_id'
            . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id'
            . ' WHERE f.id=?',
            [$fileId]
        );
        if (!$file) {
            throw new RuntimeException('File no longer exists in the catalog.');
        }
        if ((string)($file['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('Legacy Data Audit requires a verified catalog file.');
        }

        $engine = $this->engine($file);
        if ($engine === '') {
            throw new RuntimeException('This audit supports only UE1, UE2 and UE3 files.');
        }

        $path = \catalog_file_maintenance_storage_path($this->config, $file);
        if ($path === null || !is_file($path)) {
            throw new RuntimeException('Stored package file is missing.');
        }

        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata audit.');
        }

        \scanner_emit_percent($progress, 'audit', 2, 'Opening ' . $engine . ' reader for ' . (string)$file['original_name']);
        $readerClass = \scanner_load_reader_class($this->config, $engine);
        $pkg = new $readerClass($path);
        $readerIssues = method_exists($pkg, 'validatePackage')
            ? $pkg->validatePackage()
            : (method_exists($pkg, 'getDebugErrors') ? $pkg->getDebugErrors() : []);
        if ($readerIssues !== []) {
            throw new RuntimeException('Fresh reader reported: ' . implode(' | ', array_map('strval', $readerIssues)));
        }

        \scanner_emit_percent($progress, 'audit', 12, 'Reading fresh package tables and current compact metadata');
        $header = $pkg->getHeader();
        $names = $pkg->getNames();
        $imports = $pkg->getImports();
        $exports = $pkg->getExports();
        $snapshot = (new BlockedCompressedMetadataSnapshotLoader($this->db, $storageRoot))->load($fileId);

        $storedNames = $this->rowsByIndex((array)($snapshot['names'] ?? []), 'name_index');
        $storedImports = $this->rowsByIndex((array)($snapshot['imports'] ?? []), 'import_index');
        $storedExports = $this->rowsByIndex((array)($snapshot['exports'] ?? []), 'export_index');
        $storedDependencies = array_values((array)($snapshot['dependencies'] ?? []));

        $found = [];
        $add = static function (array $issue) use (&$found): void {
            if (count($found) < 250) {
                $found[] = $issue;
            }
        };

        $freshCounts = ['names' => count($names), 'imports' => count($imports), 'exports' => count($exports)];
        $storedCounts = [
            'names' => count($storedNames),
            'imports' => count($storedImports),
            'exports' => count($storedExports),
        ];
        foreach ($freshCounts as $key => $freshCount) {
            $storedCount = $storedCounts[$key];
            $fileCountColumn = $key === 'names' ? 'name_count' : ($key === 'imports' ? 'import_count' : 'export_count');
            if ((int)$file[$fileCountColumn] !== $freshCount) {
                $add($this->issue(
                    'error',
                    'file_count_mismatch',
                    'ue_files',
                    null,
                    ucfirst($key) . ' count in ue_files differs from a fresh parse.',
                    $freshCount,
                    (int)$file[$fileCountColumn]
                ));
            }
            if ($storedCount !== $freshCount) {
                $add($this->issue(
                    'error',
                    'metadata_count_mismatch',
                    'metadata.' . $key,
                    null,
                    ucfirst($key) . ' count in format-2 metadata differs from a fresh parse.',
                    $freshCount,
                    $storedCount
                ));
            }
        }

        $headerChecks = [
            'nameCount' => count($names),
            'importCount' => count($imports),
            'exportCount' => count($exports),
        ];
        foreach ($headerChecks as $headerKey => $actualCount) {
            if (isset($header[$headerKey]) && (int)$header[$headerKey] !== $actualCount) {
                $add($this->issue(
                    'error',
                    'reader_header_count_mismatch',
                    'reader',
                    null,
                    $headerKey . ' differs from the table produced by the reader.',
                    (int)$header[$headerKey],
                    $actualCount
                ));
            }
        }

        $literalPackage = $this->literalPackageName($file);
        if ($literalPackage !== '' && strcasecmp($literalPackage, (string)$file['package_name']) !== 0) {
            $add($this->issue(
                'warning',
                'legacy_filename_identity_changed',
                'ue_files',
                null,
                'Stored package name differs from the literal source filename stem. This may indicate historical filename cleanup or a legitimate alias; review rather than auto-repair.',
                $literalPackage,
                (string)$file['package_name']
            ));
        }

        \scanner_emit_percent($progress, 'audit', 24, 'Comparing Names with format-2 metadata');
        foreach ($names as $index => $name) {
            $stored = $storedNames[$index] ?? null;
            if (!$stored) {
                continue;
            }
            $expectedText = (string)($name['name'] ?? $name['text'] ?? '');
            $expectedFlags = isset($name['flags']) ? (int)$name['flags'] : null;
            foreach ([
                'name_index' => $index,
                'name_text' => $expectedText,
                'flags' => $expectedFlags,
            ] as $field => $expected) {
                $actual = $stored[$field] ?? null;
                if (!$this->valueEqual($expected, $actual)) {
                    $add($this->issue(
                        'error',
                        'name_field_mismatch',
                        'metadata.names',
                        $index,
                        'Stored ' . $field . ' differs from the fresh reader.',
                        $expected,
                        $actual
                    ));
                }
            }
        }

        \scanner_emit_percent($progress, 'audit', 40, 'Comparing Imports and validating outer references');
        $cache = [];
        $importCount = count($imports);
        $exportCount = count($exports);
        foreach ($imports as $index => $import) {
            $stored = $storedImports[$index] ?? null;
            $fullPath = \scanner_ref_path(-($index + 1), $imports, $exports, $cache);
            $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
            $rootPackage = (string)($parts[0] ?? '');
            $relativePath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $outer = (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0);

            if (!$this->referenceValid($outer, $importCount, $exportCount)) {
                $add($this->issue(
                    'error',
                    'invalid_import_outer',
                    'metadata.imports',
                    $index,
                    'Import outer reference is outside the fresh import/export tables.',
                    'valid package index',
                    $outer
                ));
            } elseif ($this->referenceCycle(-($index + 1), $imports, $exports)) {
                $add($this->issue(
                    'error',
                    'cyclic_import_outer',
                    'metadata.imports',
                    $index,
                    'Import outer chain contains a cycle.',
                    'acyclic chain',
                    $outer
                ));
            }

            if (!$stored) {
                continue;
            }
            $expected = [
                'import_index' => $index,
                'class_package' => (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')),
                'class_name' => (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')),
                'object_name' => (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')),
                'outer_index' => $outer,
                'full_path' => $fullPath,
                'root_package' => $rootPackage,
                'relative_object_path' => $relativePath,
            ];
            foreach ($expected as $field => $value) {
                $actual = $stored[$field] ?? null;
                if (!$this->valueEqual($value, $actual)) {
                    $add($this->issue(
                        'error',
                        'import_field_mismatch',
                        'metadata.imports',
                        $index,
                        'Stored ' . $field . ' differs from the fresh reader projection.',
                        $value,
                        $actual
                    ));
                }
            }
        }

        \scanner_emit_percent($progress, 'audit', 65, 'Comparing Exports and validating outer references');
        foreach ($exports as $index => $export) {
            $stored = $storedExports[$index] ?? null;
            $localPath = \scanner_ref_path($index + 1, $imports, $exports, $cache);
            $classRef = (int)($export['classIndex'] ?? $export['class'] ?? 0);
            $className = $classRef !== 0 ? \scanner_ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0);

            if (!$this->referenceValid($outer, $importCount, $exportCount)) {
                $add($this->issue(
                    'error',
                    'invalid_export_outer',
                    'metadata.exports',
                    $index,
                    'Export outer reference is outside the fresh import/export tables.',
                    'valid package index',
                    $outer
                ));
            } elseif ($this->referenceCycle($index + 1, $imports, $exports)) {
                $add($this->issue(
                    'error',
                    'cyclic_export_outer',
                    'metadata.exports',
                    $index,
                    'Export outer chain contains a cycle.',
                    'acyclic chain',
                    $outer
                ));
            }

            if (!$stored) {
                continue;
            }
            $expected = [
                'export_index' => $index,
                'class_name' => $className,
                'object_name' => (string)($export['objectNameText'] ?? ''),
                'outer_index' => $outer,
                'local_path' => $localPath,
                'full_path' => \scanner_join_path_parts([(string)$file['package_name'], $localPath]),
                'serial_size' => isset($export['serialSize']) ? (int)$export['serialSize'] : null,
                'serial_offset' => isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
            ];
            foreach ($expected as $field => $value) {
                $actual = $stored[$field] ?? null;
                if (!$this->valueEqual($value, $actual)) {
                    $add($this->issue(
                        'error',
                        'export_field_mismatch',
                        'metadata.exports',
                        $index,
                        'Stored ' . $field . ' differs from the fresh reader projection.',
                        $value,
                        $actual
                    ));
                }
            }
        }

        \scanner_emit_percent($progress, 'audit', 88, 'Checking compact dependency projections');
        $dependenciesByImport = [];
        foreach ($storedDependencies as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            $dependenciesByImport[(int)($dependency['import_index'] ?? -1)][] = $dependency;
        }
        foreach ($storedImports as $importIndex => $storedImport) {
            $rows = $dependenciesByImport[(int)$importIndex] ?? [];
            if (count($rows) !== 1) {
                $add($this->issue(
                    'error',
                    'dependency_cardinality',
                    'metadata.dependencies',
                    (int)$importIndex,
                    'Each stored import should have exactly one dependency projection.',
                    1,
                    count($rows)
                ));
                continue;
            }
            $dependency = $rows[0];
            if ((string)($dependency['required_package'] ?? '') !== (string)($storedImport['root_package'] ?? '')) {
                $add($this->issue(
                    'error',
                    'dependency_package_mismatch',
                    'metadata.dependencies',
                    (int)$importIndex,
                    'Dependency required_package differs from the stored import root_package.',
                    (string)($storedImport['root_package'] ?? ''),
                    (string)($dependency['required_package'] ?? '')
                ));
            }
            if ((string)($dependency['required_object_path'] ?? '') !== (string)($storedImport['full_path'] ?? '')) {
                $add($this->issue(
                    'error',
                    'dependency_path_mismatch',
                    'metadata.dependencies',
                    (int)$importIndex,
                    'Dependency required_object_path differs from the stored import full_path.',
                    (string)($storedImport['full_path'] ?? ''),
                    (string)($dependency['required_object_path'] ?? '')
                ));
            }
        }

        $errorCount = count(array_filter(
            $found,
            static fn(array $issue): bool => $issue['severity'] === 'error'
        ));
        $warningCount = count($found) - $errorCount;
        \scanner_emit_percent(
            $progress,
            'audit',
            100,
            $errorCount === 0 ? 'Legacy package audit complete' : 'Legacy package audit found data errors'
        );

        return [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'game_name' => (string)$file['game_name'],
            'engine' => $engine,
            'package_name' => (string)$file['package_name'],
            'original_name' => (string)$file['original_name'],
            'fresh_counts' => $freshCounts,
            'stored_counts' => $storedCounts,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'issues' => $found,
            'issue_limit_reached' => count($found) >= 250,
        ];
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function rowsByIndex(array $rows, string $field): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = (int)($row[$field] ?? -1);
            if ($index >= 0) {
                $indexed[$index] = $row;
            }
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /** @return array{severity:string,code:string,table:string,rowIndex:?int,message:string,expected:mixed,actual:mixed} */
    private function issue(
        string $severity,
        string $code,
        string $table,
        ?int $rowIndex,
        string $message,
        mixed $expected = null,
        mixed $actual = null
    ): array {
        return compact('severity', 'code', 'table', 'rowIndex', 'message', 'expected', 'actual');
    }

    /** @param array<string,mixed> $file */
    private function engine(array $file): string
    {
        $detected = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        if (in_array($detected, ['UE1', 'UE2', 'UE3'], true)) {
            return $detected;
        }
        $profile = strtoupper(trim((string)($file['profile_engine'] ?? '')));
        return in_array($profile, ['UE1', 'UE2', 'UE3'], true) ? $profile : '';
    }

    /** @param array<string,mixed> $file */
    private function literalSourceName(array $file): string
    {
        $path = \scanner_normalize_source_relative_path((string)($file['source_relative_path'] ?? ''));
        if ($path === '') {
            $location = \catalog_one(
                $this->db,
                'SELECT source_relative_path FROM ue_file_locations WHERE file_id=? AND source_relative_path<>"" ORDER BY id LIMIT 1',
                [(int)$file['id']]
            );
            $path = \scanner_normalize_source_relative_path((string)($location['source_relative_path'] ?? ''));
        }
        if ($path !== '') {
            $path = preg_replace('/\.(uz|uz2|uz3)$/i', '', $path) ?? $path;
            $parts = explode('/', $path);
            return trim(str_replace("\0", '', (string)end($parts)));
        }
        return trim(str_replace("\0", '', (string)($file['original_name'] ?? '')));
    }

    /** @param array<string,mixed> $file */
    private function literalPackageName(array $file): string
    {
        $name = $this->literalSourceName($file);
        if ($name === '') {
            return '';
        }
        return trim((string)pathinfo($name, PATHINFO_FILENAME));
    }

    private function referenceValid(int $ref, int $importCount, int $exportCount): bool
    {
        if ($ref === 0) {
            return true;
        }
        return $ref < 0
            ? (-$ref - 1) >= 0 && (-$ref - 1) < $importCount
            : ($ref - 1) >= 0 && ($ref - 1) < $exportCount;
    }

    /** @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $exports */
    private function referenceCycle(int $ref, array $imports, array $exports): bool
    {
        $seen = [];
        $limit = count($imports) + count($exports) + 1;
        for ($step = 0; $ref !== 0 && $step < $limit; $step++) {
            if (isset($seen[$ref])) {
                return true;
            }
            $seen[$ref] = true;
            if ($ref < 0) {
                $row = $imports[-$ref - 1] ?? null;
                if (!is_array($row)) {
                    return false;
                }
                $ref = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0);
            } else {
                $row = $exports[$ref - 1] ?? null;
                if (!is_array($row)) {
                    return false;
                }
                $ref = (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0);
            }
        }
        return $ref !== 0;
    }

    private function valueEqual(mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }
        if (is_int($expected) || is_int($actual) || is_float($expected) || is_float($actual)) {
            return (string)$expected === (string)$actual;
        }
        return (string)$expected === (string)$actual;
    }
}
