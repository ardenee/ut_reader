<?php
declare(strict_types=1);
/** Read-only V5 compact schema audit. Uses information_schema only; no candidate scans. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $db = catalog_bootstrap(false)->db;
    $tables = [
        'ue_uedb5_compact_name_dictionary',
        'ue_uedb5_compact_name_candidates',
        'ue_uedb5_compact_object_candidates',
    ];
    $legacy = ['ue_uedb5_name_candidates', 'ue_uedb5_object_candidates'];
    $rows = $db->query(
        "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
    )->fetchAll(PDO::FETCH_ASSOC);
    $present = [];
    foreach ($rows as $row) { $present[(string)$row['TABLE_NAME']] = (string)$row['ENGINE']; }
    $checks = [];
    foreach ($tables as $table) {
        $checks['innodb_' . $table] = ($present[$table] ?? '') === 'InnoDB';
    }
    foreach ($legacy as $table) {
        $checks['absent_' . $table] = !array_key_exists($table, $present);
    }
    $indexes = $db->query(
        "SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
         ('ue_uedb5_compact_name_dictionary','ue_uedb5_compact_name_candidates','ue_uedb5_compact_object_candidates')
         ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC);
    $actual = [];
    foreach ($indexes as $row) {
        $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
        $actual[$key][] = (string)$row['COLUMN_NAME'];
    }
    $expected = [
        'ue_uedb5_compact_name_dictionary.uq_uedb5_compact_name_fingerprint' => ['name_key_fingerprint'],
        'ue_uedb5_compact_name_dictionary.idx_uedb5_compact_name_lookup' => ['name_key_hash','name_key_length','name_key_fingerprint'],
        'ue_uedb5_compact_name_candidates.PRIMARY' => ['file_id','name_id'],
        'ue_uedb5_compact_name_candidates.idx_uedb5_compact_names_lookup' => ['name_id','file_id'],
        'ue_uedb5_compact_object_candidates.PRIMARY' => ['file_id','object_kind','object_index'],
        'ue_uedb5_compact_object_candidates.idx_uedb5_compact_objects_lookup' => ['name_id','file_id','object_kind','object_index'],
        'ue_uedb5_compact_object_candidates.idx_uedb5_compact_public_export' => ['file_id','object_kind','public_export_hash','object_index'],
    ];
    foreach ($expected as $name => $columns) {
        $checks['index_' . $name] = ($actual[$name] ?? []) === $columns;
    }
    $fk = $db->query(
        "SELECT CONSTRAINT_NAME,TABLE_NAME,REFERENCED_TABLE_NAME,DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN
         ('ue_uedb5_compact_name_candidates','ue_uedb5_compact_object_candidates')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $actualFk = [];
    foreach ($fk as $row) {
        $actualFk[(string)$row['CONSTRAINT_NAME']] = [(string)$row['REFERENCED_TABLE_NAME'],(string)$row['DELETE_RULE']];
    }
    foreach ([
        'fk_uedb5_compact_names_file' => ['ue_uedb5_files','CASCADE'],
        'fk_uedb5_compact_names_dict' => ['ue_uedb5_compact_name_dictionary','NO ACTION'],
        'fk_uedb5_compact_objects_file' => ['ue_uedb5_files','CASCADE'],
        'fk_uedb5_compact_objects_dict' => ['ue_uedb5_compact_name_dictionary','NO ACTION'],
    ] as $name => $rule) {
        $checks['fk_' . $name] = ($actualFk[$name] ?? []) === $rule;
    }
    $failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    echo json_encode(['ok' => $failed === [], 'read_only' => true,
        'information_schema_only' => true, 'checks' => $checks, 'failures' => $failed],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$error->getMessage()]) . PHP_EOL);
    exit(1);
}
