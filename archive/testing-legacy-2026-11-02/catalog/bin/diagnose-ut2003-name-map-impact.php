#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut2003SnapshotBuilder;

$o=getopt('',['summary']);
$app=catalog_bootstrap();$db=$app->db;$config=catalog_config();
$storage=rtrim((string)($config['storage_path']??''),"\\/");
$q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ue_uedb5_files'");
if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Required Step 5 table is missing: ue_uedb5_files\n");exit(2);}
$game=$db->query("SELECT id FROM ue_games WHERE slug='ut2003' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($game)){fwrite(STDERR,"UT2003 game registration was not found.\n");exit(2);}
$gid=(int)$game['id'];$reader=new Uedb5MetadataReader($storage);
$s=$db->prepare('SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id');$s->execute([$gid]);
$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];

$fname=static function(mixed$v):?int{
 if(!is_array($v))return null;$i=$v['name_index']??$v['index']??null;
 return $i===null||$i===''?null:(int)$i;
};
$flags=static function(mixed$v):int{
 if(is_int($v))return $v;$t=trim((string)$v);if($t==='')return 0;
 return preg_match('/^[0-9A-Fa-f]+$/',$t)===1?(int)hexdec($t):(int)$t;
};
$expected=static fn(int$v):string=>$v<=120
 ? Uedb5Ut2003SnapshotBuilder::SOURCE_POLICY
 : Uedb5Ut2003SnapshotBuilder::POLICY_POST_V120_UNRESOLVED;

$context=[];$policy=[];$reparse=[];$details=[];$examined=0;
foreach($rows as$reg){
 $fid=(int)$reg['file_id'];
 $summary=$reader->page($gid,$fid,'summary',0,1)[0]??null;
 if(!is_array($summary))throw new RuntimeException("UEDB5 summary is missing for UT2003 file #$fid.");
 $ver=(int)($summary['package_version']??-1);
 $want=$expected($ver);$have=(string)($reg['source_policy']??'');
 $policyChanged=$have!==$want;if($policyChanged)$policy[]=$fid;

 $refs=[];$negative=false;
 foreach($reader->scan($gid,$fid,'imports')as$r){
  foreach(['class_package','class_name','object_name']as$f){
   $i=$fname($r[$f]??null);if($i!==null&&$i>=0)$refs[$i]=true;
  }
 }
 foreach($reader->scan($gid,$fid,'exports')as$r){
  $i=$fname($r['object_name']??null);if($i!==null&&$i>=0)$refs[$i]=true;
  if((int)($r['serial_size']??0)<0)$negative=true;
 }
 if($negative)$reparse[]=$fid;

 $filtered=[];
 if($ver>=60&&$ver<=120&&$refs!==[]){
  $names=$reader->rowsByPositions($gid,$fid,'names',array_keys($refs));$examined+=count($names);
  foreach($names as$p=>$n){
   if(($flags($n['flags']??0)&CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS)===0){
    $filtered[]=(int)($n['index']??$p);
   }
  }
 }
 if($filtered!==[])$context[]=$fid;
 if($filtered!==[]||$policyChanged||$negative){
  $details[(string)$fid]=[
   'package_version'=>$ver,
   'context_filtered_name_indexes'=>$filtered,
   'negative_serial_size_requires_pass1_reparse'=>$negative,
   'source_policy_current'=>$have,
   'source_policy_expected'=>$want,
  ];
 }
}
foreach(['context','policy','reparse']as$v){$$v=array_values(array_unique($$v));sort($$v,SORT_NUMERIC);}
$impacted=array_values(array_unique(array_merge($context,$policy,$reparse)));sort($impacted,SORT_NUMERIC);
$result=[
 'ok'=>true,'read_only'=>true,'game_id'=>$gid,'staged_file_count'=>count($rows),
 'referenced_name_rows_examined'=>$examined,
 'context_filter_file_count'=>count($context),
 'source_policy_refresh_file_count'=>count($policy),
 'serial_offset_pass1_reparse_file_count'=>count($reparse),
 'impacted_file_count'=>count($impacted),
 'requires_original_package_read'=>$reparse!==[],
];
if(!isset($o['summary'])){
 $result['context_filter_file_ids']=$context;
 $result['source_policy_refresh_file_ids']=$policy;
 $result['serial_offset_pass1_reparse_file_ids']=$reparse;
 $result['impacted_file_ids']=$impacted;
 $result['details']=$details;
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
