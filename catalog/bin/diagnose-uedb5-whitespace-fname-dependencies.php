#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

$options = getopt('', ['game-id::','game::','value-hex::','limit::']);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$slug = trim((string)($options['game'] ?? ''));
$valueHex = strtoupper(trim((string)($options['value-hex'] ?? '20')));
$limit = max(1, min(10000, (int)($options['limit'] ?? 1000)));
if ($gameId < 1 && $slug === '') {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-uedb5-whitespace-fname-dependencies.php --game=ut99 [--value-hex=20] [--limit=1000]\n");
    exit(2);
}
if ($valueHex === '' || (strlen($valueHex) % 2) !== 0 || preg_match('/^[0-9A-F]+$/', $valueHex) !== 1) {
    fwrite(STDERR, "--value-hex must contain an even number of hexadecimal digits.\n");
    exit(2);
}
$value = hex2bin($valueHex);
if ($value === false || $value === '' || trim($value) !== '') {
    fwrite(STDERR, "--value-hex must encode a non-empty whitespace-only FName value.\n");
    exit(2);
}

try {
    $app = catalog_bootstrap();
    $db = $app->db;
    if ($gameId < 1) {
        $s = $db->prepare('SELECT id FROM ue_games WHERE slug=? LIMIT 1');
        $s->execute([$slug]);
        $resolved = $s->fetchColumn();
        if ($resolved === false) { throw new RuntimeException('Unknown game slug: ' . $slug); }
        $gameId = (int)$resolved;
    }
    $game = $db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1');
    $game->execute([$gameId]);
    $gameRow = $game->fetch(PDO::FETCH_ASSOC);
    if (!is_array($gameRow)) { throw new RuntimeException('Unknown game ID: ' . $gameId); }

    $term = $db->prepare('SELECT id,value_prefix FROM ue_terms WHERE value_hash=? AND value_length=? ORDER BY id LIMIT 1');
    $term->execute([md5($value, true), strlen($value)]);
    $termRow = $term->fetch(PDO::FETCH_ASSOC);
    $termId = 0;
    if (is_array($termRow)) {
        $stored = (string)($termRow['value_prefix'] ?? '');
        if (hash_equals($stored, $value) || hash_equals($stored, substr($value, 0, 200))) {
            $termId = (int)$termRow['id'];
        }
    }
    if ($termId < 1) {
        echo json_encode([
            'ok'=>true,'read_only'=>true,'game'=>$gameRow,'value_hex'=>$valueHex,
            'term_id'=>null,'affected_file_count'=>0,'row_count'=>0,'rows'=>[],
            'note'=>'Exact ue_terms hash/length lookup found no matching serialized FName; no dependency-table scan was performed.',
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }

    $count = $db->prepare(
        'SELECT COUNT(*) row_count,COUNT(DISTINCT l.file_id) file_count '
        . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
        . 'WHERE l.import_object_term_id=? AND f.game_id=? AND f.scan_status="verified"'
    );
    $count->execute([$termId, $gameId]);
    $counts = $count->fetch(PDO::FETCH_ASSOC) ?: [];

    $rows = $db->prepare(
        'SELECT l.file_id,l.import_index,l.status v4_outcome,l.resolved_file_id v4_provider,'
        . 'CONVERT(p.value_prefix USING utf8mb4) required_package,'
        . 'e.outcome v5_outcome,e.resolved_file_id v5_provider,e.resolved_object_index v5_object_index '
        . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
        . 'LEFT JOIN ue_terms p ON p.id=l.required_package_term_id '
        . 'LEFT JOIN ue_uedb5_dependency_edges e ON e.file_id=l.file_id AND e.source_kind=1 AND e.source_index=l.import_index '
        . 'WHERE l.import_object_term_id=? AND f.game_id=? AND f.scan_status="verified" '
        . 'ORDER BY l.file_id,l.import_index LIMIT ' . $limit
    );
    $rows->execute([$termId, $gameId]);
    $details = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($details as &$row) {
        foreach (['file_id','import_index','v4_outcome','v4_provider','v5_outcome','v5_provider','v5_object_index'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) { $row[$field] = (int)$row[$field]; }
        }
        $row['import_object_hex'] = $valueHex;
    }
    unset($row);

    echo json_encode([
        'ok'=>true,'read_only'=>true,'game'=>$gameRow,'value_hex'=>$valueHex,'term_id'=>$termId,
        'row_count'=>(int)($counts['row_count'] ?? 0),
        'affected_file_count'=>(int)($counts['file_count'] ?? 0),
        'returned_row_count'=>count($details),'limit'=>$limit,'rows'=>$details,
        'note'=>'Exact indexed ue_terms identity lookup followed by indexed import_object_term_id lookup; no package/UEDB container scan is performed.',
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
