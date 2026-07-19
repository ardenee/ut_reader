<?php
declare(strict_types=1);


final class UEFolderBinaryReader
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { $this->pos = max(0, min($pos, $this->len)); }
    public function remaining(): int { return $this->len - $this->pos; }
    public function size(): int { return $this->len; }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->len) {
            throw new OutOfBoundsException("read overrun need=$count pos={$this->pos} len={$this->len}");
        }
        $out = substr($this->buf, $this->pos, $count);
        $this->pos += $count;
        return $out;
    }

    public function u8(): int { return ord($this->bytes(1)); }
    public function u16(): int { return unpack('v', $this->bytes(2))[1]; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
    public function f32(): float { return unpack('g', $this->bytes(4))[1]; }
    public function u64(): int { $lo = $this->u32(); $hi = $this->u32(); return ($hi << 32) | $lo; }

    public function compactIndex(): int
    {
        $b = $this->u8();
        $negative = ($b & 0x80) !== 0;
        $more = ($b & 0x40) !== 0;
        $value = $b & 0x3F;
        $shift = 6;
        $count = 1;
        while ($more) {
            if (++$count > 5) {
                throw new RuntimeException('Invalid compact index length');
            }
            $b = $this->u8();
            $more = ($b & 0x80) !== 0;
            $value |= ($b & 0x7F) << $shift;
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
        return self::toUtf8($out);
    }

    public function fstring32(): string { return $this->stringByLength($this->i32()); }
    public function fstringIndex(int $version): string { return $this->stringByLength($this->versionIndex($version)); }

    private function stringByLength(int $length): string
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
            return self::toUtf8($raw);
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

    public static function toUtf8(string $raw): string
    {
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return $out === false ? $raw : $out;
    }
}

final class UnrealPackageReader
{
    private string $path;
    private string $bytes = '';
    private array $header = [];
    private array $names = [];
    private array $imports = [];
    private array $exports = [];
    private array $issues = [];
    private array $propertyCache = [];
	private array $rawHeaderFields = [];

    private const PKG_FLAGS = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000002 => 'PKG_ClientOptional',
        0x00000004 => 'PKG_ServerSideOnly',
        0x00000008 => 'PKG_NoExportAllowed',
        0x00000010 => 'PKG_Cooked',
        0x00000020 => 'PKG_Encrypted',
    ];

    private const RF_FLAGS = [
        0x00000001 => 'RF_Transactional',
        0x00000002 => 'RF_Unreachable',
        0x00000004 => 'RF_Public',
        0x00000008 => 'RF_TagImp',
        0x00000010 => 'RF_TagExp',
        0x00000020 => 'RF_SourceModified',
        0x00000040 => 'RF_TagGarbage',
        0x00000200 => 'RF_NeedLoad',
        0x00000400 => 'RF_HighlightedName',
        0x00004000 => 'RF_Transient',
        0x00010000 => 'RF_LoadForClient',
        0x00020000 => 'RF_LoadForServer',
        0x00040000 => 'RF_LoadForEdit',
        0x00080000 => 'RF_Standalone',
        0x01000000 => 'RF_NeedPostLoad',
        0x04000000 => 'RF_Native',
    ];

    private const PROP_TYPES = [
        0 => 'None',
        1 => 'ByteProperty',
        2 => 'IntProperty',
        3 => 'BoolProperty',
        4 => 'FloatProperty',
        5 => 'ObjectProperty',
        6 => 'NameProperty',
        7 => 'StringProperty',
        8 => 'ClassProperty',
        9 => 'ArrayProperty',
        10 => 'StructProperty',
        11 => 'VectorProperty',
        12 => 'RotatorProperty',
        13 => 'StrProperty',
        14 => 'MapProperty',
        15 => 'FixedArrayProperty',
    ];

    private const PROP_TYPE_NAMES = [
        'ByteProperty' => 1,
        'IntProperty' => 2,
        'BoolProperty' => 3,
        'FloatProperty' => 4,
        'ObjectProperty' => 5,
        'NameProperty' => 6,
        'StringProperty' => 7,
        'DelegateProperty' => 7,
        'ClassProperty' => 8,
        'ArrayProperty' => 9,
        'StructProperty' => 10,
        'VectorProperty' => 11,
        'RotatorProperty' => 12,
        'StrProperty' => 13,
        'MapProperty' => 14,
        'TextProperty' => 14,
        'FixedArrayProperty' => 15,
        'InterfaceProperty' => 15,
    ];

    public function __construct(string $path)
    {
        $this->path = $path;
        try {
            $data = file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException("Failed to read package: $path");
            }
            $this->bytes = $data;
            $this->parse();
        } catch (Throwable $e) {
            $this->issues[] = $this->formatThrowable($e);
            $this->header = $this->blankHeader();
        }
    }

    private function formatThrowable(Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . ' File: ' . $e->getFile() . ':' . $e->getLine() . ' PHP: ' . PHP_VERSION . ' Package: ' . $this->path . ' Trace: ' . $e->getTraceAsString();
    }

    private function blankHeader(): array
    {
        return [
            'signature' => 0,
            'tag' => 0,
            'version' => 0,
            'licensee' => 0,
            'licenseeVersion' => 0,
            'pkgFlags' => 0,
            'packageFlags' => 0,
            'nameCount' => 0,
            'nameOffset' => 0,
            'exportCount' => 0,
            'exportOffset' => 0,
            'importCount' => 0,
            'importOffset' => 0,
            'dependsOffset' => 0,
            'guid' => '',
            'generations' => [],
            'chunks' => [],
            'compressedChunks' => [],
            'compressed' => false,
            'compressionFlags' => 0,
            'cFlags' => 0,
        ];
    }

    private function parse(): void
    {
        $r = new UEFolderBinaryReader($this->bytes);
        $tag = $r->u32();
        if ($tag !== 0x9E2A83C1) {
            throw new RuntimeException(sprintf('Bad package tag 0x%08X', $tag));
        }
        $packed = $r->u32();
        $version = $packed & 0xFFFF;
        $licensee = ($packed >> 16) & 0xFFFF;
        $ext = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
        $isUE3 = in_array($ext, ['ut3', 'upk', 'xxx'], true) || $version >= 334;
        if ($isUE3) {
            $this->parseUE3($r, $tag, $packed, $version, $licensee);
        } else {
            $this->parseUE12($r, $tag, $packed, $version, $licensee);
        }
    }

    private function parseUE12(UEFolderBinaryReader $r, int $tag, int $packed, int $version, int $licensee): void
    {
        $flags = $r->u32();
        $this->header = $this->blankHeader();
        $this->header['signature'] = $tag;
        $this->header['tag'] = $tag;
        $this->header['packedVersion'] = $packed;
        $this->header['version'] = $version;
        $this->header['licensee'] = $licensee;
        $this->header['licenseeVersion'] = $licensee;
        $this->header['pkgFlags'] = $flags;
        $this->header['packageFlags'] = $flags;
        $this->header['nameCount'] = $r->i32();
        $this->header['nameOffset'] = $r->i32();
        $this->header['exportCount'] = $r->i32();
        $this->header['exportOffset'] = $r->i32();
        $this->header['importCount'] = $r->i32();
        $this->header['importOffset'] = $r->i32();
        if ($version < 68) {
            $this->header['heritageCount'] = $r->i32();
            $this->header['heritageOffset'] = $r->i32();
            $this->header['guid'] = '';
            $this->header['generations'] = [[
                'e' => $this->header['exportCount'],
                'n' => $this->header['nameCount'],
                'exportCount' => $this->header['exportCount'],
                'nameCount' => $this->header['nameCount'],
            ]];
        } else {
            $guid = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
            $this->header['guidArray'] = $guid;
            $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);
            $genCount = $r->i32();
            $this->header['genCount'] = $genCount;
            for ($i = 0; $i < $genCount; $i++) {
                $e = $r->i32();
                $n = $r->i32();
                $this->header['generations'][] = ['e' => $e, 'n' => $n, 'exportCount' => $e, 'nameCount' => $n];
            }
        }
        $this->readUE12Names();
        $this->readUE12Imports();
        $this->readUE12Exports();
    }

    private function tableReader(int $offset): UEFolderBinaryReader
    {
        $r = new UEFolderBinaryReader($this->bytes);
        $r->seek($offset);
        return $r;
    }

    private function readUE12Names(): void
    {
        $r = $this->tableReader((int)$this->header['nameOffset']);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name = $version < 64 ? $r->cstring() : $r->fstringIndex($version);
            $flags = $r->u32();
            $this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags, 'objectFlags' => $flags];
        }
    }

    private function readUE12Imports(): void
    {
        $r = $this->tableReader((int)$this->header['importOffset']);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $classPackage = $r->versionIndex($version);
            $className = $r->versionIndex($version);
            $outer = $r->i32();
            $objectName = $r->versionIndex($version);
            $this->imports[] = $this->makeImport($i, $classPackage, 0, $className, 0, $outer, $objectName, 0);
        }
    }

    private function readUE12Exports(): void
    {
        $r = $this->tableReader((int)$this->header['exportOffset']);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->versionIndex($version);
            $super = $r->versionIndex($version);
            $outer = $r->i32();
            $objectName = $r->versionIndex($version);
            $flags = $r->u32();
            $serialSize = $r->versionIndex($version);
            $serialOffset = $serialSize > 0 ? $r->versionIndex($version) : 0;
            $this->exports[] = $this->makeExport($i, $class, $super, $outer, $objectName, 0, 0, $flags, $serialSize, $serialOffset);
        }
    }

    private function parseUE3(UEFolderBinaryReader $r, int $tag, int $packed, int $version, int $licensee): void
    {
        $this->header = $this->blankHeader();
        $this->header['signature'] = $tag;
        $this->header['tag'] = $tag;
        $this->header['packedVersion'] = $packed;
        $this->header['version'] = $version;
        $this->header['licensee'] = $licensee;
        $this->header['licenseeVersion'] = $licensee;
        $this->header['headerSize'] = $version >= 249 ? $r->u32() : 0;
        $this->header['folderName'] = $version >= 269 ? $r->fstring32() : '';
        $flags = $r->u32();
        $this->header['packageFlags'] = $flags;
        $this->header['pkgFlags'] = $flags;
        $this->header['nameCount'] = $r->u32();
        $this->header['nameOffset'] = $r->u32();
        $this->header['exportCount'] = $r->u32();
        $this->header['exportOffset'] = $r->u32();
        $this->header['importCount'] = $r->u32();
        $this->header['importOffset'] = $r->u32();
        $this->header['dependsOffset'] = $version >= 415 ? $r->u32() : 0;
        if ($version >= 623) {
            $this->header['importExportGuidsOffset'] = $r->u32();
            $this->header['importGuidsCount'] = $r->u32();
            $this->header['exportGuidsCount'] = $r->u32();
        }
        if ($version >= 584) {
            $this->header['thumbnailTableOffset'] = $r->u32();
        }
        $guid = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
        $this->header['guidArray'] = $guid;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);
        $genCount = $r->u32();
        $this->header['genCount'] = $genCount;
        for ($i = 0; $i < $genCount; $i++) {
            $e = $r->u32();
            $n = $r->u32();
            $net = $version >= 322 ? $r->u32() : 0;
            $this->header['generations'][] = ['e' => $e, 'n' => $n, 'exportCount' => $e, 'nameCount' => $n, 'netObjectCount' => $net];
        }
        $this->header['engineVersion'] = $version >= 245 ? $r->u32() : 0;
        $this->header['cookerVersion'] = $version >= 277 ? $r->u32() : 0;
        $this->header['compressionFlags'] = $version >= 334 ? $r->u32() : 0;
        $this->header['cFlags'] = $this->header['compressionFlags'];
        $this->header['compressed'] = $this->header['compressionFlags'] !== 0;
        if ($this->header['compressed']) {
            $chunkCount = $r->u32();
            for ($i = 0; $i < $chunkCount; $i++) {
                $uOff = $r->u32();
                $uSize = $r->u32();
                $cOff = $r->u32();
                $cSize = $r->u32();
                $this->header['chunks'][] = ['cOff' => $cOff, 'cLen' => $cSize, 'cSize' => $cSize, 'uOff' => $uOff, 'uLen' => $uSize, 'uSize' => $uSize];
            }
            $this->header['compressedChunks'] = $this->header['chunks'];
        }
        $this->readUE3Names();
        $this->readUE3Imports();
        $this->readUE3Exports();
    }

    private function readUE3Names(): void
    {
        $r = $this->tableReader((int)$this->header['nameOffset']);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name = $r->fstring32();
            $flags = $version >= 195 ? $r->u64() : $r->u32();
            $this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags, 'objectFlags' => $flags];
        }
    }

    private function readUE3Imports(): void
    {
        $r = $this->tableReader((int)$this->header['importOffset']);
        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $classPackage = $r->i32();
            $classPackageNumber = $r->i32();
            $className = $r->i32();
            $classNameNumber = $r->i32();
            $outer = $r->i32();
            $objectName = $r->i32();
            $objectNameNumber = $r->i32();
            $this->imports[] = $this->makeImport($i, $classPackage, $classPackageNumber, $className, $classNameNumber, $outer, $objectName, $objectNameNumber);
        }
    }

    private function readUE3Exports(): void
    {
        $r = $this->tableReader((int)$this->header['exportOffset']);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->i32();
            $super = $r->i32();
            $outer = $r->i32();
            $objectName = $r->i32();
            $objectNameNumber = $r->i32();
            $archetype = $version >= 220 ? $r->i32() : 0;
            $flagsLo = $r->u32();
            $flagsHi = $version >= 195 ? $r->u32() : 0;
            $flags = ($flagsHi << 32) | $flagsLo;
            $serialSize = $r->i32();
            $serialOffset = ($serialSize !== 0 || $version >= 249) ? $r->i32() : 0;
            $components = [];
            if ($version >= 220 && $version < 543) {
                $componentCount = $r->i32();
                if ($componentCount < 0 || $componentCount > 65536) {
                    throw new RuntimeException("Bad component count $componentCount in export $i");
                }
                for ($j = 0; $j < $componentCount; $j++) {
                    $components[] = ['name' => $r->i32(), 'nameNumber' => $r->i32(), 'ref' => $r->i32()];
                }
            }
            $exportFlags = $version >= 247 ? $r->u32() : 0;
            if ($version >= 322) {
                for ($j = 0; $j < 16; $j++) { $r->i32(); }
                $r->u32(); $r->u32(); $r->u32(); $r->u32();
            }
            if ($version >= 475) { $r->i32(); }
            $this->exports[] = $this->makeExport($i, $class, $super, $outer, $objectName, $objectNameNumber, $archetype, $flags, $serialSize, $serialOffset, $components, $exportFlags);
        }
    }

    private function makeImport(int $i, int $classPackage, int $classPackageNumber, int $className, int $classNameNumber, int $outer, int $objectName, int $objectNameNumber): array
    {
        $cpText = $this->nameByIndex($classPackage, $classPackageNumber);
        $cnText = $this->nameByIndex($className, $classNameNumber);
        $onText = $this->nameByIndex($objectName, $objectNameNumber);
        return [
            'index' => $i,
            'classPackage' => $classPackage,
            'className' => $className,
            'outerIndex' => $outer,
            'outer' => $outer,
            'outerName' => $this->displayNameFromRef($outer),
            'objectName' => $objectName,
            'classPackageText' => $cpText,
            'classNameText' => $cnText,
            'objectNameText' => $onText,
            'ClassPackage' => ['index' => $classPackage, 'number' => $classPackageNumber, 'text' => $cpText],
            'ClassName' => ['index' => $className, 'number' => $classNameNumber, 'text' => $cnText],
            'OuterIndex' => $outer,
            'ObjectName' => ['index' => $objectName, 'number' => $objectNameNumber, 'text' => $onText],
        ];
    }

    private function makeExport(int $i, int $class, int $super, int $outer, int $objectName, int $objectNameNumber, int $archetype, int $flags, int $serialSize, int $serialOffset, array $components = [], int $exportFlags = 0): array
    {
        return [
            'index' => $i,
            'classIndex' => $class,
            'class' => $class,
            'superIndex' => $super,
            'super' => $super,
            'packageIndex' => $outer,
            'outerIndex' => $outer,
            'outer' => $outer,
            'objectName' => $objectName,
            'nameIndex' => $objectName,
            'nameNumber' => $objectNameNumber,
            'objectNameText' => $this->nameByIndex($objectName, $objectNameNumber),
            'objectFlags' => $flags,
            'serialSize' => $serialSize,
            'serialOffset' => $serialOffset,
            'archetype' => $archetype,
            'components' => $components,
            'componentMap' => $components,
            'exportFlags' => $exportFlags,
        ];
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getFileSize(): string { return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : ''; }
    public function getDebugErrors(): array { return $this->issues; }
    public function validatePackage(): array { return $this->issues; }
	public function getRawHeaderFields(): array { return $this->rawHeaderFields; }

    public function getCompressionInfo(): array
    {
        $chunks = $this->header['chunks'] ?? [];
        $totalC = 0;
        $totalU = 0;
        foreach ($chunks as $c) {
            $totalC += (int)($c['cLen'] ?? 0);
            $totalU += (int)($c['uLen'] ?? 0);
        }
        return ['isCompressed' => (bool)($this->header['compressed'] ?? false), 'flags' => (int)($this->header['compressionFlags'] ?? 0), 'chunks' => $chunks, 'totalCompressed' => $totalC, 'totalUncompressed' => $totalU];
    }

    public function nameByIndex(int $index, int $number = 0): string
    {
        if ($index < 0 || !isset($this->names[$index])) {
            return '';
        }
        $name = (string)($this->names[$index]['name'] ?? '');
        return ($number !== 0 && $name !== '') ? $name . '_' . $number : $name;
    }

    private function baseNameByIndex(int $index): string
    {
        return ($index >= 0 && isset($this->names[$index])) ? (string)($this->names[$index]['name'] ?? '') : '';
    }

    public function displayNameFromRef(int $ref): string
    {
        if ($ref === 0) {
            return '';
        }
        if ($ref > 0) {
            $ex = $this->exports[$ref - 1] ?? null;
            return is_array($ex) ? $this->nameByIndex((int)($ex['objectName'] ?? -1), (int)($ex['nameNumber'] ?? 0)) : '';
        }
        $im = $this->imports[-$ref - 1] ?? null;
        return is_array($im) ? $this->nameByIndex((int)($im['objectName'] ?? -1), (int)($im['ObjectName']['number'] ?? 0)) : '';
    }

    public function importClassPackageName(int $index): string { return $this->nameByIndex($index); }
    public function importClassName(int $index): string { return $this->nameByIndex($index); }
    public function importPackageName(int $ref): string { return $this->displayNameFromRef($ref); }
    public function importObjectName(int $index): string { return $this->nameByIndex($index); }
    public function exportClassName(int $ref): string { return $this->displayNameFromRef($ref); }
    public function exportSuperName(int $ref): string { return $this->displayNameFromRef($ref); }
    public function exportPackageName(int $ref): string { return $this->displayNameFromRef($ref); }
    public function exportObjectName(int $index): string { return $this->nameByIndex($index); }
    public function decodePKG(int $flags): array { return $this->decodeFlags($flags, self::PKG_FLAGS); }
    public function decodeRF(int $flags): array { return $this->decodeFlags($flags, self::RF_FLAGS); }
    public function describeCompressionFlags(int $flags): string { return $flags === 0 ? '' : implode(', ', $this->decodePKG($flags)); }

    public function getExportProperties(int $exportIndex): ?array
    {
        if (isset($this->propertyCache[$exportIndex])) {
            return $this->propertyCache[$exportIndex];
        }
        $ex = $this->exports[$exportIndex] ?? null;
        if (!$ex || (int)$ex['serialSize'] <= 0 || (int)$ex['serialOffset'] <= 0) {
            return $this->propertyCache[$exportIndex] = [];
        }
        try {
            $props = $this->readPropertyList((int)$ex['serialOffset'], (int)$ex['serialSize']);
            return $this->propertyCache[$exportIndex] = $props;
        } catch (Throwable $e) {
            $this->issues[] = 'Property parse failed for export ' . $exportIndex . ': ' . $e->getMessage();
            return $this->propertyCache[$exportIndex] = [];
        }
    }

    public function getExportProperty(int $exportIndex, string $name, $default = null)
    {
        foreach ($this->getExportProperties($exportIndex) ?? [] as $prop) {
            if (($prop['name'] ?? '') === $name) {
                return $prop['value'] ?? $default;
            }
        }
        return $default;
    }

    public function getPropertiesByClass(string $className): array { return []; }
    public function readPropertiesForExport(int $exportIndex): array { return $this->getExportProperties($exportIndex) ?? []; }

    private function readPropertyList(int $offset, int $serialSize): array
    {
        $version = (int)$this->header['version'];
        if ($version >= 334) {
            return $this->readUE3PropertyList($offset, $serialSize);
        }
        return $this->readUE12PropertyList($offset, $serialSize);
    }

    private function readUE12PropertyList(int $offset, int $serialSize): array
    {
        $version = (int)$this->header['version'];
        $r = $this->tableReader($offset);
        $end = min($r->size(), $offset + $serialSize);
        $props = [];
        for ($i = 0; $i < 2048 && $r->tell() < $end; $i++) {
            $propStart = $r->tell();
            $nameIndex = $r->compactIndex();
            $name = $this->nameByIndex($nameIndex);
            if ($name === '' || strcasecmp($name, 'None') === 0) {
                break;
            }
            $info = $r->u8();
            $typeId = $info & 0x0F;
            $sizeCode = ($info >> 4) & 0x07;
            $boolFlag = ($info & 0x80) !== 0;
            $isBool = $typeId === 3;
            $structName = '';
            if ($typeId === 10) {
                $structNameIndex = $r->compactIndex();
                $structName = $this->nameByIndex($structNameIndex);
            }
            $size = $this->readPropertySize($r, $sizeCode);
            $arrayIndex = 0;
            if (!$isBool && $boolFlag) {
                $arrayIndex = $this->readPropertyArrayIndex($r);
            }
            $valueOffset = $r->tell();
            $raw = '';
            if ($isBool) {
                $value = $boolFlag;
            } else {
                $readSize = min($size, max(0, $end - $r->tell()));
                $raw = $readSize > 0 ? $r->bytes($readSize) : '';
                $value = $this->decodeUE12PropertyValue($typeId, $raw, $version, $structName);
            }
            $props[] = $this->makeProp($propStart, $r->tell(), $name, $typeId, $structName, (!$isBool && $boolFlag), $isBool ? (int)$boolFlag : 0, $arrayIndex, $sizeCode, $size, $info, $value, $raw, $valueOffset, 'UE1/UE2');
        }
        return $props;
    }

    private function readUE3PropertyList(int $offset, int $serialSize): array
    {
        $version = (int)$this->header['version'];
        $r = $this->tableReader($offset);
        $end = min($r->size(), $offset + $serialSize);
        $props = [];
        for ($i = 0; $i < 4096 && $r->tell() < $end; $i++) {
            $propStart = $r->tell();
            $nameRef = $this->readUE3FName($r);
            $name = $this->nameByIndex($nameRef['index'], $nameRef['number']);
            $baseName = $this->baseNameByIndex($nameRef['index']);
            if ($baseName === '' || strcasecmp($baseName, 'None') === 0) {
                break;
            }

            $typeRef = $this->readUE3FName($r);
            $typeName = $this->baseNameByIndex($typeRef['index']);
            $typeId = self::PROP_TYPE_NAMES[$typeName] ?? 0;
            $size = $r->i32();
            $arrayIndex = $r->i32();
            $structName = '';
            $boolValue = 0;
            $enumName = '';

            if ($typeId === 10) {
                $structRef = $this->readUE3FName($r);
                $structName = $this->nameByIndex($structRef['index'], $structRef['number']);
            } elseif ($typeId === 3) {
                $boolValue = $version < 673 ? $r->i32() : $r->u8();
            } elseif ($typeId === 1 && $version >= 633) {
                $enumRef = $this->readUE3FName($r);
                $enumName = $this->nameByIndex($enumRef['index'], $enumRef['number']);
            }

            $valueOffset = $r->tell();
            $raw = '';
            if ($typeId === 3) {
                $value = (bool)$boolValue;
            } else {
                $readSize = min(max(0, $size), max(0, $end - $r->tell()));
                $raw = $readSize > 0 ? $r->bytes($readSize) : '';
                $value = $this->decodeUE3PropertyValue($typeId, $raw, $version, $structName);
            }

            $props[] = $this->makeProp($propStart, $r->tell(), $name, $typeId, $structName, false, $boolValue, $arrayIndex, 0, $size, 0, $value, $raw, $valueOffset, 'UE3', [
                'typeName' => $typeName,
                'enumName' => $enumName,
                'nameIndex' => $nameRef['index'],
                'nameNumber' => $nameRef['number'],
                'typeIndex' => $typeRef['index'],
                'typeNumber' => $typeRef['number'],
            ]);
        }
        return $props;
    }

    private function makeProp(int $start, int $tell, string $name, int $typeId, string $structName, bool $isArray, int $boolFlag, int $arrayIndex, int $sizeCode, int $size, int $info, $value, string $raw, int $valueOffset, string $format, array $extra = []): array
    {
        return array_merge([
            'offset' => $start,
            'length' => $tell - $start,
            'name' => $name,
            'type' => self::PROP_TYPES[$typeId] ?? ('Type' . $typeId),
            'struct' => $structName,
            'isArray' => $isArray ? 1 : 0,
            'boolFlag' => $boolFlag,
            'idx' => $arrayIndex,
            'idxFromFile' => $arrayIndex,
            'sizeCode' => $sizeCode,
            'dataSize' => $size,
            'infoByte' => $info,
            'value' => $value,
            'rawHex' => strtoupper(bin2hex($raw)),
            'valueOffset' => $valueOffset,
            'tagFormat' => $format,
        ], $extra);
    }

    private function readUE3FName(UEFolderBinaryReader $r): array
    {
        return ['index' => $r->i32(), 'number' => $r->i32()];
    }

    private function readPropertySize(UEFolderBinaryReader $r, int $sizeCode): int
    {
        return match ($sizeCode) {
            0 => 1,
            1 => 2,
            2 => 4,
            3 => 12,
            4 => 16,
            5 => $r->u8(),
            6 => $r->u16(),
            7 => $r->i32(),
            default => 0,
        };
    }

    private function readPropertyArrayIndex(UEFolderBinaryReader $r): int
    {
        $b = $r->u8();
        if ($b < 128) {
            return $b;
        }
        $b2 = $r->u8();
        if (($b & 0x40) !== 0) {
            $b3 = $r->u8();
            $b4 = $r->u8();
            return (($b << 24) | ($b2 << 16) | ($b3 << 8) | $b4) & 0x3FFFFF;
        }
        return (($b << 8) | $b2) & 0x3FFF;
    }

    private function decodeUE12PropertyValue(int $typeId, string $raw, int $version, string $structName = '')
    {
        $r = new UEFolderBinaryReader($raw);
        try {
            return match ($typeId) {
                1 => strlen($raw) >= 1 ? $r->u8() : '',
                2 => $this->decodeIntegerRaw($raw),
                3 => '',
                4 => strlen($raw) >= 4 ? $r->f32() : '',
                5, 8 => strlen($raw) >= 1 ? $this->formatObjectRef($r->compactIndex()) : '',
                6 => strlen($raw) >= 1 ? $this->nameByIndex($r->compactIndex()) : '',
                7, 13 => strlen($raw) > 0 ? UEFolderBinaryReader::toUtf8(rtrim($raw, "\0")) : '',
                10 => $this->decodeStructProperty($structName, $raw),
                11 => strlen($raw) >= 12 ? $this->formatVector($raw) : strtoupper(bin2hex($raw)),
                12 => strlen($raw) >= 12 ? $this->formatRotator($raw) : strtoupper(bin2hex($raw)),
                default => strtoupper(bin2hex($raw)),
            };
        } catch (Throwable $e) {
            return strtoupper(bin2hex($raw));
        }
    }

    private function decodeUE3PropertyValue(int $typeId, string $raw, int $version, string $structName = '')
    {
        $r = new UEFolderBinaryReader($raw);
        try {
            return match ($typeId) {
                1 => strlen($raw) >= 1 ? $r->u8() : '',
                2 => $this->decodeIntegerRaw($raw),
                3 => '',
                4 => strlen($raw) >= 4 ? $r->f32() : '',
                5, 8 => strlen($raw) >= 4 ? $this->formatObjectRef($r->i32()) : '',
                6 => strlen($raw) >= 8 ? $this->formatUE3FNameValue($r) : '',
                7, 13 => strlen($raw) > 0 ? $this->decodeFStringRaw($raw) : '',
                10 => $this->decodeStructProperty($structName, $raw),
                11 => strlen($raw) >= 12 ? $this->formatVector($raw) : strtoupper(bin2hex($raw)),
                12 => strlen($raw) >= 12 ? $this->formatRotator($raw) : strtoupper(bin2hex($raw)),
                default => strtoupper(bin2hex($raw)),
            };
        } catch (Throwable $e) {
            return strtoupper(bin2hex($raw));
        }
    }

    private function formatUE3FNameValue(UEFolderBinaryReader $r): string
    {
        $idx = $r->i32();
        $num = $r->i32();
        return $this->nameByIndex($idx, $num);
    }

    private function decodeFStringRaw(string $raw): string
    {
        $r = new UEFolderBinaryReader($raw);
        try {
            return $r->fstring32();
        } catch (Throwable $e) {
            return UEFolderBinaryReader::toUtf8(rtrim($raw, "\0"));
        }
    }

    private function decodeStructProperty(string $structName, string $raw)
    {
        if (strcasecmp($structName, 'Color') === 0 && strlen($raw) === 4) {
            return $this->formatColor($raw);
        }
        if (strcasecmp($structName, 'LinearColor') === 0 && strlen($raw) >= 16) {
            return $this->formatLinearColor($raw);
        }
        if ((strcasecmp($structName, 'Vector') === 0 || strcasecmp($structName, 'Plane') === 0) && strlen($raw) >= 12) {
            return $this->formatVector($raw);
        }
        if (strcasecmp($structName, 'Rotator') === 0 && strlen($raw) >= 12) {
            return $this->formatRotator($raw);
        }
        return strtoupper(bin2hex($raw));
    }

    private function decodeIntegerRaw(string $raw)
    {
        return match (strlen($raw)) {
            1 => ord($raw),
            2 => unpack('v', $raw)[1],
            4 => $this->signed32((int)unpack('V', $raw)[1]),
            default => strtoupper(bin2hex($raw)),
        };
    }

    private function signed32(int $v): int
    {
        return ($v & 0x80000000) ? $v - 0x100000000 : $v;
    }

    private function formatObjectRef(int $ref): string
    {
        if ($ref === 0) {
            return '';
        }
        $name = $this->displayNameFromRef($ref);
        return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
    }

    private function formatVector(string $raw): string
    {
        $x = unpack('g', substr($raw, 0, 4))[1];
        $y = unpack('g', substr($raw, 4, 4))[1];
        $z = unpack('g', substr($raw, 8, 4))[1];
        return sprintf('(X=%s,Y=%s,Z=%s)', $this->fmtFloat((float)$x), $this->fmtFloat((float)$y), $this->fmtFloat((float)$z));
    }

    private function formatRotator(string $raw): string
    {
        $p = unpack('V', substr($raw, 0, 4))[1];
        $y = unpack('V', substr($raw, 4, 4))[1];
        $r = unpack('V', substr($raw, 8, 4))[1];
        return sprintf('(Pitch=%d,Yaw=%d,Roll=%d)', $p, $y, $r);
    }

    private function formatColor(string $raw): string
    {
        $c = unpack('C4', $raw);
        return sprintf('(R=%d,G=%d,B=%d,A=%d)', $c[1], $c[2], $c[3], $c[4]);
    }

    private function formatLinearColor(string $raw): string
    {
        $r = unpack('g', substr($raw, 0, 4))[1];
        $g = unpack('g', substr($raw, 4, 4))[1];
        $b = unpack('g', substr($raw, 8, 4))[1];
        $a = unpack('g', substr($raw, 12, 4))[1];
        return sprintf('(R=%s,G=%s,B=%s,A=%s)', $this->fmtFloat((float)$r), $this->fmtFloat((float)$g), $this->fmtFloat((float)$b), $this->fmtFloat((float)$a));
    }

    private function fmtFloat(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    private function decodeFlags(int $flags, array $map): array
    {
        $out = [];
        foreach ($map as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $out[] = $name;
            }
        }
        return $out;
    }
	
	private function readHeaderFString32(object $r, string $name): string
	{
		$offset = $r->tell();
		$value = $r->fstring32();
		$this->addRawHeaderField($name, $offset, $r->tell() - $offset, 'FString', $value);
		return $value;
	}

	private function rawHexAt(int $offset, int $size): string
	{
		if ($size <= 0) {
			return '';
		}

		return strtoupper(trim(chunk_split(bin2hex(substr($this->bytes, $offset, $size)), 2, ' ')));
	}

	private function addRawHeaderField(string $name, int $offset, int $size, string $type, $value, string $note = ''): void
	{
		$this->rawHeaderFields[] = [
			'offset' => $offset,
			'size' => $size,
			'name' => $name,
			'type' => $type,
			'value' => $value,
			'rawHex' => $this->rawHexAt($offset, $size),
			'note' => $note,
		];
	}

	private function readHeaderU32(object $r, string $name): int
	{
		$offset = $r->tell();
		$value = $r->u32();
		$this->addRawHeaderField($name, $offset, 4, 'uint32', $value);
		return $value;
	}

	private function readHeaderI32(object $r, string $name): int
	{
		$offset = $r->tell();
		$value = $r->i32();
		$this->addRawHeaderField($name, $offset, 4, 'int32', $value);
		return $value;
	}

	private function readHeaderU16(object $r, string $name): int
	{
		$offset = $r->tell();
		$value = $r->u16();
		$this->addRawHeaderField($name, $offset, 2, 'uint16', $value);
		return $value;
	}

	private function readHeaderI64(object $r, string $name): int
	{
		$offset = $r->tell();
		$value = $r->i64();
		$this->addRawHeaderField($name, $offset, 8, 'int64', $value);
		return $value;
	}

	private function readHeaderGuid(object $r, string $name = 'guid'): string
	{
		$offset = $r->tell();
		$a = $r->u32();
		$b = $r->u32();
		$c = $r->u32();
		$d = $r->u32();
		$value = sprintf('%08X-%08X-%08X-%08X', $a, $b, $c, $d);
		$this->addRawHeaderField($name, $offset, 16, 'FGuid', $value);
		return $value;
	}

	private function addUnparsedHeaderBytes(int $start, int $end, string $note): void
	{
		if ($end <= $start) {
			return;
		}

		$size = $end - $start;
		$this->addRawHeaderField('unparsedHeaderBytes', $start, $size, 'bytes', $size . ' bytes', $note);
	}

	private function firstPositiveHeaderOffset(array $keys): int
	{
		$values = [];
		foreach ($keys as $key) {
			$value = (int)($this->header[$key] ?? 0);
			if ($value > 0) {
				$values[] = $value;
			}
		}

		return $values ? min($values) : 0;
	}
}
