#!/usr/bin/env php
<?php
/** Isolated bounded InnoDB benchmark. --fixtures never writes; --measure requires
 * explicit disposable EMPTY uedb5_shadow_* database. Never touches live SQL rows. */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
$opts=getopt('', ['fixtures','measure','rows::']);
if(isset($opts['fixtures'])===isset($opts['measure'])) {fwrite(STDERR,"Choose --fixtures or --measure.\n");exit(2);}
function fullId(string $name):array {
    $key=CatalogUnrealIdentityHash::nameKey($name);
    return ['normalized'=>$key,'hash'=>md5($key,true),'length'=>strlen($key),'fingerprint'=>hash('sha256',$key,true)];
}
if(isset($opts['fixtures'])){
    $names=['Example','example',' Name ','NAME_None','A_3','A_003','Ärger',''];
    $fail=[];
    foreach($names as $n){
        $id=fullId($n);
        if(strlen($id['hash'])!==16||strlen($id['fingerprint'])!==32)
            $fail[]='invalid digest width';
        if($id['normalized']!==CatalogUnrealIdentityHash::nameKey($n))
            $fail[]='source normalization mismatch';
    }
    // Deliberate hash+length collision. SHA-256 and text remain distinct.
    $a=fullId('ABCD');$b=fullId('WXYZ');
    $b['hash']=$a['hash'];
    $collision=($a['hash']===$b['hash'] && $a['length']===$b['length']
       && $a['fingerprint']!==$b['fingerprint']);
    if(!$collision)$fail[]='synthetic ambiguous hash was not detected';
    echo json_encode(['ok'=>$fail===[],'cases'=>count($names),'synthetic_collision_detected'=>$collision,
      'collision_policy'=>'Never select a dictionary ID by MD5 and length alone; resolve exact bytes or fail closed.',
      'errors'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
    exit($fail===[]?0:2);
}
$dsn=(string)(getenv('UEDB5_SHADOW_DSN')?:'');
$user=(string)(getenv('UEDB5_SHADOW_USER')?:'');
if(!$dsn||!$user){fwrite(STDERR,"Refused: UEDB5_SHADOW_DSN and UEDB5_SHADOW_USER required.\n");exit(3);}
$shadow=null;$created=[];$result=['ok'=>false];
try {
  $shadow=new PDO($dsn,$user,(string)(getenv('UEDB5_SHADOW_PASSWORD')?:''),
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $schema=(string)$shadow->query('SELECT DATABASE()')->fetchColumn();
  if(!preg_match('/^uedb5_shadow_[a-z0-9_]+$/D',$schema))
      throw new RuntimeException('Refused: database is not a dedicated uedb5_shadow_* schema.');
  $existing=(int)$shadow->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
  if($existing)throw new RuntimeException('Refused: shadow schema is not empty.');
  $prod=catalog_bootstrap(false)->db;
  if($schema===(string)$prod->query('SELECT DATABASE()')->fetchColumn())
      throw new RuntimeException('Refused: identical production database.');
  $ids=[111139,1363209,113253,1362900,148919,1360665,111460,1363138,
        112300,1362977,113526,1359117,110902,1362870];
  $limit=max(100,min(10000,(int)($opts['rows']??3000)));
  $rows=$prod->query('SELECT file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index
     FROM ue_uedb5_name_candidates WHERE file_id IN ('.implode(',',$ids).')
     ORDER BY file_id,name_key_fingerprint LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
  if(count($rows)<100)throw new RuntimeException('Insufficient sample rows.');
  $ddl=[
   'u5_old'=>'CREATE TABLE u5_old(file_id BIGINT UNSIGNED NOT NULL,name_key_hash BINARY(16) NOT NULL,
     name_key_length INT UNSIGNED NOT NULL,name_key_fingerprint BINARY(32) NOT NULL,
     first_name_index INT UNSIGNED NOT NULL,
     PRIMARY KEY(file_id,name_key_fingerprint),
     KEY lookup(name_key_hash,name_key_length,name_key_fingerprint,file_id)) ENGINE=InnoDB',
   'u5_dictionary'=>'CREATE TABLE u5_dictionary(name_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
     name_key_hash BINARY(16) NOT NULL,name_key_length INT UNSIGNED NOT NULL,
     name_key_fingerprint BINARY(32) NOT NULL,PRIMARY KEY(name_id),
     UNIQUE KEY fingerprint(name_key_fingerprint),
     KEY lookup(name_key_hash,name_key_length,name_key_fingerprint)) ENGINE=InnoDB',
   'u5_compact'=>'CREATE TABLE u5_compact(file_id BIGINT UNSIGNED NOT NULL,
     name_id BIGINT UNSIGNED NOT NULL,first_name_index INT UNSIGNED NOT NULL,
     PRIMARY KEY(file_id,name_id),KEY lookup(name_id,file_id)) ENGINE=InnoDB'
  ];
  foreach($ddl as $name=>$q){$shadow->exec($q);$created[]=$name;}
  $putOld=$shadow->prepare('INSERT INTO u5_old VALUES(?,?,?,?,?)');
  $putDict=$shadow->prepare('INSERT INTO u5_dictionary(name_key_hash,name_key_length,name_key_fingerprint) VALUES(?,?,?)');
  $putCompact=$shadow->prepare('INSERT INTO u5_compact VALUES(?,?,?)');
  $names=[];
  foreach($rows as $r){
    $file=(int)$r['file_id'];$hash=(string)$r['name_key_hash'];
    $len=(int)$r['name_key_length'];$fp=(string)$r['name_key_fingerprint'];$idx=(int)$r['first_name_index'];
    $putOld->execute([$file,$hash,$len,$fp,$idx]);
    $hex=bin2hex($fp);
    if(!isset($names[$hex])){$putDict->execute([$hash,$len,$fp]);$names[$hex]=(int)$shadow->lastInsertId();}
    $putCompact->execute([$file,$names[$hex],$idx]);
  }
  foreach($created as $table)$shadow->exec('ANALYZE TABLE '.$table);
  $size=$shadow->query('SELECT table_name,table_rows,data_length,index_length FROM information_schema.tables
    WHERE table_schema=DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_ASSOC);
  $old=$shadow->prepare('SELECT file_id,first_name_index FROM u5_old WHERE name_key_hash=?
    AND name_key_length=? AND name_key_fingerprint=? ORDER BY file_id,first_name_index');
  $new=$shadow->prepare('SELECT c.file_id,c.first_name_index FROM u5_dictionary d
    JOIN u5_compact c ON c.name_id=d.name_id WHERE d.name_key_hash=?
    AND d.name_key_length=? AND d.name_key_fingerprint=? ORDER BY c.file_id,c.first_name_index');
  $differences=[];$oldSecs=0.;$newSecs=0.;
  for($i=0;$i<30;$i++){
    $r=$rows[($i*71)%count($rows)];
    $args=[$r['name_key_hash'],$r['name_key_length'],$r['name_key_fingerprint']];
    $t=microtime(true);$old->execute($args);$a=$old->fetchAll(PDO::FETCH_NUM);$oldSecs+=microtime(true)-$t;
    $t=microtime(true);$new->execute($args);$b=$new->fetchAll(PDO::FETCH_NUM);$newSecs+=microtime(true)-$t;
    if($a!==$b)$differences[]=$i;
  }
  $result=['ok'=>$differences===[],'schema'=>$schema,'sample_rows'=>count($rows),
    'dictionary_keys'=>count($names),'lookup_mismatches'=>$differences,
    'original_seconds'=>round($oldSecs,6),'compact_seconds'=>round($newSecs,6),
    'measured_innodb_tables'=>$size,
    'warning'=>'Small sample and indexes may not scale linearly. No production tables modified.'];
} catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
finally{if($shadow instanceof PDO)foreach(array_reverse($created) as $table)
  try{$shadow->exec('DROP TABLE IF EXISTS '.$table);}catch(Throwable){}}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit(($result['ok']??false)?0:2);
