#!/usr/bin/env php
<?php
/**
 * Deletes obsolete compact metadata files after a clean format cutover.
 *
 * Safety rule for future v5+: never delete the immediately previous format
 * until the database has zero registrations for that format and the current
 * format has been verified.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$options = getopt('', ['apply', 'extensions::', 'max::']);
$apply = array_key_exists('apply', $options);
$extensionsRaw = trim((string)($options['extensions'] ?? 'uedb2,uedb3'));
$max = isset($options['max']) ? max(1, (int)$options['max']) : PHP_INT_MAX;

$extensions = [];
foreach (preg_split('/[\s,;]+/', $extensionsRaw) ?: [] as $extension) {
    $extension = strtolower(trim($extension, " .\t\r\n"));
    if (in_array($extension, ['uedb2', 'uedb3'], true)) {
        $extensions[$extension] = true;
    }
}
if ($extensions === []) {
    throw new InvalidArgumentException('--extensions must contain uedb2 and/or uedb3.');
}

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = rtrim((string)($config['storage_path'] ?? ''), "\\/");
if ($storageRoot === '') {
    throw new RuntimeException('catalog.storage_path is required.');
}

if (isset($extensions['uedb3'])) {
    $remainingV3 = (int)$db->query(
        'SELECT COUNT(*) FROM ue_file_metadata WHERE format_version=3'
    )->fetchColumn();
    if ($remainingV3 > 0) {
        throw new RuntimeException(
            'Refusing to delete .uedb3 files while ' . $remainingV3
            . ' database metadata registrations still use format 3.'
        );
    }
}

$metadataRoot = $storageRoot . DIRECTORY_SEPARATOR . 'metadata';
if (!is_dir($metadataRoot)) {
    throw new RuntimeException('Metadata directory does not exist: ' . $metadataRoot);
}

$matched = 0;
$deleted = 0;
$failed = 0;
$bytes = 0;
$sample = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($metadataRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }
    $extension = strtolower($fileInfo->getExtension());
    if (!isset($extensions[$extension])) {
        continue;
    }
    if ($matched >= $max) {
        break;
    }

    $matched++;
    $path = $fileInfo->getPathname();
    $size = (int)$fileInfo->getSize();
    $bytes += $size;

    if (count($sample) < 100) {
        $sample[] = [
            'path' => $path,
            'extension' => $extension,
            'size' => $size,
            'action' => $apply ? 'delete' : 'would_delete',
        ];
    }

    if (!$apply) {
        continue;
    }
    if (@unlink($path)) {
        $deleted++;
    } else {
        $failed++;
    }
}

echo json_encode([
    'ok' => $failed === 0,
    'apply' => $apply,
    'extensions' => array_keys($extensions),
    'matched' => $matched,
    'deleted' => $deleted,
    'failed' => $failed,
    'bytes' => $bytes,
    'sample' => $sample,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === 0 ? 0 : 2);
