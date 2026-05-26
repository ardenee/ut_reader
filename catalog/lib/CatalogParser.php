<?php
declare(strict_types=1);

function catalog_load_reader_class(array $config, string $engineKey): string
{
    $readerConfig = $config['engine_readers'][$engineKey] ?? [];

    if ($engineKey === 'UE3') {
        $catalogReader = realpath(__DIR__ . '/../parsers/UE3CatalogReader.php');
        if ($catalogReader && is_file($catalogReader)) {
            require_once $catalogReader;
            if (class_exists('CatalogUE3PackageReader', false)) {
                return 'CatalogUE3PackageReader';
            }
        }
    }

    $rel = $readerConfig['reader'] ?? '';
    $path = realpath(__DIR__ . '/../' . $rel);
    if (!$path || !is_file($path)) {
        throw new RuntimeException('Reader not found for ' . $engineKey . ': ' . $rel);
    }

    require_once $path;

    $candidates = [];
    if (!empty($readerConfig['class'])) {
        $candidates[] = (string)$readerConfig['class'];
    }
    $candidates[] = match ($engineKey) {
        'UE4' => 'UnrealPackageReader4',
        default => 'UnrealPackageReader',
    };
    $candidates[] = 'UnrealPackageReader';
    $candidates[] = 'UnrealPackageReader4';

    foreach (array_unique($candidates) as $class) {
        if ($class !== '' && class_exists($class, false)) {
            return $class;
        }
    }

    throw new RuntimeException('Reader file loaded for ' . $engineKey . ', but no supported reader class was found.');
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
