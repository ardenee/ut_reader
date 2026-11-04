#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/lib/CatalogSupportCore.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenScriptObjectProjector;

$opts=getopt('',['container:']);
$id=(int)($opts['container']??0);
if($id<1){fwrite(STDERR,"Usage: php index-zen-script-objects.php --container=1\n");exit(2);}
$c=catalog_config();$db=catalog_db($c);
$q=$db->prepare('SELECT utoc_relative_path,utoc_sha256 FROM ue_iostore_containers '
 .'WHERE id=? AND status="ready" AND LOWER(name)="global.utoc"');
$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
if(!is_array($row))throw new RuntimeException('Ready global.utoc not found.');
$relative=str_replace('\\','/',(string)$row['utoc_relative_path']);
if(!str_starts_with($relative,'games/') || str_contains($relative,'..')
  || str_contains($relative,':') || str_contains($relative,'//')) {
 throw new RuntimeException('Unsafe retained TOC relative path.');
}
$root=realpath((string)$c['storage_path']);
$path=$root!==false?realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative)):false;
if($root===false||$path===false||is_link($path)
 || !str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower(rtrim(str_replace('\\','/',$root),'/').'/'))) {
 throw new RuntimeException('Retained TOC outside catalog storage.');
}
$started=hrtime(true);
$toc=new Uedb5IoStoreTocReader($path);
$summary=(new PdoZenScriptObjectProjector($db))->publish($id,$toc);
echo json_encode($summary+['wall_ms'=>round((hrtime(true)-$started)/1e6,2)],JSON_UNESCAPED_SLASHES),PHP_EOL;
