<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
require_once dirname(__DIR__) . '/lib/CatalogSearchService.php';

$query = trim((string)($argv[1] ?? ''));
if ($query === '') {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-search-term.php <term>\n");
    exit(2);
}
$config = catalog_config();
$db = catalog_db($config);

$out = [
    'query' => $query,
    'bytes' => strlen($query),
    'md5' => md5($query),
    'term_rows' => [],
    'projection_counts' => [],
    'service' => [],
];

$stmt = $db->prepare(
    'SELECT id,value_length,is_overflow,HEX(value_hash) value_hash,'
    . 'CONVERT(value_prefix USING utf8mb4) value_text '
    . 'FROM ue_terms WHERE value_hash=? AND value_length=? ORDER BY id'
);
$stmt->execute([md5($query, true), strlen($query)]);
$terms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($terms as $row) {
    $row['exact_bytes'] = hash_equals((string)$row['value_text'], $query);
    $out['term_rows'][] = $row;
    $id = (int)$row['id'];
    foreach ([
        'ue_name_lookup.name_term_id' => ['ue_name_lookup', 'name_term_id'],
        'ue_export_lookup.object_term_id' => ['ue_export_lookup', 'object_term_id'],
        'ue_export_lookup.local_path_term_id' => ['ue_export_lookup', 'local_path_term_id'],
        'ue_dependency_links.import_object_term_id' => ['ue_dependency_links', 'import_object_term_id'],
        'ue_dependency_links.required_object_term_id' => ['ue_dependency_links', 'required_object_term_id'],
        'ue_dependency_links.required_package_term_id' => ['ue_dependency_links', 'required_package_term_id'],
    ] as $label => [$table, $column]) {
        $s = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . '=?');
        $s->execute([$id]);
        $out['projection_counts'][$label][$id] = (int)$s->fetchColumn();
    }
}

$columns = [
    ['ue_name_lookup','name_term_id'],
    ['ue_export_lookup','object_term_id'],
    ['ue_export_lookup','local_path_term_id'],
    ['ue_dependency_links','import_object_term_id'],
    ['ue_dependency_links','required_object_term_id'],
];
foreach ($columns as [$table,$column]) {
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table,$column]);
    $out['compact_columns'][$table.'.'.$column] = (bool)$s->fetchColumn();
}

foreach ([
    'global' => [],
    'exports' => ['fields'=>['exports']],
    'names' => ['fields'=>['names']],
    'imports' => ['fields'=>['imports']],
] as $label => $filters) {
    try {
        $rows = CatalogSearchService::findFiles($db, $query, 20, null, $filters);
        $out['service'][$label] = array_map(static fn(array $r): array => [
            'id'=>(int)$r['id'],
            'game'=>(string)($r['game_name'] ?? ''),
            'file'=>(string)($r['original_name'] ?? ''),
            'package'=>(string)($r['package_name'] ?? ''),
            'matches'=>$r['matched_fields'] ?? [],
        ], $rows);
    } catch (Throwable $e) {
        $out['service'][$label] = ['error'=>get_class($e).': '.$e->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
