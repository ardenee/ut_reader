<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/** Native PHP decoder for PKZIP method 6 (Implode). */
final class CatalogZipImplodeDecoder
{
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
        int $flags,
        int $maxBytes,
        ?callable $heartbeat = null
    ): array {
        if ($compressedBytes < 1 || $expectedBytes < 1) {
            throw new \RuntimeException('Imploded ZIP member has an invalid compressed or uncompressed size.');
        }

        $reader = new CatalogZipBitReader($input, $compressedBytes);
        $codedLiterals = ($flags & 0x0004) !== 0;
        $distanceLowBits = ($flags & 0x0002) !== 0 ? 7 : 6;
        $windowBytes = 1 << (6 + $distanceLowBits);
        $minimumMatch = $codedLiterals ? 3 : 2;

        $literalTree = null;
        if ($codedLiterals) {
            $literalTree = CatalogZipHuffmanTree::fromLengths(
                $this->readTreeLengths($reader, 256, 'literal'),
                true
            );
        }
        $lengthTree = CatalogZipHuffmanTree::fromLengths(
            $this->readTreeLengths($reader, 64, 'length'),
            true
        );
        $distanceTree = CatalogZipHuffmanTree::fromLengths(
            $this->readTreeLengths($reader, 64, 'distance'),
            true
        );

        $heartbeatClosure = $heartbeat !== null ? \Closure::fromCallable($heartbeat) : null;
        $writer = new CatalogZipOutputWriter(
            $output,
            $maxBytes,
            $expectedBytes,
            $windowBytes,
            $heartbeatClosure
        );

        while ($writer->bytesWritten() < $expectedBytes) {
            if ($reader->readBits(1) !== 0) {
                $literal = $literalTree !== null
                    ? $literalTree->decode($reader)
                    : $reader->readBits(8);
                $writer->writeByte($literal);
                continue;
            }

            $distanceLow = $reader->readBits($distanceLowBits);
            $distanceHigh = $distanceTree->decode($reader);
            $distance = (($distanceHigh << $distanceLowBits) | $distanceLow) + 1;

            $lengthCode = $lengthTree->decode($reader);
            $length = $lengthCode + $minimumMatch;
            if ($lengthCode === 63) {
                $length += $reader->readBits(8);
            }
            if ($length > $writer->remainingExpected()) {
                throw new \RuntimeException(
                    'Imploded ZIP match exceeds the member uncompressed size; match=' . number_format($length)
                    . ', remaining=' . number_format($writer->remainingExpected()) . '.'
                );
            }
            $writer->copyDistance($distance, $length, true);
        }

        // Implode has no end-of-stream marker; the ZIP central-directory
        // uncompressed size is authoritative. A valid encoder can leave padding
        // bits in the final byte, but should not leave additional whole bytes.
        if ($reader->remainingBytes() !== 0) {
            throw new \RuntimeException(
                'Imploded ZIP member reached its declared output size with '
                . number_format($reader->remainingBytes()) . ' unread compressed bytes.'
            );
        }

        return $writer->finish();
    }

    /** @return list<int> */
    private function readTreeLengths(CatalogZipBitReader $reader, int $symbols, string $label): array
    {
        $descriptionBytes = $reader->readBits(8) + 1;
        $lengths = [];
        for ($index = 0; $index < $descriptionBytes; $index++) {
            $descriptor = $reader->readBits(8);
            $repeat = (($descriptor >> 4) & 0x0f) + 1;
            $bitLength = ($descriptor & 0x0f) + 1;
            if (count($lengths) + $repeat > $symbols) {
                throw new \RuntimeException('Imploded ZIP ' . $label . ' tree contains too many symbols.');
            }
            for ($item = 0; $item < $repeat; $item++) {
                $lengths[] = $bitLength;
            }
        }
        if (count($lengths) !== $symbols) {
            throw new \RuntimeException(
                'Imploded ZIP ' . $label . ' tree contains ' . number_format(count($lengths))
                . ' symbols; expected ' . number_format($symbols) . '.'
            );
        }
        return $lengths;
    }
}
