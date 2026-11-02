#!/usr/bin/env php
<?php
/** Read-only UEDB5 runtime contract used by production closeout and disaster recovery. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
$database = in_array('--database', array_slice($argv, 1), true);
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check'=>$name, 'ok'=>$ok, 'detail'=>$detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$read = static function (string $relative) use ($root): string {
    $source = @file_get_contents($root . '/' . $relative);
    return is_string($source) ? $source : '';
};
$files = [
    'src/Infrastructure/Metadata/Uedb5VerifiedFilePublisher.php',
    'src/Infrastructure/Metadata/Uedb5MetadataReader.php',
    'src/Infrastructure/Metadata/Uedb5MetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php',
    'src/Infrastructure/Metadata/CatalogCompactMetadataMutationService.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Import/CatalogVerifiedPackagePublisher.php',
    'src/Infrastructure/Persistence/PdoDependencyResolver.php',
];
foreach ($files as $file) {
    $source = $read($file);
    $check('file_present:' . $file, $source !== '');
    if ($source === '') continue;
    try {
        token_get_all($source, TOKEN_PARSE);
        $check('php_syntax:' . $file, true);
    } catch (ParseError $error) {
        $check('php_syntax:' . $file, false, $error->getMessage());
    }
}
$publisher = $read($files[0]);
$mutation = $read($files[4]);
$check('publisher_writes_registers_v5',
    str_contains($publisher, '$this->writer->write($snapshot)')
    && str_contains($publisher, '$this->registration->register($gameId, $fileId)'));
$check('publisher_rebuilds_v5_dependencies',
    str_contains($publisher, '$this->dependencies->runFile($gameId, $fileId, true, true)'));
$check('mutation_uses_v5_publisher',
    str_contains($mutation, 'Uedb5MetadataContainer::FORMAT_VERSION')
    && str_contains($mutation, 'new Uedb5VerifiedFilePublisher('));
foreach ([
    'src/Infrastructure/Metadata/BlockedCompressedMetadataReader.php',
    'src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
    'src/Infrastructure/Metadata/BlockedCompressedFileMetadataConverter.php',
    'src/Infrastructure/Persistence/PdoDependencyReadSource.php',
] as $retired) {
    $check('retired_runtime_absent:' . $retired, !is_file($root . '/' . $retired));
}
if ($database) {
    try {
        require_once $root . '/bootstrap.php';
        $db = catalog_bootstrap(false)->db;
        $db->query('SELECT file_id,format_version FROM ue_uedb5_files LIMIT 0');
        $db->query('SELECT file_id FROM ue_uedb5_dependency_edges LIMIT 0');
        $db->query('SELECT file_id FROM ue_uedb5_provider_keys LIMIT 0');
        $check('v5_schema_queries_compile', true);
        $statement = $db->query(
            'SELECT f.id,v.format_version FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.scan_status="verified" ORDER BY f.id LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $check('sample_verified_file_registered_v5',
            is_array($row) && (int)($row['format_version'] ?? 0) === 5,
            'sample_file_id=' . (int)($row['id'] ?? 0));
    } catch (Throwable $error) {
        $check('database_v5_runtime', false, $error->getMessage());
    }
}
echo json_encode([
    'ok'=>$failures===[],
    'read_only'=>true,
    'database_checked'=>$database,
    'checks'=>$checks,
    'failures'=>$failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 2);
