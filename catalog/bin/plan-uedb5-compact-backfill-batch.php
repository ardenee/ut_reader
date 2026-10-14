#!/usr/bin/env php
<?php
/**
 * Read-only, cursor-based compact V5 backfill batch planner.
 * No OFFSET pagination, large table scans or permanent SQL writes.
 * Future migration executor can consume file_ids + next_after_id.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(2);}
require_once dirname(__DIR__).'/bootstrap.php';
$opt=getopt('', ['game-id:','after-id::','limit::']);
$game=(int)($opt['game-id']??0);
$after=(int)($opt['after-id']??0);
$limit=max(1,min(100,(int)($opt['limit']??25)));
if(!in_array($game,[2,3,4,5,6,7,12],true)||$after<0){
 fwrite(STDERR,"Usage: --game-id=2|3|4|5|6|7|12 [--after-id=0] [--limit=25] (max 100)\n");
 exit(2);
}
$report=['ok'=>false,'read_only'=>true,'game_id'=>$game,
 'requested_after_id'=>$after,'limit'=>$limit];
try{
 $db=catalog_bootstrap(false)->db;
 $stmt=$db->prepare('SELECT file_id FROM ue_uedb5_files
   WHERE game_id=? AND file_id>? ORDER BY file_id LIMIT '.($limit+1));
 $stmt->execute([$game,$after]);
 $all=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
 $hasMore=count($all)>$limit;
 $ids=array_slice($all,0,$limit);
 $names=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_name_candidates WHERE file_id=?');
 $objects=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_object_candidates WHERE file_id=?');
 $rows=[];$nameTotal=0;$objectTotal=0;
 foreach($ids as $id){
  $names->execute([$id]);$nameCount=(int)$names->fetchColumn();
  $objects->execute([$id]);$objectCount=(int)$objects->fetchColumn();
  $nameTotal+=$nameCount;$objectTotal+=$objectCount;
  $rows[]=['file_id'=>$id,'name_candidates'=>$nameCount,'object_candidates'=>$objectCount];
 }
 $report+=['files'=>$rows,'sampled_file_count'=>count($rows),
   'name_candidate_count'=>$nameTotal,'object_candidate_count'=>$objectTotal,
   'next_after_id'=>$ids ? end($ids) : $after,'has_more'=>$hasMore,
   'cursor_policy'=>'Resume from next_after_id for same game. Re-read source snapshots at execution time.',
   'caution'=>'Planner only. Does not create compact tables, publish, migrate, or modify the live catalogue.'];
 $report['ok']=true;
}catch(Throwable $e){$report['error']=$e->getMessage();}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($report['ok']?0:2);
