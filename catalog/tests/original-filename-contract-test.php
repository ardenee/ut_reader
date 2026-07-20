<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;

$reflection = new ReflectionClass(CatalogIncomingFileStore::class);
$store = $reflection->newInstanceWithoutConstructor();
$logicalName = $reflection->getMethod('logicalName');
$logicalName->setAccessible(true);

$cases = [
    'DM-{UEM}-OldGlory.ut2' => 'DM-{UEM}-OldGlory.ut2',
    'DM-{UEM}-OldGlory (2).ut2' => 'DM-{UEM}-OldGlory.ut2',
    'DM-{UEM}-OldGlory.ut2 (2)' => 'DM-{UEM}-OldGlory.ut2',
    '[FF$]Soundspack1.uax (2).uz2' => '[FF$]Soundspack1.uax.uz2',
    'CTF-[Clan]{Arena}+$Mix.ut2' => 'CTF-[Clan]{Arena}+$Mix.ut2',
    'Some  Package.utx' => 'Some  Package.utx',
];

foreach ($cases as $input => $expected) {
    $actual = (string)$logicalName->invoke($store, $input);
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Original filename mismatch for [' . $input . ']: expected [' . $expected . '], got [' . $actual . ']'
        );
    }
}

fwrite(STDOUT, "Original filename contract tests passed.\n");
