<?php
declare(strict_types=1);

/**
 * UE1 UCC redirect archives use the native codec chain in Core/Inc/FCodec.h.
 * Compression order is RLE -> BWT -> MTF -> Huffman, therefore decoding runs
 * Huffman -> MTF -> BWT -> RLE. The outer wrapper starts with magic 1234 and
 * an Unreal-serialized embedded filename.
 */

final class CatalogLegacyUzBitReader
{
    public int $position = 0;
    public readonly int $length;

    public function __construct(public readonly string $data)
    {
        $this->length = strlen($data) * 8;
    }

    public function readBit(): int
    {
        if ($this->position >= $this->length) {
            throw new RuntimeException('Legacy UZ Huffman bitstream ended unexpectedly.');
        }
        $position = $this->position++;
        return (ord($this->data[$position >> 3]) >> ($position & 7)) & 1;
    }

    public function readByte(): int
    {
        $value = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            $value |= $this->readBit() << $bit;
        }
        return $value;
    }
}

function catalog_legacy_uz_read_u32(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        return -1;
    }
    $value = unpack('V', substr($data, $offset, 4));
    return (int)($value[1] ?? -1);
}

function catalog_legacy_uz_read_i32(string $data, int $offset): int
{
    $value = catalog_legacy_uz_read_u32($data, $offset);
    if ($value < 0) {
        return -1;
    }
    return $value >= 0x80000000 ? $value - 0x100000000 : $value;
}

function catalog_legacy_uz_read_compact_index(string $data, int &$position): ?int
{
    $length = strlen($data);
    if ($position >= $length) {
        return null;
    }

    $first = ord($data[$position++]);
    $negative = ($first & 0x80) !== 0;
    $value = $first & 0x3f;
    if (($first & 0x40) !== 0) {
        $shift = 6;
        for ($index = 0; $index < 4; $index++) {
            if ($position >= $length) {
                return null;
            }
            $next = ord($data[$position++]);
            $value |= ($next & 0x7f) << $shift;
            if (($next & 0x80) === 0) {
                break;
            }
            if ($index === 3) {
                return null;
            }
            $shift += 7;
        }
    }
    return $negative ? -$value : $value;
}

/** @return array{offset:int,filename:string}|null */
function catalog_legacy_uz_header(string $data): ?array
{
    if (catalog_legacy_uz_read_u32($data, 0) !== 1234) {
        return null;
    }

    $position = 4;
    $characters = catalog_legacy_uz_read_compact_index($data, $position);
    if ($characters === null || $characters === 0) {
        return null;
    }

    if ($characters > 0) {
        if ($characters > 1024 || $position + $characters > strlen($data)) {
            return null;
        }
        $rawName = substr($data, $position, $characters);
        $position += $characters;
        if ($rawName === '' || $rawName[-1] !== "\0") {
            return null;
        }
        $filename = substr($rawName, 0, -1);
    } else {
        $characterCount = -$characters;
        $byteCount = $characterCount * 2;
        if ($characterCount > 1024 || $position + $byteCount > strlen($data)) {
            return null;
        }
        $rawName = substr($data, $position, $byteCount);
        $position += $byteCount;
        if (substr($rawName, -2) !== "\0\0" || !function_exists('iconv')) {
            return null;
        }
        $converted = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($rawName, 0, -2));
        $filename = is_string($converted) ? $converted : '';
    }

    $filename = basename(str_replace('\\', '/', $filename));
    if ($filename === '' || $position + 4 > strlen($data)) {
        return null;
    }
    return ['offset' => $position, 'filename' => $filename];
}

function catalog_legacy_uz_huffman_tree(
    CatalogLegacyUzBitReader $reader,
    array &$left,
    array &$right,
    int $depth = 0
): int {
    if ($depth > 256 || count($left) > 255) {
        throw new RuntimeException('Legacy UZ Huffman table is invalid.');
    }
    if ($reader->readBit() === 0) {
        return -$reader->readByte() - 1;
    }

    $index = count($left);
    $left[$index] = 0;
    $right[$index] = 0;
    $left[$index] = catalog_legacy_uz_huffman_tree($reader, $left, $right, $depth + 1);
    $right[$index] = catalog_legacy_uz_huffman_tree($reader, $left, $right, $depth + 1);
    return $index;
}

function catalog_legacy_uz_decode_huffman(string $data, int $limit): string
{
    $total = catalog_legacy_uz_read_i32($data, 0);
    if ($total <= 0 || $total > $limit) {
        throw new RuntimeException('Legacy UZ Huffman output size is invalid.');
    }

    $reader = new CatalogLegacyUzBitReader(substr($data, 4));
    $left = [];
    $right = [];
    $root = catalog_legacy_uz_huffman_tree($reader, $left, $right);
    $output = str_repeat("\0", $total);

    for ($position = 0; $position < $total; $position++) {
        $node = $root;
        while ($node >= 0) {
            $node = $reader->readBit() !== 0 ? $right[$node] : $left[$node];
        }
        $output[$position] = chr(-$node - 1);
    }

    $remaining = $reader->length - $reader->position;
    if ($remaining >= 8) {
        throw new RuntimeException('Legacy UZ Huffman stream contains trailing data.');
    }
    while ($reader->position < $reader->length) {
        if ($reader->readBit() !== 0) {
            throw new RuntimeException('Legacy UZ Huffman padding is invalid.');
        }
    }
    return $output;
}

function catalog_legacy_uz_decode_mtf(string $data): string
{
    $list = range(0, 255);
    $length = strlen($data);
    $output = str_repeat("\0", $length);
    for ($position = 0; $position < $length; $position++) {
        $index = ord($data[$position]);
        $value = $list[$index];
        $output[$position] = chr($value);
        for ($move = $index; $move > 0; $move--) {
            $list[$move] = $list[$move - 1];
        }
        $list[0] = $value;
    }
    return $output;
}

/** @return array{data:string,chunks:int} */
function catalog_legacy_uz_decode_bwt(string $data, int $limit): array
{
    $position = 0;
    $length = strlen($data);
    $parts = [];
    $total = 0;
    $chunks = 0;

    while ($position < $length) {
        if ($position + 12 > $length) {
            throw new RuntimeException('Legacy UZ BWT header is truncated.');
        }
        $blockLength = catalog_legacy_uz_read_i32($data, $position);
        $first = catalog_legacy_uz_read_i32($data, $position + 4);
        $last = catalog_legacy_uz_read_i32($data, $position + 8);
        $position += 12;

        if ($blockLength < 0 || $blockLength > 0x40000) {
            throw new RuntimeException('Legacy UZ BWT block size is invalid.');
        }
        $encodedLength = $blockLength + 1;
        if (
            $first < 0 || $first >= $encodedLength
            || $last < 0 || $last >= $encodedLength
            || $position + $encodedLength > $length
        ) {
            throw new RuntimeException('Legacy UZ BWT block references are invalid.');
        }

        $buffer = substr($data, $position, $encodedLength);
        $position += $encodedLength;
        $counts = array_fill(0, 257, 0);
        for ($index = 0; $index < $encodedLength; $index++) {
            $symbol = $index === $last ? 256 : ord($buffer[$index]);
            $counts[$symbol]++;
        }

        $running = array_fill(0, 257, 0);
        $sum = 0;
        for ($symbol = 0; $symbol < 257; $symbol++) {
            $running[$symbol] = $sum;
            $sum += $counts[$symbol];
            $counts[$symbol] = 0;
        }

        $mapping = array_fill(0, $encodedLength, 0);
        for ($index = 0; $index < $encodedLength; $index++) {
            $symbol = $index === $last ? 256 : ord($buffer[$index]);
            $target = $running[$symbol] + $counts[$symbol]++;
            $mapping[$target] = $index;
        }

        $decoded = str_repeat("\0", $blockLength);
        $index = $first;
        for ($outputIndex = 0; $outputIndex < $blockLength; $outputIndex++) {
            $decoded[$outputIndex] = $buffer[$index];
            $index = $mapping[$index];
        }

        $parts[] = $decoded;
        $total += $blockLength;
        $chunks++;
        if ($total > $limit) {
            throw new RuntimeException('Legacy UZ BWT output exceeds the configured limit.');
        }
        unset($mapping, $counts, $running, $buffer, $decoded);
    }

    if ($chunks === 0) {
        throw new RuntimeException('Legacy UZ BWT stream is empty.');
    }
    return ['data' => implode('', $parts), 'chunks' => $chunks];
}

function catalog_legacy_uz_decode_rle(string $data, int $limit): string
{
    $length = strlen($data);
    $parts = [];
    $buffer = '';
    $count = 0;
    $previous = 0;
    $total = 0;

    for ($position = 0; $position < $length; $position++) {
        $byte = ord($data[$position]);
        $character = $data[$position];
        $buffer .= $character;
        $total++;

        if ($byte !== $previous) {
            $previous = $byte;
            $count = 1;
        } elseif (++$count === 5) {
            if (++$position >= $length) {
                throw new RuntimeException('Legacy UZ RLE stream is truncated.');
            }
            $runLength = ord($data[$position]);
            if ($runLength < 2) {
                throw new RuntimeException('Legacy UZ RLE run length is invalid.');
            }
            if ($runLength > 5) {
                $extra = $runLength - 5;
                $buffer .= str_repeat($character, $extra);
                $total += $extra;
            }
            $count = 0;
        }

        if ($total > $limit) {
            throw new RuntimeException('Legacy UZ output exceeds the configured limit.');
        }
        if (strlen($buffer) >= 65536) {
            $parts[] = $buffer;
            $buffer = '';
        }
    }

    if ($buffer !== '') {
        $parts[] = $buffer;
    }
    return implode('', $parts);
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename:string}|null */
function catalog_legacy_uz_decode(string $data, int $maxOutputBytes): ?array
{
    $header = catalog_legacy_uz_header($data);
    if ($header === null) {
        return null;
    }

    try {
        $limit = max(1, $maxOutputBytes);
        $stageLimit = $limit + intdiv($limit, 4) + 16 * 1024 * 1024;
        $huffman = catalog_legacy_uz_decode_huffman(substr($data, $header['offset']), $stageLimit);
        $mtf = catalog_legacy_uz_decode_mtf($huffman);
        unset($huffman);
        $bwt = catalog_legacy_uz_decode_bwt($mtf, $stageLimit);
        unset($mtf);
        $output = catalog_legacy_uz_decode_rle($bwt['data'], $limit);
        if (!catalog_redirect_archive_has_package_tag($output)) {
            throw new RuntimeException('Legacy UZ output does not contain an Unreal package.');
        }
        return [
            'data' => $output,
            'decoder' => 'legacy-uz-huffman+mtf+bwt+rle',
            'chunks' => (int)$bwt['chunks'],
            'expected_bytes' => strlen($output),
            'embedded_filename' => (string)$header['filename'],
        ];
    } catch (Throwable) {
        return null;
    }
}
