#!/usr/bin/env php
<?php
/**
 * Read-only inventory of retired UEDB4 physical containers.
 * Exactly examines the canonical metadata game/shard directories; performs no writes.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
try {
    $config=catalog_config();
    $root=rtrim((string)($config['storage_path']??''),'\\/').DIRECTORY_SEPARATOR.'metadata';
    if (!is_dir($root)) throw new RuntimeException('Metadata directory missing.');
    $summary=[];$total4=0;$total5=0;$unpaired=0;$bytes=0;$samples=[];
    foreach (new DirectoryIterator($root) as $game) {
        if (!$game->isDir() || $game->isDot()) continue;
        $gameName=$game->getFilename();
        if (!ctype_digit($gameName)) continue;
        $v4=0;$v5=0;$gameBytes=0;$gameUnpaired=0;
        foreach (new DirectoryIterator($game->getPathname()) as $shard) {
            if (!$shard->isDir() || $shard->isDot()) continue;
            foreach (new DirectoryIterator($shard->getPathname()) as $f) {
                if (!$f->isFile()) continue;
                $name=$f->getFilename();
                if (str_ends_with($name,'.uedb5')) { $v5++;continue; }
                if (!str_ends_with($name,'.uedb4')) continue;
                $v4++;$gameBytes+=(int)$f->getSize();
                $peer=substr($name,0,-6).'.uedb5';
                if (!is_file($shard->getPathname().DIRECTORY_SEPARATOR.$peer)) {
                    $gameUnpaired++;
                    if (count($samples)<10) $samples[]=$f->getPathname();
                }
            }
        }
        $summary[$gameName]=['uedb4'=>$v4,'uedb5'=>$v5,
            'uedb4_bytes'=>$gameBytes,'unpaired_uedb4'=>$gameUnpaired];
        $total4+=$v4;$total5+=$v5;$bytes+=$gameBytes;$unpaired+=$gameUnpaired;
    }
    ksort($summary,SORT_NUMERIC);
    echo json_encode(['ok'=>$unpaired===0,'read_only'=>true,'uedb4_count'=>$total4,
        'uedb5_count'=>$total5,'uedb4_bytes'=>$bytes,
        'uedb4_gib'=>round($bytes/1073741824,3),'unpaired_uedb4'=>$unpaired,
        'unpaired_samples'=>$samples,'games'=>$summary,
        'warning'=>'Matching names are not proof of V5 integrity or recoverability. No files were deleted.'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($unpaired===0?0:2);
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
