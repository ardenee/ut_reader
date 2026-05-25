<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

final class UE1BinaryReader
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

    public function indexForVersion(int $version): int
    {
        return $version < 178 ? $this->compactIndex() : $this->i32();
    }

    public function cstring(int $max = 4096): string
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

    public function fstringCompact(int $version): string
    {
        return $this->stringByLength($this->indexForVersion($version));
    }

    private function stringByLength(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        if ($length > 0) {
            if ($length > 65536 || $length > $this->remaining()) {
                throw new OutOfBoundsException("bad string length=$length pos={$this->pos} remaining={$this->remaining()}");
            }
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            return self::toUtf8($raw);
        }

        $chars = -$length;
        $raw = $this->bytes($chars * 2);
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
            $this->issues[] = get_class($e) . ': ' . $e->getMessage() . ' File: ' . $e->getFile() . ':' . $e->getLine() . ' PHP: ' . PHP_VERSION . ' Package: ' . $this->path . ' Trace: ' . $e->getTraceAsString();
            $this->header = $this->blankHeader();
        }
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
            'heritageCount' => '',
            'heritageOffset' => '',
            'dependsOffset' => '',
            'guid' => '',
            'guidRaw' => '',
            'guidDwords' => '',
            'generations' => [],
            'chunks' => [],
            'compressedChunks' => [],
            'compressed' => false,
            'compressionFlags' => 0,
            'cFlags' => 0,
        ];
    }

    private function readGuidFromReader(UE1BinaryReader $r): void
    {
        $dwords = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
        $this->header['guidArray'] = $dwords;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $dwords[0], $dwords[1], $dwords[2], $dwords[3]);
        $this->header['guidDwords'] = $this->header['guid'];
        $this->header['guidRaw'] = strtoupper(bin2hex(pack('V4', $dwords[0], $dwords[1], $dwords[2], $dwords[3])));
    }

    private function parse(): void
    {
        $r = new UE1BinaryReader($this->bytes);
        $tag = $r->u32();
        if ($tag !== 0x9E2A83C1) {
            throw new RuntimeException(sprintf('Bad package tag 0x%08X', $tag));
        }

        $packed = $r->u32();
        $version = $packed & 0xFFFF;
        $licensee = ($packed >> 16) & 0xFFFF;
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
            $remainingBeforeNames = (int)$this->header['nameOffset'] - $r->tell();
            if ($remainingBeforeNames >= 16) {
                $this->readGuidFromReader($r);
            }
            $this->header['generations'] = [[
                'e' => $this->header['exportCount'],
                'n' => $this->header['nameCount'],
                'exportCount' => $this->header['exportCount'],
                'nameCount' => $this->header['nameCount'],
            ]];
        } else {
            $this->readGuidFromReader($r);
            $genCount = $r->i32();
            $this->header['genCount'] = $genCount;
            for ($i = 0; $i < $genCount; $i++) {
                $e = $r->i32();
                $n = $r->i32();
                $this->header['generations'][] = ['e' => $e, 'n' => $n, 'exportCount' => $e, 'nameCount' => $n];
            }
        }

        $this->readNames();
        $this->readImports();
        $this->readExports();
    }

    private function readerAt(int $offset): UE1BinaryReader
    {
        $r = new UE1BinaryReader($this->bytes);
        $r->seek($offset);
        return $r;
    }

    private function readNames(): void
    {
        $r = $this->readerAt((int)$this->header['nameOffset']);
        $version = (int)$this->header['version'];

        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name = $version < 64 ? $r->cstring() : $r->fstringCompact($version);
            $flags = $r->u32();
            $this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags, 'objectFlags' => $flags];
        }
    }

    private function readImports(): void
    {
        $r = $this->readerAt((int)$this->header['importOffset']);
        $version = (int)$this->header['version'];

        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $classPackage = $r->indexForVersion($version);
            $className = $r->indexForVersion($version);
            $outer = $r->i32();
            $objectName = $r->indexForVersion($version);
            $this->imports[] = $this->makeImport($i, $classPackage, 0, $className, 0, $outer, $objectName, 0);
        }
    }

    private function readExports(): void
    {
        $r = $this->readerAt((int)$this->header['exportOffset']);
        $version = (int)$this->header['version'];

        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->indexForVersion($version);
            $super = $r->indexForVersion($version);
            $outer = $r->i32();
            $objectName = $r->indexForVersion($version);
            $flags = $r->u32();
            $serialSize = $r->indexForVersion($version);
            $serialOffset = $serialSize > 0 ? $r->indexForVersion($version) : 0;
            $this->exports[] = $this->makeExport($i, $class, $super, $outer, $objectName, 0, 0, $flags, $serialSize, $serialOffset);
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

    private function makeExport(int $i, int $class, int $super, int $outer, int $objectName, int $objectNameNumber, int $archetype, int $flags, int $serialSize, int $serialOffset): array
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
            'components' => [],
            'componentMap' => [],
            'exportFlags' => 0,
        ];
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getFileSize(): string { return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : ''; }
    public function getDebugErrors(): array { return $this->issues; }
    public function validatePackage(): array { return $this->issues; }
    public function getCompressionInfo(): array { return ['isCompressed' => false, 'flags' => 0, 'chunks' => [], 'totalCompressed' => 0, 'totalUncompressed' => 0]; }

    public function nameByIndex(int $index, int $number = 0): string
    {
        if ($index < 0 || !isset($this->names[$index])) {
            return '';
        }
        $name = (string)($this->names[$index]['name'] ?? '');
        return ($number !== 0 && $name !== '') ? $name . '_' . $number : $name;
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
    public function describeCompressionFlags(int $flags): string { return ''; }

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
            return $this->propertyCache[$exportIndex] = $this->readPropertyList((int)$ex['serialOffset'], (int)$ex['serialSize']);
        } catch (Throwable $e) {
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
        $r = $this->readerAt($offset);
        $end = min($r->size(), $offset + $serialSize);
        $props = [];

        for ($i = 0; $i < 1024 && $r->tell() < $end; $i++) {
            $propStart = $r->tell();
            $nameIndex = $r->indexForVersion($version);
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
                $structNameIndex = $r->indexForVersion($version);
                $structName = $this->nameByIndex($structNameIndex);
            }

            $size = $this->readPropertySize($r, $sizeCode);
            $arrayIndex = 0;

            if (!$isBool && $boolFlag) {
                $arrayIndex = $this->readPropertyArrayIndex($r);
            }

            $valueOffset = $r->tell();
            $raw = '';
            $value = '';

            if ($isBool) {
                $value = $boolFlag;
            } else {
                $readSize = min($size, max(0, $end - $r->tell()));
                $raw = $readSize > 0 ? $r->bytes($readSize) : '';
                $value = $this->decodePropertyValue($typeId, $raw, $version, $structName);
            }

            $props[] = [
                'offset' => $propStart,
                'length' => $r->tell() - $propStart,
                'name' => $name,
                'type' => self::PROP_TYPES[$typeId] ?? ('Type' . $typeId),
                'struct' => $structName,
                'isArray' => (!$isBool && $boolFlag) ? 1 : 0,
                'boolFlag' => $isBool ? (int)$boolFlag : 0,
                'idx' => $arrayIndex,
                'idxFromFile' => $arrayIndex,
                'sizeCode' => $sizeCode,
                'dataSize' => $size,
                'infoByte' => $info,
                'value' => $value,
                'rawHex' => strtoupper(bin2hex($raw)),
                'valueOffset' => $valueOffset,
            ];
        }

        return $props;
    }

    private function readPropertySize(UE1BinaryReader $r, int $sizeCode): int
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

    private function readPropertyArrayIndex(UE1BinaryReader $r): int
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

    private function decodePropertyValue(int $typeId, string $raw, int $version, string $structName = '')
    {
        $r = new UE1BinaryReader($raw);
        try {
            return match ($typeId) {
                1 => strlen($raw) >= 1 ? $r->u8() : '',
                2 => $this->decodeIntegerRaw($raw),
                3 => '',
                4 => strlen($raw) >= 4 ? $r->f32() : '',
                5, 8 => strlen($raw) >= 1 ? $this->formatObjectRef($r->indexForVersion($version)) : '',
                6 => strlen($raw) >= 1 ? $this->nameByIndex($r->indexForVersion($version)) : '',
                7, 13 => strlen($raw) > 0 ? UE1BinaryReader::toUtf8(rtrim($raw, "\0")) : '',
                10 => $this->decodeStructProperty($structName, $raw),
                11 => strlen($raw) >= 12 ? $this->formatVector($raw) : strtoupper(bin2hex($raw)),
                12 => strlen($raw) >= 12 ? $this->formatRotator($raw) : strtoupper(bin2hex($raw)),
                default => strtoupper(bin2hex($raw)),
            };
        } catch (Throwable $e) {
            return strtoupper(bin2hex($raw));
        }
    }

    private function decodeStructProperty(string $structName, string $raw)
    {
        if (strcasecmp($structName, 'Color') === 0 && strlen($raw) === 4) {
            return $this->formatColor($raw);
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
}
