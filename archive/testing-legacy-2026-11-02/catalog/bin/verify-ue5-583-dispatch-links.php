<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[
 ['UE5 canonical reader','src/Infrastructure/Readers/CatalogReaderResolver.php','UnrealPackageReader5'],
 ['UE5 classic source snapshot','src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php','Uedb5Ue5ClassicSnapshotBuilder::build'],
 ['UE5 dedicated Zen snapshot','src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php','class Uedb5Ue5ZenIoStoreSnapshotBuilder'],
 ['V5 source publication','src/Infrastructure/Metadata/Uedb5VerifiedFilePublisher.php','buildForGameId('],
 ['V5 search dispatch','src/Infrastructure/Search/PdoCatalogSearchRepository.php','new Uedb5CatalogMetadataSearch(']
];
$fail=[];foreach($checks as [$name,$file,$symbol]){if(!str_contains((string)@file_get_contents($root.'/'.$file),$symbol))$fail[]=$name;}
echo json_encode(['ok'=>!$fail,'checks'=>count($checks),'failures'=>$fail,'scope'=>'UE5 dispatch link subset only'],JSON_PRETTY_PRINT),PHP_EOL;
exit($fail?2:0);
