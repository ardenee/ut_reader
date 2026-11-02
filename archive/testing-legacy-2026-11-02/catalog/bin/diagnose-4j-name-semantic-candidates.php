#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut3SnapshotBuilder;

$o=getopt('',['game:','ids-file:','summary']);
$slug=strtolower(trim((string)($o['game']??'')));
$idsFile=trim((string)($o['ids-file']??''));
if(!in_array($slug,['ut99','ut2004','ut3'],true)||$idsFile===''){
 fwrite(STDERR,"Usage: php catalog/bin/diagnose-4j-name-semantic-candidates.php --game=ut99|ut2004|ut3 --ids-file=PATH [--summary]\n");exit(1);
}
$app=catalog_bootstrap();$db=$app->db;$cfg=catalog_config();$storage=rtrim((string)($cfg['storage_path']??''),"\\/");
$g=$db->prepare('SELECT id FROM ue_games WHERE slug=? LIMIT 1');$g->execute([$slug]);$gid=(int)($g->fetchColumn()?:0);
if($gid<1){throw new RuntimeException("Game not found: $slug");}
$r=new Uedb5MetadataReader($storage);
$s=$db->prepare('SELECT file_id,source_policy FROM ue_uedb5_files WHERE game_id=? ORDER BY file_id');$s->execute([$gid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
$fnameIndex=static function(mixed$v):?int{
 if(!is_array($v))return null;
 $i=$v['name_index']??$v['index']??null;
 return $i===null||$i===''?null:(int)$i;
};
$flags32=static function(mixed$v):int{
 if(is_int($v))return$v;
 $t=trim((string)$v);
 if($t==='')return 0;
 return preg_match('/^[0-9A-Fa-f]+$/',$t)?(int)hexdec($t):(int)$t;
};
$flags64=static function(mixed$v):int{
 if(is_int($v))return$v;
 $hex=strtoupper(trim((string)$v));
 if($hex==='')return 0;
 if(preg_match('/^[0-9A-F]{1,16}$/',$hex)!==1)throw new RuntimeException('Name flags are not canonical hexadecimal data.');
 $hex=str_pad($hex,16,'0',STR_PAD_LEFT);
 return ((int)hexdec(substr($hex,0,8))<<32)|(int)hexdec(substr($hex,8,8));
};
$candidates=[];$namesExamined=0;$referencedNamesExamined=0;$eligible=0;$context=0;$truncate=0;
foreach($rows as$reg){
 $fid=(int)$reg['file_id'];$sum=$r->page($gid,$fid,'summary',0,1)[0]??null;
 if(!is_array($sum))throw new RuntimeException("Missing UEDB5 summary for file #$fid.");
 $v=(int)($sum['package_version']??-1);$lic=(int)($sum['licensee_version']??0);
 if($slug==='ut2004'&&($v<60||$v>129))continue;
 if($slug==='ut3'&&($v!==512||$lic!==0||strcasecmp((string)$reg['source_policy'],Uedb5Ut3SnapshotBuilder::SOURCE_POLICY)!==0))continue;
 $eligible++;
 $refs=[];
 foreach($r->scan($gid,$fid,'imports')as$row){
  $fields=$slug==='ut99'?['class_package','class_name','object_name','object_package']:['class_package','class_name','object_name'];
  foreach($fields as$field){
   $i=$fnameIndex($row[$field]??null);
   if($i!==null&&$i>=0)$refs[$i]=true;
  }
 }
 foreach($r->scan($gid,$fid,'exports')as$row){
  $i=$fnameIndex($row['object_name']??null);
  if($i!==null&&$i>=0)$refs[$i]=true;
 }
 if($refs===[])continue;
 $hasContext=false;$hasTruncate=false;
 foreach($r->scan($gid,$fid,'names')as$pos=>$n){
  $namesExamined++;
  $idx=(int)($n['index']??$pos);
  if(!isset($refs[$idx]))continue;
  $referencedNamesExamined++;
  $text=(string)($n['text']??'');
  if($slug==='ut3'){
   if(($flags64($n['flags']??0)&CatalogLegacyNameMapPreprocessor::UE3_ALL_LOAD_CONTEXTS)===0)$hasContext=true;
   if(mb_strlen($text,'UTF-8')>127)$hasTruncate=true;
  }else{
   if(($flags32($n['flags']??0)&CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS)===0)$hasContext=true;
   if($slug==='ut2004'&&$v>=64&&mb_strlen($text,'UTF-8')>63)$hasTruncate=true;
  }
  if($hasContext&&($slug==='ut99'||$hasTruncate))break;
 }
 if($hasContext)$context++;
 if($hasTruncate)$truncate++;
 if($hasContext||$hasTruncate)$candidates[]=$fid;
}
$candidates=array_values(array_unique($candidates));sort($candidates,SORT_NUMERIC);
if(file_put_contents($idsFile,$candidates===[]?'':implode(PHP_EOL,$candidates).PHP_EOL)===false)throw new RuntimeException("Could not write IDs file: $idsFile");
$out=['ok'=>true,'read_only'=>true,'game'=>$slug,'game_id'=>$gid,'staged_file_count'=>count($rows),'eligible_file_count'=>$eligible,'name_rows_streamed'=>$namesExamined,'referenced_name_rows_examined'=>$referencedNamesExamined,'context_filter_file_count'=>$context,'runtime_name_truncation_file_count'=>$truncate,'candidate_file_count'=>count($candidates),'ids_file'=>$idsFile,'exact'=>true,'strategy'=>'sequential-import-export-reference-set-plus-sequential-name-stream'];
if(!isset($o['summary']))$out['candidate_file_ids']=$candidates;
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
