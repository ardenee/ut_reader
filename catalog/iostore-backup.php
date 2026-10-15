<?php
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;

catalog_start_session();
try{
 $config=catalog_config();$db=catalog_db($config);
 if(!catalog_require_admin_page('IoStore container backups'))exit;
 $installed=CatalogIoStoreContainerIndexer::schemaInstalled($db);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  catalog_check_csrf('iostore-backup');
  if(!$installed)throw new RuntimeException('IoStore schema migration must be installed first.');
  $operation=trim((string)($_POST['operation']??''));
  $gameId=filter_input(INPUT_POST,'game_id',FILTER_VALIDATE_INT);
  if(!is_int($gameId)||$gameId<1)throw new RuntimeException('Select a valid UE5 game.');
  $profile=$db->prepare('SELECT id FROM ue_games WHERE id=? AND profile_id IN (SELECT id FROM ue_game_profiles WHERE is_active=1 AND UPPER(engine_key) LIKE "UE5%")');
  $profile->execute([$gameId]);
  if($profile->fetchColumn()===false)throw new RuntimeException('Target game must have an active UE5 profile.');
  $payload=[];$type='';
  if($operation==='backup'){
   $containerId=filter_input(INPUT_POST,'container_id',FILTER_VALIDATE_INT);
   if(!is_int($containerId)||$containerId<1)throw new RuntimeException('Select a retained container.');
   $q=$db->prepare('SELECT id FROM ue_iostore_containers WHERE id=? AND game_id=? AND status="ready"');
   $q->execute([$containerId,$gameId]);
   if($q->fetchColumn()===false)throw new RuntimeException('Only a ready container of the selected game can be backed up.');
   $key='iostore-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5));
   $payload=['container_id'=>$containerId,'backup_key'=>$key];
   $type=JobType::BACKUP_IOSTORE_CONTAINER;
  }elseif($operation==='restore'){
   $key=trim((string)($_POST['backup_key']??''));
   $sha=strtolower(trim((string)($_POST['utoc_sha256']??'')));
   if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D',$key)!==1
     ||preg_match('/^[a-f0-9]{64}$/D',$sha)!==1)throw new RuntimeException('Enter the backup key and its 64-character TOC SHA-256.');
   $payload=['game_id'=>$gameId,'backup_key'=>$key,'utoc_sha256'=>$sha];
   $type=JobType::RESTORE_IOSTORE_CONTAINER;
  }else throw new RuntimeException('Invalid IoStore backup operation.');
  $queue=trim((string)($config['queue']['name']??'catalog'))?:'catalog';
  $userId=(int)($_SESSION['user']['id']??0)?:null;
  $jobId=(new PdoJobQueue($db))->enqueue($queue,$type,$payload,100,null,null,$userId,3);
  (new CatalogQueueWorkerStarter($db,$config))->start($queue,true,$userId);
  $_SESSION['iostore_backup_flash']='Queued IoStore '.($operation==='backup'?'backup':'restore').' job #'.$jobId
    .($operation==='backup'?' (backup key '.$key.')':'');
  header('Location: iostore-backup.php?game_id='.$gameId.'&job_id='.$jobId);exit;
 }
 $games=catalog_all($db,'SELECT g.id,g.name FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE UPPER(p.engine_key) LIKE "UE5%" ORDER BY g.name',[]);
 $selected=max(0,(int)($_GET['game_id']??0));
 catalog_head('Package Viewer: IoStore backups');
 catalog_flash($_SESSION['iostore_backup_flash']??null);unset($_SESSION['iostore_backup_flash']);
 catalog_page_header('IoStore container backups',
    'Preserve entire .utoc/.ucas sets, their partition SHA-256 manifest and internal package indexes through durable jobs.',
    ['Package Viewer'=>'paks.php','Background Jobs'=>'background-jobs.php']);
 if(!$installed)echo CatalogUi::alert('warning','IoStore migration not installed. Backup and restore are disabled.');
 elseif($games===[])echo CatalogUi::alert('warning','No active UE5 game profiles are available.');
 else{
  $containers=catalog_all($db,'SELECT c.id,c.name,c.game_id FROM ue_iostore_containers c WHERE c.status="ready" ORDER BY c.game_id,c.id',[]);
  echo '<section class="ui-section"><div class="ui-section__header"><h2>Export retained IoStore container</h2></div><div class="ui-section__body">';
  echo '<form method="post"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('iostore-backup')).'"><input type="hidden" name="operation" value="backup">';
  echo '<label>Game <select name="game_id">';
  foreach($games as $g)echo '<option value="'.(int)$g['id'].'"'.((int)$g['id']===$selected?' selected':'').'>'.catalog_h((string)$g['name']).'</option>';
  echo '</select></label> <label>Container <select name="container_id">';
  foreach($containers as $c)echo '<option value="'.(int)$c['id'].'">'.catalog_h((string)$c['name']).' (#'.(int)$c['id'].', game '.(int)$c['game_id'].')</option>';
  echo '</select></label> <button type="submit">Queue container backup</button></form></div></section>';
  echo '<section class="ui-section"><div class="ui-section__header"><h2>Restore verified IoStore backup</h2></div><div class="ui-section__body">';
  echo '<form method="post"><input type="hidden" name="csrf" value="'.catalog_h(catalog_csrf('iostore-backup')).'"><input type="hidden" name="operation" value="restore">';
  echo '<label>Target UE5 game <select name="game_id">';
  foreach($games as $g)echo '<option value="'.(int)$g['id'].'"'.((int)$g['id']===$selected?' selected':'').'>'.catalog_h((string)$g['name']).'</option>';
  echo '</select></label>';
  echo '<p><label>Backup key <input name="backup_key" required maxlength="120"></label></p>';
  echo '<p><label>TOC SHA-256 <input name="utoc_sha256" required maxlength="64" minlength="64" size="67"></label></p>';
  echo '<p>Restore verifies every backed-up partition and indexes contained packages. Publishing V5 members and rebuilding mounted dependencies are separate jobs.</p>';
  echo '<button type="submit">Queue verified restore</button></form></div></section>';
 }
 catalog_foot();
}catch(Throwable $error){
 if(!headers_sent())catalog_head('IoStore backup error');
 echo CatalogUi::alert('danger',catalog_exception_display_message($error));
 catalog_foot();
}
