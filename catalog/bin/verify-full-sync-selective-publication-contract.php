#!/usr/bin/env php
<?php
/** Verifies the guarded UT3 Full Sync selective-publication fast path. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogParsedPackageMetadataSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Metadata\CompressedMetadataLookupWriter;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$base = [
    'file' => [
        'id' => 42,
        'game_id' => 6,
        'package_name' => 'Pkg',
        'original_name' => 'Pkg.ut3',
        'name_count' => 0,
        'import_count' => 1,
        'export_count' => 2,
        'scan_status' => 'verified',
    ],
    'names' => [],
    'imports' => [[
        'id' => 1,
        'import_index' => 0,
        'class_package' => 'Core',
        'class_name' => 'Class',
        'object_name' => 'Thing',
        'outer_index' => 0,
        'full_path' => 'Pkg.Thing',
        'root_package' => 'Pkg',
        'relative_object_path' => 'Thing',
        'is_common' => 0,
        'class_package_name_index' => 0,
        'class_name_index' => 1,
        'object_name_index' => 2,
        'verify_identity_hash' => '',
        'path_hash_ci' => CatalogUnrealIdentityHash::objectPathHex('Thing'),
    ]],
    'exports' => [
        [
            'id' => 1,
            'export_index' => 0,
            'class_name' => 'Core.Class',
            'object_name' => 'PublicObject',
            'outer_index' => 0,
            'local_path' => 'PublicObject',
            'full_path' => 'Pkg.PublicObject',
            'object_flags' => '0',
            'serial_size' => 100,
            'serial_offset' => 200,
            'class_index' => 0,
            'super_index' => 0,
            'template_index' => 0,
            'object_name_index' => 3,
            'verify_class_package' => '',
            'verify_class_name' => '',
            'verify_identity_hash' => '',
            'path_hash_ci' => CatalogUnrealIdentityHash::objectPathHex('PublicObject'),
        ],
        [
            'id' => 2,
            'export_index' => 1,
            'class_name' => 'Core.Class',
            'object_name' => 'Nested',
            'outer_index' => 1,
            'local_path' => 'PublicObject.Nested',
            'full_path' => 'Pkg.PublicObject.Nested',
            'object_flags' => '0',
            'serial_size' => 50,
            'serial_offset' => 300,
            'class_index' => 0,
            'super_index' => 0,
            'template_index' => 0,
            'object_name_index' => 4,
            'verify_class_package' => '',
            'verify_class_name' => '',
            'verify_identity_hash' => '',
            'path_hash_ci' => CatalogUnrealIdentityHash::objectPathHex('PublicObject.Nested'),
        ],
    ],
    'paths' => [
        'imports' => [0 => ['relative' => 'Thing']],
        'exports' => [
            0 => ['local' => 'PublicObject'],
            1 => ['local' => 'PublicObject.Nested'],
        ],
    ],
    'dependencies' => [[
        'file_id' => 42,
        'import_index' => 0,
        'required_package' => 'Pkg',
        'required_object_path' => 'Pkg.Thing',
        'resolved_file_id' => null,
        'resolved_export_index' => null,
        'status' => 'missing',
        'resolution_source' => 'none',
        'resolution_confidence' => 'missing',
    ]],
];

$flagRepair = $base;
$flagRepair['exports'][0]['object_flags'] = '17179869184'; // UT3 RF_Public = 0x0000000400000000.
$record(
    'flag_change_changes_full_fingerprint',
    CatalogParsedPackageMetadataSnapshotBuilder::parsedContentFingerprint($base)
        !== CatalogParsedPackageMetadataSnapshotBuilder::parsedContentFingerprint($flagRepair),
    'Recovered serialized ObjectFlags must remain a real metadata change.'
);
$record(
    'flag_change_keeps_structure_fingerprint',
    CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($base)
        === CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($flagRepair),
    'A flag-only repair may use the narrow UT3 publication path.'
);
$pathChange = $flagRepair;
$pathChange['exports'][0]['local_path'] = 'ChangedPath';
$record(
    'export_path_change_rejects_narrow_path',
    CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($base)
        !== CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($pathChange),
    'Search/export path changes must fall back to complete publication.'
);
$importChange = $flagRepair;
$importChange['imports'][0]['outer_index'] = -1;
$record(
    'import_graph_change_rejects_narrow_path',
    CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($base)
        !== CatalogParsedPackageMetadataSnapshotBuilder::parsedStructureFingerprint($importChange),
    'Import graph changes must fall back to complete publication.'
);

if (!extension_loaded('pdo_sqlite')) {
    $record('sqlite_projection_fixture', false, 'pdo_sqlite is required for the isolated projection contract.');
} else {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,slug TEXT NOT NULL)');
    $db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER NOT NULL)');
    $db->exec('CREATE TABLE ue_terms(id INTEGER PRIMARY KEY,value_hash BLOB NOT NULL,value_length INTEGER NOT NULL,value_prefix TEXT NOT NULL,is_overflow INTEGER NOT NULL)');
    $db->exec('CREATE TABLE ue_export_path_lookup(file_id INTEGER NOT NULL,export_index INTEGER NOT NULL,path_hash_ci BLOB,local_path_term_id INTEGER,class_term_id INTEGER,class_package_term_id INTEGER,class_name_term_id INTEGER,object_flags TEXT,outer_index INTEGER,PRIMARY KEY(file_id,export_index))');
    $db->exec('CREATE TABLE ue_dependency_links(file_id INTEGER NOT NULL,import_index INTEGER NOT NULL,required_package_term_id INTEGER,required_path_hash BLOB,required_object_term_id INTEGER,import_class_package_term_id INTEGER,import_class_name_term_id INTEGER,import_object_term_id INTEGER,resolved_file_id INTEGER,resolved_export_index INTEGER,status INTEGER,resolution_source INTEGER,resolution_confidence INTEGER,resolution_source_term_id INTEGER,resolution_confidence_term_id INTEGER,PRIMARY KEY(file_id,import_index))');
    $db->exec('CREATE TABLE ue_dependency_identity_lookup(file_id INTEGER NOT NULL,import_index INTEGER NOT NULL,required_package_term_id INTEGER,verify_identity_hash BLOB,required_path_hash_ci BLOB,PRIMARY KEY(file_id,import_index))');
    $db->exec("INSERT INTO ue_games(id,slug) VALUES(6,'ut3')");
    $db->exec('INSERT INTO ue_files(id,game_id) VALUES(42,6)');

    $termIds = [];
    $insertTerm = $db->prepare('INSERT INTO ue_terms(id,value_hash,value_length,value_prefix,is_overflow) VALUES(?,?,?,?,0)');
    $nextTermId = 1;
    foreach (['Core', 'Class', 'Thing', 'Pkg', 'Pkg.Thing', 'none', 'missing'] as $term) {
        $termIds[$term] = $nextTermId;
        $insertTerm->execute([$nextTermId, md5($term, true), strlen($term), $term]);
        $nextTermId++;
    }

    $insertExport = $db->prepare('INSERT INTO ue_export_path_lookup(file_id,export_index,path_hash_ci,local_path_term_id,class_term_id,class_package_term_id,class_name_term_id,object_flags,outer_index) VALUES(?,?,?,?,?,?,?,?,?)');
    $insertExport->execute([42, 0, random_bytes(16), 901, 902, null, null, '0', 0]);
    $insertExport->execute([42, 1, random_bytes(16), 903, 904, null, null, '0', 1]);
    $before = $db->query('SELECT export_index,hex(path_hash_ci) path_hash_ci,local_path_term_id,class_term_id FROM ue_export_path_lookup ORDER BY export_index')->fetchAll(PDO::FETCH_ASSOC);

    $db->exec("INSERT INTO ue_dependency_links(file_id,import_index,status) VALUES(42,0,99)");
    $db->exec("INSERT INTO ue_dependency_identity_lookup(file_id,import_index) VALUES(42,0)");

    $writer = new CompressedMetadataLookupWriter($db);
    $ue3Prepared = $writer->prepareUe3ExportIdentityRefresh(
        42,
        'Pkg',
        $flagRepair['imports'],
        $flagRepair['exports'],
        512
    );
    $dependencyPrepared = $writer->prepareDependencyProjection($flagRepair);
    $sqlBatches = 0;
    $db->beginTransaction();
    $writer->writePreparedUe3ExportIdentityRefresh(42, $ue3Prepared, $sqlBatches);
    $writer->writePreparedDependencyProjection(42, $dependencyPrepared, $sqlBatches);
    $db->commit();

    $after = $db->query('SELECT export_index,hex(path_hash_ci) path_hash_ci,local_path_term_id,class_term_id,class_package_term_id,class_name_term_id,object_flags,outer_index FROM ue_export_path_lookup ORDER BY export_index')->fetchAll(PDO::FETCH_ASSOC);
    $record(
        'narrow_export_refresh_preserves_search_columns',
        count($before) === count($after)
            && (string)$before[0]['path_hash_ci'] === (string)$after[0]['path_hash_ci']
            && (int)$before[0]['local_path_term_id'] === (int)$after[0]['local_path_term_id']
            && (int)$before[0]['class_term_id'] === (int)$after[0]['class_term_id']
            && (string)$before[1]['path_hash_ci'] === (string)$after[1]['path_hash_ci']
            && (int)$before[1]['local_path_term_id'] === (int)$after[1]['local_path_term_id']
            && (int)$before[1]['class_term_id'] === (int)$after[1]['class_term_id'],
        'The narrow update must not rewrite path/local-path/class search columns.'
    );
    $record(
        'narrow_export_refresh_updates_ue3_identity',
        (int)$after[0]['class_package_term_id'] === $termIds['Core']
            && (int)$after[0]['class_name_term_id'] === $termIds['Class']
            && (string)$after[0]['object_flags'] === '17179869184'
            && (int)$after[0]['outer_index'] === 0,
        'UT3 class package/name, 64-bit flags and OuterIndex must be refreshed.'
    );

    $dependency = $db->query('SELECT * FROM ue_dependency_links WHERE file_id=42')->fetchAll(PDO::FETCH_ASSOC);
    $identity = $db->query('SELECT * FROM ue_dependency_identity_lookup WHERE file_id=42')->fetchAll(PDO::FETCH_ASSOC);
    $record(
        'dependency_only_projection_replaces_stale_rows',
        count($dependency) === 1
            && count($identity) === 1
            && (int)$dependency[0]['status'] !== 99
            && (int)$dependency[0]['import_object_term_id'] === $termIds['Thing']
            && (int)$dependency[0]['required_package_term_id'] === $termIds['Pkg'],
        'Selective dependency publication must fully replace the file-owned dependency projection.'
    );
}

$workerVersionSource = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$record(
    'worker_fingerprint_tracks_selective_publication_runtime',
    str_contains($workerVersionSource, '/src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php')
        && str_contains($workerVersionSource, '/src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php')
        && str_contains($workerVersionSource, '/src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php')
        && str_contains($workerVersionSource, '/src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php')
        && str_contains($workerVersionSource, '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php'),
    'Detached workers must recycle when any selective-publication runtime component changes.'
);
$rebuilderSource = (string)file_get_contents($root . '/src/Infrastructure/Metadata/CompactDependencyRebuilder.php');
$finalizerSource = (string)file_get_contents($root . '/src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php');
$record(
    'dependency_rebuilder_uses_dependency_only_writer',
    str_contains($rebuilderSource, 'writeDependencyRefresh($snapshot)'),
    'Dependency changes must not route through complete export/search publication.'
);
$record(
    'narrow_path_is_ut3_full_sync_only',
    str_contains($finalizerSource, '!$resolveDependencies')
        && str_contains($finalizerSource, "=== 'UE3'")
        && str_contains($finalizerSource, "=== 'ut3'")
        && str_contains($finalizerSource, 'parsedStructureFingerprint'),
    'The source-pass optimization must remain restricted to audited UT3 Full Sync with equal structure.'
);

foreach ([
    'src/Infrastructure/Metadata/CatalogParsedPackageMetadataSnapshotBuilder.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Metadata/CompactDependencyRebuilder.php',
] as $relative) {
    $path = $root . '/' . $relative;
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $ok = is_resource($process);
    $detail = '';
    if ($ok) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $ok = $exit === 0;
        $detail = trim((string)$stdout . ' ' . (string)$stderr);
    } else {
        $detail = 'Could not start PHP syntax check.';
    }
    $record('syntax:' . basename($relative), $ok, $ok ? '' : $detail);
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
