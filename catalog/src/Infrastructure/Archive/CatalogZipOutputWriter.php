<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Archive;

/** Bounded streaming output sink with CRC32 and an O(1) circular history window. */
final class CatalogZipOutputWriter
{
    private const FLUSH_BYTES = 65536;

    /** @var resource */
    private $output;
    /** @var resource|\HashContext */
    private $crc;
    private int $written = 0;
    private string $window;
    private int $windowPosition = 0;
    private int $windowFilled = 0;
    private string $pending = '';
    private int $nextHeartbeat = 1048576;

    /** @param resource $output */
    public function __construct(
        $output,
        private readonly int $maxBytes,
        private readonly int $expectedBytes,
        private readonly int $windowBytes,
        private readonly ?\Closure $heartbeat = null
    ) {
        if (!is_resource($output)) {
            throw new \InvalidArgumentException('ZIP output writer requires a stream resource.');
        }
        if ($maxBytes < 1 || $expectedBytes < 0 || $windowBytes < 1) {
            throw new \InvalidArgumentException('ZIP output writer limits are invalid.');
        }
        if ($expectedBytes > $maxBytes) {
            throw new \RuntimeException('ZIP member exceeds its configured extraction limit.');
        }
        $this->output = $output;
        $this->crc = hash_init('crc32b');
        $this->window = str_repeat("\0", $windowBytes);
    }

    public function bytesWritten(): int
    {
        return $this->written;
    }

    public function remainingExpected(): int
    {
        return max(0, $this->expectedBytes - $this->written);
    }

    public function writeByte(int $byte): void
    {
        if ($byte < 0 || $byte > 255) {
            throw new \InvalidArgumentException('ZIP decoded byte is outside the 0..255 range.');
        }
        $this->writeString(chr($byte));
    }

    public function writeString(string $data): void
    {
        if ($data === '') {
            return;
        }
        $length = strlen($data);
        $newTotal = $this->written + $length;
        if ($newTotal > $this->maxBytes || ($this->expectedBytes > 0 && $newTotal > $this->expectedBytes)) {
            throw new \RuntimeException('ZIP decoded member exceeded its declared or configured output size.');
        }

        $this->pending .= $data;
        $this->appendHistory($data);
        $this->written = $newTotal;

        if (strlen($this->pending) >= self::FLUSH_BYTES) {
            $this->flush();
        }
        if ($this->heartbeat !== null && $this->written >= $this->nextHeartbeat) {
            ($this->heartbeat)();
            $this->nextHeartbeat = $this->written + 1048576;
        }
    }

    public function copyDistance(int $distance, int $length, bool $allowZeroPrefix = false): void
    {
        if ($distance < 1 || $distance > $this->windowBytes) {
            throw new \RuntimeException('ZIP back-reference distance is outside the decoder window.');
        }
        if ($length < 1) {
            throw new \RuntimeException('ZIP back-reference length must be positive.');
        }

        if ($distance <= $this->written) {
            $seed = $this->historyBytes($distance);
        } else {
            if (!$allowZeroPrefix) {
                throw new \RuntimeException('ZIP back-reference points before the start of decoded output.');
            }
            // Implode defines bytes before output offset zero as zero-filled. At
            // this point written < distance <= 8K, so all real output is retained.
            $seed = str_repeat("\0", $distance - $this->written)
                . $this->historyBytes($this->written);
        }

        if ($seed === '' || strlen($seed) !== $distance) {
            throw new \RuntimeException('ZIP back-reference seed could not be reconstructed.');
        }

        $remaining = $length;
        while ($remaining > 0) {
            $take = min($remaining, self::FLUSH_BYTES);
            $repeats = intdiv($take + $distance - 1, $distance);
            $chunk = substr(str_repeat($seed, max(1, $repeats)), 0, $take);
            $this->writeString($chunk);
            $remaining -= $take;
        }
    }

    /** @return array{bytes:int,crc32:string} */
    public function finish(): array
    {
        $this->flush();
        if ($this->expectedBytes > 0 && $this->written !== $this->expectedBytes) {
            throw new \RuntimeException(
                'ZIP decoded member size mismatch; expected ' . number_format($this->expectedBytes)
                . ' bytes, got ' . number_format($this->written) . ' bytes.'
            );
        }
        return [
            'bytes' => $this->written,
            'crc32' => strtolower(hash_final($this->crc, false)),
        ];
    }

    private function appendHistory(string $data): void
    {
        $length = strlen($data);
        if ($length >= $this->windowBytes) {
            $this->window = substr($data, -$this->windowBytes);
            $this->windowPosition = 0;
            $this->windowFilled = $this->windowBytes;
            return;
        }

        if ($length === 1) {
            $this->window[$this->windowPosition] = $data;
            $this->windowPosition++;
            if ($this->windowPosition === $this->windowBytes) {
                $this->windowPosition = 0;
            }
            $this->windowFilled = min($this->windowBytes, $this->windowFilled + 1);
            return;
        }

        $first = min($length, $this->windowBytes - $this->windowPosition);
        $this->window = substr_replace($this->window, substr($data, 0, $first), $this->windowPosition, $first);
        $rest = $length - $first;
        if ($rest > 0) {
            $this->window = substr_replace($this->window, substr($data, $first), 0, $rest);
        }
        $this->windowPosition = ($this->windowPosition + $length) % $this->windowBytes;
        $this->windowFilled = min($this->windowBytes, $this->windowFilled + $length);
    }

    private function historyBytes(int $count): string
    {
        if ($count === 0) {
            return '';
        }
        if ($count < 0 || $count > $this->windowFilled) {
            throw new \RuntimeException('ZIP requested history is not available in the decoder window.');
        }
        $start = $this->windowPosition - $count;
        if ($start < 0) {
            $start += $this->windowBytes;
        }
        if ($start + $count <= $this->windowBytes) {
            return substr($this->window, $start, $count);
        }
        $first = $this->windowBytes - $start;
        return substr($this->window, $start, $first) . substr($this->window, 0, $count - $first);
    }

    private function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        $length = strlen($this->pending);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($this->output, substr($this->pending, $offset));
            if ($written === false || $written < 1) {
                throw new \RuntimeException('Could not write decoded ZIP member to temporary storage.');
            }
            $offset += $written;
        }
        hash_update($this->crc, $this->pending);
        $this->pending = '';
    }
}
