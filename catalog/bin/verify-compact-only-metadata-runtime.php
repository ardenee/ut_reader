#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies verified-file runtime metadata is format-2 only while dedicated unverified compressed staging remains isolated.
 * Role: Read-only architecture and optional live database gate after physical legacy-table retirement.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php';
require_once $root . '/src/Infrastructure/Persistence/PdoPackageTablePageQuery.php';

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
$withoutComments = static function (string $source): string {
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
};
$retiredReferences = static function (string $source): array {
    return \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataReferences($source);
};
$legacyTables = \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataTables();

$uniqueValuesMethod = new ReflectionMethod(
    \UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery::class,
    'uniqueValues'
);
$numericNames = $uniqueValuesMethod->invoke(null, ['Alpha', '123', '123', '0']);
$record(
    'file_examiner_preserves_numeric_fname_strings',
    $numericNames === ['Alpha', '123', '0']
        && array_reduce($numericNames, static fn(bool $allStrings, mixed $value): bool => $allStrings && is_string($value), true),
    'numeric Unreal names must remain strings after deduplication'
);

$runtimeFiles = [
    'src/Infrastructure/Persistence/PdoDependencyReadSource.php',
    'src/Infrastructure/Persistence/PdoDependencyResolver.php',
    'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
    'src/Infrastructure/Persistence/PdoPackageTablePageQuery.php',
    'src/Infrastructure/Persistence/PdoDependencySchemaManager.php',
    'src/Infrastructure/Persistence/PdoMissingFileListQuery.php',
    'src/Infrastructure/Persistence/PdoMissingDetailListQuery.php',
    'src/Infrastructure/Metadata/CatalogCompactDependencyReadService.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php',
    'src/Infrastructure/Maintenance/CatalogLegacyDataAuditService.php',
    'src/Infrastructure/Unverified/PdoUnverifiedReferenceMatchQuery.php',
];
foreach ($runtimeFiles as $relative) {
    $source = $read($relative);
    $present = $source !== '';
    $record('present:' . $relative, $present, $relative);
    if (!$present) {
        continue;
    }
    $found = $retiredReferences($withoutComments($source));
    $record(
        'compact_only:' . $relative,
        $found === [],
        $found === [] ? 'no executable retired metadata table references' : 'found: ' . implode(', ', $found)
    );
}

$installSql = $read('install.sql');
$installLegacyReferences = $retiredReferences($installSql);
$record(
    'fresh_install_omits_retired_metadata_tables',
    $installSql !== '' && $installLegacyReferences === [],
    $installSql === ''
        ? 'install.sql could not be read'
        : ($installLegacyReferences === []
            ? 'fresh-install schema contains only current metadata storage/projections'
            : 'found: ' . implode(', ', $installLegacyReferences))
);

$record(
    'dependency_sql_bridge_retired',
    !is_file($root . '/lib/CatalogRuntimeSqlCompatibility.php'),
    'runtime SQL must name current dependency sources explicitly; the retired-table rewriter must stay absent'
);

$coreSupport = $read('lib/CatalogSupportCore.php');
$coreSupportExecutable = $withoutComments($coreSupport);
$record(
    'metadata_sql_shape_bridge_retired',
    !is_file($root . '/lib/CatalogCompactMetadataCompatibility.php')
        && !is_file($root . '/src/Infrastructure/Metadata/CatalogCompactMetadataCompatibilityService.php')
        && !str_contains($coreSupportExecutable, 'catalog_metadata_compat_query')
        && !str_contains($coreSupportExecutable, 'CatalogCompactMetadataCompatibility.php'),
    'shared query helpers must execute only explicit current SQL; retired metadata SQL-shape compatibility must stay absent'
);

$importer = $read('src/Infrastructure/Import/CatalogPackageImporterAdapter.php');
$pdoImporter = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$inspector = $read('src/Infrastructure/Import/CatalogVerifiedPackageInspector.php');
$publisher = $read('src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php');
$persistence = $read('src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php');
$finalizer = $read('src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php');
$finalizerExecutable = $withoutComments($finalizer);
$record(
    'verified_import_publishes_parser_snapshot',
    str_contains($importer, 'VerifiedPackageInspectorPort')
        && str_contains($importer, '$this->publisher->publishMetadata(')
        && str_contains($inspector, '$package->getNames()')
        && str_contains($inspector, '$package->getImports()')
        && str_contains($inspector, '$package->getExports()')
        && str_contains($publisher, 'VerifiedFileCompactMetadataFinalizer::finalizeParsed(')
        && str_contains($publisher, '$inspection->names')
        && str_contains($publisher, '$inspection->imports')
        && str_contains($publisher, '$inspection->exports')
        && str_contains($finalizer, 'CatalogParsedPackageMetadataSnapshotBuilder')
        && str_contains($finalizer, 'BlockedCompressedMetadataSnapshotWriter')
        && !str_contains($persistence, 'PdoCatalogDependencyRebuilder')
        && !str_contains($persistence, '->rebuild(')
        && str_contains($pdoImporter, 'CatalogPackageImporterFactory::create('),
    'new verified imports must publish format-2 metadata directly from the inspected parser snapshot through the port-driven adapter'
);
$finalizerLegacyReferences = $retiredReferences($finalizerExecutable);
$record(
    'verified_runtime_finalizer_no_legacy_conversion',
    !str_contains($finalizer, 'BlockedCompressedFileMetadataConverter')
        && str_contains($finalizer, 'has no current format-2 metadata.')
        && $finalizerLegacyReferences === [],
    $finalizerLegacyReferences === []
        ? 'runtime verification fails closed when format-2 is missing and contains no retired-table conversion path'
        : 'found retired metadata references: ' . implode(', ', $finalizerLegacyReferences)
);

$writer = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$writerLegacyReferences = $retiredReferences($withoutComments($writer));
$record(
    'compact_export_lookup_is_self_sufficient',
    str_contains($writer, "'local_path_term_id'")
        && str_contains($writer, '$this->requiredTermId($termIds, $localPath)')
        && str_contains($writer, '$this->requiredTermId($termIds, $source)')
        && str_contains($writer, '$this->requiredTermId($termIds, $confidence)')
        && str_contains($writer, 'private function requiredTermId(')
        && $writerLegacyReferences === [],
    $writerLegacyReferences === []
        ? 'current projection writes include local paths and dependency labels without retired metadata rereads'
        : 'found retired metadata references: ' . implode(', ', $writerLegacyReferences)
);

$resolver = $read('src/Infrastructure/Persistence/PdoDependencyResolver.php');
$resolverLegacyReferences = $retiredReferences($withoutComments($resolver));
$record(
    'compact_object_resolution_boundary',
    str_contains($resolver, 'ue_export_lookup')
        && str_contains($resolver, 'l.path_hash=?')
        && $resolverLegacyReferences === [],
    $resolverLegacyReferences === []
        ? 'object dependency resolution uses current export projections only'
        : 'found retired metadata references: ' . implode(', ', $resolverLegacyReferences)
);

$converter = $read('src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php');
$converterExecutable = $withoutComments($converter);
$converterLegacyReferences = $retiredReferences($converterExecutable);
$record(
    'current_projection_rebuild_uses_current_snapshot',
    str_contains($converter, 'BlockedCompressedMetadataSnapshotLoader')
        && str_contains($converter, 'Historical SQL metadata conversion has been retired')
        && $converterLegacyReferences === [],
    $converterLegacyReferences === []
        ? 'projection rebuilds and verification use current format-2 containers only'
        : 'found retired metadata references: ' . implode(', ', $converterLegacyReferences)
);

$record(
    'historical_conversion_tools_retired',
    !is_file($root . '/src/Infrastructure/Metadata/CompressedFileMetadataConverter.php')
        && !is_file($root . '/bin/convert-file-metadata.php'),
    'completed SQL-to-compact conversion tooling must not remain callable'
);

$syntaxFiles = array_values(array_unique(array_merge($runtimeFiles, [
    'lib/CatalogSupportCore.php',
    'lib/CatalogPerformance.php',
    'missing.php',
    'src/Application/Maintenance/LegacyMetadataRuntimeAudit.php',
    'src/Application/Import/CatalogVerifiedPackageInspection.php',
    'src/Application/Import/Contract/VerifiedPackageInspectorPort.php',
    'src/Application/Import/Contract/VerifiedPackageIdentityPort.php',
    'src/Application/Import/Contract/VerifiedPackagePublisherPort.php',
    'src/Application/Import/Contract/VerifiedPackageDependencyPort.php',
    'src/Infrastructure/Import/PdoCatalogPackageImporter.php',
    'src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageInspector.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
    'src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php',
    'src/Infrastructure/Import/CatalogVerifiedPackageDependencyCoordinator.php',
    'src/Infrastructure/Composition/CatalogPackageImporterFactory.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
    'src/Infrastructure/Metadata/CompactDependencyEncoding.php',
    'src/Infrastructure/Metadata/CompressedMetadataLegacySnapshot.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedMetadataStore.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedCompactMetadataFinalizer.php',
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

        $legacyTableStates = [];
        $presentLegacyTables = [];
        foreach ($legacyTables as $table) {
            $existsStatement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            );
            $existsStatement->execute([$table]);
            if ((int)$existsStatement->fetchColumn() !== 1) {
                $legacyTableStates[$table] = 'absent';
                continue;
            }

            $rowCount = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            $legacyTableStates[$table] = 'present; rows=' . $rowCount;
            $presentLegacyTables[] = $table;
        }
        $record(
            'retired_metadata_tables_physically_absent',
            $presentLegacyTables === [],
            json_encode($legacyTableStates, JSON_UNESCAPED_SLASHES) ?: ''
        );

        $source = \UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource::sql($db);
        $statement = $db->prepare('SELECT * FROM ' . $source . ' d LIMIT 0');
        $statement->execute();
        $record('compact_dependency_source_compiles', true, 'mode=compact-only');

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
