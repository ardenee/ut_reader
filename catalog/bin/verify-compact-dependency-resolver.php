#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyResolver;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return int */
function compact_resolver_file_id(array $arguments): int
{
    foreach ($arguments as $argument) {
        if (str_starts_with((string)$argument, '--file-id=')) {
            return max(0, (int)substr((string)$argument, strlen('--file-id=')));
        }
    }
    return 0;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $requestedFileId = compact_resolver_file_id(array_slice($argv, 1));

    $where = $requestedFileId > 0 ? ' AND l.file_id=?' : '';
    $statement = $db->prepare(
        'SELECT l.file_id,l.import_index,l.resolved_file_id,l.resolved_export_index,'
        . 'f.game_id,i.id import_id,i.root_package,i.full_path,i.relative_object_path,i.is_common,'
        . 'e.id legacy_export_id,'
        . '(SELECT COUNT(*) FROM ue_dependencies rd WHERE rd.resolved_export_id=e.id) legacy_reference_count '
        . 'FROM ue_dependency_links l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
        . 'JOIN ue_exports e ON e.file_id=l.resolved_file_id AND e.export_index=l.resolved_export_index '
        . 'WHERE l.status=1 AND l.resolved_file_id IS NOT NULL AND l.resolved_export_index IS NOT NULL'
        . $where
        . ' HAVING legacy_reference_count BETWEEN 1 AND 10 '
        . 'ORDER BY legacy_reference_count,l.file_id,l.import_index LIMIT 1'
    );
    $statement->execute($requestedFileId > 0 ? [$requestedFileId] : []);
    $fixture = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($fixture)) {
        throw new RuntimeException(
            'No resolved dependency fixture with 1-10 legacy references was found'
            . ($requestedFileId > 0 ? ' for file #' . $requestedFileId : '') . '.'
        );
    }

    $legacyExportId = (int)$fixture['legacy_export_id'];
    $legacyReferenceCount = (int)$fixture['legacy_reference_count'];
    if ($legacyExportId < 1 || $legacyReferenceCount < 1 || $legacyReferenceCount > 10) {
        throw new RuntimeException('The selected rollback fixture is outside the safe reference bound.');
    }

    $import = [
        'id' => (int)$fixture['import_id'],
        'root_package' => (string)$fixture['root_package'],
        'full_path' => (string)$fixture['full_path'],
        'relative_object_path' => (string)$fixture['relative_object_path'],
        'is_common' => (int)$fixture['is_common'],
    ];

    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM ue_exports WHERE id=?');
        $delete->execute([$legacyExportId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Could not hide the selected legacy Export row.');
        }

        $resolved = CatalogDependencyResolver::resolve(
            $db,
            (int)$fixture['game_id'],
            (int)$fixture['file_id'],
            [$import]
        );
        $actual = $resolved[(int)$fixture['import_id']] ?? null;
        if (!is_array($actual)) {
            throw new RuntimeException('Resolver returned no result for the selected Import.');
        }
        if ((string)$actual['status'] !== 'resolved') {
            throw new RuntimeException('Compact resolver status mismatch: ' . (string)$actual['status'] . '.');
        }
        if ((int)$actual['resolved_file_id'] !== (int)$fixture['resolved_file_id']) {
            throw new RuntimeException('Compact resolver target file mismatch.');
        }
        if ((int)$actual['resolved_export_index'] !== (int)$fixture['resolved_export_index']) {
            throw new RuntimeException('Compact resolver target export_index mismatch.');
        }
        if ($actual['resolved_export_id'] !== null) {
            throw new RuntimeException('Resolver unexpectedly returned a deleted legacy Export identifier.');
        }
        if (!in_array((string)$actual['source'], ['exact_object', 'exact_object_alias'], true)) {
            throw new RuntimeException('Resolver returned an unexpected resolution source label.');
        }

        $output = [
            'verified' => true,
            'source_file_id' => (int)$fixture['file_id'],
            'import_index' => (int)$fixture['import_index'],
            'target_file_id' => (int)$fixture['resolved_file_id'],
            'target_export_index' => (int)$fixture['resolved_export_index'],
            'hidden_legacy_export_id' => $legacyExportId,
            'hidden_export_reference_count' => $legacyReferenceCount,
            'resolution_source' => (string)$actual['source'],
            'compact_resolution_proven_by_null_legacy_id' => true,
            'transaction_rolled_back' => true,
        ];
        $db->rollBack();
        fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact dependency resolver verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
