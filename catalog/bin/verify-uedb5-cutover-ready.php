#!/usr/bin/env php
<?php
/** Final read-only gate for atomic UEDB5 production cutover readiness. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap.php';
require_once $root.'/lib/GameProfiles.php';
require_once $root.'/lib/CatalogUE4ParserProfile.php';
require_once $root.'/lib/CatalogUE5ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CutoverReadinessVerifier;

$options=getopt('',['database','progress-every::','max-failures::']);
$withDatabase=isset($options['database']);
$progressEvery=max(1,(int)($options['progress-every']??500));
$maxFailures=max(1,min(500,(int)($options['max-failures']??50)));

try{
    $source=Uedb5CutoverReadinessVerifier::sourceChecks($root);
    $database=null;
    if($withDatabase){
        $app=catalog_bootstrap();
        $verifier=new Uedb5CutoverReadinessVerifier($app->db,catalog_config(),$root);
        $emit=static function(array $row):void{
            fwrite(STDERR,json_encode($row,JSON_UNESCAPED_SLASHES).PHP_EOL);
            fflush(STDERR);
        };
        $database=$verifier->verifyDatabase($progressEvery,$maxFailures,$emit);
    }
    $sourceFailures=(array)($source['failures']??[]);
    $databaseFailures=$database===null?[]:(array)($database['failures']??[]);
    $cutoverReady=$withDatabase&&$sourceFailures===[]&&$databaseFailures===[]&&!empty($database['cutover_ready']);
    $ok=$withDatabase?$cutoverReady:$sourceFailures===[];
    echo json_encode([
        'ok'=>$ok,
        'cutover_ready'=>$cutoverReady,
        'database_checked'=>$withDatabase,
        'source_contract'=>$source,
        'database'=>$database,
        'failures'=>array_values(array_merge($sourceFailures,$databaseFailures)),
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($ok?0:2);
}catch(Throwable $error){
    fwrite(STDERR,json_encode(['ok'=>false,'cutover_ready'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
