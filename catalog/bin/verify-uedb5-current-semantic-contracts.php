#!/usr/bin/env php
<?php
/**
 * Executable V5-source closeout contract group, deliberately excluding V4
 * SQL-projection fixtures. Fail on any missing or failing contract.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
$root=dirname(__DIR__);
$tests=[
 'verify-ue1-verify-import-profile-contract.php',
 'verify-ue2-profile-verify-import-contract.php',
 'verify-uedb5-unreal-classic-dependency-resolution.php',
 'verify-uedb5-ut99-persistence.php',
 'verify-uedb5-ut3-persistence.php',
 'verify-uedb5-ut4-persistence.php',
 'verify-uedb5-dependency-rebuilder.php',
 'verify-uedb5-dependency-projection-publisher.php',
 'verify-uedb5-parity-targeted-search.php',
 'verify-uedb5-ue5-classic-dependency-resolution.php',
 'verify-uedb5-ue5-iostore-foundation.php',
 'verify-uedb5-ue5-zen-package.php',
 'verify-uedb5-ue5-zen-dependency-resolution.php',
];
$results=[];
foreach($tests as $file){
    $path=$root.'/bin/'.$file;
    if(!is_file($path)){ $results[]=['contract'=>$file,'ok'=>false,'error'=>'missing'];continue; }
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($path);
    $lines=[];$exit=0;exec($command.' 2>&1',$lines,$exit);
    $joined=implode("\n",$lines);
    $decoded=json_decode($joined,true);
    $ok=$exit===0 && is_array($decoded) && ($decoded['ok']??false)===true;
    $results[]=['contract'=>$file,'ok'=>$ok,'exit'=>$exit,
        'failures'=>is_array($decoded)?($decoded['failures']??[]):[],
        'output_tail'=>$ok?null:substr($joined,-700)];
    echo json_encode(end($results),JSON_UNESCAPED_SLASHES),PHP_EOL;
    flush();
}
$passed=count(array_filter($results,static fn(array $r):bool=>$r['ok']));
echo json_encode(['ok'=>$passed===count($results),'passed'=>$passed,'total'=>count($results),'scope'=>'V5 semantic contracts, not catalogue-wide source parity or live Zen ingestion'],JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($passed===count($results)?0:2);
