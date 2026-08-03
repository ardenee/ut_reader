#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyReadSource;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $source = CatalogDependencyReadSource::sql($db);

    $explicitTotal = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ' . $source . ' d')['c'] ?? -1);
    $rewrittenTotal = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_dependencies')['c'] ?? -2);
    if ($explicitTotal !== $rewrittenTotal) {
        throw new RuntimeException('Unaliased dependency total mismatch.');
    }

    $explicitMissing = (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c FROM ' . $source . ' d WHERE d.status="missing"'
    )['c'] ?? -1);
    $rewrittenMissing = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"');
    if ($explicitMissing !== $rewrittenMissing) {
        throw new RuntimeException('Unaliased missing dependency count mismatch.');
    }

    $fileId = (int)($db->query(
        'SELECT file_id FROM ue_dependency_links ORDER BY file_id,import_index LIMIT 1'
    )->fetchColumn() ?: 0);
    if ($fileId < 1) {
        throw new RuntimeException('No compact dependency row is available for verification.');
    }

    $explicitRows = catalog_all(
        $db,
        'SELECT d.file_id,d.import_id,d.import_index,d.required_package,d.required_object_path,'
        . 'd.resolved_file_id,d.resolved_export_index,d.status,d.resolution_source,d.resolution_confidence '
        . 'FROM ' . $source . ' d WHERE d.file_id=? ORDER BY d.import_index LIMIT 25',
        [$fileId]
    );
    $rewrittenRows = catalog_all(
        $db,
        'SELECT d.file_id,d.import_id,d.import_index,d.required_package,d.required_object_path,'
        . 'd.resolved_file_id,d.resolved_export_index,d.status,d.resolution_source,d.resolution_confidence '
        . 'FROM ue_dependencies d WHERE d.file_id=? ORDER BY d.import_index LIMIT 25',
        [$fileId]
    );
    if ($explicitRows !== $rewrittenRows) {
        throw new RuntimeException('Aliased per-file dependency rows differ from the explicit mixed source.');
    }

    $explicitResolvedFiles = catalog_all(
        $db,
        'SELECT DISTINCT rf.id FROM ' . $source . ' d '
        . 'JOIN ue_files rf ON rf.id=d.resolved_file_id '
        . 'WHERE d.file_id=? AND d.status IN ("resolved","package_only") ORDER BY rf.id LIMIT 25',
        [$fileId]
    );
    $rewrittenResolvedFiles = catalog_all(
        $db,
        'SELECT DISTINCT rf.id FROM ue_dependencies d '
        . 'JOIN ue_files rf ON rf.id=d.resolved_file_id '
        . 'WHERE d.file_id=? AND d.status IN ("resolved","package_only") ORDER BY rf.id LIMIT 25',
        [$fileId]
    );
    if ($explicitResolvedFiles !== $rewrittenResolvedFiles) {
        throw new RuntimeException('Dependency join rows differ from the explicit mixed source.');
    }

    $mutation = 'UPDATE ue_dependencies SET status="missing" WHERE file_id=0';
    if (catalog_runtime_sql_compat_rewrite($db, $mutation) !== $mutation) {
        throw new RuntimeException('Mutation SQL was unexpectedly rewritten.');
    }

    fwrite(STDOUT, json_encode([
        'verified' => true,
        'checked_file_id' => $fileId,
        'total_dependencies' => $rewrittenTotal,
        'missing_dependencies' => $rewrittenMissing,
        'sample_rows' => count($rewrittenRows),
        'sample_resolved_files' => count($rewrittenResolvedFiles),
        'unaliased_select_rewrite' => true,
        'aliased_select_rewrite' => true,
        'join_rewrite' => true,
        'mutation_rewrite_blocked' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Runtime dependency SQL compatibility verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
