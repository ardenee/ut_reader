#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameParityAuditService;

$options=getopt('',['game:','preflight','max-details::','search-samples::','relation-samples::']);
$game=trim((string)($options['game']??''));
if($game===''){
    fwrite(STDERR,"Usage: php catalog/bin/audit-uedb5-game-parity.php --game=ut99 [--preflight] [--max-details=100] [--search-samples=150] [--relation-samples=500]\n");
    exit(1);
}
$app=catalog_bootstrap();
$service=new Uedb5GameParityAuditService($app->db,catalog_config());
try{
    if(isset($options['preflight'])){
        echo json_encode(['ok'=>true,'preflight'=>$service->preflight($game)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit(0);
    }
    $maxDetails=max(1,min(5000,(int)($options['max-details']??100)));
    $searchSamples=max(3,min(1500,(int)($options['search-samples']??150)));
    $relationSamples=max(1,min(5000,(int)($options['relation-samples']??500)));
    $result=$service->audit($game,$maxDetails,$searchSamples,$relationSamples);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(!empty($result['ok'])?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
