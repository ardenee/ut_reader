#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

$options = getopt('', ['game-id::']);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$app = catalog_bootstrap();
$db = $app->db;

$requiredTables = [
    'ue_dependency_package_summaries','ue_files','ue_file_package_aliases','ue_invalid_file_identities',
];
$tableCheck = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
);
foreach ($requiredTables as $table) {
    $tableCheck->execute([$table]);
    if ((int)$tableCheck->fetchColumn() !== 1) {
        fwrite(STDERR, json_encode(['ok'=>false,'missing_table'=>$table], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}
if ($gameId > 0) {
    $gameCheck = $db->prepare('SELECT COUNT(*) FROM ue_games WHERE id=?');
    $gameCheck->execute([$gameId]);
    if ((int)$gameCheck->fetchColumn() !== 1) {
        fwrite(STDERR, json_encode(['ok'=>false,'error'=>'Unknown game ID.','game_id'=>$gameId], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}

$physicalCandidatesSql =
    'SELECT f.game_id,f.package_name,f.id file_id FROM ue_files f '
    . 'WHERE f.scan_status="verified" AND f.package_name<>"" '
    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
    . 'UNION '
    . 'SELECT a.game_id,a.package_name,a.file_id FROM ue_file_package_aliases a '
    . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
    . 'WHERE f.scan_status="verified" AND a.package_name<>"" '
    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
    . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))';
$ambiguousPackagesSql =
    'SELECT c.game_id,c.package_name,GROUP_CONCAT(DISTINCT c.file_id ORDER BY c.file_id) candidate_file_ids,'
    . 'COUNT(DISTINCT c.file_id) candidate_count FROM (' . $physicalCandidatesSql . ') c '
    . 'GROUP BY c.game_id,c.package_name HAVING COUNT(DISTINCT c.file_id)>1';

$where = $gameId > 0 ? ' AND s.game_id=?' : '';
$args = $gameId > 0 ? [$gameId] : [];
$sql =
    'SELECT s.game_id,s.file_id,s.required_package,amb.candidate_file_ids,amb.candidate_count '
    . 'FROM ue_dependency_package_summaries s '
    . 'JOIN ue_files consumer ON consumer.id=s.file_id AND consumer.game_id=s.game_id '
    . 'JOIN (' . $ambiguousPackagesSql . ') amb ON amb.game_id=s.game_id AND amb.package_name=s.required_package '
    . 'WHERE consumer.scan_status="verified" AND s.common_count<s.dependency_count' . $where
    . ' ORDER BY s.game_id,s.file_id,s.required_package';
$statement = $db->prepare($sql);
$statement->execute($args);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filesByGame = [];
$packagesByGame = [];
$examples = [];
foreach ($rows as $row) {
    $gid = (int)$row['game_id'];
    $fid = (int)$row['file_id'];
    $filesByGame[$gid][$fid] = true;
    $package = (string)$row['required_package'];
    $packagesByGame[$gid][$package] = true;
    if (count($examples) < 100) {
        $candidateIds = array_values(array_filter(array_map('intval', explode(',', (string)$row['candidate_file_ids']))));
        $examples[] = [
            'game_id'=>$gid,
            'file_id'=>$fid,
            'required_package'=>$package,
            'candidate_file_ids'=>$candidateIds,
        ];
    }
}

$normalizedFiles = [];
$normalizedPackages = [];
foreach ($filesByGame as $gid => $ids) {
    $list = array_map('intval', array_keys($ids));
    sort($list, SORT_NUMERIC);
    $normalizedFiles[(int)$gid] = $list;
}
foreach ($packagesByGame as $gid => $names) {
    $list = array_keys($names);
    natcasesort($list);
    $normalizedPackages[(int)$gid] = array_values($list);
}
ksort($normalizedFiles);
ksort($normalizedPackages);

$totalFiles = 0;
foreach ($normalizedFiles as $ids) { $totalFiles += count($ids); }
echo json_encode([
    'ok'=>true,
    'read_only'=>true,
    'game_id'=>$gameId > 0 ? $gameId : null,
    'impacted_consumer_count'=>$totalFiles,
    'impacted_consumer_file_ids_by_game'=>$normalizedFiles,
    'ambiguous_required_packages_by_game'=>$normalizedPackages,
    'examples'=>$examples,
    'repair'=>'After deploying the source-conformant resolver, rebuild only these exact consumers with rebuild-legacy-dependencies.php --file-id=<id> --game-id=<game> --apply.',
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
