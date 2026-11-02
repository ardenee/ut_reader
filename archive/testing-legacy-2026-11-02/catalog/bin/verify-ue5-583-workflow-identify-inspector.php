<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
$path='H:/UE5-583-runtime-probe/Engine/Content/Animation/DefaultAnimBoneCompressionSettings.uasset';
if(!is_file($path))throw new RuntimeException('Pinned UE5 real classic fixture missing');
$raw=file_get_contents($path,false,null,0,12);
$legacy=unpack('V',substr($raw,4,4))[1];if($legacy>=0x80000000)$legacy-=0x100000000;
$actual=CatalogPackageHeaderInspector::inspect($path,['extension'=>'uasset']);
$checks=['authentic_ue5_legacy_minus8'=>$legacy===-8,'inspector_success'=>($actual['ok']??false)===true,
 'displayed_engine_is_ue5'=>($actual['summary']['Build']??null)==='UE5',
 'matches_file_legacy_version'=>($actual['summary']['Legacy File Version']??null)===$legacy,
 'has_decoded_counts'=>str_starts_with((string)($actual['summary']['Counts']??''),'N '),
 'has_ue5_version'=>(int)($actual['summary']['UE5 File Version']??0)>=1000];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'checks'=>$checks,'version'=>$actual['summary']['UE5 File Version']??null,'error'=>$actual['error']??null],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
