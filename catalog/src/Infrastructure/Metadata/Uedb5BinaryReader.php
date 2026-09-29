<?php
/**
 * Small little-endian binary cursor used by isolated UEDB5 staging readers.
 * Unsigned 64-bit source identities are exposed as fixed-width hex, never PHP ints.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5BinaryReader
{
    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly string $label = 'binary data'
    ) {
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function remaining(): int
    {
        return $this->size() - $this->offset;
    }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size()) {
            throw new RuntimeException($this->label . ': seek is outside the buffer.');
        }
        $this->offset = $offset;
    }

    public function skip(int $length): void
    {
        $this->seek($this->offset + $length);
    }

    public function read(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new RuntimeException($this->label . ': read exceeds the available bytes.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    public function u8(): int
    {
        return ord($this->read(1));
    }

    public function u16le(): int
    {
        return (int)unpack('vvalue', $this->read(2))['value'];
    }

    public function u16be(): int
    {
        return (int)unpack('nvalue', $this->read(2))['value'];
    }

    public function u24le(): int
    {
        $bytes = $this->read(3);
        return ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16);
    }

    public function u32le(): int
    {
        return (int)unpack('Vvalue', $this->read(4))['value'];
    }

    public function i32le(): int
    {
        $value = $this->u32le();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function u40be(): int
    {
        $bytes = $this->read(5);
        $value = 0;
        for ($index = 0; $index < 5; $index++) {
            $value = ($value * 256) + ord($bytes[$index]);
        }
        return $value;
    }

    public function u40le(): int
    {
        $bytes = $this->read(5);
        $value = 0;
        for ($index = 4; $index >= 0; $index--) {
            $value = ($value * 256) + ord($bytes[$index]);
        }
        return $value;
    }

    public function u64HexLe(): string
    {
        return strtoupper(bin2hex(strrev($this->read(8))));
    }

    public function u64IntLe(): int
    {
        $low = $this->u32le();
        $high = $this->u32le();
        if ($high > 0x7fffffff) {
            throw new RuntimeException($this->label . ': uint64 does not fit a non-negative PHP int.');
        }
        return (int)($high * 4294967296 + $low);
    }

    public function i64le(): int
    {
        $low = $this->u32le();
        $high = $this->u32le();
        if (($high & 0x80000000) === 0) {
            return (int)($high * 4294967296 + $low);
        }
        $invLow = (~$low) & 0xffffffff;
        $invHigh = (~$high) & 0xffffffff;
        $magnitude = (int)($invHigh * 4294967296 + $invLow + 1);
        return -$magnitude;
    }

    public static function unsignedHexLeToIntOrNull(string $hex): ?int
    {
        $hex = strtoupper(trim($hex));
        if (preg_match('/^[0-9A-F]{16}$/', $hex) !== 1 || $hex > '7FFFFFFFFFFFFFFF') {
            return null;
        }
        $high = hexdec(substr($hex, 0, 8));
        $low = hexdec(substr($hex, 8, 8));
        return (int)($high * 4294967296 + $low);
    }

    /** @return array{raw_index:int,type:int,index:int} */
    public static function decodeMappedNameIndex(int $rawIndex): array
    {
        $unsigned = $rawIndex & 0xffffffff;
        return [
            'raw_index' => $unsigned,
            'type' => ($unsigned >> 30) & 0x3,
            'index' => $unsigned & 0x3fffffff,
        ];
    }
}
