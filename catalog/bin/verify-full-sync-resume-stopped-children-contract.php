#!/usr/bin/env php
<?php
/** Verifies Full Sync resumes only children cancelled by its earlier Stop action. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogFullSyncJobHandler;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required.\n");
    exit(2);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_background_jobs('
    . 'id INTEGER PRIMARY KEY,parent_job_id INTEGER,workflow_unit_key TEXT,status TEXT,attempts INTEGER,'
    . 'available_at TEXT,worker_id TEXT,lease_token TEXT,leased_at TEXT,lease_expires_at TEXT,'
    . 'last_heartbeat_at TEXT,last_error TEXT,result_json TEXT,cancel_requested_at TEXT,'
    . 'cancel_requested_by INTEGER,cancel_reason TEXT,dead_lettered_at TEXT,completed_at TEXT,updated_at TEXT)');
$insert = $db->prepare('INSERT INTO ue_background_jobs(id,parent_job_id,workflow_unit_key,status,attempts,cancel_reason) VALUES(?,?,?,?,?,?)');
$insert->execute([1,100,'reimport:10','cancelled',1,'Stopped from Background Jobs.']);
$insert->execute([2,100,'reimport:11','cancelled',1,'Cancelled by administrator.']);
$insert->execute([3,100,'dependency:12','cancelled',1,'Stopped from Background Jobs.']);
$insert->execute([4,100,'reimport:13','failed',1,'Stopped from Background Jobs.']);
$handler = new CatalogFullSyncJobHandler($db, []);
$method = new ReflectionMethod($handler, 'resumeStoppedChildren');
$resumed = (int)$method->invoke($handler, 100, 'reimport:');
$rows = $db->query('SELECT id,status,cancel_reason FROM ue_background_jobs ORDER BY id')->fetchAll();
$byId = [];
foreach ($rows as $row) {
    $byId[(int)$row['id']] = $row;
}
$checks = [
    ['check'=>'exact_stop_child_requeued','ok'=>$resumed===1 && ($byId[1]['status']??'')==='queued'],
    ['check'=>'manual_cancel_preserved','ok'=>($byId[2]['status']??'')==='cancelled'],
    ['check'=>'other_phase_cancel_preserved','ok'=>($byId[3]['status']??'')==='cancelled'],
    ['check'=>'failed_child_preserved','ok'=>($byId[4]['status']??'')==='failed'],
    ['check'=>'requeued_cancel_reason_cleared','ok'=>($byId[1]['cancel_reason']??null)===null],
];
$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok'=>$ok,'resumed'=>$resumed,'checks'=>$checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 3);
