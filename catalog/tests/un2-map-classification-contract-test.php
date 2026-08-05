<?php
declare(strict_types=1);

function un2_map_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'game-files.php' => file_get_contents(__DIR__ . '/../game-files.php'),
    'file-info.php' => file_get_contents(__DIR__ . '/../file-info.php'),
    'duplicates.php' => file_get_contents(__DIR__ . '/../duplicates.php'),
];

foreach ($files as $name => $source) {
    un2_map_expect(is_string($source) && $source !== '', 'Could not read ' . $name . '.');
    un2_map_expect(
        str_contains($source, "'unr', 'un2', 'ut2', 'ut3', 'umap' => ['map', 'type-map']"),
        $name . ' does not classify .un2 as a map.'
    );
    un2_map_expect(
        str_contains($source, "'u', 'upk', 'uasset' => ['package', 'type-package']"),
        $name . ' package classification is not the expected script/container set.'
    );
    un2_map_expect(
        !str_contains($source, "'u', 'un2', 'upk', 'uasset' => ['package', 'type-package']"),
        $name . ' still classifies .un2 as a package.'
    );
}

foreach (['game-files.php', 'duplicates.php'] as $name) {
    $source = $files[$name];
    un2_map_expect(
        str_contains($source, "'map' => ['unr', 'un2', 'ut2', 'ut3', 'umap']"),
        $name . ' map filter does not include .un2.'
    );
    un2_map_expect(
        str_contains($source, "'package' => ['u', 'upk', 'uasset']"),
        $name . ' package filter is not the expected script/container set.'
    );
    un2_map_expect(
        !str_contains($source, "'package' => ['u', 'un2', 'upk', 'uasset']"),
        $name . ' package filter still includes .un2.'
    );
}

echo "UN2 map classification contract tests passed.\n";
