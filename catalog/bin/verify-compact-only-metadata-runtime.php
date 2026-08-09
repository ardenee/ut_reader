#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies verified-file runtime metadata is format-2 only while explicit unverified/migration staging remains isolated.
 * Role: Read-only architecture and optional live database cutover verifier.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$withDatabase = in_array('--database', array_slice($argv, 1), true);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$runtimeFiles = [
    'src/Infrastructure/Persistence/PdoDependencyReadSource.php',
    'src/Infrastructure/Persistence/PdoDependencyResolver.php',
    'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
    'src/Infrastructure/Persistence/PdoPackageTablePageQuery.php',
    'src/Infrastructure/Metadata/CatalogCompactDependencyReadService.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php',
];
$legacyTables = ['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'];
foreach ($runtimeFiles as $relative) {
    $source = $read($relative);
    $present = $source !== '';
    $record('present:' . $relative, $present, $relative);
    if (!$present) {
        continue;
    }
    $found = [];
    foreach ($legacyTables as $table) {
        if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $source) === 1) {
            $found[] = $table;
        }
    }
    $record(
        'compact_only:' . $relative,
        $found === [],
        $found === [] ? 'no retired metadata table references' : 'found: ' . implode(', ', $found)
    );
}

$runtimeBridge = $read('lib/CatalogRuntimeSqlCompatibility.php');
$record(
    'dependency_sql_bridge_fails_closed',
    str_contains($runtimeBridge, 'CatalogDependencyReadSource')
        && str_contains($runtimeBridge, 'runtime legacy dependency reads are disabled')
        && !str_contains($runtimeBridge, 'SQL rewrite skipped')
        && !str_contains($runtimeBridge, 'return $sql;\n    } catch'),
    'historical dependency query shapes must rewrite to current projections or fail; never execute legacy SQL'
);

$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$persistence = $read('src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php');
$finalizer = $read('src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php');
$record(
    'verified_import_publishes_parser_snapshot',
    str_contains($importer, 'VerifiedFileCompactMetadataFinalizer::finalizeParsed(')
        && str_contains($importer, '$names,')
        && str_contains($importer, '$imports,')
        && str_contains($importer, '$exports,')
        && str_contains($finalizer, 'CatalogParsedPackageMetadataSnapshotBuilder')
        && str_contains($finalizer, 'BlockedCompressedMetadataSnapshotWriter')
        && !str_contains($persistence, 'PdoCatalogDependencyRebuilder')
        && !str_contains($persistence, '->rebuild('),
    'new verified imports must publish format-2 metadata directly from in-memory parser tables'
);
$record(
    'verified_runtime_finalizer_no_legacy_conversion',
    !str_contains($finalizer, 'BlockedCompressedFileMetadataConverter')
        && str_contains($finalizer, 'has no current format-2 metadata')
        && str_contains($finalizer, 'Run the explicit metadata conversion/repair workflow'),
    'runtime verification must not silently convert by rereading legacy metadata'
);

$writer = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$record(
    'compact_export_lookup_is_self_sufficient',
    str_contains($writer, "'local_path_term_id'")
        && str_contains($writer, '$termIds[$this->termKey($localPath)]')
        && !str_contains($writer, 'FROM ue_dependencies')
        && !str_contains($writer, 'JOIN ue_imports'),
    'current projection writes must include local paths and dependency labels without legacy rereads'
);

$resolver = $read('src/Infrastructure/Persistence/PdoDependencyResolver.php');
$record(
    'compact_object_resolution_boundary',
    str_contains($resolver, 'ue_export_lookup')
        && str_contains($resolver, 'l.path_hash=?')
        && !str_contains($resolver, 'FROM ue_exports')
        && !str_contains($resolver, 'JOIN ue_exports'),
    'object dependency resolution must use current export projections only'
);

$converter = $read('src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php');
$record(
    'current_projection_rebuild_uses_current_snapshot',
    str_contains($converter, 'BlockedCompressedMetadataSnapshotLoader')
        && str_contains($converter, 'CompressedMetadataLegacySnapshot')
        && str_contains($converter, 'explicit historical conversion path'),
    'current projection rebuilds use current containers; legacy reads remain isolated to explicit conversion'
);

$syntaxFiles = array_values(array_unique(array_merge($runtimeFiles, [
    'lib/CatalogRuntimeSqlCompatibility.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
])));
foreach ($syntaxFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $process = proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = '';
    $exit = 1;
    if (is_resource($process)) {
        $output = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }
    $record('php_syntax:' . $relative, $exit === 0, $exit === 0 ? '' : $output);
}

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $db = $application->db;

        $missing = (int)$db->query(
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" '
            . 'AND (m.file_id IS NULL OR m.format_version<>2)'
        )->fetchColumn();
        $record(
            'verified_files_current_format_coverage',
            $missing === 0,
            'verified_without_format2=' . $missing
        );

        $mismatchedCounts = (int)$db->query(
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND ('
            . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
        )->fetchColumn();
        $record(
            'verified_compact_count_parity',
            $mismatchedCounts === 0,
            'count_mismatches=' . $mismatchedCounts
        );

        $source = \UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource::sql($db);
        $statement = $db->prepare('SELECT * FROM ' . $source . ' d LIMIT 0');
        $statement->execute();
        $record(
            'compact_dependency_source_compiles',
            true,
            'mode=compact-only'
        );

        $missingLocalPathTerms = (int)$db->query(
            'SELECT COUNT(*) FROM ue_export_lookup l '
            . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
            . 'WHERE l.local_path_term_id IS NULL'
        )->fetchColumn();
        $checks[] = [
            'check' => 'compact_export_local_path_term_backlog',
            'ok' => true,
            'detail' => 'rows_without_local_path_term=' . $missingLocalPathTerms
                . '; path_hash remains the current compact resolution key until projections are naturally rebuilt',
        ];
    } catch (Throwable $error) {
        $record('database_compact_runtime_checks', false, get_class($error) . ': ' . $error->getMessage());
    }
}

$result = [
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
