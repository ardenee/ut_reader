#!/usr/bin/env php
<?php
declare(strict_types=1);

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
require_once $root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UnrealSnapshotBuilder;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};
$u32=static fn(int $v):string=>pack('V',$v&0xFFFFFFFF);
$compact=static function(int $value):string{$neg=$value<0;$m=abs($value);$first=$m&0x3F;$m>>=6;if($neg)$first|=0x80;if($m!==0)$first|=0x40;$out=chr($first);while($m!==0){$n=$m&0x7F;$m>>=7;if($m!==0)$n|=0x80;$out.=chr($n);}return$out;};

$fixture=static function(int $serializedCount)use($u32,$compact):string{
    $effective=max(0,min($serializedCount,64));
    $summarySize=56+($effective*8);
    $header=$u32(0x9E2A83C1).$u32(69).$u32(0)
        .$u32(1).$u32($summarySize)
        .$u32(0).$u32(0)
        .$u32(0).$u32(0)
        .$u32(1).$u32(2).$u32(3).$u32(4)
        .$u32($serializedCount);
    for($i=0;$i<$effective;$i++){$header.=$u32($i).$u32($i+1);}
    return $header.$compact(5)."Test\0".$u32(3);
};

foreach([65=>64,-1=>0] as $serialized=>$effective){
    $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'unrealdb-v227-generation-'.str_replace('-','n',(string)$serialized).'-'.bin2hex(random_bytes(4)).'.u';
    file_put_contents($path,$fixture($serialized));
    try{
        $reader=new CatalogUE1PackageReader($path,true);
        $check("generation_{$serialized}_parses",$reader->validatePackage()===[]);
        $header=$reader->getHeader();
        $check("generation_{$serialized}_preserves_serialized_count",(int)($header['genCount']??999)===$serialized);
        $check("generation_{$serialized}_uses_v227_effective_clamp",(int)($header['effectiveGenCount']??999)===$effective&&count((array)($header['generations']??[]))===$effective);
        $snapshot=Uedb5UnrealSnapshotBuilder::build($reader,[
            'id'=>227000+abs($serialized),'game_id'=>12,'package_name'=>'V227Generation','original_name'=>'V227Generation.u',
        ]);
        $summary=(array)($snapshot['sections']['summary'][0]??[]);
        $check("generation_{$serialized}_v5_keeps_both_counts",
            (int)($summary['generation_count']??999)===$serialized
            &&(int)($summary['effective_generation_count']??999)===$effective);
    }finally{@unlink($path);}
}

$ok=$failures===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
