#!/usr/bin/env php
<?php
/** Static and optional database contract for BIGINT compact term IDs. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};
$database = in_array('--database', $argv, true);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$migration = $read('migrations/202609040001_compact_term_ids_bigint.php');
$repair = $read('bin/repair-ue-terms-auto-increment.php');

$record(
    'migration_widens_all_term_reference_columns',
    str_contains($migration, "'ue_name_lookup'")
        && str_contains($migration, "'name_term_id'")
        && str_contains($migration, "'ue_export_lookup'")
        && str_contains($migration, "'object_term_id'")
        && str_contains($migration, "'class_term_id'")
        && str_contains($migration, "'local_path_term_id'")
        && str_contains($migration, "'ue_dependency_links'")
        && str_contains($migration, "'required_package_term_id'")
        && str_contains($migration, "'required_object_term_id'")
        && str_contains($migration, "'import_class_package_term_id'")
        && str_contains($migration, "'import_class_name_term_id'")
        && str_contains($migration, "'import_object_term_id'")
        && str_contains($migration, "'resolution_source_term_id'")
        && str_contains($migration, "'resolution_confidence_term_id'"),
    'Every durable column that stores a ue_terms.id value must be widened.'
);

$record(
    'references_are_widened_before_dictionary',
    strpos($migration, "foreach (\$referenceColumns as \$table => \$columns)")
        < strpos($migration, "ALTER TABLE ue_terms MODIFY COLUMN id BIGINT UNSIGNED"),
    'Reference columns must be widened before ue_terms.id so an interrupted migration remains storage-compatible.'
);

$record(
    'migration_is_restartable_and_checks_workers',
    str_contains($migration, "str_starts_with(\$type, 'bigint')")
        && str_contains($migration, 'Stop all Background Jobs workers')
        && str_contains($migration, 'BIGINT term migration verification failed'),
    'Large DDL may be resumed after interruption and must refuse to start while jobs are running.'
);

$record(
    'repair_reports_bigint_migration_when_uint32_is_full',
    str_contains($repair, 'migration 202609040001')
        && str_contains($repair, 'BIGINT UNSIGNED'),
    'The old AUTO_INCREMENT repair tool must direct an exhausted live dictionary to the coordinated schema migration.'
);

$syntaxFailures = [];
foreach ([
    $root . '/migrations/202609040001_compact_term_ids_bigint.php',
    $root . '/bin/preflight-term-id-bigint-migration.php',
    $root . '/bin/repair-ue-terms-auto-increment.php',
    __FILE__,
] as $file) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($file) . ': could not lint';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) $syntaxFailures[] = basename($file) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

if ($database) {
    require_once $root . '/lib/CatalogSupport.php';
    $db = catalog_db(catalog_config());
    $targets = [
        'ue_terms.id',
        'ue_name_lookup.name_term_id',
        'ue_export_lookup.object_term_id',
        'ue_export_lookup.class_term_id',
        'ue_export_lookup.local_path_term_id',
        'ue_dependency_links.required_package_term_id',
        'ue_dependency_links.required_object_term_id',
        'ue_dependency_links.import_class_package_term_id',
        'ue_dependency_links.import_class_name_term_id',
        'ue_dependency_links.import_object_term_id',
        'ue_dependency_links.resolution_source_term_id',
        'ue_dependency_links.resolution_confidence_term_id',
    ];
    $notBigint = [];
    foreach ($targets as $target) {
        [$table, $column] = explode('.', $target, 2);
        $statement = $db->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $statement->execute([$table, $column]);
        $type = strtolower((string)($statement->fetchColumn() ?: 'missing'));
        if (!str_starts_with($type, 'bigint') || !str_contains($type, 'unsigned')) {
            $notBigint[] = $target . '=' . $type;
        }
    }
    $record(
        'database_term_ids_are_bigint_unsigned',
        $notBigint === [],
        implode(', ', $notBigint)
    );

    $max = (int)($db->query('SELECT COALESCE(MAX(id),0) FROM ue_terms')->fetchColumn() ?: 0);
    $auto = (int)($db->query(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" LIMIT 1'
    )->fetchColumn() ?: 0);
    $record(
        'database_dictionary_can_allocate_above_uint32',
        $auto > 4294967295 && $auto > $max,
        'max_id=' . $max . ', auto_increment=' . $auto
    );
}

$result = ['ok' => $failures === [], 'database_checked' => $database, 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
