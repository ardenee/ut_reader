#!/usr/bin/env php
<?php
/** Bounded, resumable V5-only source repair and Step 8 validation. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/GameProfiles.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';
require_once $root . '/lib/CatalogUE5ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ValidationException;

$options = getopt('', ['game:', 'limit::', 'after::', 'apply', 'progress-every::', 'workers::', 'worker-index::', 'list-only', 'resync-staged']);
$slug = trim((string)($options['game'] ?? ''));
$limit = max(1, min(500, (int)($options['limit'] ?? 50)));
$after = max(0, (int)($options['after'] ?? 0));
$apply = isset($options['apply']);
$resyncStaged = isset($options['resync-staged']);
if ($resyncStaged && !$apply) {
    throw new InvalidArgumentException('--resync-staged requires --apply.');
}
$progressEvery = max(1, (int)($options['progress-every'] ?? 10));
$workers = (int)($options['workers'] ?? 1);
$workerIndex = (int)($options['worker-index'] ?? 0);
if ($workers < 1 || $workers > 4 || $workerIndex < 0 || $workerIndex >= $workers) {
    throw new InvalidArgumentException('Expected 1..4 workers and a worker-index in 0..workers-1.');
}
if ($slug === '') {
    fwrite(STDERR, "Usage: php repair-uedb5-staged.php --game=ut2004 --limit=50 [--after=123] [--apply]\n");
    exit(1);
}
$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$gameQuery = $db->prepare('SELECT id FROM ue_games WHERE slug=? LIMIT 1');
$gameQuery->execute([$slug]);
$gameId = (int)$gameQuery->fetchColumn();
if ($gameId < 1) { throw new RuntimeException('Unknown game: '.$slug); }

$select = $db->prepare(
    'SELECT s.file_id FROM ue_uedb5_migration_status s '
    . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
    . 'WHERE s.game_id=? AND s.status="staged" AND s.file_id>? AND MOD(s.file_id,?)=? '
    . 'ORDER BY s.file_id LIMIT '.$limit
);
$select->execute([$gameId, $after, $workers, $workerIndex]);
$ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
if (isset($options['list-only'])) {
    if ($apply) { throw new InvalidArgumentException('--list-only cannot be combined with --apply.'); }
    echo json_encode(['game'=>$slug,'workers'=>$workers,'worker_index'=>$workerIndex,'file_ids'=>$ids],JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(0);
}
$validation = new Uedb5MigrationValidationService($db, $config);
$reader = new Uedb5MetadataReader((string)$config['storage_path']);
$source = $apply ? new Uedb5GameSourceMigrationService($db, $config) : null;
$dependencies = $apply ? new Uedb5GameDependencyPassService($db, $config) : null;
$summary = ['game'=>$slug,'mode'=>$resyncStaged ? 'resync_staged' : 'repair_staged','read_only'=>!$apply,'workers'=>$workers,'worker_index'=>$workerIndex,'selected'=>count($ids),
    'validated_existing'=>0,'repaired_validated'=>0,'not_ready'=>0,'failed'=>0,'last_file_id'=>$after];
$issues = [];
foreach ($ids as $position=>$fileId) {
    $summary['last_file_id'] = $fileId;
    $repairNeeded = false;
    $reason = '';
    $backup = null;
    try {
        // Missing fields in older UE1/UE2 source snapshots are proven serializer drift.
        // Still reparse and run the complete validator after any repair.
        if (!$resyncStaged && in_array($slug, ['unreal2','ut2003','ut2004','unrealgold'], true)) {
            $summaryRow = $reader->page($gameId, $fileId, 'summary', 0, 1)[0] ?? [];
            $repairNeeded = is_array($summaryRow)
                && (!array_key_exists('effective_generation_count', $summaryRow)
                    || !array_key_exists('import_object_package_encoding', $summaryRow));
            if ($repairNeeded) { $reason = 'retired_source_summary_schema'; }
        }
        if (!$resyncStaged && !$repairNeeded) {
            if ($apply) {
                try {
                    $checked = $validation->validateFile($slug, $fileId);
                    if (($checked['status'] ?? '') === 'validated') {
                        ++$summary['validated_existing'];
                        if (($position+1)%$progressEvery===0) {
                            echo json_encode(['status'=>'progress']+$summary,JSON_UNESCAPED_SLASHES),PHP_EOL;
                            fflush(STDOUT);
                        }
                        continue;
                    }
                    ++$summary['not_ready'];
                    continue;
                } catch (Uedb5ValidationException $error) {
                    if ($error->reasonCode === 'object_projection_mismatch') {
                        $repaired = (new \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MissingObjectProjectionRepair($db, $config))
                            ->repairFile($fileId);
                        if (($repaired['status'] ?? '') !== 'validated') {
                            throw new RuntimeException('Missing object projection repair did not complete Step 8.');
                        }
                        ++$summary['repaired_validated'];
                        if (($position+1)%$progressEvery===0) {
                            echo json_encode(['status'=>'progress']+$summary,JSON_UNESCAPED_SLASHES),PHP_EOL;
                            fflush(STDOUT);
                        }
                        continue;
                    }
                    if ($error->reasonCode !== 'source_snapshot_mismatch') { throw $error; }
                    $repairNeeded = true;
                    $reason = 'source_snapshot_mismatch';
                }
            }
            if (!$apply) {
                $checked = (new \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator($db,$config))->validate($fileId);
                if (!empty($checked['ready'])) { ++$summary['validated_existing']; }
                else { ++$summary['not_ready']; }
                continue;
            }
        }
        if (!$apply) { ++$summary['not_ready']; continue; }
        $original = Uedb5MetadataContainer::path((string)$config['storage_path'],$gameId,$fileId);
        $backup = rtrim(sys_get_temp_dir(),'\\/').DIRECTORY_SEPARATOR
            .'uedb5-before-repair-'.$gameId.'-'.$fileId.'-'.getmypid().'.uedb5';
        if (!copy($original, $backup) || !hash_equals(hash_file('sha256',$original),hash_file('sha256',$backup))) {
            throw new RuntimeException('Could not verify original V5 container backup.');
        }
        $verifiedSourceSnapshot = null;
        $source->runFile($gameId,$fileId,true,$verifiedSourceSnapshot);
        // Targeted Pass 2 checks this file and its registered provider identity.
        $dependencies->runFile($gameId,$fileId,true,true);
        if (!is_array($verifiedSourceSnapshot)) {
            throw new RuntimeException('Pass 1 did not return verified source evidence.');
        }
        $checked = $validation->validateFile($slug,$fileId,$verifiedSourceSnapshot);
        unset($verifiedSourceSnapshot);
        if (($checked['status'] ?? '') !== 'validated') {
            throw new RuntimeException('Step 8 did not mark the repaired file validated.');
        }
        ++$summary['repaired_validated'];
        unlink($backup);
        $backup = null;
    } catch (Throwable $error) {
        ++$summary['failed'];
        $code = $error instanceof Uedb5ValidationException ? $error->reasonCode : 'repair_or_validation_exception';
        $issues[] = ['file_id'=>$fileId,'code'=>$code,'error'=>$error->getMessage(),'backup'=>$backup];
        echo json_encode(['status'=>'failed','file_id'=>$fileId,'code'=>$code,
            'error'=>$error->getMessage(),'backup'=>$backup],JSON_UNESCAPED_SLASHES),PHP_EOL;
    }
    if (($position+1)%$progressEvery===0) {
        echo json_encode(['status'=>'progress']+$summary,JSON_UNESCAPED_SLASHES),PHP_EOL;
        fflush(STDOUT);
    }
}
echo json_encode(['status'=>'complete','summary'=>$summary,'issues'=>$issues],
    JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($summary['failed'] === 0 ? 0 : 2);
