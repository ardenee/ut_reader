<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/** Bounded little-endian bit reader for ZIP member payloads. */
final class CatalogZipBitReader
{
    /** @var resource */
    private $input;
    private int $remainingBytes;
    private int $bitBuffer = 0;
    private int $bitCount = 0;

    /** @param resource $input */
    public function __construct($input, int $compressedBytes)
    {
        if (!is_resource($input)) {
            throw new \InvalidArgumentException('ZIP bit reader input must be a stream resource.');
        }
        if ($compressedBytes < 0) {
            throw new \InvalidArgumentException('ZIP compressed byte count cannot be negative.');
        }
        $this->input = $input;
        $this->remainingBytes = $compressedBytes;
    }

    public function readBits(int $count): int
    {
        if ($count < 0 || $count > 24) {
            throw new \InvalidArgumentException('ZIP bit reader supports reads between 0 and 24 bits.');
        }
        if ($count === 0) {
            return 0;
        }

        while ($this->bitCount < $count) {
            if ($this->remainingBytes < 1) {
                throw new \RuntimeException('ZIP compressed member ended unexpectedly while reading bits.');
            }
            $byte = fread($this->input, 1);
            if (!is_string($byte) || strlen($byte) !== 1) {
                throw new \RuntimeException('ZIP compressed member could not be read.');
            }
            $this->remainingBytes--;
            $this->bitBuffer |= ord($byte) << $this->bitCount;
            $this->bitCount += 8;
        }

        $mask = (1 << $count) - 1;
        $value = $this->bitBuffer & $mask;
        $this->bitBuffer >>= $count;
        $this->bitCount -= $count;
        return $value;
    }

    public function alignToByte(): void
    {
        $discard = $this->bitCount & 7;
        if ($discard > 0) {
            $this->bitBuffer >>= $discard;
            $this->bitCount -= $discard;
        }
    }

    public function readAlignedBytes(int $count): string
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('ZIP aligned byte count cannot be negative.');
        }
        if (($this->bitCount & 7) !== 0) {
            throw new \LogicException('ZIP aligned byte read requested while bit reader is not byte-aligned.');
        }
        if ($count === 0) {
            return '';
        }

        $prefix = '';
        while ($this->bitCount >= 8 && strlen($prefix) < $count) {
            $prefix .= chr($this->bitBuffer & 0xff);
            $this->bitBuffer >>= 8;
            $this->bitCount -= 8;
        }
        $remaining = $count - strlen($prefix);
        if ($remaining === 0) {
            return $prefix;
        }
        if ($remaining > $this->remainingBytes) {
            throw new \RuntimeException('ZIP compressed member ended unexpectedly during aligned byte read.');
        }

        $data = '';
        while (strlen($data) < $remaining) {
            $chunk = fread($this->input, $remaining - strlen($data));
            if (!is_string($chunk) || $chunk === '') {
                throw new \RuntimeException('ZIP compressed member could not be read during aligned byte read.');
            }
            $data .= $chunk;
        }
        $this->remainingBytes -= $remaining;
        return $prefix . $data;
    }

    public function remainingBytes(): int
    {
        return $this->remainingBytes;
    }

    public function bufferedBits(): int
    {
        return $this->bitCount;
    }
}
