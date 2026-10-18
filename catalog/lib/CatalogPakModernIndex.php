<?php
declare(strict_types=1);

/**
 * Epic FPakFile::LoadIndexInternal / LoadIndexInternal_DirectoryIndex and
 * DecodePakEntry, UE5.8.3, PAK v10-11. Read-only: no extraction side effects.
 */
function catalog_pak_parse_modern_index(string $path, array $footer): array
{
    $version = (int)$footer['version'];
    if ($version < 10 || $version > 11) {
        throw new RuntimeException('Unsupported modern PAK index version.');
    }
    // FPakInfo serializes five fixed-size compression method names after
    // the magic/version/index fields. Slot 0 is the implicit None method.
    $methodBytes = catalog_pak_read_bytes($path, (int)$footer['magic_offset'] + 44, 160);
    $methods = ['None'];
    for ($m = 0; $m < 5; ++$m) {
        $name = rtrim(substr($methodBytes, $m * 32, 32), "\0");
        if ($name !== '') $methods[] = $name;
    }
    $primary = catalog_pak_read_bytes($path, (int)$footer['index_offset'], (int)$footer['index_size']);
    if (!hash_equals(strtolower((string)$footer['index_hash']), sha1($primary))) {
        throw new RuntimeException('PAK primary index SHA-1 mismatch.');
    }
    $cursor = 0;
    $mount = catalog_pak_read_fstring($primary, $cursor);
    $count = catalog_pak_i32($primary, $cursor); $cursor += 4;
    if ($count < 0 || $count > 1000000) throw new RuntimeException('PAK primary count out of bounds.');
    $seed = catalog_pak_i64($primary, $cursor); $cursor += 8;
    $pathHash = catalog_pak_u32($primary, $cursor); $cursor += 4;
    if ($pathHash > 1) throw new RuntimeException('Invalid path hash index flag.');
    if ($pathHash) { $cursor += 36; }
    $hasFull = catalog_pak_u32($primary, $cursor); $cursor += 4;
    if ($hasFull !== 1) throw new RuntimeException('Full directory PAK index unavailable.');
    $fullOffset = catalog_pak_i64($primary, $cursor);
    $fullSize = catalog_pak_i64($primary, $cursor+8);
    $fullHash = bin2hex(substr($primary, $cursor+16, 20));
    $cursor += 36;
    $encodedLength = catalog_pak_i32($primary, $cursor); $cursor += 4;
    if ($encodedLength < 0 || $encodedLength > 100000000 || $cursor + $encodedLength > strlen($primary)) {
        throw new RuntimeException('PAK encoded-entry block is invalid.');
    }
    $encoded = substr($primary, $cursor, $encodedLength); $cursor += $encodedLength;
    $plainCount = catalog_pak_i32($primary, $cursor); $cursor += 4;
    if ($plainCount !== 0) {
        throw new RuntimeException('PAK unencoded entries require additional source layout support.');
    }
    if ($fullOffset < 0 || $fullSize <= 0 || $fullSize > 200000000 || $fullOffset + $fullSize > filesize($path)) {
        throw new RuntimeException('PAK directory index bounds invalid.');
    }
    $directory = catalog_pak_read_bytes($path, $fullOffset, $fullSize);
    if (!hash_equals(strtolower($fullHash), sha1($directory))) {
        throw new RuntimeException('PAK full-directory index SHA-1 mismatch.');
    }
    $offset = 0;
    $numDirectories = catalog_pak_i32($directory, $offset); $offset += 4;
    if ($numDirectories < 0 || $numDirectories > 1000000) {
        throw new RuntimeException('PAK directory count invalid.');
    }
    $entries = [];
    for ($n = 0; $n < $numDirectories; ++$n) {
        $dirname = catalog_pak_read_fstring($directory, $offset);
        $numFiles = catalog_pak_i32($directory, $offset); $offset += 4;
        if ($numFiles < 0 || $numFiles > 1000000 || count($entries)+$numFiles > $count) {
            throw new RuntimeException('PAK directory file count invalid.');
        }
        for ($j = 0; $j < $numFiles; ++$j) {
            $name = catalog_pak_read_fstring($directory, $offset);
            $location = catalog_pak_i32($directory, $offset); $offset += 4;
            if ($location === -2147483648 || $location === 2147483647) continue;
            if ($location < 0) throw new RuntimeException('PAK unencoded directory entry unsupported.');
            $entry = catalog_pak_decode_modern_entry($encoded, $location, $version);
            $entry['compression_name'] = $methods[$entry['compression_method']] ?? '';
            if ($entry['compression_name'] === '') throw new RuntimeException('PAK compression method index is not declared in FPakInfo.');
            $filename = trim(str_replace(chr(92), '/', $dirname.$name), '/');
            if ($filename === '' || str_contains('/'.$filename.'/', '/../')) {
                throw new RuntimeException('PAK invalid path in full directory index.');
            }
            $entries[] = ['filename' => $filename] + $entry;
        }
    }
    if (count($entries) !== $count) {
        throw new RuntimeException('PAK directory entry count differs from primary index.');
    }
    return ['mount_point' => $mount, 'entries' => $entries, 'path_hash_seed' => $seed,
        'index_type' => 'epic_compact_directory'];
}

function catalog_pak_decode_modern_entry(string $data, int $offset, int $version): array
{
    $pos = $offset;
    $word = catalog_pak_u32($data, $pos); $pos += 4;
    $blockSize = ($word & 63) << 11;
    if (($word & 63) === 63) {
        $blockSize = catalog_pak_u32($data, $pos); $pos += 4;
    }
    $method = ($word >> 23) & 63;
    $readSize = static function(bool $short) use ($data, &$pos): int {
        $size = $short ? catalog_pak_u32($data, $pos) : catalog_pak_i64($data, $pos);
        $pos += $short ? 4 : 8;
        if ($size < 0) throw new RuntimeException('PAK negative encoded entry size.');
        return $size;
    };
    $fileOffset = $readSize(($word & 0x80000000) !== 0);
    $uncompressed = $readSize(($word & 0x40000000) !== 0);
    $size = $method === 0 ? $uncompressed : $readSize(($word & 0x20000000) !== 0);
    $encrypted = ($word & 0x400000) !== 0;
    $blocksCount = ($word >> 6) & 65535;
    if ($blocksCount > 8192) throw new RuntimeException('PAK oversized encoded compression blocks.');
    $headerSize = 8+8+8+20+4+1+4+($method !== 0 ? (4+16*$blocksCount) : 0);
    $blocks = [];
    if ($blocksCount === 1 && !$encrypted) {
        $blocks[] = ['start' => $headerSize, 'end' => $headerSize+$size];
    } elseif ($blocksCount > 0) {
        $blockCursor = $headerSize;
        $align = $encrypted ? 16 : 1;
        for ($i=0; $i<$blocksCount; ++$i) {
            $compressedSize = catalog_pak_u32($data, $pos); $pos += 4;
            $blocks[] = ['start'=>$blockCursor,'end'=>$blockCursor+$compressedSize];
            $blockCursor += intdiv($compressedSize+$align-1,$align)*$align;
        }
    }
    return ['offset'=>$fileOffset, 'size'=>$size, 'uncompressed_size'=>$uncompressed,
        'compression_method'=>$method, 'compression_block_size'=>$blocksCount===1 ? $uncompressed:$blockSize,
        'encrypted'=>$encrypted,'blocks'=>$blocks,'version'=>$version,
        'hash'=>str_repeat('0',40),'encoded_offset'=>$offset,'blocks_relative'=>true];
}
