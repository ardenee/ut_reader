<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog support for catalog redirect codec, centered on `CatalogRedirectBitWriter`.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogRedirectArchivePayload.php';

const CATALOG_REDIRECT_BWT_ENCODE_BLOCK_BYTES = 0x40000;

final class CatalogRedirectBitWriter
{
    private string $data = '';
    private int $bitPosition = 0;

    public function writeBit(int $bit): void
    {
        $byteIndex = intdiv($this->bitPosition, 8);
        $bitIndex = $this->bitPosition & 7;
        if ($byteIndex === strlen($this->data)) {
            $this->data .= "\0";
        }
        if (($bit & 1) !== 0) {
            $this->data[$byteIndex] = chr(ord($this->data[$byteIndex]) | (1 << $bitIndex));
        }
        $this->bitPosition++;
    }

    public function writeByte(int $value): void
    {
        for ($bit = 0; $bit < 8; $bit++) {
            $this->writeBit(($value >> $bit) & 1);
        }
    }

    /** @param list<int> $bits */
    public function writeBits(array $bits): void
    {
        foreach ($bits as $bit) {
            $this->writeBit($bit);
        }
    }

    public function data(): string
    {
        return $this->data;
    }
}

function catalog_redirect_archive_normalize_extension(string $extension): string
{
    return strtolower(ltrim(trim($extension), '.'));
}

function catalog_redirect_archive_encode_compact_index(int $value): string
{
    $negative = $value < 0;
    $remaining = abs($value);
    $first = $remaining & 0x3f;
    $remaining >>= 6;
    if ($negative) {
        $first |= 0x80;
    }
    if ($remaining > 0) {
        $first |= 0x40;
    }

    $output = chr($first);
    for ($index = 0; $remaining > 0 && $index < 4; $index++) {
        $next = $remaining & 0x7f;
        $remaining >>= 7;
        if ($remaining > 0) {
            $next |= 0x80;
        }
        $output .= chr($next);
    }
    if ($remaining > 0) {
        throw new RuntimeException('Unreal compact index exceeds the supported range.');
    }
    return $output;
}

function catalog_redirect_archive_encode_rle(string $data): string
{
    $length = strlen($data);
    if ($length === 0) {
        return '';
    }

    $output = '';
    $position = 0;
    while ($position < $length) {
        $character = $data[$position];
        $count = 1;
        $position++;
        while ($position < $length && $data[$position] === $character && $count < 255) {
            $count++;
            $position++;
        }

        $output .= str_repeat($character, min($count, 5));
        if ($count >= 5) {
            $output .= chr($count);
        }
    }
    return $output;
}

function catalog_redirect_archive_encode_mtf(string $data): string
{
    $list = range(0, 255);
    $positions = range(0, 255);
    $length = strlen($data);
    $output = str_repeat("\0", $length);

    for ($position = 0; $position < $length; $position++) {
        $value = ord($data[$position]);
        $index = $positions[$value];
        $output[$position] = chr($index);

        for ($move = $index; $move > 0; $move--) {
            $shifted = $list[$move - 1];
            $list[$move] = $shifted;
            $positions[$shifted] = $move;
        }
        $list[0] = $value;
        $positions[$value] = 0;
    }
    return $output;
}

/** @return list<int> */
function catalog_redirect_archive_suffix_array(string $data): array
{
    $length = strlen($data);
    $count = $length + 1;
    $suffixes = range(0, $length);
    $ranks = [];
    for ($position = 0; $position < $length; $position++) {
        $ranks[$position] = ord($data[$position]);
    }
    $ranks[$length] = 256;

    for ($width = 1; $width < $count; $width <<= 1) {
        $firstKeys = [];
        $secondKeys = [];
        foreach ($suffixes as $slot => $position) {
            $firstKeys[$slot] = $ranks[$position];
            $secondKeys[$slot] = $position + $width < $count ? $ranks[$position + $width] : -1;
        }

        array_multisort(
            $firstKeys,
            SORT_ASC,
            SORT_NUMERIC,
            $secondKeys,
            SORT_ASC,
            SORT_NUMERIC,
            $suffixes,
            SORT_ASC,
            SORT_NUMERIC
        );

        $newRanks = array_fill(0, $count, 0);
        $rank = 0;
        $newRanks[$suffixes[0]] = 0;
        for ($slot = 1; $slot < $count; $slot++) {
            if ($firstKeys[$slot] !== $firstKeys[$slot - 1] || $secondKeys[$slot] !== $secondKeys[$slot - 1]) {
                $rank++;
            }
            $newRanks[$suffixes[$slot]] = $rank;
        }
        $ranks = $newRanks;
        if ($rank === $count - 1) {
            break;
        }
    }

    return $suffixes;
}

/** @return array{data:string,chunks:int} */
function catalog_redirect_archive_encode_bwt(string $data, int $blockBytes = CATALOG_REDIRECT_BWT_ENCODE_BLOCK_BYTES): array
{
    if ($blockBytes <= 0 || $blockBytes > 0x40000) {
        throw new InvalidArgumentException('BWT block size must be between 1 and 262144 bytes.');
    }

    $length = strlen($data);
    $offset = 0;
    $output = '';
    $chunks = 0;
    while ($offset < $length) {
        $block = substr($data, $offset, min($blockBytes, $length - $offset));
        $blockLength = strlen($block);
        $suffixes = catalog_redirect_archive_suffix_array($block);
        $first = -1;
        $last = -1;
        $encoded = str_repeat("\0", $blockLength + 1);

        foreach ($suffixes as $slot => $position) {
            if ($position === 1) {
                $first = $slot;
            } elseif ($position === 0) {
                $last = $slot;
            }
            $encoded[$slot] = $block[$position > 0 ? $position - 1 : 0];
        }

        if ($first < 0 || $last < 0) {
            throw new RuntimeException('Could not construct Unreal BWT block references.');
        }
        $output .= pack('V3', $blockLength, $first, $last) . $encoded;
        $offset += $blockLength;
        $chunks++;
    }

    if ($chunks === 0) {
        throw new RuntimeException('Cannot BWT-compress an empty stream.');
    }
    return ['data' => $output, 'chunks' => $chunks];
}

/**
 * @param array{ch:int,count:int,left:?array,right:?array,order:int} $node
 * @param array<int,list<int>> $codes
 * @param list<int> $prefix
 */
function catalog_redirect_archive_huffman_codes(array $node, array &$codes, array $prefix = []): void
{
    if ($node['ch'] >= 0) {
        $codes[$node['ch']] = $prefix;
        return;
    }
    $left = $prefix;
    $left[] = 0;
    catalog_redirect_archive_huffman_codes($node['left'], $codes, $left);
    $right = $prefix;
    $right[] = 1;
    catalog_redirect_archive_huffman_codes($node['right'], $codes, $right);
}

/** @param array{ch:int,count:int,left:?array,right:?array,order:int} $node */
function catalog_redirect_archive_huffman_write_tree(array $node, CatalogRedirectBitWriter $writer): void
{
    if ($node['ch'] >= 0) {
        $writer->writeBit(0);
        $writer->writeByte($node['ch']);
        return;
    }
    $writer->writeBit(1);
    catalog_redirect_archive_huffman_write_tree($node['left'], $writer);
    catalog_redirect_archive_huffman_write_tree($node['right'], $writer);
}

function catalog_redirect_archive_encode_huffman(string $data): string
{
    $length = strlen($data);
    if ($length === 0) {
        throw new RuntimeException('Cannot Huffman-compress an empty stream.');
    }

    $counts = array_fill(0, 256, 0);
    for ($position = 0; $position < $length; $position++) {
        $counts[ord($data[$position])]++;
    }

    $nodes = [];
    $order = 0;
    foreach ($counts as $character => $count) {
        if ($count > 0) {
            $nodes[] = [
                'ch' => $character,
                'count' => $count,
                'left' => null,
                'right' => null,
                'order' => $order++,
            ];
        }
    }

    while (count($nodes) > 1) {
        usort($nodes, static function (array $left, array $right): int {
            $countCompare = $left['count'] <=> $right['count'];
            return $countCompare !== 0 ? $countCompare : ($left['order'] <=> $right['order']);
        });
        $left = array_shift($nodes);
        $right = array_shift($nodes);
        $nodes[] = [
            'ch' => -1,
            'count' => $left['count'] + $right['count'],
            'left' => $left,
            'right' => $right,
            'order' => $order++,
        ];
    }

    $root = $nodes[0];
    $codes = [];
    catalog_redirect_archive_huffman_codes($root, $codes);
    $writer = new CatalogRedirectBitWriter();
    catalog_redirect_archive_huffman_write_tree($root, $writer);
    for ($position = 0; $position < $length; $position++) {
        $writer->writeBits($codes[ord($data[$position])]);
    }
    return pack('V', $length) . $writer->data();
}

/** @return array{data:string,encoder:string,chunks:int,embedded_filename:string,wrapper_signature:int} */
function catalog_redirect_archive_encode_native_codec(
    string $packageData,
    string $packageFilename,
    int $signature,
    int $bwtBlockBytes = CATALOG_REDIRECT_BWT_ENCODE_BLOCK_BYTES
): array {
    if (!in_array($signature, [1234, 5678], true)) {
        throw new InvalidArgumentException('Native Unreal redirect signature must be 1234 or 5678.');
    }

    $packageFilename = catalog_clean_unreal_filename(basename(str_replace('\\', '/', $packageFilename)));
    if ($packageFilename === '' || strlen($packageFilename) > 1023 || str_contains($packageFilename, "\0")) {
        throw new InvalidArgumentException('Package filename is not valid for an Unreal redirect archive.');
    }

    $rle = catalog_redirect_archive_encode_rle($packageData);
    $bwt = catalog_redirect_archive_encode_bwt($rle, $bwtBlockBytes);
    unset($rle);
    $mtf = catalog_redirect_archive_encode_mtf($bwt['data']);
    unset($bwt['data']);

    if ($signature === 5678) {
        $secondRle = catalog_redirect_archive_encode_rle($mtf);
        unset($mtf);
        $payload = catalog_redirect_archive_encode_huffman($secondRle);
        unset($secondRle);
        $encoder = 'epic-uz-5678-rle+bwt+mtf+rle+huffman';
    } else {
        $payload = catalog_redirect_archive_encode_huffman($mtf);
        unset($mtf);
        $encoder = 'epic-uz-1234-rle+bwt+mtf+huffman';
    }

    $filename = $packageFilename . "\0";
    $wrapper = pack('V', $signature)
        . catalog_redirect_archive_encode_compact_index(strlen($filename))
        . $filename
        . $payload;

    return [
        'data' => $wrapper,
        'encoder' => $encoder,
        'chunks' => (int)$bwt['chunks'],
        'embedded_filename' => $packageFilename,
        'wrapper_signature' => $signature,
    ];
}

/** @return array{data:string,encoder:string,chunks:int} */
function catalog_redirect_archive_encode_epic_uz2(string $packageData, int $compressionLevel = 9): array
{
    if ($compressionLevel < -1 || $compressionLevel > 9) {
        throw new InvalidArgumentException('Zlib compression level must be between -1 and 9.');
    }

    $output = '';
    $chunks = 0;
    foreach (str_split($packageData, CATALOG_EPIC_UZ2_BLOCK_BYTES) as $block) {
        $compressed = gzcompress($block, $compressionLevel);
        if (!is_string($compressed)) {
            throw new RuntimeException('Could not zlib-compress an Epic UZ2 block.');
        }
        if (strlen($compressed) > CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES) {
            throw new RuntimeException('Compressed Epic UZ2 block exceeds the engine size limit.');
        }
        $output .= pack('V2', strlen($compressed), strlen($block)) . $compressed;
        $chunks++;
    }

    if ($chunks === 0) {
        throw new RuntimeException('Cannot compress an empty package.');
    }
    return ['data' => $output, 'encoder' => 'epic-uz2-zlib', 'chunks' => $chunks];
}

/** @return array{data:string,encoder:string,chunks:int,wrapper_signature:int} */
function catalog_redirect_archive_encode_epic_uz3(string $packageData): array
{
    $uncompressedBytes = strlen($packageData);
    if ($uncompressedBytes <= 0) {
        throw new RuntimeException('Cannot compress an empty package.');
    }
    if ($uncompressedBytes > 0xFFFFFFFF) {
        throw new RuntimeException('Epic UZ3 supports at most a 32-bit uncompressed file size.');
    }
    if (!function_exists('gzcompress')) {
        throw new RuntimeException('Zlib compression support is unavailable.');
    }

    // UT3's commandlet uses zlib compress(), i.e. Z_DEFAULT_COMPRESSION.
    $compressed = gzcompress($packageData);
    if (!is_string($compressed) || $compressed === '') {
        throw new RuntimeException('Could not zlib-compress an Epic UZ3 file.');
    }

    return [
        'data' => pack('V2', 5678, $uncompressedBytes) . $compressed,
        'encoder' => 'epic-uz3-zlib',
        'chunks' => 1,
        'wrapper_signature' => 5678,
    ];
}

/**
 * Extension-aware decompression entry point.
 *
 * `.uz` uses an FCodec wrapper with signature 1234 or 5678 and an embedded
 * filename. Canonical UT3 `.uz3` uses tag 5678, total uncompressed size, then
 * one zlib stream containing the complete file. Historic mirrors also contain
 * signature-5678 FCodec files mislabeled with a `.uz3` suffix, so decoding is
 * content-aware while encoding remains canonical UT3 UZ3.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null
 */
function catalog_redirect_archive_decompress_data(
    string $archiveData,
    string $sourceExtension,
    int $maxOutputBytes = 0
): ?array {
    $extension = catalog_redirect_archive_normalize_extension($sourceExtension);
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    return match ($extension) {
        'uz' => catalog_redirect_archive_legacy_payload($archiveData, $limit),
        'uz2' => catalog_redirect_archive_epic_uz2_payload($archiveData, $limit),
        'uz3' => catalog_redirect_archive_compatible_uz3_payload($archiveData, $limit),
        default => null,
    };
}

/**
 * @return array{
 *   data:string,
 *   filename:string,
 *   bytes:int,
 *   uncompressed_bytes:int,
 *   target_extension:string,
 *   encoder:string,
 *   chunks:int,
 *   embedded_filename?:string,
 *   wrapper_signature?:int
 * }
 */
function catalog_redirect_archive_compress_data(
    string $packageData,
    string $packageFilename,
    string $targetExtension,
    int $compressionLevel = 9,
    int $bwtBlockBytes = CATALOG_REDIRECT_BWT_ENCODE_BLOCK_BYTES,
    int $uzSignature = 1234
): array {
    $extension = catalog_redirect_archive_normalize_extension($targetExtension);
    if ($extension === 'uz' && !in_array($uzSignature, [1234, 5678], true)) {
        throw new InvalidArgumentException('UZ uses Epic FCodec signature 1234 or 5678.');
    }

    $encoded = match ($extension) {
        'uz' => catalog_redirect_archive_encode_native_codec($packageData, $packageFilename, $uzSignature, $bwtBlockBytes),
        'uz2' => catalog_redirect_archive_encode_epic_uz2($packageData, $compressionLevel),
        'uz3' => catalog_redirect_archive_encode_epic_uz3($packageData),
        default => throw new InvalidArgumentException('Unsupported Unreal redirect extension: ' . $targetExtension),
    };

    $archiveData = (string)$encoded['data'];
    return [
        'data' => $archiveData,
        'filename' => catalog_clean_unreal_filename(basename(str_replace('\\', '/', $packageFilename))) . '.' . $extension,
        'bytes' => strlen($archiveData),
        'uncompressed_bytes' => strlen($packageData),
        'target_extension' => $extension,
        'encoder' => (string)$encoded['encoder'],
        'chunks' => (int)$encoded['chunks'],
        ...isset($encoded['embedded_filename']) ? ['embedded_filename' => (string)$encoded['embedded_filename']] : [],
        ...isset($encoded['wrapper_signature']) ? ['wrapper_signature' => (int)$encoded['wrapper_signature']] : [],
    ];
}

/**
 * @return array{
 *   path:string,
 *   filename:string,
 *   bytes:int,
 *   uncompressed_bytes:int,
 *   target_extension:string,
 *   encoder:string,
 *   chunks:int
 * }
 */
function catalog_redirect_archive_compress_to_temp(
    string $sourcePath,
    string $sourceName,
    string $targetExtension,
    int $compressionLevel = 9,
    int $bwtBlockBytes = CATALOG_REDIRECT_BWT_ENCODE_BLOCK_BYTES,
    int $uzSignature = 1234
): array {
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Unreal package source file is missing.');
    }
    $packageData = @file_get_contents($sourcePath);
    if (!is_string($packageData) || $packageData === '') {
        throw new RuntimeException('Could not read Unreal package source file.');
    }

    $result = catalog_redirect_archive_compress_data(
        $packageData,
        $sourceName,
        $targetExtension,
        $compressionLevel,
        $bwtBlockBytes,
        $uzSignature
    );
    $archiveData = (string)$result['data'];
    $tmp = tempnam(sys_get_temp_dir(), 'ue_redirect_compress_');
    if ($tmp === false || @file_put_contents($tmp, $archiveData) !== strlen($archiveData)) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not write compressed Unreal redirect archive.');
    }
    unset($result['data']);
    return ['path' => $tmp, ...$result];
}
