<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides compatibility writer functions for generated package artifacts.
 * Why: Binary writers remain procedural for compatibility while descriptor policy lives under src/.
 * Role: Transitional writer facade over namespaced descriptor policy and existing binary codecs/validators.
 */
declare(strict_types=1);

require_once __DIR__ . '/ModPackageBuilder.php';
require_once __DIR__ . '/LegacyUmodPackageBuilder.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedPackageDescriptor;

function modpkg_generated_version(mixed $value): string
{
    return CatalogGeneratedPackageDescriptor::generatedVersion($value);
}

/** @return array<string,string> */
function modpkg_generated_umod_manifest(array $plan, array $options): array
{
    $productKey = CatalogGeneratedPackageDescriptor::productKey((string)$options['name']);
    $requirement = CatalogGeneratedPackageDescriptor::umodRequirement(
        $plan['game'],
        (string)$plan['format']
    );
    $version = CatalogGeneratedPackageDescriptor::generatedVersion($options['version'] ?? '1.0');
    $safeName = CatalogGeneratedPackageDescriptor::safeComponent((string)$options['name']);
    $setup = [
        '[Setup]',
        'Product=' . $productKey,
        'Version=' . $version,
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

    return [
        'System\\Manifest.ini' => implode("\r\n", $setup),
        'System\\Manifest.int' => implode("\r\n", $localized),
        'System\\UnrealDB-Mod.json' => CatalogGeneratedPackageDescriptor::json(
            CatalogGeneratedPackageDescriptor::metadata($plan, $options)
        ),
        'System\\Readme-' . $safeName . '.txt' => CatalogGeneratedPackageDescriptor::readme($plan, $options),
    ];
}

function modpkg_write_generated_umod(string $outputPath, array $plan, array $options): array
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
                    throw new RuntimeException('Could not completely copy ' . $file['original_name'] . ' into the package.');
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

        foreach (modpkg_generated_umod_manifest($plan, $options) as $path => $content) {
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
                'flags' => in_array($manifestName, ['system\\manifest.ini', 'system\\manifest.int'], true) ? 3 : 0,
            ];
        }

        $tableOffset = ftell($handle);
        if ($tableOffset === false) {
            throw new RuntimeException('Could not determine the UMOD directory offset.');
        }
        $table = modpkg_compact_index(count($entries));
        foreach ($entries as $entry) {
            $table .= modpkg_ue1_string((string)$entry['filename']);
            $table .= modpkg_pack_u32((int)$entry['offset']);
            $table .= modpkg_pack_u32((int)$entry['size']);
            $table .= modpkg_pack_u32((int)$entry['flags']);
        }
        $written = fwrite($handle, $table);
        if ($written === false || $written !== strlen($table) || !fflush($handle)) {
            throw new RuntimeException('Could not write the UMOD file table.');
        }

        $beforeFooterSize = ftell($handle);
        if ($beforeFooterSize === false) {
            throw new RuntimeException('Could not determine the UMOD archive size.');
        }
        $crc = modpkg_unreal_mem_crc_stream($handle, (int)$beforeFooterSize);
        $fileSize = (int)$beforeFooterSize + 20;
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException('Could not seek to the UMOD footer.');
        }
        $footer = modpkg_pack_u32(0x9FE3C5A3)
            . modpkg_pack_u32((int)$tableOffset)
            . modpkg_pack_u32($fileSize)
            . modpkg_pack_u32(1)
            . modpkg_pack_u32($crc);
        if (fwrite($handle, $footer) !== 20 || !fflush($handle)) {
            throw new RuntimeException('Could not write the UMOD footer.');
        }
    } finally {
        fclose($handle);
    }

    $validation = modpkg_validate_compatible_umod($outputPath);
    if (!$validation['ok']) {
        throw new RuntimeException('Generated UMOD validation failed: ' . implode('; ', $validation['errors']));
    }
    $validation['package_version'] = CatalogGeneratedPackageDescriptor::generatedVersion(
        $options['version'] ?? '1.0'
    );
    return $validation;
}

function modpkg_write_payload_zip(string $outputPath, array $plan): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is required for ZIP package exports.');
    }

    $zip = new ZipArchive();
    if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the package ZIP.');
    }

    $closed = false;
    try {
        foreach ($plan['files'] as $file) {
            if (!$zip->addFile((string)$file['storage_path'], (string)$file['install_path'])) {
                throw new RuntimeException('Could not add ' . $file['original_name'] . ' to the ZIP.');
            }
        }
        $closed = $zip->close();
    } finally {
        if (!$closed) {
            @$zip->close();
        }
    }
    if (!$closed) {
        throw new RuntimeException('Could not finalize the package ZIP.');
    }

    $validation = modpkg_validate_payload_zip($outputPath, $plan);
    if (!$validation['ok']) {
        throw new RuntimeException('Generated ZIP validation failed: ' . implode('; ', $validation['errors']));
    }
    return $validation;
}

function modpkg_validate_payload_zip(string $path, array $plan): array
{
    $errors = [];
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'errors' => ['ZipArchive unavailable'], 'file_count' => 0];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'errors' => ['Could not reopen generated ZIP'], 'file_count' => 0];
    }
    try {
        if ($zip->numFiles !== count($plan['files'])) {
            $errors[] = 'ZIP contains files other than the selected package payload.';
        }
        foreach ($plan['files'] as $file) {
            $stat = $zip->statName((string)$file['install_path']);
            if ($stat === false) {
                $errors[] = 'Missing entry ' . $file['install_path'];
            } elseif ((int)$stat['size'] !== (int)$file['file_size']) {
                $errors[] = 'Size mismatch for ' . $file['install_path'];
            }
        }
        foreach (['UnrealDB-Mod.json', 'Readme.txt'] as $unwanted) {
            if ($zip->locateName($unwanted, ZipArchive::FL_NOCASE) !== false) {
                $errors[] = 'Unexpected generated text file ' . $unwanted;
            }
        }
    } finally {
        $zip->close();
    }
    return ['ok' => !$errors, 'errors' => $errors, 'file_count' => count($plan['files'])];
}

function modpkg_build_generated_package(
    string $outputPath,
    array $plan,
    array $options,
    array $settings
): array {
    return match ((string)$plan['format']) {
        MODPKG_FORMAT_DEPENDENCY_ZIP, MODPKG_FORMAT_UT3_ZIP => modpkg_write_payload_zip($outputPath, $plan),
        MODPKG_FORMAT_UMOD, MODPKG_FORMAT_UT2MOD, MODPKG_FORMAT_UT4MOD => modpkg_write_generated_umod($outputPath, $plan, $options),
        MODPKG_FORMAT_UT4_PAK => modpkg_write_pak($outputPath, $plan, $options, $settings),
        default => throw new RuntimeException('Unsupported package format.'),
    };
}
