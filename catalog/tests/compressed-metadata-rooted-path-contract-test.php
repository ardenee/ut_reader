#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\CompressedMetadataLegacySnapshot;

require_once __DIR__ . '/../bootstrap/autoload.php';

try {
    $reflection = new ReflectionClass(CompressedMetadataLegacySnapshot::class);
    $snapshot = $reflection->newInstanceWithoutConstructor();
    $joinPath = $reflection->getMethod('joinPath');
    $joinPath->setAccessible(true);

    $cases = [
        [['Engine', 'Default_Actor'], 'Engine.Default_Actor'],
        [['/Script/Engine', 'TextureCube'], '/Script/Engine.TextureCube'],
        [['/Game/Maps/Test', 'PersistentLevel', 'Actor_0'], '/Game/Maps/Test.PersistentLevel.Actor_0'],
        [['\\Script\\Engine', 'TextureCube'], '/Script/Engine.TextureCube'],
        [['', '/Script//Engine.', '.TextureCube.'], '/Script/Engine.TextureCube'],
    ];

    foreach ($cases as [$parts, $expected]) {
        $actual = $joinPath->invoke($snapshot, $parts);
        if ($actual !== $expected) {
            throw new RuntimeException(
                'Path mismatch for ' . json_encode($parts, JSON_UNESCAPED_SLASHES)
                . ': expected "' . $expected . '", got "' . (string)$actual . '".'
            );
        }
    }

    $validatePaths = $reflection->getMethod('validatePaths');
    $validatePaths->setAccessible(true);
    $paths = $validatePaths->invoke(
        $snapshot,
        'KittysSymbols',
        [],
        [
            [
                'export_index' => 0,
                'object_name' => 'Group1',
                'outer_index' => 0,
                'local_path' => 'Group1',
                'full_path' => 'KittysSymbols.Group1',
            ],
            [
                'export_index' => 4,
                'object_name' => '',
                'outer_index' => 1,
                'local_path' => 'Group1./',
                'full_path' => 'KittysSymbols.Group1./',
            ],
        ]
    );
    if (($paths['exports'][4]['local'] ?? null) !== 'Group1./') {
        throw new RuntimeException('Legacy Export local-path override was not preserved.');
    }
    if (($paths['exports'][4]['full'] ?? null) !== 'KittysSymbols.Group1./') {
        throw new RuntimeException('Legacy Export full-path override was not preserved.');
    }
    if ((int)($paths['override_count'] ?? 0) !== 1) {
        throw new RuntimeException('Expected exactly one legacy path override.');
    }

    echo "Compressed metadata rooted-path and override contract passed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compressed metadata rooted-path contract failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
