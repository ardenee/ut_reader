<?php
/**
 * Read-only acceptance for bounded Access Matrix grouped-result cache.
 * Never queries or persists individual user event rows in the cache.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogAccessMatrixAggregateCache as Cache;

function check(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}

$root=dirname(__DIR__, 2);
$page=(string)file_get_contents($root.'/catalog/access-matrix.php');
$cacheCall = '$matrixCache->';
check(str_contains($page,'CatalogAccessMatrixAggregateCache')
    && str_contains($page,$cacheCall.'invalidate()')
    && str_contains($page,$cacheCall."remember('top_pages'")
    && str_contains($page,$cacheCall."remember('top_ips'")
    && str_contains($page,$cacheCall."remember('summary'")
    && str_contains($page,$cacheCall."remember('raw_count'")
    && !str_contains($page,$cacheCall."remember('raw_events'"),
    'Access Matrix aggregate cache wiring or live rows changed');

$config=catalog_config();
$config['storage_path'] .= '/acceptance-isolated-'.bin2hex(random_bytes(8));
$cache=new Cache($config);
$cache->invalidate();
$invocations=0;
$id=['name'=>'tests','marker'=>bin2hex(random_bytes(8))];
$first=$cache->remember('sample',$id,static function () use (&$invocations): array {
    $invocations++;
    return ['hits'=>'19','rows'=>[['path'=>'/catalog/games.php','n'=>12]]];
});
$second=$cache->remember('sample',$id,static function () use (&$invocations): array {
    $invocations++;
    throw new RuntimeException('Cache hit unexpectedly executed SQL loader');
});
check($first===$second && $invocations===1,'Warm read changed grouped values or executed loader');
$filtered=$cache->remember('sample',$id+['filter'=>'something else'],
    static function () use (&$invocations): array {
        $invocations++;
        return ['hits'=>'3','rows'=>[]];
    });
check($invocations===2 && $filtered!==$first,'Distinct filters shared a cached aggregate');
$cache->invalidate();
$third=$cache->remember('sample',$id,static function () use (&$invocations): array {
    $invocations++;
    return ['hits'=>'7','rows'=>[]];
});
check($invocations===3 && $third!==$first,'Admin moderation failed to invalidate cached counts');

$bench=[];
if (in_array('--db', $argv, true)) {
    $dbConfig=catalog_config();
    $dbConfig['db']['host']=(string)(getenv('EXAMINE_DB_HOST') ?: '192.168.0.55');
    $db=catalog_db($dbConfig);
    $db->exec('SET SESSION MAX_EXECUTION_TIME=12000');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $liveCache=new Cache($dbConfig);
        $liveCache->invalidate();
        $where='a.occurred_at>=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 7 DAY)';
        $queries=[
          'summary'=>'SELECT SUM(a.event_type IN ("page_view","server_page")) page_activity,'
            .'COUNT(DISTINCT CASE WHEN a.event_type IN ("page_view","server_page") THEN a.ip_address END) unique_ips '
            .'FROM ue_access_events a WHERE '.$where,
          'top_pages'=>'SELECT a.page_key,MIN(a.request_path) sample_path,'
            .'COUNT(*) hits,COUNT(DISTINCT a.ip_address) unique_ips FROM ue_access_events a '
            .'WHERE '.$where.' AND a.event_type IN ("page_view","server_page") '
            .'GROUP BY a.page_key ORDER BY hits DESC,a.page_key LIMIT 10',
        ];
        foreach($queries as $name=>$sql){
            $identity=[$sql,'seven-day-profile'];
            $start=hrtime(true);
            $cold=$liveCache->remember('benchmark_'.$name,$identity,
                static fn():array=>$db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            $coldMs=round((hrtime(true)-$start)/1e6,2);
            $start=hrtime(true);
            $warm=$liveCache->remember('benchmark_'.$name,$identity,
                static function ():array{throw new RuntimeException('Warm metric ran SQL');});
            $warmMs=round((hrtime(true)-$start)/1e6,2);
            check($cold===$warm,'Cached SQL values changed');
            $bench[$name]=['cold_ms'=>$coldMs,'warm_ms'=>$warmMs,
                'row_count'=>count($warm)];
        }
        $db->rollBack();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
echo json_encode([
    'pass'=>true,'cache_ttl_seconds'=>Cache::TTL_SECONDS,
    'cold_warm_isolation_and_invalidation'=>true,
    'query_specific_identity'=>true,'raw_events_not_cached'=>true,
    'source_db_mutations'=>0,'live_benchmark'=>$bench,
],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
