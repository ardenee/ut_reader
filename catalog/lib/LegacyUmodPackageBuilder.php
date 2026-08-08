<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for legacy UMOD-family binary generation and validation.
 * Why: Existing procedural callers retain stable modpkg_* functions while byte encoding/checksum policy lives under src/.
 * Role: Transitional writer/validator facade over CatalogUmodBinaryCodec and package descriptor policy.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedPackageDescriptor;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageInstallPathResolver;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUmodBinaryCodec;

/** @return array<int,int> */
function modpkg_unreal_mem_crc_table(): array
{
    return CatalogUmodBinaryCodec::unrealMemCrcTable();
}

function modpkg_unreal_mem_crc(string $data, int $seed = 0): int
{
    return CatalogUmodBinaryCodec::unrealMemCrc($data, $seed);
}

/** @param resource $handle */
function modpkg_unreal_mem_crc_stream($handle, int $length, int $seed = 0): int
{
    return CatalogUmodBinaryCodec::unrealMemCrcStream($handle, $length, $seed);
}

function modpkg_compatible_umod_path(string $path): string
{
    $path = CatalogPackageInstallPathResolver::normalizeRelativePath($path);
    if ($path === '') {
        throw new RuntimeException('UMOD archive entries require a valid relative path.');
    }
    return str_replace('/', '\\', $path);
}

/** @return array<string,string> */
function modpkg_compatible_umod_manifest(array $plan, array $options): array
{
    $productKey = CatalogGeneratedPackageDescriptor::productKey((string)$options['name']);
    $requirement = CatalogGeneratedPackageDescriptor::umodRequirement(
        $plan['game'],
        (string)$plan['format']
    );
    // Preserve the historical compatibility-manifest version rule: digits only.
    $manifestVersion = preg_replace('/[^0-9]+/', '', (string)$options['version']) ?: '1';
    $safeName = CatalogGeneratedPackageDescriptor::safeComponent((string)$options['name']);
    $setup = [
        '[Setup]',
        'Product=' . $productKey,
        'Version=' . $manifestVersion,
        'Requires=' . $requirement['section'],
        'Group=ModFiles',
        '',
        '[' . $requirement['section'] . ']',
        'Product=' . $requirement['product'],
        'Version=' . $requirement['version'],
        '',
        '[ModFiles]',
    ];
    foreach ($plan['files'] as $file) {
        $path = str_replace('/', '\\', (string)$file['install_path']);
        $setup[] = 'File=(Src=' . $path . ',Size=' . (int)$file['file_size'] . ')';
    }
    $setup[] = 'File=(Src=System\\UnrealDB-Mod.json)';
    $setup[] = 'File=(Src=System\\Readme-' . $safeName . '.txt)';
    $setup[] = '';

    $localized = [
        '[Setup]',
        'LocalProduct=' . (string)$options['name'],
        'Developer=' . (string)$options['author'],
        'SetupWindowTitle=' . (string)$options['name'] . ' Setup',
        '',
        '[ModFiles]',
        'Caption=' . (string)$options['name'],
        'Description=Installs ' . (string)$options['name'] . ' for ' . (string)$plan['game']['name'],
        '',
    ];

    $manifest = [
        'System/Manifest.ini' => implode("\r\n", $setup),
        'System/Manifest.int' => implode("\r\n", $localized),
        'System/UnrealDB-Mod.json' => CatalogGeneratedPackageDescriptor::json(
            CatalogGeneratedPackageDescriptor::metadata($plan, $options)
        ),
        'System/Readme-' . $safeName . '.txt' => CatalogGeneratedPackageDescriptor::readme($plan, $options),
    ];

    $compatible = [];
    foreach ($manifest as $path => $content) {
        $compatible[modpkg_compatible_umod_path((string)$path)] = (string)$content;
    }
    return $compatible;
}

/**
 * Write a UMOD/UT2MOD/UT4MOD accepted by the original Unreal Setup application.
 * Manifest entries use flags 3 and Windows archive separators.
 */
function modpkg_write_compatible_umod(string $outputPath, array $plan, array $options): array
{
    $entries = [];
    $handle = fopen($outputPath, 'w+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create the UMOD-family package.');
    }

    try {
        foreach ($plan['files'] as $file) {
            $path = modpkg_compatible_umod_path((string)$file['install_path']);
            $offset = ftell($handle);
            if ($offset === false) {
                throw new RuntimeException('Could not determine the UMOD payload offset.');
            }

            $input = fopen((string)$file['storage_path'], 'rb');
            if ($input === false) {
                throw new RuntimeException('Could not open ' . $file['original_name'] . ' for packaging.');
            }
            try {
                $copied = stream_copy_to_stream($input, $handle);
                if ($copied === false || $copied !== (int)$file['file_size']) {
                    throw new RuntimeException(
                        'Could not completely copy ' . $file['original_name'] . ' into the package.'
                    );
                }
            } finally {
                fclose($input);
            }

            $entries[] = [
                'filename' => $path,
                'offset' => (int)$offset,
                'size' => (int)$file['file_size'],
                'flags' => 0,
            ];
        }

        foreach (modpkg_compatible_umod_manifest($plan, $options) as $path => $content) {
            $offset = ftell($handle);
            if ($offset === false) {
                throw new RuntimeException('Could not determine the UMOD manifest offset.');
            }
            $written = fwrite($handle, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException('Could not write ' . $path . ' into the package.');
            }
            $manifestName = strtolower(str_replace('/', '\\', $path));
            $entries[] = [
                'filename' => $path,
                'offset' => (int)$offset,
                'size' => strlen($content),
                'flags' => in_array(
                    $manifestName,
                    ['system\\manifest.ini', 'system\\manifest.int'],
                    true
                ) ? 3 : 0,
            ];
        }

        $tableOffset = ftell($handle);
        if ($tableOffset === false) {
            throw new RuntimeException('Could not determine the UMOD directory offset.');
        }
        $table = CatalogUmodBinaryCodec::compactIndex(count($entries));
        foreach ($entries as $entry) {
            $table .= CatalogUmodBinaryCodec::ue1String((string)$entry['filename']);
            $table .= CatalogUmodBinaryCodec::packU32((int)$entry['offset']);
            $table .= CatalogUmodBinaryCodec::packU32((int)$entry['size']);
            $table .= CatalogUmodBinaryCodec::packU32((int)$entry['flags']);
        }
        $written = fwrite($handle, $table);
        if ($written === false || $written !== strlen($table) || !fflush($handle)) {
            throw new RuntimeException('Could not write the UMOD file table.');
        }

        $beforeFooterSize = ftell($handle);
        if ($beforeFooterSize === false) {
            throw new RuntimeException('Could not determine the UMOD archive size.');
        }
        $crc = CatalogUmodBinaryCodec::unrealMemCrcStream($handle, (int)$beforeFooterSize);
        $fileSize = (int)$beforeFooterSize + 20;
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException('Could not seek to the UMOD footer.');
        }
        $footer = CatalogUmodBinaryCodec::packU32(0x9FE3C5A3)
            . CatalogUmodBinaryCodec::packU32((int)$tableOffset)
            . CatalogUmodBinaryCodec::packU32($fileSize)
            . CatalogUmodBinaryCodec::packU32(1)
            . CatalogUmodBinaryCodec::packU32($crc);
        if (fwrite($handle, $footer) !== 20 || !fflush($handle)) {
            throw new RuntimeException('Could not write the UMOD footer.');
        }
    } finally {
        fclose($handle);
    }

    $validation = modpkg_validate_compatible_umod($outputPath);
    if (!$validation['ok']) {
        throw new RuntimeException(
            'Generated UMOD validation failed: ' . implode('; ', $validation['errors'])
        );
    }
    return $validation;
}

function modpkg_validate_compatible_umod(string $path): array
{
    $errors = [];
    $entries = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [
            'ok' => false,
            'errors' => ['Could not open generated package'],
            'entries' => [],
            'file_count' => 0,
        ];
    }

    try {
        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize < 20 || fseek($handle, -20, SEEK_END) !== 0) {
            return [
                'ok' => false,
                'errors' => ['Package is too small'],
                'entries' => [],
                'file_count' => 0,
            ];
        }
        $footerBytes = fread($handle, 20);
        if ($footerBytes === false || strlen($footerBytes) !== 20) {
            return [
                'ok' => false,
                'errors' => ['Could not read package footer'],
                'entries' => [],
                'file_count' => 0,
            ];
        }
        $footer = unpack('Vmagic/Vtable/Vsize/Vversion/Vcrc', $footerBytes);
        if ((int)$footer['magic'] !== 0x9FE3C5A3) {
            $errors[] = 'Bad archive magic';
        }
        if ((int)$footer['version'] !== 1) {
            $errors[] = 'Unsupported archive version';
        }
        if ((int)$footer['size'] !== (int)$fileSize) {
            $errors[] = 'Archive size footer mismatch';
        }
        $tableOffset = (int)$footer['table'];
        if ($tableOffset < 0 || $tableOffset >= (int)$fileSize - 20) {
            $errors[] = 'Bad archive table offset';
        }

        if (!$errors) {
            $actualCrc = CatalogUmodBinaryCodec::unrealMemCrcStream(
                $handle,
                (int)$fileSize - 20
            );
            if (($actualCrc & 0xFFFFFFFF) !== ((int)$footer['crc'] & 0xFFFFFFFF)) {
                $errors[] = 'Archive CRC mismatch';
            }
        }

        if (!$errors) {
            $tableLength = ((int)$fileSize - 20) - $tableOffset;
            if (fseek($handle, $tableOffset, SEEK_SET) !== 0) {
                throw new RuntimeException('Could not seek to the UMOD directory.');
            }
            $table = '';
            $remaining = $tableLength;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Could not completely read the UMOD directory.');
                }
                $table .= $chunk;
                $remaining -= strlen($chunk);
            }

            $offset = 0;
            $count = CatalogUmodBinaryCodec::readCompactIndex($table, $offset);
            if ($count < 0 || $count > 100000) {
                throw new RuntimeException('Invalid archive item count.');
            }
            for ($index = 0; $index < $count; $index++) {
                $filename = CatalogUmodBinaryCodec::readUe1String($table, $offset);
                if ($offset + 12 > strlen($table)) {
                    throw new RuntimeException('Truncated archive item.');
                }
                $item = unpack('Voffset/Vsize/Vflags', substr($table, $offset, 12));
                $offset += 12;
                $itemOffset = (int)$item['offset'];
                $itemSize = (int)$item['size'];
                if ($itemOffset < 0
                    || $itemSize < 0
                    || $itemOffset + $itemSize > $tableOffset) {
                    throw new RuntimeException(
                        'Archive item points outside the payload: ' . $filename
                    );
                }
                $entries[] = [
                    'filename' => $filename,
                    'offset' => $itemOffset,
                    'size' => $itemSize,
                    'flags' => (int)$item['flags'],
                ];
            }

            $byName = [];
            foreach ($entries as $entry) {
                $byName[strtolower(str_replace('/', '\\', (string)$entry['filename']))] = $entry;
            }
            foreach (['system\\manifest.ini', 'system\\manifest.int'] as $manifestName) {
                if (!isset($byName[$manifestName])) {
                    $errors[] = basename(str_replace('\\', '/', $manifestName)) . ' is missing';
                } elseif ((int)$byName[$manifestName]['flags'] !== 3) {
                    $errors[] = basename(str_replace('\\', '/', $manifestName))
                        . ' has invalid UMOD flags';
                }
            }
        }
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    } finally {
        fclose($handle);
    }

    return [
        'ok' => !$errors,
        'errors' => $errors,
        'entries' => $entries,
        'file_count' => count($entries),
    ];
}
