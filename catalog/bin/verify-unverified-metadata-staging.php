#!/usr/bin/env php
<?php
/**
 * Purpose: Verifies that active unverified/verified import paths use compressed staging and current compact metadata only.
 * Role: Source and optional live-database regression gate after metadata-table retirement and schema consolidation.
 */
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php';

$withDatabase = in_array('--database', array_slice($argv, 1), true);
$failures = [];
$checks = [];

$record = static function (string $check, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $check . ($detail !== '' ? ': ' . $detail : '');
};

$activeFiles = [
    'src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php',
    'src/Infrastructure/Persistence/PdoCatalogVerifiedPackagePersistence.php',
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
    'src/Infrastructure/Metadata/CatalogCompactMetadataMutationService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedStagingIndex.php',
    'src/Infrastructure/Unverified/PdoUnverifiedFileDetailsQuery.php',
    'src/Infrastructure/Unverified/PdoUnverifiedGameMatchQuery.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedRenameService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedMetadataRepairService.php',
    'src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php',
];
$legacyTables = \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataTables();
foreach ($activeFiles as $relative) {
    $source = (string)@file_get_contents($root . '/' . $relative);
    $hits = \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataReferences($source);
    $record('source_no_legacy_tables:' . $relative, $source !== '' && $hits === [], implode(',', $hits));
}

$store = $root . '/src/Infrastructure/Unverified/CatalogUnverifiedMetadataStore.php';
$builder = $root . '/src/Infrastructure/Unverified/CatalogUnverifiedMetadataSnapshotBuilder.php';
$finalizer = $root . '/src/Infrastructure/Unverified/CatalogUnverifiedCompactMetadataFinalizer.php';
$record('source_staging_store_present', is_file($store), $store);
$record('source_staging_builder_present', is_file($builder), $builder);
$record('source_staging_finalizer_present', is_file($finalizer), $finalizer);
$record(
    'source_legacy_row_writer_removed',
    !is_file($root . '/src/Infrastructure/Persistence/PdoCatalogPackageTableWriter.php'),
    'PdoCatalogPackageTableWriter must remain retired'
);

$installSql = (string)@file_get_contents($root . '/install.sql');
$record(
    'fresh_install_contains_unverified_staging',
    $installSql !== ''
        && str_contains($installSql, 'Consolidated migration baseline: 202608090002')
        && preg_match('/CREATE\s+TABLE\s+ue_unverified_metadata\b/i', $installSql) === 1,
    'consolidated install.sql must own the current unverified staging schema'
);

if ($withDatabase) {
    try {
        require_once $root . '/bootstrap.php';
        $application = catalog_bootstrap(false);
        $db = $application->db;

        $exists = $db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_unverified_metadata"'
        )->fetchColumn();
        $record('db_unverified_metadata_table', (int)$exists === 1, 'table exists=' . (int)$exists);

        if ((int)$exists === 1) {
            $unverified = (int)$db->query('SELECT COUNT(*) FROM ue_files WHERE scan_status="unverified"')->fetchColumn();
            $staged = (int)$db->query(
                'SELECT COUNT(*) FROM ue_unverified_metadata m '
                . 'JOIN ue_files f ON f.id=m.file_id AND f.scan_status="unverified"'
            )->fetchColumn();
            $missing = (int)$db->query(
                'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_unverified_metadata m ON m.file_id=f.id '
                . 'WHERE f.scan_status="unverified" AND m.file_id IS NULL'
            )->fetchColumn();
            $staleCurrent = (int)$db->query(
                'SELECT COUNT(*) FROM ue_unverified_metadata s '
                . 'JOIN ue_files f ON f.id=s.file_id AND f.scan_status="verified" '
                . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
            )->fetchColumn();
            $record(
                'db_unverified_staging_coverage',
                $missing === 0 && $unverified === $staged,
                'unverified=' . $unverified . ' staged=' . $staged . ' missing=' . $missing
            );
            $record(
                'db_no_stale_staging_after_format2',
                $staleCurrent === 0,
                'stale_current=' . $staleCurrent
            );

            $mismatches = (int)$db->query(
                'SELECT COUNT(*) FROM ue_files f JOIN ue_unverified_metadata s ON s.file_id=f.id '
                . 'WHERE f.scan_status="unverified" AND ('
                . 'f.name_count<>s.name_count OR f.import_count<>s.import_count OR f.export_count<>s.export_count)'
            )->fetchColumn();
            $record('db_unverified_staging_count_parity', $mismatches === 0, 'mismatches=' . $mismatches);
        }

        $legacyDetail = [];
        $allAbsent = true;
        foreach ($legacyTables as $table) {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            );
            $statement->execute([$table]);
            $present = (int)$statement->fetchColumn() === 1;
            $legacyDetail[$table] = $present ? 'present' : 'absent';
            $allAbsent = $allAbsent && !$present;
        }
        $record(
            'db_retired_metadata_tables_physically_absent',
            $allAbsent,
            json_encode($legacyDetail, JSON_UNESCAPED_SLASHES) ?: ''
        );
    } catch (Throwable $error) {
        $record('database_checks', false, get_class($error) . ': ' . $error->getMessage());
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
