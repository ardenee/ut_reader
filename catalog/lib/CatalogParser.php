<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogReaderResolver.php';

function catalog_load_reader_class(array $config, string $engineKey): string
{
    return CatalogReaderResolver::resolve(
        $config,
        $engineKey,
        'Reader not found for',
        'Reader file loaded for ',
        ['UE4'],
        false
    );
}

function catalog_try_read_package_header(array $config, string $engineKey, string $path): array
{
    $class = catalog_load_reader_class($config, $engineKey);
    $reader = new $class($path);
    if (!method_exists($reader, 'getHeader')) {
        throw new RuntimeException('Reader missing getHeader()');
    }
    $header = $reader->getHeader();
    if (!is_array($header)) {
        throw new RuntimeException('Reader returned invalid header');
    }
    return $header;
}

function catalog_header_guid(array $header): string
{
    return trim((string)($header['guid'] ?? $header['GUID'] ?? $header['packageGuid'] ?? ''));
}
