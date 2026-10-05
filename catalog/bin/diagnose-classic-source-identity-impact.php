#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);} $root=realpath(dirname(__DIR__))?:dirname(__DIR__);require_once $root.'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassicSourceIdentityImpactQuery;
$o=getopt('',['game-id::','summary']);$gid=max(0,(int)($o['game-id']??0));$app=catalog_bootstrap();$q=new PdoClassicSourceIdentityImpactQuery($app->db);
$current=$q->currentOldPolicyFiles($gid);$r=$q->run($gid,array_map(static fn(array$x):int=>(int)$x['file_id'],$current));
$base=['ok'=>true,'read_only'=>true,'game_id'=>$gid?:null,'old_policy_candidate_count'=>count($current),'impacted_consumer_count'=>$r['total'],'reason_counts'=>$r['reason_counts'],'counts_by_game'=>array_map('count',$r['file_ids_by_game'])];
if(isset($o['summary'])){echo json_encode($base,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit(0);} $base['impacted_consumer_file_ids_by_game']=$r['file_ids_by_game'];echo json_encode($base,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
