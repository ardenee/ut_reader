<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog legacy data audit.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogFileMaintenance.php';

/** @return array{severity:string,code:string,table:string,row_index:?int,message:string,expected:mixed,actual:mixed} */
function catalog_legacy_audit_issue(
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

function catalog_legacy_audit_engine(array $file): string
{
    $detected = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
    if (in_array($detected, ['UE1', 'UE2', 'UE3'], true)) {
        return $detected;
    }
    $profile = strtoupper(trim((string)($file['profile_engine'] ?? '')));
    return in_array($profile, ['UE1', 'UE2', 'UE3'], true) ? $profile : '';
}

function catalog_legacy_audit_literal_source_name(PDO $db, array $file): string
{
    $path = scanner_normalize_source_relative_path((string)($file['source_relative_path'] ?? ''));
    if ($path === '') {
        $location = catalog_one(
            $db,
            'SELECT source_relative_path FROM ue_file_locations WHERE file_id=? AND source_relative_path<>"" ORDER BY id LIMIT 1',
            [(int)$file['id']]
        );
        $path = scanner_normalize_source_relative_path((string)($location['source_relative_path'] ?? ''));
    }
    if ($path !== '') {
        $path = preg_replace('/\.(uz|uz2|uz3)$/i', '', $path) ?? $path;
        $parts = explode('/', $path);
        return trim(str_replace("\0", '', (string)end($parts)));
    }
    return trim(str_replace("\0", '', (string)($file['original_name'] ?? '')));
}

function catalog_legacy_audit_literal_package_name(PDO $db, array $file): string
{
    $name = catalog_legacy_audit_literal_source_name($db, $file);
    if ($name === '') {
        return '';
    }
    return trim((string)pathinfo($name, PATHINFO_FILENAME));
}

function catalog_legacy_audit_reference_valid(int $ref, int $importCount, int $exportCount): bool
{
    if ($ref === 0) {
        return true;
    }
    return $ref < 0
        ? (-$ref - 1) >= 0 && (-$ref - 1) < $importCount
        : ($ref - 1) >= 0 && ($ref - 1) < $exportCount;
}

/** @param list<array<string,mixed>> $imports @param list<array<string,mixed>> $exports */
function catalog_legacy_audit_reference_cycle(int $ref, array $imports, array $exports): bool
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

function catalog_legacy_audit_value_equal(mixed $expected, mixed $actual): bool
{
    if ($expected === null || $actual === null) {
        return $expected === $actual;
    }
    if (is_int($expected) || is_int($actual) || is_float($expected) || is_float($actual)) {
        return (string)$expected === (string)$actual;
    }
    return (string)$expected === (string)$actual;
}

/**
 * Read-only verification of one UE1/UE2/UE3 package. It reparses the stored
 * bytes and compares the fresh reader output with the catalog projections.
 *
 * @return array<string,mixed>
 */
function catalog_legacy_audit_file(PDO $db, array $config, int $fileId, ?callable $progress = null): array
{
    $file = catalog_one(
        $db,
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

    $engine = catalog_legacy_audit_engine($file);
    if ($engine === '') {
        throw new RuntimeException('This audit supports only UE1, UE2 and UE3 files.');
    }

    $path = catalog_file_maintenance_storage_path($config, $file);
    if ($path === null || !is_file($path)) {
        throw new RuntimeException('Stored package file is missing.');
    }

    scanner_emit_percent($progress, 'audit', 2, 'Opening ' . $engine . ' reader for ' . (string)$file['original_name']);
    $readerClass = scanner_load_reader_class($config, $engine);
    $pkg = new $readerClass($path);
    $issues = method_exists($pkg, 'validatePackage') ? $pkg->validatePackage() : (method_exists($pkg, 'getDebugErrors') ? $pkg->getDebugErrors() : []);
    if ($issues !== []) {
        throw new RuntimeException('Fresh reader reported: ' . implode(' | ', array_map('strval', $issues)));
    }

    scanner_emit_percent($progress, 'audit', 12, 'Reading fresh Names, Imports and Exports');
    $header = $pkg->getHeader();
    $names = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();

    $dbNames = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index,id', [$fileId]);
    $dbImports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index,id', [$fileId]);
    $dbExports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index,id', [$fileId]);
    $dbDependencies = catalog_all($db, 'SELECT * FROM ue_dependencies WHERE file_id=? ORDER BY import_id,id', [$fileId]);

    $found = [];
    $add = static function (array $issue) use (&$found): void {
        if (count($found) < 250) {
            $found[] = $issue;
        }
    };

    $freshCounts = ['names' => count($names), 'imports' => count($imports), 'exports' => count($exports)];
    $storedCounts = ['names' => count($dbNames), 'imports' => count($dbImports), 'exports' => count($dbExports)];
    foreach ($freshCounts as $key => $freshCount) {
        $storedCount = $storedCounts[$key];
        $fileCountColumn = $key === 'names' ? 'name_count' : ($key === 'imports' ? 'import_count' : 'export_count');
        if ((int)$file[$fileCountColumn] !== $freshCount) {
            $add(catalog_legacy_audit_issue('error', 'file_count_mismatch', 'ue_files', null, ucfirst($key) . ' count in ue_files differs from a fresh parse.', $freshCount, (int)$file[$fileCountColumn]));
        }
        if ($storedCount !== $freshCount) {
            $add(catalog_legacy_audit_issue('error', 'table_count_mismatch', 'ue_' . $key, null, ucfirst($key) . ' row count differs from a fresh parse.', $freshCount, $storedCount));
        }
    }

    $headerChecks = [
        'nameCount' => count($names),
        'importCount' => count($imports),
        'exportCount' => count($exports),
    ];
    foreach ($headerChecks as $headerKey => $actualCount) {
        if (isset($header[$headerKey]) && (int)$header[$headerKey] !== $actualCount) {
            $add(catalog_legacy_audit_issue('error', 'reader_header_count_mismatch', 'reader', null, $headerKey . ' differs from the table produced by the reader.', (int)$header[$headerKey], $actualCount));
        }
    }

    $literalPackage = catalog_legacy_audit_literal_package_name($db, $file);
    if ($literalPackage !== '' && strcasecmp($literalPackage, (string)$file['package_name']) !== 0) {
        $add(catalog_legacy_audit_issue(
            'warning',
            'legacy_filename_identity_changed',
            'ue_files',
            null,
            'Stored legacy package name differs from the literal source filename stem. This may indicate historical filename cleanup or a legitimate alias; review rather than auto-repair.',
            $literalPackage,
            (string)$file['package_name']
        ));
    }

    scanner_emit_percent($progress, 'audit', 24, 'Comparing Names table');
    foreach ($names as $index => $name) {
        $stored = $dbNames[$index] ?? null;
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
            if (!catalog_legacy_audit_value_equal($expected, $actual)) {
                $add(catalog_legacy_audit_issue('error', 'name_field_mismatch', 'ue_names', $index, 'Stored ' . $field . ' differs from the fresh reader.', $expected, $actual));
            }
        }
    }

    scanner_emit_percent($progress, 'audit', 40, 'Comparing Imports and validating outer references');
    $cache = [];
    $importCount = count($imports);
    $exportCount = count($exports);
    foreach ($imports as $index => $import) {
        $stored = $dbImports[$index] ?? null;
        $fullPath = scanner_ref_path(-($index + 1), $imports, $exports, $cache);
        $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
        $rootPackage = (string)($parts[0] ?? '');
        $relativePath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
        $outer = (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0);

        if (!catalog_legacy_audit_reference_valid($outer, $importCount, $exportCount)) {
            $add(catalog_legacy_audit_issue('error', 'invalid_import_outer', 'ue_imports', $index, 'Import outer reference is outside the fresh import/export tables.', 'valid package index', $outer));
        } elseif (catalog_legacy_audit_reference_cycle(-($index + 1), $imports, $exports)) {
            $add(catalog_legacy_audit_issue('error', 'cyclic_import_outer', 'ue_imports', $index, 'Import outer chain contains a cycle.', 'acyclic chain', $outer));
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
            if (!catalog_legacy_audit_value_equal($value, $actual)) {
                $add(catalog_legacy_audit_issue('error', 'import_field_mismatch', 'ue_imports', $index, 'Stored ' . $field . ' differs from the fresh reader projection.', $value, $actual));
            }
        }
    }

    scanner_emit_percent($progress, 'audit', 65, 'Comparing Exports and validating outer references');
    foreach ($exports as $index => $export) {
        $stored = $dbExports[$index] ?? null;
        $localPath = scanner_ref_path($index + 1, $imports, $exports, $cache);
        $classRef = (int)($export['classIndex'] ?? $export['class'] ?? 0);
        $className = $classRef !== 0 ? scanner_ref_path($classRef, $imports, $exports, $cache) : '';
        $outer = (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0);

        if (!catalog_legacy_audit_reference_valid($outer, $importCount, $exportCount)) {
            $add(catalog_legacy_audit_issue('error', 'invalid_export_outer', 'ue_exports', $index, 'Export outer reference is outside the fresh import/export tables.', 'valid package index', $outer));
        } elseif (catalog_legacy_audit_reference_cycle($index + 1, $imports, $exports)) {
            $add(catalog_legacy_audit_issue('error', 'cyclic_export_outer', 'ue_exports', $index, 'Export outer chain contains a cycle.', 'acyclic chain', $outer));
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
            'full_path' => scanner_join_path_parts([(string)$file['package_name'], $localPath]),
            'serial_size' => isset($export['serialSize']) ? (int)$export['serialSize'] : null,
            'serial_offset' => isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
        ];
        foreach ($expected as $field => $value) {
            $actual = $stored[$field] ?? null;
            if (!catalog_legacy_audit_value_equal($value, $actual)) {
                $add(catalog_legacy_audit_issue('error', 'export_field_mismatch', 'ue_exports', $index, 'Stored ' . $field . ' differs from the fresh reader projection.', $value, $actual));
            }
        }
    }

    scanner_emit_percent($progress, 'audit', 88, 'Checking dependency projections');
    $dependenciesByImport = [];
    foreach ($dbDependencies as $dependency) {
        $dependenciesByImport[(int)$dependency['import_id']][] = $dependency;
    }
    foreach ($dbImports as $storedImport) {
        $rows = $dependenciesByImport[(int)$storedImport['id']] ?? [];
        if (count($rows) !== 1) {
            $add(catalog_legacy_audit_issue('error', 'dependency_cardinality', 'ue_dependencies', (int)$storedImport['import_index'], 'Each stored import should have exactly one dependency projection.', 1, count($rows)));
            continue;
        }
        $dependency = $rows[0];
        if ((string)$dependency['required_package'] !== (string)$storedImport['root_package']) {
            $add(catalog_legacy_audit_issue('error', 'dependency_package_mismatch', 'ue_dependencies', (int)$storedImport['import_index'], 'Dependency required_package differs from the stored import root_package.', (string)$storedImport['root_package'], (string)$dependency['required_package']));
        }
        if ((string)$dependency['required_object_path'] !== (string)$storedImport['full_path']) {
            $add(catalog_legacy_audit_issue('error', 'dependency_path_mismatch', 'ue_dependencies', (int)$storedImport['import_index'], 'Dependency required_object_path differs from the stored import full_path.', (string)$storedImport['full_path'], (string)$dependency['required_object_path']));
        }
    }

    $errorCount = count(array_filter($found, static fn(array $issue): bool => $issue['severity'] === 'error'));
    $warningCount = count($found) - $errorCount;
    scanner_emit_percent($progress, 'audit', 100, $errorCount === 0 ? 'Legacy package audit complete' : 'Legacy package audit found data errors');

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
