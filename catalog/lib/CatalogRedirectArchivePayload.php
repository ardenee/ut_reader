<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/CatalogEpicRedirect.php';

/**
 * Decode Epic's exact UE2 UZ2 record stream without requiring package magic.
 * Exact zlib is attempted first for every record. If it fails and the declared
 * compressed and uncompressed sizes are equal, the record is copied verbatim.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null
 */
function catalog_redirect_archive_epic_uz2_payload(string $data, int $limit): ?array
{
    $position = 0;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $encodings = [];

    while ($position < $length) {
        if ($position + 8 > $length) {
            return null;
        }

        $compressed = catalog_redirect_archive_read_u32($data, $position, 'le');
        $uncompressed = catalog_redirect_archive_read_u32($data, $position + 4, 'le');
        $position += 8;

        if (
            $compressed <= 0
            || $compressed > CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES
            || $uncompressed <= 0
            || $uncompressed > CATALOG_EPIC_UZ2_BLOCK_BYTES
            || $compressed > $length - $position
            || $uncompressed > $limit - strlen($output)
        ) {
            return null;
        }

        $payload = substr($data, $position, $compressed);
        $position += $compressed;
        $decoded = catalog_redirect_archive_inflate_epic_zlib(
            $payload,
            $limit - strlen($output),
            $uncompressed
        );

        if ($decoded !== null) {
            $block = (string)$decoded['data'];
            $encoding = 'zlib';
        } elseif ($compressed === $uncompressed) {
            $block = $payload;
            $encoding = 'stored';
        } else {
            return null;
        }

        if (strlen($block) !== $uncompressed) {
            return null;
        }
        $output .= $block;
        $chunks++;
        $encodings[$encoding] = true;
    }

    if ($chunks === 0 || $position !== $length || $output === '') {
        return null;
    }

    return [
        'data' => $output,
        'decoder' => 'epic-uz2-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => strlen($output),
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null */
function catalog_redirect_archive_decode_payload(string $data, string $sourceExtension, int $maxOutputBytes = 0): ?array
{
    if ($data === '') {
        return null;
    }

    $extension = catalog_redirect_archive_extension('payload.' . strtolower(trim($sourceExtension, '. ')));
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    if ($extension === 'uz') {
        return catalog_epic_uz_decode($data, min($limit, 2147483647));
    }

    if ($extension === 'uz2') {
        return catalog_redirect_archive_epic_uz2_payload($data, $limit);
    }

    if ($extension === 'uz3') {
        return catalog_epic_uz3_decode($data, min($limit, 2147483647));
    }

    return null;
}

/**
 * Decompress a redirect wrapper beside its staged source using the same strict,
 * extension-aware Epic dispatcher as the Upload Bucket. Package magic is not
 * required here because redirect servers may also carry text/native support files.
 *
 * @return array{
 *   path:string,
 *   filename:string,
 *   bytes:int,
 *   compressed_bytes:int,
 *   source_extension:string,
 *   decoder:string,
 *   chunks:int,
 *   expected_bytes:int,
 *   wrapper_signature?:int,
 *   is_unreal_package:bool
 * }
 */
function catalog_redirect_archive_decompress_payload_to_temp(
    string $sourcePath,
    string $sourceName,
    int $maxOutputBytes = 0
): array {
    return catalog_epic_redirect_decompress_to_temp(
        $sourcePath,
        $sourceName,
        $maxOutputBytes,
        false
    );
}
