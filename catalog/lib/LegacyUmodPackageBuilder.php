<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for legacy umod package builder.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

/**
 * Unreal's legacy appMemCrc implementation is not the reflected CRC32 used by
 * PHP's crc32()/crc32b helpers. UMOD Setup validates this exact checksum.
 */
function modpkg_unreal_mem_crc_table(): array
{
    static $table = null;
    if (is_array($table)) {
        return $table;
    }

    $table = [];
    for ($index = 0; $index < 256; $index++) {
        $crc = ($index << 24) & 0xFFFFFFFF;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = (($crc & 0x80000000) !== 0)
                ? ((($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF)
                : (($crc << 1) & 0xFFFFFFFF);
        }
        $table[$index] = $crc;
    }
    return $table;
}

function modpkg_unreal_mem_crc(string $data, int $seed = 0): int
{
    $table = modpkg_unreal_mem_crc_table();
    $crc = (~$seed) & 0xFFFFFFFF;
    $length = strlen($data);
    for ($offset = 0; $offset < $length; $offset++) {
        $lookup = (($crc >> 24) ^ ord($data[$offset])) & 0xFF;
        $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $table[$lookup]) & 0xFFFFFFFF;
    }
    return (~$crc) & 0xFFFFFFFF;
}

/** @param resource $handle */
function modpkg_unreal_mem_crc_stream($handle, int $length, int $seed = 0): int
{
    if ($length < 0 || fseek($handle, 0, SEEK_SET) !== 0) {
        throw new RuntimeException('Could not seek the UMOD payload for checksum validation.');
    }

    $table = modpkg_unreal_mem_crc_table();
    $crc = (~$seed) & 0xFFFFFFFF;
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Could not read the UMOD payload for checksum validation.');
        }
        $chunkLength = strlen($chunk);
        for ($offset = 0; $offset < $chunkLength; $offset++) {
            $lookup = (($crc >> 24) ^ ord($chunk[$offset])) & 0xFF;
            $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $table[$lookup]) & 0xFFFFFFFF;
        }
        $remaining -= $chunkLength;
    }
    return (~$crc) & 0xFFFFFFFF;
}

function modpkg_compatible_umod_path(string $path): string
{
    $path = modpkg_normalize_relative_path($path);
    if ($path === '') {
        throw new RuntimeException('UMOD archive entries require a valid relative path.');
    }
    return str_replace('/', '\\', $path);
}

/** @return array<string,string> */
function modpkg_compatible_umod_manifest(array $plan, array $options): array
{
    $manifest = [];
    foreach (modpkg_umod_manifest($plan, $options) as $path => $content) {
        $manifest[modpkg_compatible_umod_path((string)$path)] = (string)$content;
    }
    return $manifest;
}

/**
 * Write a UMOD/UT2MOD/UT4MOD that is accepted by the original Unreal Setup
 * application. Manifest entries must use flags 3 and archive paths use the
 * Windows separators expected by the installer.
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
    return $validation;
}

function modpkg_validate_compatible_umod(string $path): array
{
    $errors = [];
    $entries = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'errors' => ['Could not open generated package'], 'entries' => [], 'file_count' => 0];
    }

    try {
        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize < 20 || fseek($handle, -20, SEEK_END) !== 0) {
            return ['ok' => false, 'errors' => ['Package is too small'], 'entries' => [], 'file_count' => 0];
        }
        $footerBytes = fread($handle, 20);
        if ($footerBytes === false || strlen($footerBytes) !== 20) {
            return ['ok' => false, 'errors' => ['Could not read package footer'], 'entries' => [], 'file_count' => 0];
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
            $actualCrc = modpkg_unreal_mem_crc_stream($handle, (int)$fileSize - 20);
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
            $count = modpkg_read_compact_index($table, $offset);
            if ($count < 0 || $count > 100000) {
                throw new RuntimeException('Invalid archive item count.');
            }
            for ($index = 0; $index < $count; $index++) {
                $filename = modpkg_read_ue1_string($table, $offset);
                if ($offset + 12 > strlen($table)) {
                    throw new RuntimeException('Truncated archive item.');
                }
                $item = unpack('Voffset/Vsize/Vflags', substr($table, $offset, 12));
                $offset += 12;
                $itemOffset = (int)$item['offset'];
                $itemSize = (int)$item['size'];
                if ($itemOffset < 0 || $itemSize < 0 || $itemOffset + $itemSize > $tableOffset) {
                    throw new RuntimeException('Archive item points outside the payload: ' . $filename);
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
                    $errors[] = basename(str_replace('\\', '/', $manifestName)) . ' has invalid UMOD flags';
                }
            }
        }
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    } finally {
        fclose($handle);
    }

    return ['ok' => !$errors, 'errors' => $errors, 'entries' => $entries, 'file_count' => count($entries)];
}
