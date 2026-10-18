<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Domain/Package/CatalogUnrealPackageTag.php';
require_once dirname(__DIR__).'/src/Application/Catalog/CatalogPackageHeaderInspector.php';
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
$results=[];
foreach ([-8,-7,-9] as $legacy) {
    $data=pack('V',0x9E2A83C1).pack('V',$legacy & 0xFFFFFFFF).str_repeat("\0",512);
    $file=tempnam(sys_get_temp_dir(),'ue4legacy');
    file_put_contents($file,$data);
    try {
        $result=CatalogPackageHeaderInspector::inspect($file,['extension'=>'uasset']);
        $results[(string)$legacy]=['ok'=>$result['ok'],'error'=>$result['error'],'legacy_detected'=>str_contains($result['error']??'','legacy package-summary layout')];
    } finally {unlink($file);}
}
$checks=['newer_minus8_rejected'=>!$results['-8']['ok']&&$results['-8']['legacy_detected'],'newer_minus9_rejected'=>!$results['-9']['ok']&&$results['-9']['legacy_detected'],'supported_minus7_not_rejected_as_future'=>!$results['-7']['legacy_detected']];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'checks'=>$checks,'samples'=>$results],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:1);
