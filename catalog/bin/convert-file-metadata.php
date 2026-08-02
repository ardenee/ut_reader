#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedFileMetadataConverter;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

function compact_metadata_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/convert-file-metadata.php --file-id=12345\n");
    fwrite(STDOUT, "  php catalog/bin/convert-file-metadata.php --verify-file-id=12345\n");
}

/** @return array{file_id:int,verify_file_id:int} */
function compact_metadata_arguments(array $arguments): array
{
    $fileId = 0;
    $verifyFileId = 0;
    foreach ($arguments as $argument) {
        $argument = trim((string)$argument);
        if (in_array($argument, ['--help', '-h', 'help'], true)) {
            compact_metadata_usage();
            exit(0);
        }
        if (str_starts_with($argument, '--file-id=')) {
            $fileId = (int)substr($argument, strlen('--file-id='));
            continue;
        }
        if (str_starts_with($argument, '--verify-file-id=')) {
            $verifyFileId = (int)substr($argument, strlen('--verify-file-id='));
            continue;
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    if (($fileId > 0) === ($verifyFileId > 0)) {
        throw new InvalidArgumentException('Specify exactly one of --file-id or --verify-file-id.');
    }
    return ['file_id' => $fileId, 'verify_file_id' => $verifyFileId];
}

try {
    $arguments = compact_metadata_arguments(array_slice($argv, 1));
    $config = catalog_config();
    $storagePath = trim((string)($config['storage_path'] ?? ''));
    if ($storagePath === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }

    $converter = new BlockedCompressedFileMetadataConverter(catalog_db($config), $storagePath);
    $result = $arguments['file_id'] > 0
        ? $converter->convert($arguments['file_id'])
        : $converter->verify($arguments['verify_file_id']);

    fwrite(
        STDOUT,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . PHP_EOL
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compressed metadata command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
