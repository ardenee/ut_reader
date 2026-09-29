<?php
/**
 * Isolated reader for UE5 IoStore .utoc/.ucas staging ingestion.
 * It mirrors the UE5 5.8.3 TOC layout and chunk reconstruction without wiring production runtime reads.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5IoStoreTocReader
{
    public const TOC_MAGIC = '-==--==--==--==-';
    public const TOC_HEADER_SIZE = 144;
    public const TOC_VERSION_DIRECTORY_INDEX = 2;
    public const TOC_VERSION_PERFECT_HASH = 4;
    public const TOC_VERSION_PERFECT_HASH_WITH_OVERFLOW = 5;
    public const TOC_VERSION_REPLACE_IO_CHUNK_HASH = 8;
    public const TOC_VERSION_LATEST = 8;

    public const CONTAINER_FLAG_COMPRESSED = 0x01;
    public const CONTAINER_FLAG_ENCRYPTED = 0x02;
    public const CONTAINER_FLAG_SIGNED = 0x04;
    public const CONTAINER_FLAG_INDEXED = 0x08;
    public const CONTAINER_FLAG_ON_DEMAND = 0x10;

    public const CHUNK_TYPE_EXPORT_BUNDLE_DATA = 1;
    public const CHUNK_TYPE_CONTAINER_HEADER = 6;

    /** @var array<string,mixed> */
    private array $header;
    /** @var list<array<string,mixed>> */
    private array $chunks = [];
    /** @var list<array<string,mixed>> */
    private array $compressionBlocks = [];
    /** @var list<string> */
    private array $compressionMethods = ['None'];
    /** @var list<array<string,mixed>> */
    private array $chunkMetas = [];
    private string $directoryIndex = '';
    private string $tocSha256 = '';

    public function __construct(
        private readonly string $utocPath,
        private readonly ?string $aesKeyHex = null,
        ?string $oodleLibraryPath = null
    ) {
        if (!is_file($utocPath)) {
            throw new RuntimeException('IoStore TOC does not exist: ' . $utocPath);
        }
        $this->codec = new Uedb5IoStoreCodec($oodleLibraryPath);
        $bytes = @file_get_contents($utocPath);
        if (!is_string($bytes)) {
            throw new RuntimeException('Could not read IoStore TOC: ' . $utocPath);
        }
        $this->tocSha256 = hash('sha256', $bytes);
        $this->parse($bytes);
    }

    private Uedb5IoStoreCodec $codec;

    public function tocSha256(): string
    {
        return $this->tocSha256;
    }

    public function utocFilename(): string
    {
        return basename($this->utocPath);
    }

    /** @return array<string,mixed> */
    public function header(): array
    {
        return $this->header;
    }

    /** @return list<array<string,mixed>> */
    public function chunks(): array
    {
        return $this->chunks;
    }

    /** @return list<array<string,mixed>> */
    public function compressionBlocks(): array
    {
        return $this->compressionBlocks;
    }

    /** @return list<string> */
    public function compressionMethods(): array
    {
        return $this->compressionMethods;
    }

    /** @return list<array<string,mixed>> */
    public function chunkMetas(): array
    {
        return $this->chunkMetas;
    }

    public function directoryIndexBytes(): string
    {
        return $this->directoryIndex;
    }

    /** @return list<int> */
    public function chunkIndexesByType(int $chunkType): array
    {
        $indexes = [];
        foreach ($this->chunks as $index => $chunk) {
            if ((int)$chunk['chunk_type'] === $chunkType) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    public function findPackageChunk(string $packageIdHex, int $chunkType = self::CHUNK_TYPE_EXPORT_BUNDLE_DATA): ?int
    {
        $packageIdHex = strtoupper(trim($packageIdHex));
        foreach ($this->chunks as $index => $chunk) {
            if (
                (int)$chunk['chunk_type'] === $chunkType
                && (string)$chunk['package_id'] === $packageIdHex
            ) {
                return $index;
            }
        }
        return null;
    }

    public function readChunk(int $tocIndex): string
    {
        if (!isset($this->chunks[$tocIndex])) {
            throw new RuntimeException('IoStore chunk index is outside the TOC.');
        }
        $chunk = $this->chunks[$tocIndex];
        $length = (int)$chunk['length'];
        if ($length === 0) {
            return '';
        }

        $blockSize = (int)$this->header['compression_block_size'];
        if ($blockSize <= 0) {
            throw new RuntimeException('IoStore compression block size is invalid.');
        }
        $logicalOffset = (int)$chunk['offset'];
        $firstBlock = intdiv($logicalOffset, $blockSize);
        $lastBlock = intdiv($logicalOffset + $length - 1, $blockSize);
        if (!isset($this->compressionBlocks[$firstBlock], $this->compressionBlocks[$lastBlock])) {
            throw new RuntimeException('IoStore chunk references compression blocks outside the TOC.');
        }

        $remaining = $length;
        $offsetInBlock = $logicalOffset % $blockSize;
        $result = '';
        for ($blockIndex = $firstBlock; $blockIndex <= $lastBlock; $blockIndex++) {
            $block = $this->compressionBlocks[$blockIndex];
            $decoded = $this->readAndDecodeBlock($block);
            $available = strlen($decoded) - $offsetInBlock;
            if ($available < 0) {
                throw new RuntimeException('IoStore chunk offset exceeds its first compression block.');
            }
            $copy = min($remaining, $available);
            $result .= substr($decoded, $offsetInBlock, $copy);
            $remaining -= $copy;
            $offsetInBlock = 0;
        }

        if ($remaining !== 0 || strlen($result) !== $length) {
            throw new RuntimeException('IoStore chunk reconstruction did not produce the declared length.');
        }
        return $result;
    }

    /** @param array<string,mixed> $block */
    private function readAndDecodeBlock(array $block): string
    {
        $compressedSize = (int)$block['compressed_size'];
        $readSize = $compressedSize;
        $encrypted = (((int)$this->header['container_flags']) & self::CONTAINER_FLAG_ENCRYPTED) !== 0;
        if ($encrypted) {
            $readSize = ($compressedSize + 15) & ~15;
        }

        $partitionSize = (int)$this->header['partition_size'];
        $physicalOffset = (int)$block['offset'];
        $partitionIndex = $partitionSize === PHP_INT_MAX ? 0 : intdiv($physicalOffset, $partitionSize);
        $partitionOffset = $partitionSize === PHP_INT_MAX ? $physicalOffset : ($physicalOffset % $partitionSize);
        $partitionPath = $this->partitionPath($partitionIndex);
        $raw = @file_get_contents($partitionPath, false, null, $partitionOffset, $readSize);
        if (!is_string($raw) || strlen($raw) !== $readSize) {
            throw new RuntimeException(
                'Could not read IoStore partition block ' . $partitionIndex . ' at offset ' . $partitionOffset . '.'
            );
        }

        if ($encrypted) {
            $raw = $this->decryptBlock($raw);
        }
        $compressed = substr($raw, 0, $compressedSize);
        $methodIndex = (int)$block['compression_method_index'];
        if (!array_key_exists($methodIndex, $this->compressionMethods)) {
            throw new RuntimeException('IoStore compression method index is outside the TOC method table.');
        }
        return $this->codec->decode(
            $this->compressionMethods[$methodIndex],
            $compressed,
            (int)$block['uncompressed_size']
        );
    }

    private function decryptBlock(string $bytes): string
    {
        $hex = preg_replace('/\s+/', '', (string)$this->aesKeyHex);
        if (!is_string($hex) || preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            throw new RuntimeException('Encrypted IoStore content requires a 32-byte AES key as 64 hex characters.');
        }
        $key = hex2bin($hex);
        if (!is_string($key)) {
            throw new RuntimeException('IoStore AES key could not be decoded.');
        }
        $decoded = openssl_decrypt(
            $bytes,
            'aes-256-ecb',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
        );
        if (!is_string($decoded) || strlen($decoded) !== strlen($bytes)) {
            throw new RuntimeException('IoStore AES-256 block decryption failed.');
        }
        return $decoded;
    }

    private function partitionPath(int $partitionIndex): string
    {
        $base = preg_replace('/\.utoc$/i', '', $this->utocPath);
        if (!is_string($base) || $base === $this->utocPath) {
            $base = $this->utocPath;
        }
        return $base . ($partitionIndex > 0 ? '_s' . $partitionIndex : '') . '.ucas';
    }

    private function parse(string $bytes): void
    {
        $reader = new Uedb5BinaryReader($bytes, basename($this->utocPath));
        $magic = $reader->read(16);
        if ($magic !== self::TOC_MAGIC) {
            throw new RuntimeException('IoStore TOC magic mismatch.');
        }

        $version = $reader->u8();
        $reader->u8();
        $reader->u16le();
        $tocHeaderSize = $reader->u32le();
        $tocEntryCount = $reader->u32le();
        $blockEntryCount = $reader->u32le();
        $blockEntrySize = $reader->u32le();
        $methodCount = $reader->u32le();
        $methodNameLength = $reader->u32le();
        $compressionBlockSize = $reader->u32le();
        $directoryIndexSize = $reader->u32le();
        $partitionCount = $reader->u32le();
        $containerId = $reader->u64HexLe();
        $encryptionKeyGuidRaw = strtoupper(bin2hex($reader->read(16)));
        $containerFlags = $reader->u8();
        $reader->u8();
        $reader->u16le();
        $perfectHashSeedCount = $reader->u32le();
        $partitionSizeHex = $reader->u64HexLe();
        $chunksWithoutPerfectHashCount = $reader->u32le();
        $reader->u32le();
        $reader->read(40);

        if ($tocHeaderSize !== self::TOC_HEADER_SIZE || $reader->tell() !== self::TOC_HEADER_SIZE) {
            throw new RuntimeException('IoStore TOC header size does not match UE5 5.8.3 FIoStoreTocHeader.');
        }
        if ($blockEntrySize !== 12) {
            throw new RuntimeException('IoStore compressed block entry size is not 12 bytes.');
        }
        if ($version < self::TOC_VERSION_DIRECTORY_INDEX || $version > self::TOC_VERSION_LATEST) {
            throw new RuntimeException('Unsupported IoStore TOC version: ' . $version);
        }

        $partitionSize = Uedb5BinaryReader::unsignedHexLeToIntOrNull($partitionSizeHex);
        if ($partitionSize === null) {
            if ($partitionSizeHex === 'FFFFFFFFFFFFFFFF') {
                $partitionSize = PHP_INT_MAX;
            } else {
                throw new RuntimeException('IoStore partition size exceeds the staging reader integer range: ' . $partitionSizeHex);
            }
        }
        if ($version < 3) {
            $partitionCount = 1;
            $partitionSize = PHP_INT_MAX;
        }

        $this->header = [
            'version' => $version,
            'toc_header_size' => $tocHeaderSize,
            'toc_entry_count' => $tocEntryCount,
            'compressed_block_entry_count' => $blockEntryCount,
            'compression_method_name_count' => $methodCount,
            'compression_method_name_length' => $methodNameLength,
            'compression_block_size' => $compressionBlockSize,
            'directory_index_size' => $directoryIndexSize,
            'partition_count' => $partitionCount,
            'partition_size' => $partitionSize,
            'partition_size_u64' => $partitionSizeHex,
            'container_id' => $containerId,
            'encryption_key_guid_raw' => $encryptionKeyGuidRaw,
            'container_flags' => $containerFlags,
            'perfect_hash_seed_count' => $perfectHashSeedCount,
            'chunks_without_perfect_hash_count' => $chunksWithoutPerfectHashCount,
        ];

        $chunkIds = [];
        for ($index = 0; $index < $tocEntryCount; $index++) {
            $raw = $reader->read(12);
            $chunkIds[] = [
                'toc_index' => $index,
                'raw' => strtoupper(bin2hex($raw)),
                'package_id' => strtoupper(bin2hex(strrev(substr($raw, 0, 8)))),
                'chunk_index' => (int)unpack('nvalue', substr($raw, 8, 2))['value'],
                'chunk_group' => ord($raw[10]),
                'chunk_type' => ord($raw[11]),
            ];
        }

        $offsets = [];
        for ($index = 0; $index < $tocEntryCount; $index++) {
            $offsets[] = [
                'offset' => $reader->u40be(),
                'length' => $reader->u40be(),
            ];
        }
        foreach ($chunkIds as $index => $chunk) {
            $this->chunks[] = $chunk + $offsets[$index];
        }

        if ($version >= self::TOC_VERSION_PERFECT_HASH) {
            for ($index = 0; $index < $perfectHashSeedCount; $index++) {
                $reader->i32le();
            }
        }
        if ($version >= self::TOC_VERSION_PERFECT_HASH_WITH_OVERFLOW) {
            for ($index = 0; $index < $chunksWithoutPerfectHashCount; $index++) {
                $reader->i32le();
            }
        }

        for ($index = 0; $index < $blockEntryCount; $index++) {
            $this->compressionBlocks[] = [
                'block_index' => $index,
                'offset' => $reader->u40le(),
                'compressed_size' => $reader->u24le(),
                'uncompressed_size' => $reader->u24le(),
                'compression_method_index' => $reader->u8(),
            ];
        }

        for ($index = 0; $index < $methodCount; $index++) {
            $raw = $reader->read($methodNameLength);
            $nul = strpos($raw, "\0");
            $name = $nul === false ? $raw : substr($raw, 0, $nul);
            $this->compressionMethods[] = $name;
        }

        if (($containerFlags & self::CONTAINER_FLAG_SIGNED) !== 0) {
            $hashSize = $reader->i32le();
            if ($hashSize < 0) {
                throw new RuntimeException('IoStore signature hash size is invalid.');
            }
            $reader->read($hashSize * 2);
            $reader->read($blockEntryCount * 20);
        }

        if (($containerFlags & self::CONTAINER_FLAG_INDEXED) !== 0) {
            $this->directoryIndex = $reader->read($directoryIndexSize);
        } elseif ($directoryIndexSize !== 0) {
            throw new RuntimeException('IoStore TOC has directory-index bytes without the Indexed container flag.');
        }

        $metaSize = $version >= self::TOC_VERSION_REPLACE_IO_CHUNK_HASH ? 24 : 33;
        for ($index = 0; $index < $tocEntryCount; $index++) {
            $hashBytes = $reader->read($metaSize === 24 ? 20 : 32);
            $flags = $reader->u8();
            if ($metaSize === 24) {
                $reader->read(3);
            }
            $this->chunkMetas[] = [
                'toc_index' => $index,
                'chunk_hash' => strtoupper(bin2hex($hashBytes)),
                'flags' => $flags,
                'compressed' => ($flags & 0x01) !== 0,
                'memory_mapped' => ($flags & 0x02) !== 0,
            ];
        }

        if ($reader->remaining() !== 0) {
            throw new RuntimeException(
                'IoStore TOC has ' . $reader->remaining() . ' unexpected trailing bytes after the metadata table.'
            );
        }
    }
}
