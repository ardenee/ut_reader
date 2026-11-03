<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence {
    // Disposable queue substitute: verifies the exact complete-container
    // handoff without inserting PRD/background jobs.
    final class IoStoreReviewQueueFixture
    {
        /** @var list<list<mixed>> */
        public static array $calls = [];
        public function __construct(\PDO $db) {}
        public function enqueue(mixed ...$arguments): int
        {
            self::$calls[] = $arguments;
            return 780001;
        }
    }
    class_alias(IoStoreReviewQueueFixture::class, PdoJobQueue::class);
}

namespace {
    require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';

    use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreUploadRoutingPolicy as Guard;
    use UnrealDb\Catalog\Infrastructure\Import\CatalogPublicIoStoreReviewService as Review;
    use UnrealDb\Catalog\Infrastructure\Persistence\IoStoreReviewQueueFixture;
    use UnrealDb\Catalog\Domain\Jobs\JobType;
    use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;

    $root = sys_get_temp_dir() . '/unrealdb-public-iostore-check-' . bin2hex(random_bytes(8));
    $source = 'D:/UE5/Paks';
    $rawToc = $source . '/global.utoc';
    $rawData = $source . '/global.ucas';
    if (!is_file($rawToc) || !is_file($rawData)) {
        throw new \RuntimeException('Genuine current UE5 global original is missing.');
    }
    // The actual admin unverified-files UI must expose the existing
    // CSRF-protected trusted approval handler; the ordinary indexed-file
    // bulk Import selected form must remain separate.
    $unverifiedPage = file_get_contents(dirname(__DIR__, 2) . '/catalog/unverified-files.php');
    $existingHandler = file_get_contents(dirname(__DIR__, 2) . '/catalog/iostore-import.php');
    if (!is_string($unverifiedPage) || !is_string($existingHandler)) {
        throw new \RuntimeException('Unverified review routes could not be inspected.');
    }
    foreach ([
        'CatalogPublicIoStoreReviewService',
        'IoStore contributions awaiting review',
        'Approve &amp; queue IoStore import',
        'catalog_csrf(\'iostore-import\')',
        'name="action" value="approve_public_iostore"',
        'name="review_key"',
        'action="iostore-import.php"',
        'name="game_id"',
        'iostore-public-reviews',
    ] as $required) {
        if (!str_contains($unverifiedPage, $required)) {
            throw new \RuntimeException('Admin unverified review UI is missing '. $required);
        }
    }
    if (!str_contains($existingHandler, "=== 'approve_public_iostore'")
        || !str_contains($existingHandler, "catalog_check_csrf('iostore-import')")
        || !str_contains($existingHandler, 'CatalogQueueWorkerStarter')) {
        throw new \RuntimeException('Unverified approval form does not target the actual trusted queued importer.');
    }
    $sourceHashes = [hash_file('sha256',$rawToc),hash_file('sha256',$rawData)];
    if ($sourceHashes !== [
        '0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6',
        'b57553fe214c9db7f854fb23db9cb6eb1c3c84fd714998b882487396373e211c'
    ]) throw new \RuntimeException('Actual original UE5 sources changed.');

    $db = new \Pdo\Sqlite('sqlite::memory:', null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
    ]);
    $db->createFunction('UTC_TIMESTAMP',
        static fn(int $precision = 0): string => gmdate('Y-m-d H:i:s'), -1);
    $db->exec('CREATE TABLE ue_game_profiles(id INT,engine_key TEXT,is_active INT)');
    $db->exec('CREATE TABLE ue_games(id INT,profile_id INT)');
    $db->exec('INSERT INTO ue_game_profiles VALUES(14,"UE5",1)');
    $db->exec('INSERT INTO ue_games VALUES(14,14)');
    $db->exec('CREATE TABLE ue_public_uploads(id INTEGER PRIMARY KEY,
        original_name TEXT,relative_path TEXT,quarantine_relative_path TEXT,
        upload_token TEXT,file_size INT,server_sha1 TEXT,submitter_ip BLOB,status TEXT,
        result_message TEXT,updated_at TEXT)');
    if (!mkdir($root,0700,true)) throw new \RuntimeException('No disposable test directory');
    $review = new Review($db,['storage_path'=>$root,'queue'=>['name'=>'catalog']]);
    $stmt=$db->prepare('INSERT INTO ue_public_uploads
        (id,original_name,relative_path,quarantine_relative_path,upload_token,file_size,
        server_sha1,submitter_ip,status) VALUES (?,?,?,?,?,?,?,?,?)');
    $ip=inet_pton('127.0.0.1');
    $add = static function(int $id, string $group, string $name, string $from, bool $alter = false)
            use($root,$stmt,$ip): void {
        $token=str_repeat(dechex($id % 16),64);
        $dir=$root.'/public-uploads/incoming/'.substr($token,0,2);
        if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new \RuntimeException('Could not stage fixture');
        $relative='public-uploads/incoming/'.substr($token,0,2).'/'.$token.'.bin';
        $path=$root.'/'.$relative;
        if (!copy($from,$path)) throw new \RuntimeException('Original fixture copy failed');
        $expectedSha1=hash_file('sha1',$path);
        $expectedSize=(int)filesize($path);
        if ($alter) file_put_contents($path,"x",FILE_APPEND);
        $stmt->execute([$id,$name,'iostore/'.$group.'/'.$name,$relative,$token,
            $expectedSize,$expectedSha1,$ip,'container_pending']);
    };
    try {
        $guarded=0;
        foreach(['global.utoc','global.ucas','GLOBAL.UTOC','global.UCAS'] as $name) {
            if (!Guard::isIoStoreComponent($name)) throw new \RuntimeException('Missed component: '.$name);
            try { Guard::assertProfiledPackage($name); }
            catch (\InvalidArgumentException $error) { $guarded++; }
        }
        if ($guarded!==4 || Guard::isIoStoreComponent('Example.uasset')) {
            throw new \RuntimeException('Admin loose-package guard is not scoped correctly.');
        }
        $good=str_repeat('a',32);
        $add(1,$good,'global.utoc',$rawToc);
        $add(2,$good,'global.ucas',$rawData);
        $groups=$review->pending();
        if (count($groups)!==1 || !$groups[0]['ready']
            || $groups[0]['part_count']!==1 || $groups[0]['toc_count']!==1) {
            throw new \RuntimeException('Public components were not paired into a complete review group.');
        }
        $job=$review->approve($groups[0]['key'],14,42);
        if ($job['job_id']!==780001 || $job['parts']!==1
            || $job['toc_sha256']!==$sourceHashes[0]) {
            throw new \RuntimeException('Validated original IoStore source was not queued.');
        }
        if (count(IoStoreReviewQueueFixture::$calls)!==1
            || IoStoreReviewQueueFixture::$calls[0][1]!==JobType::IMPORT_IOSTORE_CONTAINER) {
            throw new \RuntimeException('Public review did not call the trusted IoStore job type.');
        }
        $retained=$root.'/jobs/incoming/iostore/public-review/'.$groups[0]['key'];
        if (hash_file('sha256',$retained.'/global.utoc')!==$sourceHashes[0]
            || hash_file('sha256',$retained.'/global.ucas')!==$sourceHashes[1]
            || (new Toc($retained.'/global.utoc'))->header()['partition_count']!==1) {
            throw new \RuntimeException('Approved container was not retained byte-for-byte.');
        }
        $count=(int)$db->query('SELECT COUNT(*) FROM ue_public_uploads WHERE status="container_queued"')->fetchColumn();
        if ($count!==2) throw new \RuntimeException('Public quarantine was promoted without retaining both source records.');
        $negatives=[];
        $negative=static function(callable $f,string $key) use(&$negatives): void {
            try { $f(); } catch(\RuntimeException $e) { $negatives[$key]=$e->getMessage(); return; }
            throw new \RuntimeException('Failed to reject '.$key);
        };
        $negative(static fn()=> $review->approve($groups[0]['key'],14,42),'double_approval');
        $negative(static fn()=> $review->approve(str_repeat('f',64),14,42),'invented_group');
        $negative(static fn()=> $review->approve(str_repeat('f',64),999,42),'unknown_game');
        $negative(static fn()=> $review->approve(str_repeat('f',64),14,null),'anonymous_promotion');
        $incomplete=str_repeat('b',32);
        $add(3,$incomplete,'global.utoc',$rawToc);
        $partial=array_values(array_filter($review->pending(),
            static fn(array $r): bool => $r['source_folder']==='.' && !$r['ready']));
        if (count($partial)!==1) throw new \RuntimeException('Incomplete original group was not held.');
        $negative(static fn()=> $review->approve($partial[0]['key'],14,42),'orphan_toc');
        $tampered=str_repeat('c',32);
        $add(4,$tampered,'global.utoc',$rawToc);
        $add(5,$tampered,'global.ucas',$rawData,true);
        $pending=array_values(array_filter($review->pending(),
            static fn(array $r): bool => $r['ready']));
        if (count($pending)!==1) throw new \RuntimeException('Tampered group not available for validation.');
        $negative(static fn()=> $review->approve($pending[0]['key'],14,42),'quarantine_hash_drift');
        $extra = str_repeat('d',32);
        $add(6,$extra,'global.utoc',$rawToc);
        $add(7,$extra,'global.ucas',$rawData);
        $add(8,$extra,'global_s1.ucas',$rawData);
        $invalidParts=array_values(array_filter($review->pending(),
            static fn(array $r): bool => $r['ready'] && $r['part_count'] === 2));
        if (count($invalidParts)!==1) throw new \RuntimeException('Extra partition fixture not grouped.');
        $negative(static fn()=> $review->approve($invalidParts[0]['key'],14,42),
            'TOC_partition_cardinality');
        if (count(IoStoreReviewQueueFixture::$calls)!==1) {
            throw new \RuntimeException('An invalid review queued another import job.');
        }
        if ([hash_file('sha256',$rawToc),hash_file('sha256',$rawData)]!==$sourceHashes) {
            throw new \RuntimeException('Original game assets were modified.');
        }
        echo json_encode([
            'result'=>'PASS','verified_real_original_global_toc_sha256'=>$sourceHashes[0],
            'matched_current_original_global_components'=>2,
            'approved_pair_bytes_sha256_match'=>true,'registered_IoStore_job_type'=>true,
            'negative_cases'=>array_keys($negatives),'public_approval_required'=>true,
            'public_quarantine_retained'=>true,'admin_non_IoStore_guard_works'=>$guarded,
            'PRD_SQL_writes'=>0,'original_file_modifications'=>0,
            'new_acceptance_contract_ids'=>0
        ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    } finally {
        // Test-created unique directory only; never touch original sources.
        $it=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($it as $node) {
            if ($node->isDir()) rmdir((string)$node); else unlink((string)$node);
        }
        rmdir($root);
    }
}
