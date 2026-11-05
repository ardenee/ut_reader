<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Private low-level byte stream for UE1 archive parsing.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use OutOfBoundsException;
use RuntimeException;
use Throwable;

/**
 * Bounded UE1 archive stream owned by this engine. No other engine imports it.
 */
final class CatalogUE1BinaryStream
{
    /** @var resource */
    private $handle;
    private int $size;
    private int $position = 0;

    public function __construct(string $path)
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new RuntimeException('Legacy package is missing or empty: ' . basename($path));
        }
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open legacy package: ' . basename($path));
        }
        $this->handle = $handle;
        $this->size = (int)$size;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function remaining(): int
    {
        return $this->size - $this->position;
    }

    public function peekHex(int $offset, int $length = 32): string
    {
        if ($offset < 0 || $offset >= $this->size || $length < 1) {
            return '';
        }

        $saved = $this->position;
        $available = min($length, $this->size - $offset);
        if (fseek($this->handle, $offset, SEEK_SET) !== 0) {
            return '';
        }

        $data = fread($this->handle, $available);
        @fseek($this->handle, $saved, SEEK_SET);
        if (!is_string($data)) {
            return '';
        }

        return strtoupper(bin2hex($data));
    }

    public function seek(int $position): void
    {
        if ($position < 0 || $position > $this->size) {
            throw new OutOfBoundsException('Legacy package seek is outside the file: ' . $position . '/' . $this->size);
        }
        if (fseek($this->handle, $position, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek within the legacy package.');
        }
        $this->position = $position;
    }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->position + $count > $this->size) {
            throw new OutOfBoundsException(
                'Legacy package read exceeds the file: need=' . $count
                . ' position=' . $this->position . ' size=' . $this->size
            );
        }
        if ($count === 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $count) {
            $part = fread($this->handle, $count - strlen($data));
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Legacy package read stopped before the requested bytes were available.');
            }
            $data .= $part;
        }
        $this->position += $count;
        return $data;
    }

    public function u8(): int
    {
        return ord($this->bytes(1));
    }

    public function u16(): int
    {
        return (int)unpack('vvalue', $this->bytes(2))['value'];
    }

    public function u32(): int
    {
        return (int)unpack('Vvalue', $this->bytes(4))['value'];
    }

    public function i32(): int
    {
        $value = $this->u32();
        return ($value & 0x80000000) !== 0 ? $value - 0x100000000 : $value;
    }

    public function compactIndex(): int
    {
        $byte = $this->u8();
        $negative = ($byte & 0x80) !== 0;
        $more = ($byte & 0x40) !== 0;
        $value = $byte & 0x3f;
        $shift = 6;
        $count = 1;

        while ($more) {
            if (++$count > 5) {
                throw new RuntimeException('Invalid compact package index length.');
            }
            $byte = $this->u8();
            $more = ($byte & 0x80) !== 0;
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        }
        // FCompactIndex is an INT, not an arbitrary-precision LEB128.
        // The final byte can encode a magnitude outside signed 32-bit;
        // reject it rather than accepting an impossible table reference.
        if ($value > ($negative ? 0x80000000 : 0x7FFFFFFF)) {
            throw new RuntimeException('UE1 compact index exceeds signed int32 range.');
        }
        return $negative ? -$value : $value;
    }

    public function packageIndex(int $version): int
    {
        // UE1/UE2 package references, FName indices, FString lengths and
        // export serial size/offset fields are serialized with AR_INDEX
        // (FCompactIndex). UT2004 does not switch these fields to int32 at
        // package version 178; ordinary INT fields such as PackageIndex/outer
        // remain explicitly read with i32() by their callers.
        return $this->compactIndex();
    }

    public function cstring(bool $sourceAnsi = false): string
    {
        $value = '';
        while ($this->remaining() > 0) {
            $character = $this->bytes(1);
            if ($character === "\0") {
                return $sourceAnsi ? self::fromSourceAnsi($value) : self::toUtf8($value);
            }
            $value .= $character;
        }
        throw new RuntimeException('Legacy package string reaches end of file without a terminator.');
    }

    /**
     * Original Unreal v1.200 FArchive::String(Name, NAME_SIZE=64):
     * deserialize at most 63 bytes, then terminate the in-memory name.
     * An exactly 63-byte name has no on-disk NUL; the next byte is flags.
     * UT99's v400 pre-64 name operator uses a different NUL-terminated
     * loop, so this method must be selected by the Unreal game loader.
     */
    public function unrealV120Name(): string
    {
        $raw = '';
        for ($count = 0; $count < 63; $count++) {
            $byte = $this->bytes(1);
            if ($byte === "\0") {
                return self::fromSourceAnsi($raw);
            }
            $raw .= $byte;
        }
        return self::fromSourceAnsi($raw);
    }

    public function fstring(int $version, bool $sourceAnsi = false): string
    {
        return $this->stringByLength($this->packageIndex($version), $sourceAnsi);
    }

    private function stringByLength(int $length, bool $sourceAnsi): string
    {
        if ($length === 0) {
            return '';
        }
        if ($length > 0) {
            // Native UE1 FNameEntry has TCHAR Name[64]. Source v400 copies
            // the decoded FString into this fixed buffer; values without a
            // terminator or above 63 characters cannot yield a valid name.
            if ($sourceAnsi && $length > 64) {
                throw new RuntimeException('Invalid UE1 FName FString exceeds NAME_SIZE=64.');
            }
            if ($length > $this->remaining()) {
                throw new OutOfBoundsException('Invalid legacy FString byte length: ' . $length);
            }
            $raw = $this->bytes($length);
            $terminator = strpos($raw, "\0");
            if ($sourceAnsi && $terminator === false) {
                throw new RuntimeException('Invalid UE1 FName FString has no NUL terminator.');
            }
            if ($terminator !== false) {
                $raw = substr($raw, 0, $terminator);
            }
            return $sourceAnsi ? self::fromSourceAnsi($raw) : self::toUtf8($raw);
        }

        $characters = -$length;
        if ($sourceAnsi && $characters > 64) {
            throw new RuntimeException('Invalid UE1 wide FName exceeds NAME_SIZE=64.');
        }
        $bytes = $characters * 2;
        if ($characters > intdiv(PHP_INT_MAX, 2) || $bytes > $this->remaining()) {
            throw new OutOfBoundsException('Invalid legacy wide FString length: ' . $length);
        }
        $raw = $this->bytes($bytes);
        $hasTerminator = false;
        for ($offset = 0; $offset + 1 < strlen($raw); $offset += 2) {
            if ($raw[$offset] === "\0" && $raw[$offset + 1] === "\0") {
                $hasTerminator = true;
                $raw = substr($raw, 0, $offset);
                break;
            }
        }
        if ($sourceAnsi && !$hasTerminator) {
            throw new RuntimeException('Invalid UE1 wide FName has no UTF-16 NUL terminator.');
        }
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        return $converted === false ? '' : $converted;
    }

    /**
     * Epic Core.h::FromAnsi maps each ANSICHAR separately to its unsigned
     * character value. A valid UTF-8-looking byte pair is NOT one character;
     * 0x80 is U+0080, not the Windows-1252 euro sign. UTF-8 is only our
     * lossless presentation format for the engine's 8-bit name characters.
     */
    public static function fromSourceAnsi(string $raw): string
    {
        return \UnrealDb\Catalog\Domain\Package\CatalogUE1AnsiName::fromBytes($raw);
    }

    public static function toUtf8(string $raw): string
    {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return $converted === false ? $raw : $converted;
    }
}
