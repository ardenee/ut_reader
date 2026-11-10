<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Import\CatalogLegacyPakPackagePolicy as Policy;
$cases = [
    ['UE3','DM-Goop!.pak',true],
    ['UE3','DM-Goop!.ut3',false],
    ['UE4','DM-Goop!.pak',false],
    ['UE5','DM-Goop!.pak',false],
    ['UE2','DM-Goop!.pak',false],
];
foreach ($cases as [$engine,$name,$expected]) {
    if (Policy::isCandidate($engine,$name) !== $expected) {
        throw new RuntimeException('Profile routing regression: '.$engine.' '.$name);
    }
}
$good=tempnam(sys_get_temp_dir(),'ue3p');
$bad=tempnam(sys_get_temp_dir(),'ue3p');
try {
    if ($good === false || $bad === false) throw new RuntimeException('Test fixture file unavailable');
    file_put_contents($good, "\xC1\x83\x2A\x9E\x00\x02\x00\x00");
    file_put_contents($bad, "abcdef\n");
    Policy::assertPackageHeader($good);
    try {
        Policy::assertPackageHeader($bad);
        throw new RuntimeException('Invalid .pak accepted as package');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'missing UE3 package header')) throw $e;
    }
} finally {
    if (is_string($good)) @unlink($good);
    if (is_string($bad)) @unlink($bad);
}
$root=dirname(__DIR__,2);
$checks = [
    'catalog/api/v1/profiled-upload-batch.php' => ['CatalogLegacyPakPackagePolicy::assertPackageHeader($temporaryPath)','is_pak\' => !$legacyPackage'],
    'catalog/api/v1/profiled-upload-chunk.php' => ['CatalogLegacyPakPackagePolicy::assertPackageHeader((string)$completed[\'path\'])','is_pak\' => !$legacyPackage'],
    'catalog/src/Infrastructure/Import/CatalogProfiledUploadQueue.php' => ['CatalogLegacyPakPackagePolicy::assertPackageHeader($source)','JobType::IMPORT_STAGED_PACKAGE'],
    'catalog/assets/profiled-upload-jobs-core.js' => ['file.slice(0, 4).arrayBuffer()', 'batchEngineKey === \'UE3\''],
];
foreach($checks as $path=>$needles){
    $source=file_get_contents($root.'/'.$path);
    foreach($needles as $needle){
        if(!str_contains((string)$source,$needle)){
            throw new RuntimeException('Missing routing guard in '.$path.': '.$needle);
        }
    }
}
echo json_encode(['pass'=>true,'profile_cases'=>count($cases),'valid_header'=>true,'invalid_header_rejected'=>true,'batch_chunk_queue_browser_guards'=>true,'db_writes'=>0],JSON_PRETTY_PRINT).PHP_EOL;
