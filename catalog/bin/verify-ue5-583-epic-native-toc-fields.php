<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$toc='C:/Temp/uedb5-iostore-retention-test/games/unreal2/iostore/63e4f0e322276684718563e13619474c8cf5981b29e15e502620a4373e351ab5/FactoryGame-WindowsServer.utoc';
if(!is_file($exe)||!is_file($toc))throw new RuntimeException('Pinned native test prerequisites missing');
$ours=new Uedb5IoStoreTocReader($toc);
$lines=[];$exit=0;
exec(escapeshellarg($exe).' -TocProbe='.escapeshellarg($toc).' -unattended 2>&1',$lines,$exit);
$output=str_replace('\\n',"\n",implode("\n",$lines));
if(!in_array($exit,[0,777006],true))throw new RuntimeException('Epic native TOC command failed: '.$exit);
if(!preg_match('/^UE583_NATIVE_TOC_ORACLE ([0-9 ]+)$/m',$output,$found))throw new RuntimeException('Epic native TOC read marker missing');
$values=array_map('intval',explode(' ',trim($found[1])));
$h=$ours->header();$c=$ours->chunks();$blocks=$ours->compressionBlocks();$metas=$ours->chunkMetas();
$expected=[
 (int)$h['version'],(int)$h['toc_header_size'],(int)$h['toc_entry_count'],
 (int)$h['compressed_block_entry_count'],12,(int)$h['compression_method_name_count'],
 (int)$h['compression_method_name_length'],(int)$h['compression_block_size'],
 (int)$h['directory_index_size'],(int)$h['partition_count'],
 (int)$h['perfect_hash_seed_count'],(int)$h['chunks_without_perfect_hash_count'],
 count($c),count($c),count($blocks),count($ours->compressionMethods()),
 strlen($ours->directoryIndexBytes()),count($metas),0
];
$compared=0;$errors=[];
if(count($values)!==count($expected))throw new RuntimeException('Unexpected native header field count');
foreach($expected as $i=>$v){if($values[$i]!==$v)$errors[]="TOC field $i native=$values[$i] UnrealDB=$v";$compared++;}
preg_match_all('/^UE583_NATIVE_TOC_CHUNK (\d+) (\d+) (\d+) (\d+)$/m',$output,$nChunks,PREG_SET_ORDER);
if(count($nChunks)!==count($c))$errors[]='Native TOC chunk count differs from complete UnrealDB table';
foreach($nChunks as $entry){
 $i=(int)$entry[1];$row=$c[$i]??[];
 foreach(['offset','length','chunk_type'] as $j=>$key){$native=(int)$entry[$j+2];$local=(int)($row[$key]??-1);if($native!==$local)$errors[]="Chunk[$i].$key native=$native UnrealDB=$local";$compared++;}
}
preg_match_all('/^UE583_NATIVE_TOC_BLOCK (\d+) (\d+) (\d+) (\d+) (\d+)$/m',$output,$nBlocks,PREG_SET_ORDER);
if(count($nBlocks)!==count($blocks))$errors[]='Native TOC compression-block count differs from complete UnrealDB table';
foreach($nBlocks as $entry){
 $i=(int)$entry[1];$row=$blocks[$i]??[];
 foreach(['offset','compressed_size','uncompressed_size','compression_method_index'] as $j=>$key){$native=(int)$entry[$j+2];$local=(int)($row[$key]??-1);if($native!==$local)$errors[]="Block[$i].$key native=$native UnrealDB=$local";$compared++;}
}
$ok=$errors===[];echo json_encode(['ok'=>$ok,'epic_native_toc_version'=>$values[0],'chunk_count'=>count($c),'block_count'=>count($blocks),'independent_field_comparisons'=>$compared,'failures'=>array_slice($errors,0,25)],JSON_PRETTY_PRINT),PHP_EOL;exit($ok?0:2);
