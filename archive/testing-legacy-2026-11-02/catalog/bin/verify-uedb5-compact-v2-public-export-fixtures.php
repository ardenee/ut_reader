#!/usr/bin/env php
<?php
/** PublicExportHash lookup contract using real V5 snapshots plus injected
 * synthetic, valid, non-null hashes. No permanent SQL writes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher as Publisher;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidateLookup as Lookup;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader as Reader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder as Builder;
$db=catalog_bootstrap(false)->db;
$reader=new Reader(catalog_config()['storage_path']);
$created=[];$problems=[];$checks=0;$cases=[];
$ddl=[
 'CREATE TEMPORARY TABLE u5_v2_test_dictionary (name_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,UNIQUE KEY fingerprint(name_key_fingerprint)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE u5_v2_test_names (file_id BIGINT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE u5_v2_test_objects (file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,name_id BIGINT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(name_id,file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB',
 'CREATE TEMPORARY TABLE u5_public_expected (file_id BIGINT UNSIGNED NOT NULL,object_kind TINYINT UNSIGNED NOT NULL,object_index INT UNSIGNED NOT NULL,public_export_hash BINARY(8) NULL,PRIMARY KEY(file_id,object_kind,object_index),KEY lookup(file_id,object_kind,public_export_hash,object_index)) ENGINE=InnoDB'
];
$tables=['u5_v2_test_dictionary','u5_v2_test_names','u5_v2_test_objects','u5_public_expected'];
$reg=$db->prepare('SELECT * FROM ue_uedb5_files WHERE game_id=? AND file_id=?');
try{
 foreach($ddl as $i=>$statement){$db->exec($statement);$created[]=$tables[$i];}
 $pub=new Publisher($db,true);$lookup=new Lookup($db,true);
 $expectedInsert=$db->prepare('INSERT INTO u5_public_expected VALUES(?,?,?,?)');
 $expectedSelect=$db->prepare('SELECT file_id,object_kind,object_index,public_export_hash FROM u5_public_expected WHERE file_id=? AND object_kind=? AND public_export_hash=? ORDER BY object_index');
 foreach([2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902] as $game=>$file){
   try{
    $reg->execute([$game,$file]);$registration=$reg->fetch(PDO::FETCH_ASSOC);
    if(!is_array($registration))throw new RuntimeException('Missing V5 registration');
    $snapshot=$reader->snapshot($game,$file);
    $source=(array)($snapshot['sections']['exports']??[]);
    if(count($source)<1)throw new RuntimeException('No V5 source exports');
    // Inject controlled PublicExportHash values, retaining the real object identities.
    // Every first export gets a public hash. Second and third exports, if
    // available, share it, intentionally exercising duplicate results.
    $hex='A1B2C3D4E5F60708';
    $other='1020304050607080';
    foreach($snapshot['sections']['exports'] as $i=>&$row){
      if($i<=2)$row['public_export_hash']=$hex;
      elseif($i===3)$row['public_export_hash']=$other;
      else $row['public_export_hash']=null;
    }unset($row);
    $projection=Builder::build($snapshot,$registration);
    $pub->publish($snapshot,$registration);
    foreach($projection['object_candidates'] as $o)
      $expectedInsert->execute([$o['file_id'],$o['object_kind'],$o['object_index'],$o['public_export_hash']]);
    $matched=0;
    foreach([$hex,$other] as $value){
      $bin=hex2bin($value);$expectedSelect->execute([$file,1,$bin]);
      $a=$expectedSelect->fetchAll(PDO::FETCH_NUM);
      $b=array_map(static fn(array $r)=>array_values($r),$lookup->publicExports($file,1,$bin));
      if($a!==$b)throw new RuntimeException('Public export result set/order mismatch for '.$value);
      $checks++;$matched+=count($b);
    }
    if($matched===0)throw new RuntimeException('Positive public hash never matched');
    $cases[]=['game_id'=>$game,'file_id'=>$file,'checks'=>2,'matching_rows'=>$matched];
   }catch(Throwable $e){$problems[]=['game_id'=>$game,'error'=>$e->getMessage()];}
   finally{$reader->clearCache($game,$file);}
 }
}catch(Throwable $e){$problems[]=['setup_error'=>$e->getMessage()];}
finally{
 if($db->inTransaction())$db->rollBack();
 foreach(array_reverse($created) as $t)try{$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$t);}
 catch(Throwable $e){$problems[]=['cleanup_error'=>$e->getMessage()];}
}
echo json_encode(['ok'=>$problems===[]&&count($cases)===7,'synthetic_public_hash_values'=>true,'real_v5_name_and_object_rows'=>true,
 'temporary_tables_only'=>true,'live_production_tables_read_only'=>true,'lookup_checks'=>$checks,
 'cases'=>$cases,'failures'=>$problems],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($problems===[]&&count($cases)===7?0:2);
