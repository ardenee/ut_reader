<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies original filename behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../lib/CatalogScanner.php';

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

$packageCases = [
    'DM-{aNtiBot}-Defance.ut2' => 'DM-{aNtiBot}-Defance',
    'DM-{UEM}-OldGlory (2).ut2' => 'DM-{UEM}-OldGlory',
    'DM-{UEM}-OldGlory.ut2 (2)' => 'DM-{UEM}-OldGlory',
    '[FF$]Soundspack1.uax' => '[FF$]Soundspack1',
    'CTF-[Clan]{Arena}+$Mix.ut2' => 'CTF-[Clan]{Arena}+$Mix',
];

foreach ($packageCases as $input => $expected) {
    $actual = scanner_logical_package_name($input);
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Legacy package-name mismatch for [' . $input . ']: expected [' . $expected . '], got [' . $actual . ']'
        );
    }
}

fwrite(STDOUT, "Original filename and legacy package-name contract tests passed.\n");
