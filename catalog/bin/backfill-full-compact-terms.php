#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

/** @return int */
function compact_term_backfill_scalar(PDO $db, string $sql): int
{
    return (int)($db->query($sql)->fetchColumn() ?: 0);
}

/** @param array<string,string> $updates @param array<string,int> $changed */
function compact_term_backfill_run(PDO $db, array $updates, array &$changed): void
{
    foreach ($updates as $name => $sql) {
        $started = microtime(true);
        $count = $db->exec($sql);
        $changed[$name] = max(0, (int)$count);
        fwrite(
            STDERR,
            $name . ': changed=' . $changed[$name]
            . ', elapsed=' . number_format(microtime(true) - $started, 2) . "s\n"
        );
    }
}

function compact_term_backfill_remaining(PDO $db): int
{
    return compact_term_backfill_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_terms '
        . 'WHERE is_overflow=1 AND ('
        . 'OCTET_LENGTH(value_prefix)<>value_length OR value_hash<>UNHEX(MD5(value_prefix)))'
    );
}

try {
    set_time_limit(0);
    $config = catalog_config();
    $db = catalog_db($config);

    $dataType = strtolower((string)($db->query(
        'SELECT DATA_TYPE FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" '
        . 'AND COLUMN_NAME="value_prefix" LIMIT 1'
    )->fetchColumn() ?: ''));
    if ($dataType !== 'mediumblob') {
        throw new RuntimeException(
            'ue_terms.value_prefix is not MEDIUMBLOB. Run php catalog/bin/migrate.php migrate first.'
        );
    }

    $initial = compact_term_backfill_remaining($db);

    $indexedUpdates = [
        'export_object' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_export_lookup l ON l.object_term_id=t.id '
            . 'JOIN ue_exports e ON e.file_id=l.file_id AND e.export_index=l.export_index '
            . 'SET t.value_prefix=e.object_name '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(e.object_name) '
            . 'AND t.value_hash=UNHEX(MD5(e.object_name))',
        'export_local_path' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_export_lookup l ON l.local_path_term_id=t.id '
            . 'JOIN ue_exports e ON e.file_id=l.file_id AND e.export_index=l.export_index '
            . 'SET t.value_prefix=e.local_path '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(e.local_path) '
            . 'AND t.value_hash=UNHEX(MD5(e.local_path))',
        'dependency_required_package' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.required_package_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'SET t.value_prefix=i.root_package '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(i.root_package) '
            . 'AND t.value_hash=UNHEX(MD5(i.root_package))',
        'dependency_required_object' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.required_object_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'SET t.value_prefix=i.full_path '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(i.full_path) '
            . 'AND t.value_hash=UNHEX(MD5(i.full_path))',
        'dependency_import_object' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.import_object_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'SET t.value_prefix=i.object_name '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(i.object_name) '
            . 'AND t.value_hash=UNHEX(MD5(i.object_name))',
    ];

    $fallbackUpdates = [
        'export_class' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_export_lookup l ON l.class_term_id=t.id '
            . 'JOIN ue_exports e ON e.file_id=l.file_id AND e.export_index=l.export_index '
            . 'SET t.value_prefix=e.class_name '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(e.class_name) '
            . 'AND t.value_hash=UNHEX(MD5(e.class_name))',
        'dependency_class_package' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.import_class_package_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'SET t.value_prefix=i.class_package '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(i.class_package) '
            . 'AND t.value_hash=UNHEX(MD5(i.class_package))',
        'dependency_class_name' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.import_class_name_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'SET t.value_prefix=i.class_name '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(i.class_name) '
            . 'AND t.value_hash=UNHEX(MD5(i.class_name))',
        'dependency_resolution_source' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.resolution_source_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'JOIN ue_dependencies d ON d.file_id=l.file_id AND d.import_id=i.id '
            . 'SET t.value_prefix=d.resolution_source '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(d.resolution_source) '
            . 'AND t.value_hash=UNHEX(MD5(d.resolution_source))',
        'dependency_resolution_confidence' =>
            'UPDATE ue_terms t '
            . 'JOIN ue_dependency_links l ON l.resolution_confidence_term_id=t.id '
            . 'JOIN ue_imports i ON i.file_id=l.file_id AND i.import_index=l.import_index '
            . 'JOIN ue_dependencies d ON d.file_id=l.file_id AND d.import_id=i.id '
            . 'SET t.value_prefix=d.resolution_confidence '
            . 'WHERE t.is_overflow=1 AND OCTET_LENGTH(t.value_prefix)<>t.value_length '
            . 'AND t.value_length=OCTET_LENGTH(d.resolution_confidence) '
            . 'AND t.value_hash=UNHEX(MD5(d.resolution_confidence))',
    ];

    $changed = [];
    compact_term_backfill_run($db, $indexedUpdates, $changed);
    $remainingAfterIndexed = compact_term_backfill_remaining($db);

    if ($remainingAfterIndexed > 0) {
        fwrite(
            STDERR,
            'Indexed sources left ' . $remainingAfterIndexed
            . " term(s); running bounded fallback scans.\n"
        );
        compact_term_backfill_run($db, $fallbackUpdates, $changed);
    }

    $remaining = compact_term_backfill_remaining($db);
    $sample = [];
    if ($remaining > 0) {
        $sample = $db->query(
            'SELECT id,value_length,OCTET_LENGTH(value_prefix) stored_length,HEX(value_hash) value_hash '
            . 'FROM ue_terms WHERE is_overflow=1 AND ('
            . 'OCTET_LENGTH(value_prefix)<>value_length OR value_hash<>UNHEX(MD5(value_prefix))) '
            . 'ORDER BY id LIMIT 25'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $output = [
        'verified' => $remaining === 0,
        'initial_incomplete_overflow_terms' => $initial,
        'remaining_after_indexed_sources' => $remainingAfterIndexed,
        'fallback_scans_ran' => $remainingAfterIndexed > 0,
        'changed_by_source' => $changed,
        'remaining_incomplete_overflow_terms' => $remaining,
        'remaining_sample' => $sample,
    ];
    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($remaining === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact term overflow backfill failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
