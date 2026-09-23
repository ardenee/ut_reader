#!/usr/bin/env php
<?php
/** Repair ue_files metadata counts from a positively verified staged v3 container. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root=dirname(__DIR__,2);
require_once $root.'/lib/CatalogSupport.php';
require_once __DIR__.'/MetadataContainerV3.php';
use UnrealDb\Catalog\MigrationV3\MetadataContainerV3;
try {
 $o=getopt('',['file-id:','apply']);
 $fileId=(int)($o['file-id']??0); $apply=array_key_exists('apply',$o);
 if($fileId<1) throw new RuntimeException('--file-id is required.');
 $config=catalog_config(); $db=catalog_db($config); $storageRoot=trim((string)($config['storage_path']??''));
 $s=$db->prepare('SELECT id,game_id,original_name,scan_status,name_count,import_count,export_count FROM ue_files WHERE id=?');
 $s->execute([$fileId]); $file=$s->fetch(PDO::FETCH_ASSOC);
 if(!is_array($file)) throw new RuntimeException('Catalog file not found.');
 if((string)$file['scan_status']!=='verified') throw new RuntimeException('Only verified catalogue files may be repaired.');
 $path=MetadataContainerV3::path($storageRoot,(int)$file['game_id'],$fileId);
 $verified=MetadataContainerV3::verifyFile($path,$fileId,null,3);
 $manifest=(array)($verified['manifest']??[]); $counts=(array)($manifest['counts']??[]);
 $next=['name_count'=>(int)($counts['names']??-1),'import_count'=>(int)($counts['imports']??-1),'export_count'=>(int)($counts['exports']??-1)];
 if(min($next)<0 || (int)($counts['dependencies']??-1)!==$next['import_count']) throw new RuntimeException('Verified v3 manifest counts are incomplete or inconsistent.');
 $before=['name_count'=>(int)$file['name_count'],'import_count'=>(int)$file['import_count'],'export_count'=>(int)$file['export_count']];
 if($apply && $before!==$next){
  $u=$db->prepare('UPDATE ue_files SET name_count=?,import_count=?,export_count=? WHERE id=? AND scan_status="verified"');
  $u->execute([$next['name_count'],$next['import_count'],$next['export_count'],$fileId]);
 }
 echo json_encode(['ok'=>true,'dry_run'=>!$apply,'file_id'=>$fileId,'file'=>(string)$file['original_name'],'before'=>$before,'verified_v3'=>$next,'changed'=>$before!==$next,'applied'=>$apply&&$before!==$next],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e){ fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_PRETTY_PRINT).PHP_EOL); exit(1); }
