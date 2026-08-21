<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/** Canonical LSB-first Huffman decoder used by Deflate64 and PKZIP Implode. */
final class CatalogZipHuffmanTree
{
    /** @param array<int,array<int,int>> $codesByLength */
    private function __construct(
        private readonly array $codesByLength,
        private readonly int $maxBits
    ) {
    }

    /**
     * @param list<int> $lengths
     * @param bool $complementCodes PKZIP Implode sends the complement of canonical codes.
     */
    public static function fromLengths(array $lengths, bool $complementCodes = false): self
    {
        $counts = [];
        $maxBits = 0;
        $symbols = 0;
        foreach ($lengths as $length) {
            $length = (int)$length;
            if ($length < 0 || $length > 16) {
                throw new \RuntimeException('ZIP Huffman code length is outside the supported 0..16 bit range.');
            }
            if ($length === 0) {
                continue;
            }
            $counts[$length] = ($counts[$length] ?? 0) + 1;
            $maxBits = max($maxBits, $length);
            $symbols++;
        }
        if ($symbols === 0) {
            throw new \RuntimeException('ZIP Huffman tree contains no symbols.');
        }

        // Reject oversubscribed trees. Incomplete trees are legal in DEFLATE when
        // only a small symbol subset is needed, so a positive remainder is fine.
        $left = 1;
        for ($bits = 1; $bits <= $maxBits; $bits++) {
            $left = ($left << 1) - ($counts[$bits] ?? 0);
            if ($left < 0) {
                throw new \RuntimeException('ZIP Huffman tree is oversubscribed.');
            }
        }

        $nextCode = [];
        $code = 0;
        $previousCount = 0;
        for ($bits = 1; $bits <= $maxBits; $bits++) {
            $code = ($code + $previousCount) << 1;
            $nextCode[$bits] = $code;
            $previousCount = $counts[$bits] ?? 0;
        }

        $codesByLength = [];
        foreach ($lengths as $symbol => $length) {
            $length = (int)$length;
            if ($length === 0) {
                continue;
            }
            $canonical = $nextCode[$length]++;
            $wireCode = self::reverseBits($canonical, $length);
            if ($complementCodes) {
                $wireCode ^= (1 << $length) - 1;
            }
            if (isset($codesByLength[$length][$wireCode])) {
                throw new \RuntimeException('ZIP Huffman tree contains a duplicate code.');
            }
            $codesByLength[$length][$wireCode] = (int)$symbol;
        }

        return new self($codesByLength, $maxBits);
    }

    public function decode(CatalogZipBitReader $reader): int
    {
        $code = 0;
        for ($length = 1; $length <= $this->maxBits; $length++) {
            $code |= $reader->readBits(1) << ($length - 1);
            if (isset($this->codesByLength[$length][$code])) {
                return $this->codesByLength[$length][$code];
            }
        }
        throw new \RuntimeException('ZIP Huffman stream contains an invalid code.');
    }

    private static function reverseBits(int $value, int $bits): int
    {
        $reversed = 0;
        for ($index = 0; $index < $bits; $index++) {
            $reversed = ($reversed << 1) | ($value & 1);
            $value >>= 1;
        }
        return $reversed;
    }
}
