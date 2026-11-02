#!/usr/bin/env php
<?php
/** Durable progress contract regression using one session TEMPORARY ledger.
 * The permanent ledger is not created or modified.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/bootstrap.php';
$db=catalog_bootstrap(false)->db;
$table='u5_compact_progress_probe';
$checks=0;$errors=[];
try{
 $db->exec('CREATE TEMPORARY TABLE '.$table.' (
 game_id INT UNSIGNED NOT NULL,
 last_verified_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 verified_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 verified_name_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 verified_object_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY(game_id)) ENGINE=InnoDB');
 $db->exec('INSERT INTO '.$table.' VALUES(7,0,0,0,0,NOW(6))');
 $read=$db->prepare('SELECT last_verified_file_id,verified_file_count,verified_name_count,verified_object_count FROM '.$table.' WHERE game_id=?');
 $advance=$db->prepare('UPDATE '.$table.' SET last_verified_file_id=?,verified_file_count=verified_file_count+1,verified_name_count=verified_name_count+?,verified_object_count=verified_object_count+?,updated_at=NOW(6) WHERE game_id=? AND last_verified_file_id<?');
 $db->beginTransaction();
 $advance->execute([113526,18,1,7,113526]);
 if($advance->rowCount()!==1)throw new RuntimeException('First cursor advance failed');
 $db->commit();
 $read->execute([7]);$one=$read->fetch(PDO::FETCH_NUM);
 if(array_map('intval',$one)!==[113526,1,18,1])throw new RuntimeException('Committed cursor mismatch');
 $checks++;
 $db->beginTransaction();
 $advance->execute([113526,18,1,7,113526]);
 if($advance->rowCount()!==0)throw new RuntimeException('Replay incorrectly advanced the cursor');
 $db->commit();
 $checks++;
 $db->beginTransaction();
 $advance->execute([113527,15,1,7,113527]);
 if($advance->rowCount()!==1)throw new RuntimeException('Second cursor advance failed');
 $db->rollBack();
 $read->execute([7]);$after=$read->fetch(PDO::FETCH_NUM);
 if(array_map('intval',$after)!==[113526,1,18,1])throw new RuntimeException('Rolled back checkpoint became visible');
 $checks++;
 $db->beginTransaction();
 $advance->execute([113525,1,1,7,113525]);
 if($advance->rowCount()!==0)throw new RuntimeException('Cursor regressed');
 $db->commit();
 $checks++;
}catch(Throwable $e){$errors[]=$e->getMessage();}
finally{
 if($db->inTransaction())$db->rollBack();
 try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}catch(Throwable $e){$errors[]=$e->getMessage();}
}
echo json_encode(['ok'=>$errors===[]&&$checks===4,'temporary_tables_only'=>true,
 'progress_contract_checks'=>$checks,'failures'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
exit($errors===[]&&$checks===4?0:2);
