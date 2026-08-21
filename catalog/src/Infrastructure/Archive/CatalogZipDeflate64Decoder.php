<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/** Native PHP inflater for ZIP compression method 9 (Deflate64/Enhanced Deflate). */
final class CatalogZipDeflate64Decoder
{
    /** @var list<int> */
    private const LENGTH_BASE = [
        3, 4, 5, 6, 7, 8, 9, 10,
        11, 13, 15, 17, 19, 23, 27, 31,
        35, 43, 51, 59, 67, 83, 99, 115,
        131, 163, 195, 227, 3,
    ];

    /** @var list<int> */
    private const LENGTH_EXTRA = [
        0, 0, 0, 0, 0, 0, 0, 0,
        1, 1, 1, 1, 2, 2, 2, 2,
        3, 3, 3, 3, 4, 4, 4, 4,
        5, 5, 5, 5, 16,
    ];

    /** @var list<int> */
    private const DISTANCE_BASE = [
        1, 2, 3, 4, 5, 7, 9, 13,
        17, 25, 33, 49, 65, 97, 129, 193,
        257, 385, 513, 769, 1025, 1537, 2049, 3073,
        4097, 6145, 8193, 12289, 16385, 24577, 32769, 49153,
    ];

    /** @var list<int> */
    private const DISTANCE_EXTRA = [
        0, 0, 0, 0, 1, 1, 2, 2,
        3, 3, 4, 4, 5, 5, 6, 6,
        7, 7, 8, 8, 9, 9, 10, 10,
        11, 11, 12, 12, 13, 13, 14, 14,
    ];

    private const CODE_LENGTH_ORDER = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];

    /**
     * @param resource $input Positioned at the start of the compressed member.
     * @param resource $output
     * @return array{bytes:int,crc32:string}
     */
    public function decode(
        $input,
        $output,
        int $compressedBytes,
        int $expectedBytes,
        int $maxBytes,
        ?callable $heartbeat = null
    ): array {
        if ($compressedBytes < 1 || $expectedBytes < 1) {
            throw new \RuntimeException('Deflate64 ZIP member has an invalid compressed or uncompressed size.');
        }

        $reader = new CatalogZipBitReader($input, $compressedBytes);
        $heartbeatClosure = $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null;
        $writer = new CatalogZipOutputWriter($output, $maxBytes, $expectedBytes, 65536, $heartbeatClosure);

        do {
            $final = $reader->readBits(1) !== 0;
            $blockType = $reader->readBits(2);
            if ($blockType === 0) {
                $this->decodeStoredBlock($reader, $writer);
            } elseif ($blockType === 1) {
                [$literalTree, $distanceTree] = $this->fixedTrees();
                $this->decodeCompressedBlock($reader, $writer, $literalTree, $distanceTree);
            } elseif ($blockType === 2) {
                [$literalTree, $distanceTree] = $this->dynamicTrees($reader);
                $this->decodeCompressedBlock($reader, $writer, $literalTree, $distanceTree);
            } else {
                throw new \RuntimeException('Deflate64 stream contains the reserved block type 3.');
            }
        } while (!$final);

        if ($reader->remainingBytes() !== 0) {
            throw new \RuntimeException(
                'Deflate64 ZIP member ended with ' . number_format($reader->remainingBytes())
                . ' unread compressed bytes.'
            );
        }

        return $writer->finish();
    }

    private function decodeStoredBlock(CatalogZipBitReader $reader, CatalogZipOutputWriter $writer): void
    {
        $reader->alignToByte();
        $length = $reader->readBits(16);
        $inverse = $reader->readBits(16);
        if ((($length ^ 0xffff) & 0xffff) !== $inverse) {
            throw new \RuntimeException('Deflate64 stored block length check failed.');
        }
        if ($length > $writer->remainingExpected()) {
            throw new \RuntimeException('Deflate64 stored block exceeds the member uncompressed size.');
        }
        $remaining = $length;
        while ($remaining > 0) {
            $take = min($remaining, 65536);
            $writer->writeString($reader->readAlignedBytes($take));
            $remaining -= $take;
        }
    }

    /** @return array{CatalogZipHuffmanTree,CatalogZipHuffmanTree} */
    private function fixedTrees(): array
    {
        $literalLengths = array_fill(0, 288, 0);
        for ($symbol = 0; $symbol <= 143; $symbol++) {
            $literalLengths[$symbol] = 8;
        }
        for ($symbol = 144; $symbol <= 255; $symbol++) {
            $literalLengths[$symbol] = 9;
        }
        for ($symbol = 256; $symbol <= 279; $symbol++) {
            $literalLengths[$symbol] = 7;
        }
        for ($symbol = 280; $symbol <= 287; $symbol++) {
            $literalLengths[$symbol] = 8;
        }
        return [
            CatalogZipHuffmanTree::fromLengths($literalLengths),
            CatalogZipHuffmanTree::fromLengths(array_fill(0, 32, 5)),
        ];
    }

    /** @return array{CatalogZipHuffmanTree,CatalogZipHuffmanTree} */
    private function dynamicTrees(CatalogZipBitReader $reader): array
    {
        $literalCount = $reader->readBits(5) + 257;
        $distanceCount = $reader->readBits(5) + 1;
        $codeLengthCount = $reader->readBits(4) + 4;

        $codeLengthLengths = array_fill(0, 19, 0);
        for ($index = 0; $index < $codeLengthCount; $index++) {
            $codeLengthLengths[self::CODE_LENGTH_ORDER[$index]] = $reader->readBits(3);
        }
        $codeLengthTree = CatalogZipHuffmanTree::fromLengths($codeLengthLengths);

        $allLengths = [];
        $required = $literalCount + $distanceCount;
        while (count($allLengths) < $required) {
            $symbol = $codeLengthTree->decode($reader);
            if ($symbol <= 15) {
                $allLengths[] = $symbol;
                continue;
            }
            if ($symbol === 16) {
                if ($allLengths === []) {
                    throw new \RuntimeException('Deflate64 repeat code 16 appears before any code length.');
                }
                $repeat = $reader->readBits(2) + 3;
                $value = $allLengths[count($allLengths) - 1];
            } elseif ($symbol === 17) {
                $repeat = $reader->readBits(3) + 3;
                $value = 0;
            } elseif ($symbol === 18) {
                $repeat = $reader->readBits(7) + 11;
                $value = 0;
            } else {
                throw new \RuntimeException('Deflate64 code-length tree produced an invalid symbol.');
            }
            if (count($allLengths) + $repeat > $required) {
                throw new \RuntimeException('Deflate64 repeated code lengths exceed the declared tree sizes.');
            }
            for ($item = 0; $item < $repeat; $item++) {
                $allLengths[] = $value;
            }
        }

        $literalLengths = array_slice($allLengths, 0, $literalCount);
        $distanceLengths = array_slice($allLengths, $literalCount, $distanceCount);
        if (($literalLengths[256] ?? 0) === 0) {
            throw new \RuntimeException('Deflate64 literal tree does not contain an end-of-block symbol.');
        }
        return [
            CatalogZipHuffmanTree::fromLengths($literalLengths),
            CatalogZipHuffmanTree::fromLengths($distanceLengths),
        ];
    }

    private function decodeCompressedBlock(
        CatalogZipBitReader $reader,
        CatalogZipOutputWriter $writer,
        CatalogZipHuffmanTree $literalTree,
        CatalogZipHuffmanTree $distanceTree
    ): void {
        while (true) {
            $symbol = $literalTree->decode($reader);
            if ($symbol < 256) {
                $writer->writeByte($symbol);
                continue;
            }
            if ($symbol === 256) {
                return;
            }
            if ($symbol < 257 || $symbol > 285) {
                throw new \RuntimeException('Deflate64 literal/length tree produced reserved symbol ' . $symbol . '.');
            }

            $lengthIndex = $symbol - 257;
            $length = self::LENGTH_BASE[$lengthIndex];
            $extraLengthBits = self::LENGTH_EXTRA[$lengthIndex];
            if ($extraLengthBits > 0) {
                $length += $reader->readBits($extraLengthBits);
            }
            if ($length > $writer->remainingExpected()) {
                throw new \RuntimeException(
                    'Deflate64 match exceeds the member uncompressed size; match=' . number_format($length)
                    . ', remaining=' . number_format($writer->remainingExpected()) . '.'
                );
            }

            $distanceSymbol = $distanceTree->decode($reader);
            if ($distanceSymbol < 0 || $distanceSymbol > 31) {
                throw new \RuntimeException('Deflate64 distance tree produced an invalid symbol.');
            }
            $distance = self::DISTANCE_BASE[$distanceSymbol];
            $extraDistanceBits = self::DISTANCE_EXTRA[$distanceSymbol];
            if ($extraDistanceBits > 0) {
                $distance += $reader->readBits($extraDistanceBits);
            }
            if ($distance > 65536) {
                throw new \RuntimeException('Deflate64 distance exceeds the 64 KiB history window.');
            }
            $writer->copyDistance($distance, $length, false);
        }
    }
}
