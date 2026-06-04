<?php

interface IPackageReader2
{
    public function load(): void;
    public function getHeader(): array;
    public function getNames(): array;
    public function getImports(): array;
    public function getExports(): array;
    public function getDepends(): array;
    public function getVersion(): int;
    public function isCompressed(): bool;
    public function nameText(int $i): string;
}

final class UER
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $bytes)
    {
        $this->buf = $bytes;
        $this->len = strlen($bytes);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function seek(int $pos): void
    {
        $this->pos = max(0, min($pos, $this->len));
    }

    public function remaining(): int
    {
        return $this->len - $this->pos;
    }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->len) {
            throw new OutOfBoundsException("read overrun need=$count pos={$this->pos} len={$this->len}");
        }

        $out = substr($this->buf, $this->pos, $count);
        $this->pos += $count;
        return $out;
    }

    public function slice(int $offset, int $count): string
    {
        if ($offset < 0 || $count < 0 || $offset + $count > $this->len) {
            throw new OutOfBoundsException("slice overrun offset=$offset count=$count len={$this->len}");
        }

        return substr($this->buf, $offset, $count);
    }

    public function u8(): int { return ord($this->bytes(1)); }
    public function u16(): int { return unpack('v', $this->bytes(2))[1]; }
    public function i16(): int { $v = $this->u16(); return ($v & 0x8000) ? $v - 0x10000 : $v; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }

    public function u64(): int
    {
        $lo = $this->u32();
        $hi = $this->u32();
        return ($hi << 32) | $lo;
    }

    public function compactIndex(): int
    {
        $b = $this->u8();
        $negative = ($b & 0x80) !== 0;
        $more = ($b & 0x40) !== 0;
        $value = $b & 0x3f;
        $shift = 6;
        $bytes = 1;

        while ($more) {
            if (++$bytes > 5) {
                throw new RuntimeException('Invalid compact index length');
            }

            $b = $this->u8();
            $more = ($b & 0x80) !== 0;
            $value |= ($b & 0x7f) << $shift;
            $shift += 7;
        }

        return $negative ? -$value : $value;
    }

    public function versionIndex(int $version): int
    {
        return $version < 178 ? $this->compactIndex() : $this->i32();
    }

    public function cstring(int $max = 1024): string
    {
        $out = '';

        for ($i = 0; $i < $max && $this->remaining() > 0; $i++) {
            $c = $this->bytes(1);

            if ($c === "\0") {
                break;
            }

            $out .= $c;
        }

        return self::ansi($out);
    }

    public function fstringI32(): string
    {
        return $this->fstringFromLength($this->i32());
    }

    public function fstringIndex(int $version): string
    {
        return $this->fstringFromLength($this->versionIndex($version));
    }

    private function fstringFromLength(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        if ($length > 0) {
            if ($length > 65536 || $length > $this->remaining()) {
                throw new OutOfBoundsException("bad FString length=$length pos={$this->pos} remaining={$this->remaining()}");
            }

            $raw = $this->bytes($length);

            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }

            return self::ansi($raw);
        }

        $chars = -$length;
        $bytes = $chars * 2;

        if ($chars > 32768 || $bytes > $this->remaining()) {
            throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos} remaining={$this->remaining()}");
        }

        $raw = $this->bytes($bytes);

        if (substr($raw, -2) === "\0\0") {
            $raw = substr($raw, 0, -2);
        }

        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        return $out === false ? '' : $out;
    }

    private static function ansi(string $raw): string
    {
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return $out === false ? $raw : $out;
    }
}

final class TPackageReader
{
    public static function open(string $path): AbstractUE
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("File not found: $path");
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException("Failed to read: $path");
        }

        $probe = new UER($bytes);
        $tag = $probe->u32();

        if ($tag !== 0x9E2A83C1) {
            throw new RuntimeException(sprintf('Bad package tag 0x%08X', $tag));
        }

        $packedVersion = $probe->u32();
        $version = $packedVersion & 0xffff;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['ut3', 'upk', 'xxx'], true) || $version >= 180 && $version >= 334) {
            $pkg = new TUE3($path, $bytes);
        } elseif ($version >= 120) {
            $pkg = new TUE2($path, $bytes);
        } else {
            $pkg = new TUE1($path, $bytes);
        }

        $pkg->load();
        return $pkg;
    }
}

abstract class AbstractUE implements IPackageReader2
{
    protected string $path;
    protected string $bytes;
    protected UER $R;
    protected array $header = [];
    protected array $names = [];
    protected array $imports = [];
    protected array $exports = [];
    protected array $depends = [];
    protected array $chunks = [];
    protected bool $compressed = false;
    protected int $compressionFlags = 0;

    public function __construct(string $path, string $bytes)
    {
        $this->path = $path;
        $this->bytes = $bytes;
        $this->R = new UER($bytes);
    }

    public function load(): void
    {
        $this->readHeader();
        $this->readNameTable();
        $this->readImportTable();
        $this->readExportTable();
    }

    abstract protected function readHeader(): void;
    abstract protected function readNameTable(): void;
    abstract protected function readImportTable(): void;
    abstract protected function readExportTable(): void;

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getDepends(): array { return $this->depends; }
    public function getVersion(): int { return (int)($this->header['version'] ?? 0); }
    public function isCompressed(): bool { return $this->compressed; }

    public function nameText(int $index): string
    {
        return ($index >= 0 && isset($this->names[$index])) ? (string)($this->names[$index]['name'] ?? '') : '';
    }

    protected function readPackedVersion(): void
    {
        $this->header['tag'] = $this->R->u32();
        $packed = $this->R->u32();
        $this->header['packedVersion'] = $packed;
        $this->header['version'] = $packed & 0xffff;
        $this->header['licenseeVersion'] = ($packed >> 16) & 0xffff;
    }

    protected function tableReader(int $offset): UER
    {
        $r = new UER($this->bytes);
        $r->seek($offset);
        return $r;
    }

    protected function readUE12HeaderFields(): void
    {
        $this->readPackedVersion();
        $version = $this->getVersion();

        $this->header['packageFlags'] = $this->R->u32();
        $this->header['nameCount'] = $this->R->i32();
        $this->header['nameOffset'] = $this->R->i32();
        $this->header['exportCount'] = $this->R->i32();
        $this->header['exportOffset'] = $this->R->i32();
        $this->header['importCount'] = $this->R->i32();
        $this->header['importOffset'] = $this->R->i32();
        $this->header['dependsOffset'] = 0;
        $this->header['compressed'] = false;

        if ($version < 68) {
            $this->header['heritageCount'] = $this->R->i32();
            $this->header['heritageOffset'] = $this->R->i32();
            $this->header['guid'] = '';
            $this->header['generations'] = [[
                'exportCount' => $this->header['exportCount'],
                'nameCount' => $this->header['nameCount'],
            ]];
            return;
        }

        $guid = [$this->R->u32(), $this->R->u32(), $this->R->u32(), $this->R->u32()];
        $this->header['guidArray'] = $guid;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);

        $genCount = $this->R->i32();
        $this->header['genCount'] = $genCount;
        $this->header['generations'] = [];

        for ($i = 0; $i < $genCount; $i++) {
            $this->header['generations'][] = [
                'exportCount' => $this->R->i32(),
                'nameCount' => $this->R->i32(),
            ];
        }
    }

    protected function readUE12NameTable(): void
    {
        $r = $this->tableReader((int)$this->header['nameOffset']);
        $version = $this->getVersion();
        $count = (int)$this->header['nameCount'];
        $this->names = [];

        for ($i = 0; $i < $count; $i++) {
            $name = $version < 64 ? $r->cstring() : $r->fstringIndex($version);
            $flags = $r->u32();
            $this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags, 'objectFlags' => $flags];
        }
    }

    protected function readUE12ImportTable(): void
    {
        $r = $this->tableReader((int)$this->header['importOffset']);
        $version = $this->getVersion();
        $count = (int)$this->header['importCount'];
        $this->imports = [];

        for ($i = 0; $i < $count; $i++) {
            $classPackage = $r->versionIndex($version);
            $className = $r->versionIndex($version);
            $outerIndex = $r->i32();
            $objectName = $r->versionIndex($version);

            $this->imports[] = [
                'index' => $i,
                'classPackage' => $classPackage,
                'className' => $className,
                'outerIndex' => $outerIndex,
                'outer' => $outerIndex,
                'objectName' => $objectName,
                'classPackageText' => $this->nameText($classPackage),
                'classNameText' => $this->nameText($className),
                'objectNameText' => $this->nameText($objectName),
            ];
        }
    }

    protected function readUE12ExportTable(): void
    {
        $r = $this->tableReader((int)$this->header['exportOffset']);
        $version = $this->getVersion();
        $count = (int)$this->header['exportCount'];
        $this->exports = [];

        for ($i = 0; $i < $count; $i++) {
            $classIndex = $r->versionIndex($version);
            $superIndex = $r->versionIndex($version);
            $outerIndex = $r->i32();
            $objectName = $r->versionIndex($version);
            $objectFlags = $r->u32();
            $serialSize = $r->versionIndex($version);
            $serialOffset = $serialSize > 0 ? $r->versionIndex($version) : 0;

            $this->exports[] = [
                'index' => $i,
                'classIndex' => $classIndex,
                'class' => $classIndex,
                'superIndex' => $superIndex,
                'super' => $superIndex,
                'outerIndex' => $outerIndex,
                'outer' => $outerIndex,
                'packageIndex' => $outerIndex,
                'objectName' => $objectName,
                'nameIndex' => $objectName,
                'objectNameText' => $this->nameText($objectName),
                'objectFlags' => $objectFlags,
                'serialSize' => $serialSize,
                'serialOffset' => $serialOffset,
            ];
        }
    }

    protected function labelRef(int $ref): string
    {
        if ($ref === 0) {
            return '';
        }

        if ($ref > 0) {
            $row = $this->exports[$ref - 1] ?? null;
            return is_array($row) ? $this->nameText((int)($row['objectName'] ?? -1)) : '';
        }

        $row = $this->imports[-$ref - 1] ?? null;
        return is_array($row) ? $this->nameText((int)($row['objectName'] ?? -1)) : '';
    }

    protected function fname(int $index, int $number = 0): string
    {
        $name = $this->nameText($index);
        return $number !== 0 && $name !== '' ? $name . '_' . $number : $name;
    }

    public function importName(int $r): string { return $r < 0 ? $this->labelRef($r) : ''; }
    public function exportName(int $r): string { return $r > 0 ? $this->labelRef($r) : ''; }

    public function annotateTablesWithText(): void
    {
        foreach ($this->imports as &$im) {
            $im['text'] = [
                'classPackage' => $this->fname((int)($im['classPackageIndex'] ?? $im['classPackage'] ?? -1), (int)($im['classPackageNumber'] ?? 0)),
                'className' => $this->fname((int)($im['classNameIndex'] ?? $im['className'] ?? -1), (int)($im['classNameNumber'] ?? 0)),
                'objectName' => $this->fname((int)($im['objectNameIndex'] ?? $im['objectName'] ?? -1), (int)($im['objectNameNumber'] ?? 0)),
                'outer' => $this->labelRef((int)($im['outer'] ?? $im['outerIndex'] ?? 0)),
            ];
        }
        unset($im);

        foreach ($this->exports as &$ex) {
            $ex['text'] = [
                'name' => $this->fname((int)($ex['nameIndex'] ?? $ex['objectName'] ?? -1), (int)($ex['nameNumber'] ?? 0)),
                'class' => $this->labelRef((int)($ex['class'] ?? $ex['classIndex'] ?? 0)),
                'super' => $this->labelRef((int)($ex['super'] ?? $ex['superIndex'] ?? 0)),
                'outer' => $this->labelRef((int)($ex['outer'] ?? $ex['outerIndex'] ?? 0)),
                'archetype' => $this->labelRef((int)($ex['archetype'] ?? 0)),
            ];
        }
        unset($ex);
    }

    public function annotateExportHex(int $max = 10): void {}

    public function dumpExportHeader(int $i): string
    {
        $e = $this->exports[$i] ?? [];
        return sprintf('#%d name=%s serial=%d@%d', $i, $this->nameText((int)($e['objectName'] ?? -1)), (int)($e['serialSize'] ?? 0), (int)($e['serialOffset'] ?? 0));
    }
}

class TUE1 extends AbstractUE
{
    protected function readHeader(): void { $this->readUE12HeaderFields(); }
    protected function readNameTable(): void { $this->readUE12NameTable(); }
    protected function readImportTable(): void { $this->readUE12ImportTable(); }
    protected function readExportTable(): void { $this->readUE12ExportTable(); }
}

final class TUE2 extends TUE1
{
}

class TUE3 extends AbstractUE
{
    public array $chunkMeta = [];

    protected function readHeader(): void
    {
        $this->readPackedVersion();
        $version = $this->getVersion();

        $this->header['headerSize'] = $version >= 249 ? $this->R->u32() : 0;
        $this->header['folderName'] = $version >= 269 ? $this->R->fstringI32() : '';
        $this->header['packageFlags'] = $this->R->u32();
        $this->header['nameCount'] = $this->R->u32();
        $this->header['nameOffset'] = $this->R->u32();
        $this->header['exportCount'] = $this->R->u32();
        $this->header['exportOffset'] = $this->R->u32();
        $this->header['importCount'] = $this->R->u32();
        $this->header['importOffset'] = $this->R->u32();
        $this->header['dependsOffset'] = $version >= 415 ? $this->R->u32() : 0;

        if ($version >= 623) {
            $this->header['importExportGuidsOffset'] = $this->R->u32();
            $this->header['importGuidsCount'] = $this->R->u32();
            $this->header['exportGuidsCount'] = $this->R->u32();
        }

        if ($version >= 584) {
            $this->header['thumbnailTableOffset'] = $this->R->u32();
        }

        $guid = [$this->R->u32(), $this->R->u32(), $this->R->u32(), $this->R->u32()];
        $this->header['guidArray'] = $guid;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);

        $genCount = $this->R->u32();
        $this->header['genCount'] = $genCount;
        $this->header['generations'] = [];

        for ($i = 0; $i < $genCount; $i++) {
            $this->header['generations'][] = [
                'exportCount' => $this->R->u32(),
                'nameCount' => $this->R->u32(),
                'netObjectCount' => $version >= 322 ? $this->R->u32() : 0,
            ];
        }

        $this->header['engineVersion'] = $version >= 245 ? $this->R->u32() : 0;
        $this->header['cookerVersion'] = $version >= 277 ? $this->R->u32() : 0;
        $this->compressionFlags = $version >= 334 ? (int)$this->R->u32() : 0;
        $this->compressed = $this->compressionFlags !== 0;
        $this->header['compressionFlags'] = $this->compressionFlags;
        $this->header['cFlags'] = $this->compressionFlags;
        $this->header['compressed'] = $this->compressed;
        $this->chunks = [];

        if ($this->compressed) {
            $chunkCount = $this->R->u32();

            for ($i = 0; $i < $chunkCount; $i++) {
                $this->chunks[] = [
                    'uOff' => $this->R->u32(),
                    'uSize' => $this->R->u32(),
                    'uLen' => 0,
                    'cOff' => $this->R->u32(),
                    'cSize' => $this->R->u32(),
                    'cLen' => 0,
                ];
                $last = count($this->chunks) - 1;
                $this->chunks[$last]['uLen'] = $this->chunks[$last]['uSize'];
                $this->chunks[$last]['cLen'] = $this->chunks[$last]['cSize'];
            }
        }

        $this->header['chunkCount'] = count($this->chunks);
        $this->header['chunks'] = $this->chunks;
        $this->header['compressedChunks'] = $this->chunks;
    }

    protected function logicalReader(): UER
    {
        if (!$this->compressed || !$this->chunks) {
            return new UER($this->bytes);
        }

        $chunks = $this->chunks;
        usort($chunks, static fn(array $a, array $b): int => ((int)$a['uOff']) <=> ((int)$b['uOff']));
        $size = strlen($this->bytes);

        foreach ($chunks as $chunk) {
            $size = max($size, (int)$chunk['uOff'] + (int)$chunk['uSize']);
        }

        $buf = str_pad($this->bytes, $size, "\0");

        foreach ($chunks as $i => $chunk) {
            $part = $this->decompressChunk((int)$chunk['cOff'], (int)$chunk['cSize'], (int)$chunk['uSize']);
            $buf = substr_replace($buf, $part, (int)$chunk['uOff'], (int)$chunk['uSize']);
            $this->chunkMeta[$i] = $chunk + ['codec' => $this->compressionFlags];
        }

        return new UER($buf);
    }

    protected function decompressChunk(int $compressedOffset, int $compressedSize, int $uncompressedSize): string
    {
        $raw = $this->R->slice($compressedOffset, $compressedSize);
        $r = new UER($raw);
        $out = '';

        if ($r->remaining() >= 16) {
            $start = $r->tell();
            $tag = $r->u32();
            $blockSize = $r->u32();
            $compressedTotal = $r->i32();
            $uncompressedTotal = $r->i32();

            if ($blockSize > 0 && $uncompressedTotal > 0) {
                $blockCount = (int)ceil($uncompressedTotal / $blockSize);

                if ($blockCount >= 0 && $blockCount < 100000 && $r->remaining() >= $blockCount * 8) {
                    $blocks = [];

                    for ($i = 0; $i < $blockCount; $i++) {
                        $blocks[] = [$r->i32(), $r->i32()];
                    }

                    foreach ($blocks as [$cSize, $uSize]) {
                        if ($cSize <= 0 || $uSize <= 0 || $r->remaining() < $cSize) {
                            $out = '';
                            break;
                        }

                        $out .= UE_Decompress::inflate($this->compressionFlags, $r->bytes($cSize), $uSize);
                    }
                }
            }

            if ($out === '') {
                $r->seek($start);
            }
        }

        if ($out === '') {
            while ($r->remaining() >= 8 && strlen($out) < $uncompressedSize) {
                $cSize = $r->i32();
                $uSize = $r->i32();

                if ($cSize <= 0 || $uSize <= 0 || $r->remaining() < $cSize) {
                    break;
                }

                $out .= UE_Decompress::inflate($this->compressionFlags, $r->bytes($cSize), $uSize);
            }
        }

        if (strlen($out) > $uncompressedSize) {
            $out = substr($out, 0, $uncompressedSize);
        }

        if (strlen($out) < $uncompressedSize) {
            $out = str_pad($out, $uncompressedSize, "\0");
        }

        return $out;
    }

    protected function readNameTable(): void
    {
        $r = $this->logicalReader();
        $r->seek((int)$this->header['nameOffset']);
        $version = $this->getVersion();
        $this->names = [];

        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name = $r->fstringI32();
            $flags = $version >= 195 ? $r->u64() : $r->u32();
            $this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags, 'objectFlags' => $flags];
        }
    }

    protected function readImportTable(): void
    {
        $r = $this->logicalReader();
        $r->seek((int)$this->header['importOffset']);
        $this->imports = [];

        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $classPackageIndex = $r->i32();
            $classPackageNumber = $r->i32();
            $classNameIndex = $r->i32();
            $classNameNumber = $r->i32();
            $outer = $r->i32();
            $objectNameIndex = $r->i32();
            $objectNameNumber = $r->i32();

            $this->imports[] = [
                'index' => $i,
                'classPackageIndex' => $classPackageIndex,
                'classPackageNumber' => $classPackageNumber,
                'classNameIndex' => $classNameIndex,
                'classNameNumber' => $classNameNumber,
                'outer' => $outer,
                'outerIndex' => $outer,
                'objectNameIndex' => $objectNameIndex,
                'objectNameNumber' => $objectNameNumber,
                'classPackage' => $classPackageIndex,
                'className' => $classNameIndex,
                'objectName' => $objectNameIndex,
                'classPackageText' => $this->fname($classPackageIndex, $classPackageNumber),
                'classNameText' => $this->fname($classNameIndex, $classNameNumber),
                'objectNameText' => $this->fname($objectNameIndex, $objectNameNumber),
            ];
        }
    }

    protected function readExportTable(): void
    {
        $r = $this->logicalReader();
        $r->seek((int)$this->header['exportOffset']);
        $version = $this->getVersion();
        $this->exports = [];

        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->i32();
            $super = $r->i32();
            $outer = $r->i32();
            $nameIndex = $r->i32();
            $nameNumber = $r->i32();
            $archetype = $version >= 220 ? $r->i32() : 0;
            $flagsLo = $r->u32();
            $flagsHi = $version >= 195 ? $r->u32() : 0;
            $objectFlags = ($flagsHi << 32) | $flagsLo;
            $serialSize = $r->i32();
            $serialOffset = ($serialSize !== 0 || $version >= 249) ? $r->i32() : 0;
            $components = [];

            if ($version >= 220 && $version < 543) {
                $componentCount = $r->i32();

                if ($componentCount < 0 || $componentCount > 65536) {
                    throw new RuntimeException("Bad component map count $componentCount in export $i");
                }

                for ($j = 0; $j < $componentCount; $j++) {
                    $components[] = ['name' => $r->i32(), 'nameNumber' => $r->i32(), 'ref' => $r->i32()];
                }
            }

            $exportFlags = $version >= 247 ? $r->u32() : 0;
            $netObjectCount = null;
            $guid = null;

            if ($version >= 322) {
                $netObjectCount = [];
                for ($j = 0; $j < 16; $j++) {
                    $netObjectCount[] = $r->i32();
                }
                $guid = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
            }

            $u3unk6c = $version >= 475 ? $r->i32() : null;

            $this->exports[] = [
                'index' => $i,
                'class' => $class,
                'classIndex' => $class,
                'super' => $super,
                'superIndex' => $super,
                'outer' => $outer,
                'outerIndex' => $outer,
                'packageIndex' => $outer,
                'nameIndex' => $nameIndex,
                'nameNumber' => $nameNumber,
                'objectName' => $nameIndex,
                'objectNameText' => $this->fname($nameIndex, $nameNumber),
                'archetype' => $archetype,
                'objectFlagsLo' => $flagsLo,
                'objectFlagsHi' => $flagsHi,
                'objectFlags' => $objectFlags,
                'serialSize' => $serialSize,
                'serialOffset' => $serialOffset,
                'components' => $components,
                'componentMap' => $components,
                'componentCount' => count($components),
                'exportFlags' => $exportFlags,
                'netObjectCount' => $netObjectCount,
                'guid' => $guid,
                'u3unk6c' => $u3unk6c,
            ];
        }
    }
}

final class TUE4 extends TUE3 {}

final class UE_Decompress
{
    private static array $codecs = [];

    public static function register(int $id, callable $fn): void
    {
        self::$codecs[$id] = $fn;
    }

    public static function inflate(int $flags, string $payload, int $expected, array $ctx = []): string
    {
        if (($flags & 2) && isset(self::$codecs[2])) {
            return (self::$codecs[2])($payload, $expected, $ctx);
        }

        if (($flags & 1) && isset(self::$codecs[1])) {
            return (self::$codecs[1])($payload, $expected, $ctx);
        }

        throw new RuntimeException("No decoder registered for compression flags $flags");
    }
}

UE_Decompress::register(1, function (string $data, int $expected, array $ctx = []): string {
    $out = @gzuncompress($data);

    if ($out === false) {
        $out = @gzinflate($data);
    }

    if ($out === false) {
        throw new RuntimeException('zlib decompression failed');
    }

    return $out;
});

final class UE_LZO1X
{
    public static function decompress(string $data, int $expected): string
    {
        throw new RuntimeException('Pure PHP LZO fallback not available; use UE_LZO1X_register.php / native LZO for codec 2.');
    }
}
