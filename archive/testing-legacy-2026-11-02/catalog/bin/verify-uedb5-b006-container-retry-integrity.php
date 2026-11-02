<?php
declare(strict_types=1);
/**
 * Isolated real-TOC retry integrity acceptance.
 * Executes the actual CatalogIoStoreContainerIndexer with an in-memory SQLite
 * double only for MySQL's FOR UPDATE and information_schema syntactic features.
 * Does not contact a persistent MySQL database or edit any retained TOC.
 */
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

final class RetryIsolatedPDO extends \Pdo\Sqlite {
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(preg_replace('/ FOR UPDATE$/','',$query),$options);
    }
}
$checks=[];$failures=[];$details=[];
$check=static function(string $key,bool $ok)use(&$checks,&$failures):void{
    $checks[$key]=$ok;
    if(!$ok)$failures[]=$key;
};
$root=dirname(__DIR__,2);
$source=(string)file_get_contents($root.'/catalog/src/Infrastructure/Storage/CatalogIoStoreContainerIndexer.php');
$before=[];
$beforeExit=127;
exec('git -c '.escapeshellarg('safe.directory='.str_replace('\\','/',$root))
    .' -C '.escapeshellarg($root)
    .' show '.escapeshellarg('a880dc20:catalog/src/Infrastructure/Storage/CatalogIoStoreContainerIndexer.php')
    .' 2>&1',$before,$beforeExit);
$old=implode("\n",$before);
$check('iostore_retry_historical_count_only_false_acceptance',
    $beforeExit===0
    &&str_contains($old,'SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=?')
    &&!str_contains($old,'ORDER BY toc_index FOR UPDATE'));
$check('iostore_retry_current_source_checks_complete_container_header',
    str_contains($source,'toc_version,container_flags,package_count')
    &&str_contains($source,"(string)\$found['container_id']!==(string)\$head['container_id']"));
$check('iostore_retry_current_source_locks_and_streams_member_identity',
    str_contains($source,'ORDER BY toc_index FOR UPDATE')
    &&str_contains($source,'hash_equals($id,(string)$stored[\'package_id\'])')
    &&str_contains($source,"(int)\$stored['chunk_index']!==\$chunkIdx")
    &&str_contains($source,"(int)\$stored['logical_size']!==\$bytes"));
$check('iostore_retry_current_source_requires_explicit_repair_not_silent_rewrite',
    str_contains($source,'explicit repair required.')
    &&str_contains($source,'if ($members->fetch(PDO::FETCH_ASSOC)!==false)'));
$check('iostore_retry_sqlite_available',in_array('sqlite',PDO::getAvailableDrivers(),true));

try {
    $file='C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
    if(!is_file($file))throw new RuntimeException('Retained Satisfactory TOC not installed');
    $toc=new Uedb5IoStoreTocReader($file);
    $db=new RetryIsolatedPDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->createFunction('DATABASE',static fn():string=>'isolated');
    $db->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $db->exec('CREATE TABLE information_schema.tables(table_schema TEXT,table_name TEXT)');
    $db->exec("INSERT INTO information_schema.tables VALUES
        ('isolated','ue_iostore_containers'),('isolated','ue_iostore_container_parts'),('isolated','ue_iostore_packages')");
    $db->exec('CREATE TABLE ue_iostore_containers(
      id INTEGER PRIMARY KEY AUTOINCREMENT,game_id INTEGER,name TEXT,utoc_sha256 TEXT,
      utoc_relative_path TEXT,container_id TEXT,toc_version INTEGER,
      container_flags INTEGER,package_count INTEGER,status TEXT)');
    $db->exec('CREATE TABLE ue_iostore_packages(
      id INTEGER PRIMARY KEY AUTOINCREMENT,container_id INTEGER,toc_index INTEGER,
      package_id BLOB,chunk_index INTEGER,logical_size INTEGER,
      UNIQUE(container_id,toc_index))');
    $indexer=new CatalogIoStoreContainerIndexer($db);
    $path='isolated/FactoryGame-WindowsServer.utoc';
    $first=$indexer->index($toc,4,$path);
    $check('iostore_retry_real_satisfactory_toc_first_index_indexed',
        $first['status']==='indexed' &&$first['package_count']===27240);
    $repeat=$indexer->index($toc,4,$path);
    $check('iostore_retry_identical_toc_and_stored_identity_accepted',
        $repeat['status']==='existing' &&$repeat['container_id']===$first['container_id']);
    $containerId=(int)$first['container_id'];
    $original=$db->query('SELECT toc_index,package_id,chunk_index,logical_size FROM ue_iostore_packages ORDER BY toc_index LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if(!is_array($original))throw new RuntimeException('Real TOC contained no indexed packages');
    $rowId=(int)$original['toc_index'];
    $originalCount=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages')->fetchColumn();
    $check('iostore_retry_real_toc_stored_all_main_chunks', $originalCount===27240);
    $reject=static function(string $label)use($indexer,$toc,$path,$db,$check):void{
        $failed=false;$message='';
        try{$indexer->index($toc,4,$path);}
        catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'explicit repair required.');$message=$e->getMessage();}
        $check('iostore_retry_'.$label.'_requires_explicit_repair',
            $failed&&!$db->inTransaction());
    };
    $db->exec("UPDATE ue_iostore_packages SET package_id=X'0000000000000000' WHERE toc_index=$rowId");
    $sameCount=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages')->fetchColumn()===$originalCount;
    $check('iostore_retry_historical_count_gate_would_accept_swapped_package_id',$sameCount);
    $reject('corrupt_package_id');
    $restore=$db->prepare('UPDATE ue_iostore_packages SET package_id=? WHERE toc_index=?');
    $restore->execute([$original['package_id'],$rowId]);
    $db->exec("UPDATE ue_iostore_packages SET chunk_index=1 WHERE toc_index=$rowId");
    $reject('corrupt_chunk_index');
    $db->exec("UPDATE ue_iostore_packages SET chunk_index=".(int)$original['chunk_index']." WHERE toc_index=$rowId");
    $db->exec("UPDATE ue_iostore_packages SET logical_size=logical_size+17 WHERE toc_index=$rowId");
    $reject('corrupt_logical_size');
    $db->exec("UPDATE ue_iostore_packages SET logical_size=".(int)$original['logical_size']." WHERE toc_index=$rowId");
    $db->exec("UPDATE ue_iostore_packages SET toc_index=999999 WHERE toc_index=$rowId");
    $reject('corrupt_toc_position');
    $db->exec("UPDATE ue_iostore_packages SET toc_index=$rowId WHERE toc_index=999999");
    $db->exec("UPDATE ue_iostore_containers SET toc_version=toc_version+1 WHERE id=$containerId");
    $reject('corrupt_header_version');
    $db->exec("UPDATE ue_iostore_containers SET toc_version=toc_version-1 WHERE id=$containerId");
    $db->exec("UPDATE ue_iostore_containers SET container_flags=container_flags+1 WHERE id=$containerId");
    $reject('corrupt_container_flags');
    $db->exec("UPDATE ue_iostore_containers SET container_flags=container_flags-1 WHERE id=$containerId");
    $db->exec("UPDATE ue_iostore_containers SET container_id='BAD0000000000000' WHERE id=$containerId");
    $reject('corrupt_container_id');
    $restoreHeader=$db->prepare('UPDATE ue_iostore_containers SET container_id=? WHERE id=?');
    $restoreHeader->execute([(string)$toc->header()['container_id'],$containerId]);
    $db->exec("UPDATE ue_iostore_containers SET package_count=package_count+1 WHERE id=$containerId");
    $reject('corrupt_persisted_package_count');
    $db->exec("UPDATE ue_iostore_containers SET package_count=package_count-1 WHERE id=$containerId");
    $db->exec("INSERT INTO ue_iostore_packages(container_id,toc_index,package_id,chunk_index,logical_size)
      VALUES ($containerId,999999,X'0102030405060708',0,123)");
    $reject('unexpected_extra_chunk');
    $db->exec('DELETE FROM ue_iostore_packages WHERE toc_index=999999');
    $db->exec("DELETE FROM ue_iostore_packages WHERE toc_index=$rowId");
    $reject('missing_chunk_with_count_mismatch');
    $insert=$db->prepare('INSERT INTO ue_iostore_packages(container_id,toc_index,package_id,chunk_index,logical_size) VALUES(?,?,?,?,?)');
    $insert->execute([$containerId,$rowId,$original['package_id'],$original['chunk_index'],$original['logical_size']]);
    $after=$indexer->index($toc,4,$path);
    $check('iostore_retry_exact_restored_identity_accepted_after_all_negative_cases',
        $after['status']==='existing' &&$after['package_count']===27240 &&!$db->inTransaction());
    $check('iostore_retry_disposable_fixture_does_not_mutate_real_toc',
        is_file($file) &&$toc->tocSha256()===strtolower((string)$db->query('SELECT utoc_sha256 FROM ue_iostore_containers LIMIT 1')->fetchColumn()));
    $details=['retained_toc'=>basename($file),'indexed_main_rows'=>$originalCount,
      'sql_engine'=>'sqlite_in_memory_for_mysql_syntax_only',
      'uses_actual_production_indexer'=>true,'production_db_modified'=>false];
} catch(Throwable $e){
    $failures[]='iostore_retry_fixture_error';
    $details['fixture_exception']=$e->getMessage();
}
echo json_encode(['ok'=>$failures===[],'original_operation'=>'UE5-583-FILE-STORE-001',
    'difference'=>'new_retry_index_integrity_gap','checks'=>$checks,
    'failures'=>$failures,'details'=>$details,'production_db_modified'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($failures===[]?0:1);
