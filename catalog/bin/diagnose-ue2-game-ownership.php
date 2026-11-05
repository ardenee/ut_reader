#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Read-only UE2 provenance analysis. Compare *directory* evidence of UT2003
 * and UT2004 with exact existing hashes under those games. Never infer game
 * ownership from a filename containing 'ut2003' (e.g. Unreal II map packs).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';
$options = getopt('', ['db-host::','game-id::']);
$gameId = max(1, (int)($options['game-id'] ?? 2));
$config = catalog_config();
if (isset($options['db-host']) && trim((string)$options['db-host']) !== '') {
    $config['db']['host'] = trim((string)$options['db-host']);
}
$db = catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    // Require a slash after the game-identifying directory segment;
    // a package filename with UT2003 in its name is insufficient evidence.
    $sql = <<<'SQL'
SELECT
    CASE
      WHEN LOWER(f.source_relative_path) REGEXP '(^|/)[^/]*(ut2004|unreal tournament 2004)[^/]*/' THEN 5
      WHEN LOWER(f.source_relative_path) REGEXP '(^|/)[^/]*(ut2003|unreal tournament 2003)[^/]*/' THEN 4
      ELSE 0 END indicated_game_id,
    COUNT(*) suspect_files,
    SUM(EXISTS(
      SELECT 1 FROM ue_files correct
      WHERE correct.game_id=CASE
          WHEN LOWER(f.source_relative_path) REGEXP '(^|/)[^/]*(ut2004|unreal tournament 2004)[^/]*/' THEN 5
          ELSE 4 END
        AND correct.scan_status='verified'
        AND correct.file_size=f.file_size
        AND correct.md5=f.md5 AND correct.sha1=f.sha1
    )) exact_verified_copy_in_indicated_game
FROM ue_files f
WHERE f.game_id=? AND f.scan_status='verified'
AND (LOWER(f.source_relative_path) REGEXP '(^|/)[^/]*(ut2004|unreal tournament 2004)[^/]*/'
  OR LOWER(f.source_relative_path) REGEXP '(^|/)[^/]*(ut2003|unreal tournament 2003)[^/]*/')
GROUP BY indicated_game_id ORDER BY indicated_game_id
SQL;
    $statement = $db->prepare($sql);
    $statement->execute([$gameId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $sum = 0; $duplicates = 0;
    foreach ($rows as &$row) {
        $row['indicated_game_id'] = (int)$row['indicated_game_id'];
        $row['suspect_files'] = (int)$row['suspect_files'];
        $row['exact_verified_copy_in_indicated_game'] = (int)$row['exact_verified_copy_in_indicated_game'];
        $sum += $row['suspect_files'];
        $duplicates += $row['exact_verified_copy_in_indicated_game'];
    }
    unset($row);
    $db->rollBack();
    echo json_encode([
        'ok'=>true,'catalogue_game_id'=>$gameId,
        'directory_provenance_suspects'=>$sum,
        'exact_verified_under_indicated_game'=>$duplicates,
        'without_exact_cross_game_match'=>$sum-$duplicates,
        'groups'=>$rows,'read_only'=>true,
        'warning'=>'Directory provenance and exact hashes identify a repair candidate; do not rewrite game IDs or delete original records directly.'
    ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
