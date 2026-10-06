#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;

$o=getopt('',['summary']);
$app=catalog_bootstrap();$db=$app->db;$cfg=catalog_config();
$storage=rtrim((string)($cfg['storage_path']??''),"\\/");
$q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ue_uedb5_files'");
if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Required Step 5 table is missing: ue_uedb5_files\n");exit(2);}
$g=$db->query("SELECT id FROM ue_games WHERE slug='ut3' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($g)){fwrite(STDERR,"UT3 game registration was not found.\n");exit(2);}
$gid=(int)$g['id'];$reader=new Uedb5MetadataReader($storage);
$s=$db->prepare('SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id');$s->execute([$gid]);
$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];

$fname=static function(mixed$v):?int{
 if(!is_array($v))return null;$i=$v['name_index']??$v['index']??null;
 return $i===null||$i===''?null:(int)$i;
};
$flags=static function(mixed$v):int{
 if(is_int($v))return$v;
 $hex=strtoupper(trim((string)$v));if($hex==='')return 0;
 if(preg_match('/^[0-9A-F]{1,16}$/',$hex)!==1)throw new RuntimeException('UT3 name flags are not canonical hexadecimal data.');
 $hex=str_pad($hex,16,'0',STR_PAD_LEFT);
 return ((int)hexdec(substr($hex,0,8))<<32)|(int)hexdec(substr($hex,8,8));
};

$context=[];$truncate=[];$details=[];$examined=0;$eligible=0;
foreach($rows as$reg){
 $fid=(int)$reg['file_id'];
 $summary=$reader->page($gid,$fid,'summary',0,1)[0]??null;
 if(!is_array($summary))throw new RuntimeException("UEDB5 summary is missing for UT3 file #$fid.");
 $version=(int)($summary['package_version']??-1);
 $licensee=(int)($summary['licensee_version']??0);
 $policy=(string)($reg['source_policy']??'');
 if($version!==512||$licensee!==0||strcasecmp($policy,Uedb5Ut3SnapshotBuilder::SOURCE_POLICY)!==0)continue;
 $eligible++;

 $refs=[];
 foreach($reader->scan($gid,$fid,'imports')as$row){
  foreach(['class_package','class_name','object_name']as$field){
   $i=$fname($row[$field]??null);if($i!==null&&$i>=0)$refs[$i]=true;
  }
 }
 foreach($reader->scan($gid,$fid,'exports')as$row){
  $i=$fname($row['object_name']??null);if($i!==null&&$i>=0)$refs[$i]=true;
 }
 $filtered=[];$truncated=[];
 if($refs!==[]){
  $names=$reader->rowsByPositions($gid,$fid,'names',array_keys($refs));$examined+=count($names);
  foreach($names as$pos=>$row){
   $idx=(int)($row['index']??$pos);
   if(($flags($row['flags']??0)&CatalogLegacyNameMapPreprocessor::UE3_ALL_LOAD_CONTEXTS)===0)$filtered[]=$idx;
   if(mb_strlen((string)($row['text']??''),'UTF-8')>127)$truncated[]=$idx;
  }
 }
 if($filtered!==[])$context[]=$fid;
 if($truncated!==[])$truncate[]=$fid;
 if($filtered!==[]||$truncated!==[]){
  $details[(string)$fid]=[
   'package_version'=>$version,'licensee_version'=>$licensee,
   'context_filtered_name_indexes'=>$filtered,
   'runtime_truncated_name_indexes'=>$truncated,
  ];
 }
}
foreach(['context','truncate']as$n){$$n=array_values(array_unique($$n));sort($$n,SORT_NUMERIC);}
$impacted=array_values(array_unique(array_merge($context,$truncate)));sort($impacted,SORT_NUMERIC);
$out=[
 'ok'=>true,'read_only'=>true,'game_id'=>$gid,'staged_file_count'=>count($rows),
 'eligible_v512_file_count'=>$eligible,'referenced_name_rows_examined'=>$examined,
 'context_filter_file_count'=>count($context),'runtime_name_truncation_file_count'=>count($truncate),
 'impacted_file_count'=>count($impacted),'requires_original_package_read'=>false,
];
if(!isset($o['summary']))$out+=['context_filter_file_ids'=>$context,'runtime_name_truncation_file_ids'=>$truncate,'impacted_file_ids'=>$impacted,'details'=>$details];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
