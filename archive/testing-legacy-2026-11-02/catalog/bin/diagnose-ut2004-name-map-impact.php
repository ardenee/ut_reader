#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);require_once $root.'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2004SnapshotBuilder;

$o=getopt('',['summary']);$app=catalog_bootstrap();$db=$app->db;$cfg=catalog_config();$storage=rtrim((string)($cfg['storage_path']??''),"\\/");
$q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ue_uedb5_files'");
if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Required Step 5 table is missing: ue_uedb5_files\n");exit(2);}
$g=$db->query("SELECT id FROM ue_games WHERE slug='ut2004' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($g)){fwrite(STDERR,"UT2004 game registration was not found.\n");exit(2);}
$gid=(int)$g['id'];$r=new Uedb5MetadataReader($storage);
$s=$db->prepare('SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id');$s->execute([$gid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
$fi=static function(mixed$v):?int{if(!is_array($v))return null;$i=$v['name_index']??$v['index']??null;return $i===null||$i===''?null:(int)$i;};
$fl=static function(mixed$v):int{if(is_int($v))return$v;$t=trim((string)$v);if($t==='')return 0;return preg_match('/^[0-9A-Fa-f]+$/',$t)?(int)hexdec($t):(int)$t;};
$policy=static fn(int$v):string=>$v>129?Uedb5Ut2004SnapshotBuilder::POLICY_POST_V129_UNRESOLVED:($v===129?Uedb5Ut2004SnapshotBuilder::POLICY_V129:Uedb5Ut2004SnapshotBuilder::POLICY_V128);
$context=[];$truncate=[];$policyIds=[];$reparse=[];$details=[];$examined=0;
foreach($rows as$reg){
 $fid=(int)$reg['file_id'];$sum=$r->page($gid,$fid,'summary',0,1)[0]??null;if(!is_array($sum))throw new RuntimeException("Missing summary for #$fid");
 $v=(int)($sum['package_version']??-1);$want=$policy($v);$have=(string)$reg['source_policy'];$pc=$want!==$have;if($pc)$policyIds[]=$fid;
 $refs=[];$neg=false;
 foreach($r->scan($gid,$fid,'imports')as$row)foreach(['class_package','class_name','object_name']as$f){$i=$fi($row[$f]??null);if($i!==null&&$i>=0)$refs[$i]=true;}
 foreach($r->scan($gid,$fid,'exports')as$row){$i=$fi($row['object_name']??null);if($i!==null&&$i>=0)$refs[$i]=true;if((int)($row['serial_size']??0)<0)$neg=true;}
 if($neg)$reparse[]=$fid;$filtered=[];$truncated=[];
 if($v>=60&&$v<=129&&$refs!==[]){
  $names=$r->rowsByPositions($gid,$fid,'names',array_keys($refs));$examined+=count($names);
  foreach($names as$p=>$n){$idx=(int)($n['index']??$p);if(($fl($n['flags']??0)&CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS)===0)$filtered[]=$idx;if($v>=64&&mb_strlen((string)($n['text']??''),'UTF-8')>63)$truncated[]=$idx;}
 }
 if($filtered!==[])$context[]=$fid;if($truncated!==[])$truncate[]=$fid;
 if($filtered!==[]||$truncated!==[]||$pc||$neg)$details[(string)$fid]=['package_version'=>$v,'context_filtered_name_indexes'=>$filtered,'runtime_truncated_name_indexes'=>$truncated,'negative_serial_size_requires_pass1_reparse'=>$neg,'source_policy_current'=>$have,'source_policy_expected'=>$want];
}
foreach(['context','truncate','policyIds','reparse']as$n){$$n=array_values(array_unique($$n));sort($$n,SORT_NUMERIC);}
$impacted=array_values(array_unique(array_merge($context,$truncate,$policyIds,$reparse)));sort($impacted,SORT_NUMERIC);
$out=['ok'=>true,'read_only'=>true,'game_id'=>$gid,'staged_file_count'=>count($rows),'referenced_name_rows_examined'=>$examined,'context_filter_file_count'=>count($context),'runtime_name_truncation_file_count'=>count($truncate),'source_policy_refresh_file_count'=>count($policyIds),'serial_offset_pass1_reparse_file_count'=>count($reparse),'impacted_file_count'=>count($impacted),'requires_original_package_read'=>$reparse!==[]];
if(!isset($o['summary']))$out+=['context_filter_file_ids'=>$context,'runtime_name_truncation_file_ids'=>$truncate,'source_policy_refresh_file_ids'=>$policyIds,'serial_offset_pass1_reparse_file_ids'=>$reparse,'impacted_file_ids'=>$impacted,'details'=>$details];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
