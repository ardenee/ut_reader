#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;

$options=getopt('',[
    'game:', 'apply', 'continuous', 'limit::', 'progress-every::', 'preflight'
]);
$game=trim((string)($options['game'] ?? ''));
if($game===''){
    fwrite(STDERR,"Usage: php catalog/bin/migrate-uedb5-game.php --game=ut99 [--apply] [--continuous] [--limit=1000] [--progress-every=100] [--preflight]\n");
    exit(1);
}
$app=catalog_bootstrap();
$service=new Uedb5GameSourceMigrationService($app->db,catalog_config());
try{
    if(isset($options['preflight'])){
        echo json_encode(['ok'=>true,'preflight'=>$service->preflight($game)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }
    $apply=isset($options['apply']);
    $continuous=isset($options['continuous']);
    $limit=max(1,min(5000,(int)($options['limit'] ?? 1000)));
    $progressEvery=max(1,(int)($options['progress-every'] ?? 100));
    $emit=static function(array $row):void{
        $row['memory_mb']=round(memory_get_usage(true)/1048576,1);
        echo json_encode($row,JSON_UNESCAPED_SLASHES),PHP_EOL;
    };
    $result=$service->migrate($game,$apply,$limit,$continuous,$progressEvery,$emit);
    echo json_encode(['ok'=>$result['failed']===0,'summary'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($result['failed']===0?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
