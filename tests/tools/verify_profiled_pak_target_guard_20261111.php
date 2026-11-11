<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Import\CatalogLegacyPakPackagePolicy as Policy;
$temp=[];
try {
    $good=tempnam(sys_get_temp_dir(),'ut3-');
    $pak=tempnam(sys_get_temp_dir(),'ut4-');
    $empty=tempnam(sys_get_temp_dir(),'bad-');
    foreach([$good,$pak,$empty] as $v)if(!is_string($v)) throw new RuntimeException('tempfile failed');
    $temp=[$good,$pak,$empty];
    file_put_contents($good,"\xC1\x83\x2A\x9E\x00\x02\x00\x00".str_repeat("\0",56));
    file_put_contents($empty,"abcdef\n");
    $footer=pack('V',0x5A6F12E1).pack('V',3).pack('V2',0,0).pack('V2',16,0).str_repeat("\xA5",20);
    file_put_contents($pak,str_repeat("\x00",212).$footer);
    Policy::assertTarget($good,'DM-Goop!.pak','UE3');
    Policy::assertTarget($pak,'DM-Map-WindowsNoEditor.pak','UE4');
    Policy::assertTarget($pak,'DM-Map-WindowsNoEditor.pak','UE5');
    $rejected=0;
    foreach([
        [$good,'DM-Goop!.pak','UE4','Wrong game for .pak'],
        [$good,'DM-Goop!.pak','UE5','Wrong game for .pak'],
        [$pak,'UT4.pak','UE3','missing UE3 package header'],
        [$empty,'empty.pak','UE3','missing UE3 package header'],
        [$empty,'empty.pak','UE4','PAK file is too small'],
        [$pak,'UT4.pak','UE2','does not accept .pak'],
    ] as [$path,$name,$engine,$expected]) {
        try {
            Policy::assertTarget($path,$name,$engine);
            throw new RuntimeException('Wrong target was accepted: '.$name.'/'.$engine);
        } catch (RuntimeException $error) {
            if(!str_contains($error->getMessage(),$expected))throw $error;
            $rejected++;
        }
    }
    $source=(string)file_get_contents(dirname(__DIR__,2).'/catalog/api/v1/profiled-upload-batch.php');
    $chunk=(string)file_get_contents(dirname(__DIR__,2).'/catalog/api/v1/profiled-upload-chunk.php');
    $queue=(string)file_get_contents(dirname(__DIR__,2).'/catalog/src/Infrastructure/Import/CatalogProfiledUploadQueue.php');
    foreach([$source,$chunk,$queue] as $i=>$s) {
        if(!str_contains($s,'CatalogLegacyPakPackagePolicy::assertTarget(')) throw new RuntimeException('Ingress guard missing on path '.$i);
    }
    echo json_encode(['pass'=>true,'accepted'=>3,'rejected'=>$rejected,'batch_chunk_nonbatch_guarded'=>true,'database_writes'=>0],JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    foreach($temp as $path)@unlink($path);
}
