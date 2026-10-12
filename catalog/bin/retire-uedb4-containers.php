#!/usr/bin/env php
<?php
/** Explicit UEDB4 retirement: only canonical metadata/{game}/{shard}/*.uedb4 files. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
$options=getopt('', ['apply']);
if (!isset($options['apply'])) {
    fwrite(STDERR,"Specify --apply to remove only .uedb4 files from canonical metadata directories.\n");
    exit(2);
}
$root=rtrim((string)(catalog_config()['storage_path']??''),'\\/').DIRECTORY_SEPARATOR.'metadata';
if (!is_dir($root)) {fwrite(STDERR,"Metadata directory not found.\n");exit(1);}
$total=0;$failed=0;$bytes=0;$errors=[];
foreach (new DirectoryIterator($root) as $game) {
    if ($game->isDot() || !$game->isDir() || !ctype_digit($game->getFilename()) || $game->isLink()) continue;
    $gameCount=0;$gameFailed=0;$gameBytes=0;
    foreach (new DirectoryIterator($game->getPathname()) as $shard) {
        if ($shard->isDot() || !$shard->isDir() || $shard->isLink() || !ctype_digit($shard->getFilename())) continue;
        foreach (new DirectoryIterator($shard->getPathname()) as $file) {
            if (!$file->isFile() || $file->isLink() || !preg_match('/^[0-9]+\\.uedb4$/D',$file->getFilename())) continue;
            $path=$file->getPathname();$size=(int)$file->getSize();
            if (@unlink($path)) {$total++;$gameCount++;$bytes+=$size;$gameBytes+=$size;}
            else {$failed++;$gameFailed++;if(count($errors)<20)$errors[]=$path;}
        }
    }
    echo json_encode(['game_id'=>(int)$game->getFilename(),'deleted'=>$gameCount,'failed'=>$gameFailed,'deleted_bytes'=>$gameBytes],JSON_UNESCAPED_SLASHES),PHP_EOL;
    flush();
}
echo json_encode(['ok'=>$failed===0,'total_deleted'=>$total,'total_failed'=>$failed,'deleted_bytes'=>$bytes,'deleted_gib'=>round($bytes/1073741824,3),'errors'=>$errors],JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===0?0:2);
