<?php
/**
 * Genuine MySQL transaction tests with SESSION-LOCAL TEMPORARY TABLES only.
 * Shadow the production queue names for this one connection. No genuine
 * queue rows are selected or written; temp tables disappear on disconnect.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';
require_once dirname(__DIR__, 2) . '/catalog/lib/FederationAuth.php';
require_once dirname(__DIR__, 2) . '/catalog/lib/FederationBaseGamePolicy.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferService;
function expect(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$config=catalog_config();
$db=catalog_db($config);
try {
    $db->exec('CREATE TEMPORARY TABLE ue_external_mirror_jobs('
        .'id INT PRIMARY KEY, status VARCHAR(36) NOT NULL, '
        .'finished_at DATETIME NULL,last_error VARCHAR(255) NULL) ENGINE=InnoDB');
    $db->exec('INSERT INTO ue_external_mirror_jobs VALUES '
        .'(900001,"queued",NULL,NULL),(900002,"waiting_admin",NULL,NULL),'
        .'(900003,"uploading",NULL,NULL),(900004,"cancelled",NOW(),"Existing history")');
    $mir=new CatalogExternalMirrorAdminService($db,$config);
    try {$mir->cancelPendingBulk(999);throw new RuntimeException('Unsafe stale count accepted');}
    catch (RuntimeException $e) {
        expect(str_contains($e->getMessage(),'queue changed'),'Unexpected mirror count guard');
    }
    $unchanged=$db->query('SELECT COUNT(*) FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin")')->fetchColumn();
    expect((int)$unchanged===2,'Mirror failed confirmation changed queue');
    $count=$mir->cancelPendingBulk(2);
    expect($count===2,'Mirror source bulk cancellation counted incorrectly');
    $result=$db->query('SELECT id,status,finished_at,last_error FROM ue_external_mirror_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    expect($result[0]['status']==='cancelled' && $result[1]['status']==='cancelled','Mirror pending were not cancelled');
    expect($result[2]['status']==='uploading','Mirror in-flight upload was cancelled');
    expect($result[3]['status']==='cancelled','Mirror historical cancelled job changed');
    expect($result[0]['finished_at']!==null && str_contains((string)$result[0]['last_error'],'dashboard bulk'),
        'Mirror audit/status details missing');
    $db->exec('INSERT INTO ue_external_mirror_jobs VALUES (900005,"queued",NULL,NULL)');
    $single=$mir->handleQueueAction('cancel',900005);
    expect(str_contains($single,'cancelled'),'Mirror single cancel action unavailable');
    expect((string)$db->query('SELECT status FROM ue_external_mirror_jobs WHERE id=900005')->fetchColumn()==='cancelled',
        'Mirror single cancel failed');

    $db->exec('CREATE TEMPORARY TABLE ue_federation_transfer_jobs('
        .'id INT PRIMARY KEY,peer_id INT NOT NULL,status VARCHAR(36) NOT NULL,'
        .'remote_file_id VARCHAR(100) NULL,remote_request_item_id INT NULL,local_file_id INT NULL,'
        .'finished_at DATETIME NULL,last_error VARCHAR(255) NULL) ENGINE=InnoDB');
    $db->exec('CREATE TEMPORARY TABLE ue_federation_transfer_logs('
        .'peer_id INT NULL,transfer_job_id INT NULL,level VARCHAR(30),'
        .'event VARCHAR(60),details VARCHAR(255)) ENGINE=InnoDB');
    // IDs and file identities deliberately do not overlap existing data,
    // and all SELECT/UPDATE/INSERT use the session-local temporary tables.
    $db->exec('INSERT INTO ue_federation_transfer_jobs VALUES '
        .'(900101,999999,"queued","fixture-one",NULL,NULL,NULL,NULL),'
        .'(900102,999999,"queued","fixture-two",NULL,NULL,NULL,NULL),'
        .'(900103,999999,"queued","fixture-three",NULL,NULL,NULL,NULL),'
        .'(900104,999999,"running","fixture-running",NULL,NULL,NULL,NULL),'
        .'(900105,999999,"downloaded","fixture-download",NULL,NULL,NULL,NULL)');
    $fed=new CatalogFederationTransferService($db);
    try {$fed->cancelQueuedBulk(999);throw new RuntimeException('Stale federation expected count accepted');}
    catch(RuntimeException $e) {
        expect(str_contains($e->getMessage(),'queue changed'),'Unexpected federation bulk error: '.$e->getMessage());
    }
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_jobs WHERE status="queued"')->fetchColumn()===3,
        'Failed federation confirmation changed queue');
    $count=$fed->cancelQueuedBulk(3);
    expect($count===3,'Federation bulk cancellation count wrong');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_jobs WHERE status="cancelled"')->fetchColumn()===3,
        'Federation queued source records not cancelled');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_jobs WHERE status IN ("running","downloaded")')->fetchColumn()===2,
        'Federation in-flight/downloaded records were altered');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_logs WHERE event="JOB_CANCEL"')->fetchColumn()===3,
        'Missing per-job federation audit logs');
    $db->exec('INSERT INTO ue_federation_transfer_jobs VALUES '
        .'(900106,999999,"queued","fixture-single",NULL,NULL,NULL,NULL)');
    $out=$fed->handle(900106,'cancel','active');
    expect(str_contains((string)$out['flash'],'cancelled'),'Federation single cancel unavailable');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_logs WHERE event="JOB_CANCEL"')->fetchColumn()===4,
        'Federation single cancel audit missing');

    // Purge operates on terminal jobs only. It must never remove in-flight
    // mirror uploads or running/downloaded federation jobs.
    try {$mir->purgeCancelledBulk(100);throw new RuntimeException('Stale mirror purge accepted');}
    catch (RuntimeException $e) {
        expect(str_contains($e->getMessage(),'changed'),'Mirror stale purge was not rejected');
    }
    expect($mir->purgeCancelledBulk(4)===4,'Mirror terminal bulk purge count incorrect');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_external_mirror_jobs')->fetchColumn()===1,
        'Mirror purge removed an uploading job or left cancelled jobs behind');
    expect((string)$db->query('SELECT status FROM ue_external_mirror_jobs')->fetchColumn()==='uploading',
        'Mirror purge changed in-flight upload status');
    $db->exec('INSERT INTO ue_external_mirror_jobs VALUES (900006,"cancelled",NOW(),"Fixture")');
    $purgedOne=$mir->handleQueueAction('delete_cancelled',900006);
    expect(str_contains($purgedOne,'Permanently removed'),'Mirror single terminal delete missing');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_external_mirror_jobs')->fetchColumn()===1,
        'Mirror single terminal delete failed');

    try {$fed->purgeCancelledBulk(100);throw new RuntimeException('Stale federation purge accepted');}
    catch (RuntimeException $e) {
        expect(str_contains($e->getMessage(),'changed'),'Federation stale purge not rejected');
    }
    expect($fed->purgeCancelledBulk(4)===4,'Federation terminal bulk purge count incorrect');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_jobs')->fetchColumn()===2,
        'Federation purge removed an in-flight/downloaded transfer');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_logs WHERE event="JOB_CANCEL"')->fetchColumn()===4,
        'Federation purge removed historical audit events');
    $db->exec('INSERT INTO ue_federation_transfer_jobs VALUES '
        .'(900107,999999,"cancelled","fixture-deleted",NULL,NULL,NOW(),"Done")');
    $out=$fed->handle(900107,'delete_cancelled','cancelled');
    expect(str_contains($out['flash'],'Removed cancelled federation'),
        'Federation single terminal delete not available');
    expect((int)$db->query('SELECT COUNT(*) FROM ue_federation_transfer_jobs')->fetchColumn()===2,
        'Federation single terminal delete failed');

    echo json_encode([
      'pass'=>true,
      'mysql_real_transaction_logic'=>true,
      'only_session_temporary_tables'=>true,
      'original_mirror_jobs_changed'=>0,
      'original_federation_jobs_changed'=>0,
      'temp_mirror_bulk_cancelled'=>2,
      'temp_mirror_uploading_preserved'=>true,
      'temp_federation_bulk_cancelled'=>3,
      'temp_federation_running_downloaded_preserved'=>true,
      'temp_federation_logs'=>4,
      'single_cancel_both_types'=>true,
      'stale_confirmations_rejected'=>true,
      'mirror_cancelled_bulk_purged'=>4,
      'mirror_cancelled_single_purged'=>1,
      'mirror_active_upload_preserved_on_purge'=>true,
      'federation_cancelled_bulk_purged'=>4,
      'federation_cancelled_single_purged'=>1,
      'federation_running_and_downloaded_preserved_on_purge'=>true,
      'federation_audit_log_rows_retained_after_purge'=>4,
    ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $error) {
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,'ISOLATED_CANCEL_TEST_FAILED '.get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
