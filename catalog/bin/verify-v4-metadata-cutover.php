#!/usr/bin/env php
<?php
/**
 * Clean-cutover contract for production metadata format 4.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

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
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$container = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php');
$reader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php');
$writer = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php');
$loader = $read('src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotLoader.php');
$lookupWriter = $read('src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php');
$installSql = $read('install.sql');
$identity = $read('src/Infrastructure/Metadata/CatalogCompactIdentityEnricher.php');
$migrator = $read('bin/migrate-uedb3-to-uedb4.php');
$v3Reader = $read('bin/v4-migration/MetadataReaderV3.php');
$cleanup = $read('bin/cleanup-obsolete-metadata-files.php');

$record(
    'runtime_format_is_4',
    BlockedCompressedMetadataContainer::FORMAT_VERSION === 4
        && str_contains($container, 'UEDBM4')
        && str_contains($container, ".uedb4"),
    'production container must be format=4, magic=UEDBM4 and extension=.uedb4'
);
$record(
    'runtime_reader_is_v4_only',
    !str_contains($reader, 'UEDBM3')
        && !str_contains($reader, '.uedb3')
        && !str_contains($reader, 'row_schema_version'),
    'production reader must contain no v3 or row-schema compatibility branch'
);
$record(
    'future_format_rule_is_documented',
    str_contains($container, 'Future v5+')
        && str_contains($reader, 'Future v5+')
        && str_contains($writer, 'Future v5+')
        && str_contains($loader, 'Future v5+'),
    'core metadata boundaries must direct future format changes to an offline migrator'
);
$record(
    'v4_contains_durable_identity_fields',
    str_contains($container, 'verify_class_package')
        && str_contains($container, 'verify_class_name')
        && str_contains($container, 'verify_identity_hash')
        && str_contains($container, 'path_hash_ci')
        && str_contains($identity, 'verify_identity_hash')
        && str_contains($identity, 'path_hash_ci'),
    'v4 must persist exact engine identity inputs plus derived identity/path hashes'
);
$record(
    'projections_contain_identity_hashes',
    str_contains($lookupWriter, 'ue_export_path_lookup')
        && str_contains($lookupWriter, 'ue_dependency_identity_lookup')
        && str_contains($lookupWriter, 'verify_identity_hash')
        && str_contains($lookupWriter, 'path_hash_ci')
        && str_contains($installSql, 'CREATE TABLE ue_export_path_lookup')
        && str_contains($installSql, 'CREATE TABLE ue_dependency_identity_lookup')
        && str_contains($installSql, 'CREATE TABLE ue_legacy_export_identity_lookup'),
    'Format-4 hashes must use dedicated projection tables rather than ALTER the large historical lookup tables.'
);
$record(
    'v3_support_is_migration_only',
    str_contains($v3Reader, 'UEDBM3')
        && str_contains($v3Reader, '.uedb3')
        && str_contains($v3Reader, 'Production/runtime metadata code must never depend on this class')
        && str_contains($migrator, 'SnapshotLoaderV3')
        && str_contains($migrator, 'BlockedCompressedMetadataSnapshotWriter'),
    'format 3 support must exist only in the offline v4 migration path'
);
$record(
    'retired_v3_migration_removed',
    !is_dir($root . '/bin/v3-migration'),
    'old v2->v3 migration tooling must not remain in the production tree'
);
$record(
    'old_files_delete_only_after_cutover',
    str_contains($cleanup, 'format_version=3')
        && str_contains($cleanup, 'Refusing to delete .uedb3')
        && str_contains($cleanup, 'uedb2,uedb3'),
    'cleanup must remove obsolete v2/v3 files but guard v3 until no v3 registrations remain'
);

$runtimeRoots = [
    $root . '/src',
    $root . '/lib',
];
$forbidden = [];
foreach ($runtimeRoots as $runtimeRoot) {
    if (!is_dir($runtimeRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtimeRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
            continue;
        }
        $source = (string)file_get_contents($entry->getPathname());
        if (preg_match('/UEDBM[23]|\.uedb[23]\b|format_version\s*=\s*[23]\b/i', $source) === 1) {
            $forbidden[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        }
    }
}
$record(
    'runtime_has_no_v2_v3_format_literals',
    $forbidden === [],
    $forbidden === [] ? 'none' : implode(', ', array_slice($forbidden, 0, 20))
);

$syntaxFiles = [
    'src/Infrastructure/Metadata/BlockedCompressedMetadataContainer.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotLoader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/CatalogCompactIdentityEnricher.php',
    'src/Infrastructure/Metadata/CompressedMetadataLookupWriter.php',
    'src/Infrastructure/Persistence/PdoDependencyResolver.php',
    'src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php',
    'bin/v4-migration/MetadataReaderV3.php',
    'bin/v4-migration/SnapshotLoaderV3.php',
    'bin/migrate-uedb3-to-uedb4.php',
    'bin/cleanup-obsolete-metadata-files.php',
];
foreach ($syntaxFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = trim((string)($config['storage_path'] ?? ''));

    $notV4 = (int)$db->query(
        'SELECT COUNT(*) FROM ue_files f '
        . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" '
        . 'AND (m.file_id IS NULL OR m.format_version<>4)'
    )->fetchColumn();
    $record(
        'database_verified_files_are_v4',
        $notV4 === 0,
        'verified_not_v4=' . $notV4
    );

    $remainingV3 = (int)$db->query(
        'SELECT COUNT(*) FROM ue_file_metadata WHERE format_version=3'
    )->fetchColumn();
    $record(
        'database_has_no_v3_registrations',
        $remainingV3 === 0,
        'format3_registrations=' . $remainingV3
    );

    $missingV4Files = 0;
    if ($storageRoot !== '') {
        $statement = $db->query(
            'SELECT f.id,f.game_id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
            . 'WHERE f.scan_status="verified" ORDER BY f.id'
        );
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $path = BlockedCompressedMetadataContainer::path(
                $storageRoot,
                (int)$row['game_id'],
                (int)$row['id']
            );
            if (!is_file($path)) {
                $missingV4Files++;
            }
        }
    }
    $record(
        'registered_v4_files_exist',
        $storageRoot !== '' && $missingV4Files === 0,
        $storageRoot === '' ? 'storage_path is empty' : 'missing_uedb4=' . $missingV4Files
    );
}

echo json_encode([
    'ok' => $failures === [],
    'database_checked' => $withDatabase,
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 2);
