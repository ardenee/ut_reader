<?php

namespace net\shrimpworks\unreal\packages;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reader for Unreal UMOD packages.
 *
 * This file intentionally avoids Java-style nested classes. PHP does not support
 * declarations such as "public class" inside another class, so the helper
 * classes are declared as normal top-level classes in this namespace.
 */
class Umod
{
    private const UMOD_SIGNATURE = 0x9FE3C5A3;

    private UmodBinaryReader $reader;

    public int $version = 0;
    public int $size = 0;
    /** @var UmodFile[] */
    public array $files = [];

    public ?UmodFile $manifestIni = null;
    public ?UmodFile $manifestInt = null;

    public function __construct(string|UmodBinaryReader $umodFile)
    {
        $this->reader = is_string($umodFile) ? new UmodBinaryReader($umodFile) : $umodFile;

        $fileSize = $this->reader->size();
        if ($fileSize < 20) {
            throw new InvalidArgumentException('File is too small to be a UMOD package.');
        }

        $this->reader->seek($fileSize - 20);

        $signature = $this->reader->readUInt32();
        if ($signature !== self::UMOD_SIGNATURE) {
            throw new InvalidArgumentException(sprintf('Package does not seem to be a UMOD package. Signature was 0x%08X.', $signature));
        }

        $filesOffset = $this->reader->readInt32();
        $this->size = $this->reader->readInt32();
        $this->version = $this->reader->readInt32();
        $checksum = $this->reader->readInt32();

        if ($filesOffset < 0 || $filesOffset >= $fileSize) {
            throw new RuntimeException('Invalid UMOD file table offset: ' . $filesOffset);
        }

        $this->reader->seek($filesOffset);
        $fileCount = $this->reader->readCompactIndex();

        if ($fileCount < 0 || $fileCount > 100000) {
            throw new RuntimeException('Invalid UMOD file count: ' . $fileCount);
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $this->files[] = $this->readFile();
        }

        foreach ($this->files as $file) {
            $lower = strtolower($file->name);
            if ($this->manifestIni === null && substr($lower, -12) === 'manifest.ini') {
                $this->manifestIni = $file;
            }
            if ($this->manifestInt === null && substr($lower, -12) === 'manifest.int') {
                $this->manifestInt = $file;
            }
        }
    }

    public function close(): void
    {
        $this->reader->close();
    }

    private function readFile(): UmodFile
    {
        $nameSize = $this->reader->readCompactIndex();
        if ($nameSize <= 0 || $nameSize > 4096) {
            throw new RuntimeException('Invalid UMOD filename length: ' . $nameSize);
        }

        $name = rtrim($this->reader->readBytes($nameSize), "\0\r\n\t ");
        $offset = $this->reader->readInt32();
        $size = $this->reader->readInt32();
        $flags = $this->reader->readInt32();

        if ($offset < 0 || $size < 0 || ($offset + $size) > $this->reader->size()) {
            throw new RuntimeException('Invalid UMOD file entry: ' . $name);
        }

        return new UmodFile($this->reader, $name, $size, $offset, $flags);
    }
}

class UmodFile
{
    private UmodBinaryReader $reader;

    public string $name;
    public int $size;
    public int $offset;
    public int $flags;

    public function __construct(UmodBinaryReader $reader, string $name, int $size, int $offset, int $flags)
    {
        $this->reader = $reader;
        $this->name = $name;
        $this->size = $size;
        $this->offset = $offset;
        $this->flags = $flags;
    }

    public function read(): UmodFileChannel
    {
        return new UmodFileChannel($this->reader, $this->offset, $this->size);
    }

    public function contents(): string
    {
        $this->reader->seek($this->offset);
        return $this->reader->readBytes($this->size);
    }

    public function saveTo(string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('Could not create output folder: ' . $dir);
        }

        if (file_put_contents($targetPath, $this->contents()) === false) {
            throw new RuntimeException('Could not write file: ' . $targetPath);
        }
    }

    public function sha1(): string
    {
        return sha1($this->contents());
    }

    public function __toString(): string
    {
        return sprintf('UmodFile [name=%s, size=%d, offset=%d]', $this->name, $this->size, $this->offset);
    }
}

class UmodFileChannel
{
    private UmodBinaryReader $reader;
    private int $offset;
    private int $size;
    private int $position = 0;

    public function __construct(UmodBinaryReader $reader, int $offset, int $size)
    {
        $this->reader = $reader;
        $this->offset = $offset;
        $this->size = $size;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Read length cannot be negative.');
        }

        if ($this->position >= $this->size) {
            return '';
        }

        $length = min($length, $this->size - $this->position);
        $this->reader->seek($this->offset + $this->position);
        $data = $this->reader->readBytes($length);
        $this->position += strlen($data);
        return $data;
    }

    public function seek(int $newPosition): void
    {
        if ($newPosition < 0 || $newPosition > $this->size) {
            throw new InvalidArgumentException('Cannot seek outside UMOD file entry bounds.');
        }
        $this->position = $newPosition;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function size(): int
    {
        return $this->size;
    }
}

class UmodBinaryReader
{
    /** @var resource */
    private $handle;
    private int $size;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('File not found: ' . $path);
        }
        if (!is_readable($path)) {
            throw new RuntimeException('File is not readable: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Could not open file: ' . $path);
        }

        $this->handle = $handle;
        $fileSize = filesize($path);
        if ($fileSize === false) {
            throw new RuntimeException('Could not determine file size: ' . $path);
        }
        $this->size = (int)$fileSize;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function size(): int
    {
        return $this->size;
    }

    public function tell(): int
    {
        $pos = ftell($this->handle);
        if ($pos === false) {
            throw new RuntimeException('Could not get file position.');
        }
        return $pos;
    }

    public function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->size) {
            throw new RuntimeException('Seek outside file bounds: ' . $offset);
        }
        if (fseek($this->handle, $offset, SEEK_SET) !== 0) {
            throw new RuntimeException('Could not seek to offset: ' . $offset);
        }
    }

    public function readBytes(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Read length cannot be negative.');
        }
        if ($length === 0) {
            return '';
        }

        $data = fread($this->handle, $length);
        if ($data === false) {
            throw new RuntimeException('Failed reading bytes.');
        }
        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of file. Wanted ' . $length . ' bytes, got ' . strlen($data) . '.');
        }
        return $data;
    }

    public function readUInt8(): int
    {
        return ord($this->readBytes(1));
    }

    public function readUInt32(): int
    {
        $v = unpack('V', $this->readBytes(4));
        return (int)$v[1];
    }

    public function readInt32(): int
    {
        $v = $this->readUInt32();
        return ($v >= 0x80000000) ? ($v - 0x100000000) : $v;
    }

    /**
     * Reads Unreal's compact index format.
     */
    public function readCompactIndex(): int
    {
        $b0 = $this->readUInt8();
        $negative = ($b0 & 0x80) !== 0;
        $value = $b0 & 0x3F;
        $shift = 6;

        if (($b0 & 0x40) !== 0) {
            for ($i = 0; $i < 4; $i++) {
                $b = $this->readUInt8();
                $value |= ($b & 0x7F) << $shift;
                if (($b & 0x80) === 0) {
                    break;
                }
                $shift += 7;
            }
        }

        return $negative ? -$value : $value;
    }
}

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    echo '<!doctype html><html><head><meta charset="utf-8"><title>UMOD Reader</title>';
    echo '<style>body{font-family:system-ui;margin:24px;background:#111;color:#ddd}input{padding:6px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border-bottom:1px solid #333;padding:6px;text-align:left}.err{color:#ff9f9f}</style>';
    echo '</head><body><h1>UMOD Reader</h1>';
    echo '<form method="get"><label>UMOD path: <input type="text" name="file" style="width:520px" value="' . htmlspecialchars((string)($_GET['file'] ?? ''), ENT_QUOTES) . '"></label><input type="submit" value="Open"></form>';

    $file = trim((string)($_GET['file'] ?? ''));
    if ($file !== '') {
        try {
            $umod = new Umod($file);
            echo '<p>Version: ' . htmlspecialchars((string)$umod->version) . ' | Size: ' . htmlspecialchars((string)$umod->size) . ' | Files: ' . count($umod->files) . '</p>';
            echo '<table><thead><tr><th>#</th><th>Name</th><th>Size</th><th>Offset</th><th>Flags</th><th>SHA1</th></tr></thead><tbody>';
            foreach ($umod->files as $i => $entry) {
                echo '<tr><td>' . $i . '</td><td>' . htmlspecialchars($entry->name, ENT_QUOTES) . '</td><td>' . number_format($entry->size) . '</td><td>' . $entry->offset . '</td><td>0x' . strtoupper(str_pad(dechex($entry->flags), 8, '0', STR_PAD_LEFT)) . '</td><td>' . htmlspecialchars($entry->sha1(), ENT_QUOTES) . '</td></tr>';
            }
            echo '</tbody></table>';
            $umod->close();
        } catch (\Throwable $t) {
            echo '<p class="err"><strong>Error:</strong> ' . htmlspecialchars($t->getMessage(), ENT_QUOTES) . '</p>';
        }
    }

    echo '</body></html>';
}
