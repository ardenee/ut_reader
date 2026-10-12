#!/usr/bin/env php
<?php
/**
 * Read-only, deliberately bounded source-vs-V5 validation after production cutover.
 * One lowest and one highest verified file ID per game; no status or SQL writes.
 * This is a sample, not a substitute for complete source conformance.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/bootstrap.php';
require $root.'/lib/GameProfiles.php';
require $root.'/lib/CatalogUE4ParserProfile.php';
require $root.'/lib/CatalogUE5ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;

try {
    $app = catalog_bootstrap(false);
    $v = new Uedb5MigrationValidator($app->db, catalog_config());
    $sampleGroups = $app->db->query('SELECT game_id,MIN(id) first_id,MAX(id) last_id FROM ue_files WHERE scan_status="verified" GROUP BY game_id ORDER BY game_id')->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($sampleGroups as $g) {
        foreach (array_unique([(int)$g['first_id'],(int)$g['last_id']]) as $id) {
            $t = microtime(true);
            try {
                $r = $v->validate($id);
                $results[] = ['game_id'=>(int)$g['game_id'],'file_id'=>$id,'ok'=>!empty($r['ready']) && ($r['source_validation_mode']??'')==='fresh_verified_source',
                    'source_validation_mode'=>$r['source_validation_mode']??null,'seconds'=>round(microtime(true)-$t,2)];
            } catch (Throwable $e) {
                $results[] = ['game_id'=>(int)$g['game_id'],'file_id'=>$id,'ok'=>false,'error'=>$e->getMessage(),'seconds'=>round(microtime(true)-$t,2)];
            }
        }
    }
    $ok = $results !== [] && !in_array(false,array_column($results,'ok'),true);
    echo json_encode(['ok'=>$ok,'read_only'=>true,'sample_count'=>count($results),'sample_scope'=>'first and last verified ID per game; source bytes re-parsed','results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:2);
} catch (Throwable $e) {
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()]).PHP_EOL);
    exit(1);
}
