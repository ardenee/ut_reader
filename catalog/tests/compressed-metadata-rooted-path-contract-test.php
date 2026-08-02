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

    echo "Compressed metadata rooted-path contract passed.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compressed metadata rooted-path contract failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
