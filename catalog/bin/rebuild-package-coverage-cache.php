#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(1); }
$root=dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageCoverageCache;
$options=getopt('', ['game-id:']);
$gameId=(int)($options['game-id']??0);
if($gameId<1){fwrite(STDERR,"Usage: php catalog/bin/rebuild-package-coverage-cache.php --game-id=ID\n");exit(2);}
$db=catalog_db(catalog_config());
$cache=new PdoPackageCoverageCache($db);
$result=$cache->rebuildGame($gameId,static function(int $done,int $total,string $package):void{
    fwrite(STDERR,"\rCoverage $done/$total $package" . str_repeat(' ',20));
});
fwrite(STDERR,"\n");
echo json_encode(['ok'=>true,'game_id'=>$gameId]+$result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
