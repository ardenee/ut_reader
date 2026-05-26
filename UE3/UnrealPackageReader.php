<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

final class UEFolderBinaryReader
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $buf) { $this->buf = $buf; $this->len = strlen($buf); }
    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { $this->pos = max(0, min($pos, $this->len)); }
    public function remaining(): int { return $this->len - $this->pos; }
    public function size(): int { return $this->len; }
    public function peek(int $count): string { return substr($this->buf, $this->pos, max(0, $count)); }
    public function bytes(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->len) throw new OutOfBoundsException("read overrun need=$count pos={$this->pos} len={$this->len}");
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
        $neg = ($b & 0x80) !== 0;
        $more = ($b & 0x40) !== 0;
        $value = $b & 0x3F;
        $shift = 6;
        $count = 1;
        while ($more) {
            if (++$count > 5) throw new RuntimeException('Invalid compact index length');
            $b = $this->u8();
            $more = ($b & 0x80) !== 0;
            $value |= ($b & 0x7F) << $shift;
            $shift += 7;
        }
        return $neg ? -$value : $value;
    }
    public function versionIndex(int $version): int { return $version < 178 ? $this->compactIndex() : $this->i32(); }
    public function cstring(int $max = 1024): string
    {
        $out = '';
        for ($i = 0; $i < $max && $this->remaining() > 0; $i++) {
            $c = $this->bytes(1);
            if ($c === "\0") break;
            $out .= $c;
        }
        return self::toUtf8($out);
    }
    public function fstring32(): string { return $this->stringByLength($this->i32()); }
    public function fstringIndex(int $version): string { return $this->stringByLength($this->versionIndex($version)); }
    private function stringByLength(int $length): string
    {
        if ($length === 0) return '';
        if ($length > 0) {
            if ($length > 65536 || $length > $this->remaining()) throw new OutOfBoundsException("bad FString length=$length pos={$this->pos} remaining={$this->remaining()}");
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") $raw = substr($raw, 0, -1);
            return self::toUtf8($raw);
        }
        $chars = -$length;
        $bytes = $chars * 2;
        if ($chars > 32768 || $bytes > $this->remaining()) throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos} remaining={$this->remaining()}");
        $raw = $this->bytes($bytes);
        if (substr($raw, -2) === "\0\0") $raw = substr($raw, 0, -2);
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

    private const COMPRESS_ZLIB = 0x01;
    private const COMPRESS_LZO = 0x02;
    private const PKG_FLAGS = [0x00000001=>'PKG_AllowDownload',0x00000002=>'PKG_ClientOptional',0x00000004=>'PKG_ServerSideOnly',0x00000008=>'PKG_NoExportAllowed',0x00000010=>'PKG_Cooked',0x00000020=>'PKG_Encrypted',0x00800000=>'PKG_ContainsMap'];
    private const RF_FLAGS = [0x00000001=>'RF_Transactional',0x00000002=>'RF_Unreachable',0x00000004=>'RF_Public',0x00000008=>'RF_TagImp',0x00000010=>'RF_TagExp',0x00000020=>'RF_SourceModified',0x00000040=>'RF_TagGarbage',0x00000200=>'RF_NeedLoad',0x00000400=>'RF_HighlightedName',0x00004000=>'RF_Transient',0x00010000=>'RF_LoadForClient',0x00020000=>'RF_LoadForServer',0x00040000=>'RF_LoadForEdit',0x00080000=>'RF_Standalone',0x01000000=>'RF_NeedPostLoad',0x04000000=>'RF_Native'];
    private const PROP_TYPES = [0=>'None',1=>'ByteProperty',2=>'IntProperty',3=>'BoolProperty',4=>'FloatProperty',5=>'ObjectProperty',6=>'NameProperty',7=>'StringProperty',8=>'ClassProperty',9=>'ArrayProperty',10=>'StructProperty',11=>'VectorProperty',12=>'RotatorProperty',13=>'StrProperty',14=>'MapProperty',15=>'FixedArrayProperty'];
    private const PROP_TYPE_NAMES = ['ByteProperty'=>1,'IntProperty'=>2,'BoolProperty'=>3,'FloatProperty'=>4,'ObjectProperty'=>5,'NameProperty'=>6,'StringProperty'=>7,'DelegateProperty'=>7,'ClassProperty'=>8,'ArrayProperty'=>9,'StructProperty'=>10,'VectorProperty'=>11,'RotatorProperty'=>12,'StrProperty'=>13,'MapProperty'=>14,'TextProperty'=>14,'FixedArrayProperty'=>15,'InterfaceProperty'=>15];

    public function __construct(string $path)
    {
        $this->path = $path;
        try {
            $data = file_get_contents($path);
            if ($data === false) throw new RuntimeException("Failed to read package: $path");
            $this->bytes = $data;
            $this->parse();
        } catch (Throwable $e) {
            $this->issues[] = $this->formatThrowable($e);
            if (!$this->header) $this->header = $this->blankHeader();
        }
    }

    private function blankHeader(): array
    {
        return ['signature'=>0,'tag'=>0,'version'=>0,'licensee'=>0,'licenseeVersion'=>0,'pkgFlags'=>0,'packageFlags'=>0,'nameCount'=>0,'nameOffset'=>0,'exportCount'=>0,'exportOffset'=>0,'importCount'=>0,'importOffset'=>0,'dependsOffset'=>0,'guid'=>'','generations'=>[],'chunks'=>[],'compressedChunks'=>[],'compressed'=>false,'compressionFlags'=>0,'cFlags'=>0,'logicalDecompressed'=>false,'logicalSize'=>0,'nameTableLayout'=>'','exportTableLayout'=>'','u3unk60'=>''];
    }
    private function setHeaderBase(array $values): void { $this->header = array_replace($this->blankHeader(), $values); }
    private function formatThrowable(Throwable $e): string { return get_class($e) . ': ' . $e->getMessage() . ' File: ' . $e->getFile() . ':' . $e->getLine() . ' PHP: ' . PHP_VERSION . ' Package: ' . $this->path . ' Trace: ' . $e->getTraceAsString(); }

    private function parse(): void
    {
        $r = new UEFolderBinaryReader($this->bytes);
        $tag = $r->u32();
        if ($tag !== 0x9E2A83C1) throw new RuntimeException(sprintf('Bad package tag 0x%08X', $tag));
        $packed = $r->u32();
        $version = $packed & 0xFFFF;
        $licensee = ($packed >> 16) & 0xFFFF;
        $ext = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
        if (in_array($ext, ['ut3','upk','xxx'], true) || $version >= 334) $this->parseUE3($r, $tag, $packed, $version, $licensee);
        else $this->parseUE12($r, $tag, $packed, $version, $licensee);
    }

    private function parseUE12(UEFolderBinaryReader $r, int $tag, int $packed, int $version, int $licensee): void
    {
        $flags = $r->u32();
        $this->setHeaderBase(['signature'=>$tag,'tag'=>$tag,'packedVersion'=>$packed,'version'=>$version,'licensee'=>$licensee,'licenseeVersion'=>$licensee,'pkgFlags'=>$flags,'packageFlags'=>$flags]);
        $this->header['nameCount'] = $r->i32();
        $this->header['nameOffset'] = $r->i32();
        $this->header['exportCount'] = $r->i32();
        $this->header['exportOffset'] = $r->i32();
        $this->header['importCount'] = $r->i32();
        $this->header['importOffset'] = $r->i32();
        if ($version < 68) {
            $this->header['heritageCount'] = $r->i32();
            $this->header['heritageOffset'] = $r->i32();
            $this->header['generations'][] = ['e'=>$this->header['exportCount'], 'n'=>$this->header['nameCount']];
        } else {
            $guid = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
            $this->header['guidArray'] = $guid;
            $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);
            $genCount = $r->i32();
            $this->header['genCount'] = $genCount;
            for ($i = 0; $i < $genCount; $i++) $this->header['generations'][] = ['e'=>$r->i32(), 'n'=>$r->i32()];
        }
        $this->readUE12Names();
        $this->readUE12Imports();
        $this->readUE12Exports();
    }

    private function parseUE3(UEFolderBinaryReader $r, int $tag, int $packed, int $version, int $licensee): void
    {
        $this->setHeaderBase(['signature'=>$tag,'tag'=>$tag,'packedVersion'=>$packed,'version'=>$version,'licensee'=>$licensee,'licenseeVersion'=>$licensee]);
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
        if ($version >= 584) $this->header['thumbnailTableOffset'] = $r->u32();
        $guid = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
        $this->header['guidArray'] = $guid;
        $this->header['guid'] = sprintf('%08X-%08X-%08X-%08X', $guid[0], $guid[1], $guid[2], $guid[3]);
        $genCount = $r->u32();
        $this->header['genCount'] = $genCount;
        for ($i = 0; $i < $genCount; $i++) {
            $e = $r->u32();
            $n = $r->u32();
            $net = $version >= 322 ? $r->u32() : 0;
            $this->header['generations'][] = ['e'=>$e, 'n'=>$n, 'exportCount'=>$e, 'nameCount'=>$n, 'netObjectCount'=>$net];
        }
        $this->header['engineVersion'] = $version >= 245 ? $r->u32() : 0;
        $this->header['cookerVersion'] = $version >= 277 ? $r->u32() : 0;
        $this->header['compressionFlags'] = $version >= 334 ? $r->u32() : 0;
        $this->header['cFlags'] = $this->header['compressionFlags'];
        $this->header['compressed'] = $this->header['compressionFlags'] !== 0;
        if ($this->header['compressed']) {
            $chunkCount = $r->u32();
            for ($i = 0; $i < $chunkCount; $i++) $this->header['chunks'][] = ['uOff'=>$r->u32(), 'uLen'=>$r->u32(), 'cOff'=>$r->u32(), 'cLen'=>$r->u32()];
            $this->header['compressedChunks'] = $this->header['chunks'];
            $this->materializeCompressedUE3();
        }
        if ($version >= 482 && $r->remaining() >= 4) $this->header['u3unk60'] = $r->u32();
        $this->readUE3Names();
        $this->readUE3Imports();
        $this->readUE3Exports();
    }

    private function materializeCompressedUE3(): void
    {
        $chunks = $this->header['chunks'] ?? [];
        if (!$chunks) return;
        $logical = '';
        $first = min(array_map(static fn($c) => (int)$c['uOff'], $chunks));
        if ($first > 0) $logical = substr($this->bytes, 0, $first);
        foreach ($chunks as $c) {
            $uOff = (int)$c['uOff'];
            $uSize = (int)$c['uLen'];
            $cOff = (int)$c['cOff'];
            $cSize = (int)$c['cLen'];
            if (strlen($logical) < $uOff) $logical .= str_repeat("\0", $uOff - strlen($logical));
            $payload = substr($this->bytes, $cOff, $cSize);
            $decoded = $this->decompressUE3ChunkPayload($payload, $uSize);
            $logical = substr($logical, 0, $uOff) . $decoded . substr($logical, $uOff + strlen($decoded));
        }
        $this->bytes = $logical;
        $this->header['logicalDecompressed'] = true;
        $this->header['logicalSize'] = strlen($logical);
    }

    private function decompressUE3ChunkPayload(string $payload, int $expected): string
    {
        $r = new UEFolderBinaryReader($payload);
        $tag = $r->u32();
        if ($tag !== 0x9E2A83C1) throw new RuntimeException(sprintf('bad compressed chunk tag 0x%08X', $tag));
        $blockSize = $r->u32();
        $r->u32();
        $uncompressedTotal = $r->u32();
        $blockCount = (int)ceil($uncompressedTotal / max(1, $blockSize));
        $blocks = [];
        for ($i = 0; $i < $blockCount; $i++) $blocks[] = ['c'=>$r->i32(), 'u'=>$r->i32()];
        $out = '';
        foreach ($blocks as $b) $out .= $this->decompressBlock($r->bytes((int)$b['c']), (int)$b['u']);
        if ($expected > 0 && strlen($out) !== $expected) throw new RuntimeException('outer chunk size mismatch expected=' . $expected . ' got=' . strlen($out));
        return $out;
    }
    private function decompressBlock(string $src, int $expected): string
    {
        $flags = (int)($this->header['compressionFlags'] ?? 0);
        if (($flags & self::COMPRESS_ZLIB) !== 0) {
            $out = @gzuncompress($src, $expected);
            if ($out === false) $out = @gzdecode($src);
            if ($out === false) throw new RuntimeException('zlib decompression failed');
            return $out;
        }
        if (($flags & self::COMPRESS_LZO) !== 0) throw new RuntimeException('LZO package requires PHP FFI + liblzo2. Enable ffi.enable=true and install liblzo2 on Synology.');
        throw new RuntimeException('unsupported compression flags ' . sprintf('0x%08X', $flags));
    }

    private function tableReader(int $offset): UEFolderBinaryReader { $r = new UEFolderBinaryReader($this->bytes); $r->seek($offset); return $r; }
    private function looksLikeFStringAt(int $offset): bool
    {
        if ($offset < 0 || $offset + 4 > strlen($this->bytes)) return false;
        $r = $this->tableReader($offset);
        $len = $r->i32();
        if ($len > 0) return $len <= 1024 && $len <= $r->remaining();
        if ($len < 0) { $chars = -$len; return $chars <= 1024 && ($chars * 2) <= $r->remaining(); }
        return true;
    }
    private function detectUE3NameTableLayout(int $offset): string
    {
        if ($this->looksLikeFStringAt($offset)) return 'string-flags64';
        if ($this->looksLikeFStringAt($offset + 4)) return 'flags32-string';
        if ($this->looksLikeFStringAt($offset + 8)) return 'flags64-string';
        return 'invalid';
    }

    private function readUE12Names(): void
    {
        $r = $this->tableReader((int)$this->header['nameOffset']);
        $v = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name = $v < 64 ? $r->cstring() : $r->fstringIndex($v);
            $flags = $r->u32();
            $this->names[] = ['index'=>$i, 'name'=>$name, 'flags'=>$flags, 'objectFlags'=>$flags];
        }
    }
    private function readUE12Imports(): void
    {
        $r = $this->tableReader((int)$this->header['importOffset']);
        $v = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['importCount']; $i++) $this->imports[] = $this->makeImport($i, $r->versionIndex($v), 0, $r->versionIndex($v), 0, $r->i32(), $r->versionIndex($v), 0);
    }
    private function readUE12Exports(): void
    {
        $r = $this->tableReader((int)$this->header['exportOffset']);
        $v = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->versionIndex($v);
            $super = $r->versionIndex($v);
            $outer = $r->i32();
            $name = $r->versionIndex($v);
            $flags = $r->u32();
            $size = $r->versionIndex($v);
            $off = $size > 0 ? $r->versionIndex($v) : 0;
            $this->exports[] = $this->makeExport($i, $class, $super, $outer, $name, 0, 0, $flags, $size, $off);
        }
    }

    private function readUE3Names(): void
    {
        $offset = (int)$this->header['nameOffset'];
        $layout = $this->detectUE3NameTableLayout($offset);
        $this->header['nameTableLayout'] = $layout;
        if ($layout === 'invalid') throw new RuntimeException('Could not detect UE3 name table layout at nameOffset=' . $offset);
        $r = $this->tableReader($offset);
        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            if ($layout === 'flags32-string') { $flags = $r->u32(); $name = $r->fstring32(); }
            elseif ($layout === 'flags64-string') { $flags = $r->u32(); $r->u32(); $name = $r->fstring32(); }
            else { $name = $r->fstring32(); $flags = $r->u32(); $r->u32(); }
            $this->names[] = ['index'=>$i, 'name'=>$name, 'flags'=>$flags, 'objectFlags'=>$flags];
        }
    }
    private function readUE3FName(UEFolderBinaryReader $r): array
    {
        $idx = $r->versionIndex((int)$this->header['version']);
        $num = ((int)$this->header['version'] >= 343) ? $r->i32() : 0;
        return ['index'=>$idx, 'number'=>$num];
    }
    private function readUE3Imports(): void
    {
        $r = $this->tableReader((int)$this->header['importOffset']);
        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $cp = $this->readUE3FName($r);
            $cn = $this->readUE3FName($r);
            $outer = $r->i32();
            $on = $this->readUE3FName($r);
            $this->imports[] = $this->makeImport($i, $cp['index'], $cp['number'], $cn['index'], $cn['number'], $outer, $on['index'], $on['number']);
        }
    }

    private function readUE3Exports(): void
    {
        $this->header['exportTableLayout'] = 'uelib-ue3';
        $r = $this->tableReader((int)$this->header['exportOffset']);
        $v = (int)$this->header['version'];
        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class = $r->versionIndex($v);
            $super = $r->versionIndex($v);
            $outer = $r->i32();
            $objectName = $this->readUE3FName($r);
            $archetype = $v >= 220 ? $r->i32() : 0;
            if ($v >= 195) {
                $fileFlagsLo = $r->u32();
                $fileFlagsHi = $r->u32();
                $flags = $fileFlagsHi;
                $flagsHigh = $fileFlagsLo;
            } else {
                $flags = $r->u32();
                $flagsHigh = 0;
            }
            $serialSize = $r->versionIndex($v);
            $serialOffset = ($serialSize > 0 || $v >= 249) ? $r->versionIndex($v) : 0;
            $components = [];
            if ($v >= 220 && $v < 543) $components = $this->readUE3ComponentMap($r, $i);
            $exportFlags = $v >= 247 ? $r->u32() : 0;
            $netObjectCount = [];
            $guid = '';
            $packageFlags = 0;
            if ($v >= 322) {
                $netCount = $r->i32();
                if ($netCount < 0 || $netCount > 1024) throw new RuntimeException("Bad UE3 net object count $netCount in export $i at offset " . ($r->tell() - 4));
                for ($j = 0; $j < $netCount; $j++) $netObjectCount[] = $r->i32();
                $guidParts = [$r->u32(), $r->u32(), $r->u32(), $r->u32()];
                $guid = sprintf('%08X-%08X-%08X-%08X', $guidParts[0], $guidParts[1], $guidParts[2], $guidParts[3]);
            }
            if ($v >= 475) $packageFlags = $r->u32();
            $this->exports[] = $this->makeExport($i, $class, $super, $outer, $objectName['index'], $objectName['number'], $archetype, $flags, $serialSize, $serialOffset, $components, $exportFlags, $flagsHigh, $netObjectCount, $guid, $packageFlags);
        }
    }
    private function readUE3ComponentMap(UEFolderBinaryReader $r, int $exportIndex): array
    {
        $count = $r->i32();
        if ($count < 0 || $count > 4096) throw new RuntimeException("Bad UE3 component map count $count in export $exportIndex at offset " . ($r->tell() - 4));
        $items = [];
        for ($j = 0; $j < $count; $j++) {
            $name = $this->readUE3FName($r);
            $ref = $r->i32();
            $items[] = ['name'=>$name['index'], 'nameNumber'=>$name['number'], 'nameText'=>$this->nameByIndex($name['index'], $name['number']), 'ref'=>$ref];
        }
        return $items;
    }

    private function makeImport(int $i, int $classPackage, int $classPackageNumber, int $className, int $classNameNumber, int $outer, int $objectName, int $objectNameNumber): array
    {
        $cp = $this->nameByIndex($classPackage, $classPackageNumber);
        $cn = $this->nameByIndex($className, $classNameNumber);
        $on = $this->nameByIndex($objectName, $objectNameNumber);
        return ['index'=>$i,'classPackage'=>$classPackage,'className'=>$className,'outerIndex'=>$outer,'outer'=>$outer,'outerName'=>$this->displayNameFromRef($outer),'objectName'=>$objectName,'classPackageText'=>$cp,'classNameText'=>$cn,'objectNameText'=>$on,'ClassPackage'=>['index'=>$classPackage,'number'=>$classPackageNumber,'text'=>$cp],'ClassName'=>['index'=>$className,'number'=>$classNameNumber,'text'=>$cn],'OuterIndex'=>$outer,'ObjectName'=>['index'=>$objectName,'number'=>$objectNameNumber,'text'=>$on]];
    }
    private function makeExport(int $i, int $class, int $super, int $outer, int $objectName, int $objectNameNumber, int $archetype, int $flags, int $serialSize, int $serialOffset, array $components = [], int $exportFlags = 0, int $objectFlagsHigh = 0, array $netObjectCount = [], string $guid = '', int $packageFlags = 0): array
    {
        return ['index'=>$i,'classIndex'=>$class,'class'=>$class,'superIndex'=>$super,'super'=>$super,'packageIndex'=>$outer,'outerIndex'=>$outer,'outer'=>$outer,'objectName'=>$objectName,'nameIndex'=>$objectName,'nameNumber'=>$objectNameNumber,'objectNameText'=>$this->nameByIndex($objectName,$objectNameNumber),'objectFlags'=>$flags,'objectFlagsHigh'=>$objectFlagsHigh,'serialSize'=>$serialSize,'serialOffset'=>$serialOffset,'archetype'=>$archetype,'components'=>$components,'componentMap'=>$components,'exportFlags'=>$exportFlags,'netObjectCount'=>$netObjectCount,'guid'=>$guid,'packageFlags'=>$packageFlags,'u3unk6C'=>$packageFlags];
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getFileSize(): string { return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : ''; }
    public function getDebugErrors(): array { return $this->issues; }
	public function getRawHeaderFields(): array { return $this->rawHeaderFields; }
    public function validatePackage(): array { return $this->issues; }
    public function getCompressionInfo(): array
    {
        $chunks = $this->header['chunks'] ?? [];
        $tc = 0; $tu = 0;
        foreach ($chunks as $c) { $tc += (int)($c['cLen'] ?? 0); $tu += (int)($c['uLen'] ?? 0); }
        return ['isCompressed'=>(bool)($this->header['compressed'] ?? false),'flags'=>(int)($this->header['compressionFlags'] ?? 0),'chunks'=>$chunks,'totalCompressed'=>$tc,'totalUncompressed'=>$tu,'logicalDecompressed'=>(bool)($this->header['logicalDecompressed'] ?? false),'logicalSize'=>(int)($this->header['logicalSize'] ?? 0)];
    }
    public function nameByIndex(int $index, int $number = 0): string
    {
        if ($index < 0 || !isset($this->names[$index])) return '';
        $name = (string)($this->names[$index]['name'] ?? '');
        return ($number !== 0 && $name !== '') ? $name . '_' . $number : $name;
    }
    private function baseNameByIndex(int $index): string { return ($index >= 0 && isset($this->names[$index])) ? (string)($this->names[$index]['name'] ?? '') : ''; }
    public function displayNameFromRef(int $ref): string
    {
        if ($ref === 0) return '';
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
    public function describeCompressionFlags(int $flags): string
    {
        $out = [];
        if ($flags & self::COMPRESS_ZLIB) $out[] = 'ZLIB';
        if ($flags & self::COMPRESS_LZO) $out[] = 'LZO';
        return $out ? implode(', ', $out) : '';
    }
    public function getExportProperties(int $exportIndex): ?array
    {
        if (isset($this->propertyCache[$exportIndex])) return $this->propertyCache[$exportIndex];
        $ex = $this->exports[$exportIndex] ?? null;
        if (!$ex || (int)$ex['serialSize'] <= 0 || (int)$ex['serialOffset'] <= 0) return $this->propertyCache[$exportIndex] = [];
        try { return $this->propertyCache[$exportIndex] = $this->readPropertyList((int)$ex['serialOffset'], (int)$ex['serialSize']); }
        catch (Throwable $e) { $this->issues[] = 'Property parse failed for export ' . $exportIndex . ': ' . $e->getMessage(); return $this->propertyCache[$exportIndex] = []; }
    }
    public function getExportProperty(int $exportIndex, string $name, $default = null) { foreach ($this->getExportProperties($exportIndex) ?? [] as $p) if (($p['name'] ?? '') === $name) return $p['value'] ?? $default; return $default; }
    public function getPropertiesByClass(string $className): array { return []; }
    public function readPropertiesForExport(int $exportIndex): array { return $this->getExportProperties($exportIndex) ?? []; }
    private function readPropertyList(int $offset, int $serialSize): array { return ((int)$this->header['version'] >= 334) ? $this->readUE3PropertyList($offset, $serialSize) : []; }
    private function readUE3PropertyList(int $offset, int $serialSize): array
    {
        $r = $this->tableReader($offset);
        $end = min($r->size(), $offset + $serialSize);
        $props = [];
        for ($i = 0; $i < 4096 && $r->tell() + 16 <= $end; $i++) {
            $start = $r->tell();
            $nameRef = $this->readUE3FName($r);
            $base = $this->baseNameByIndex($nameRef['index']);
            $name = $this->nameByIndex($nameRef['index'], $nameRef['number']);
            if ($base === '' || strcasecmp($base, 'None') === 0) break;
            $typeRef = $this->readUE3FName($r);
            $typeName = $this->baseNameByIndex($typeRef['index']);
            $typeId = self::PROP_TYPE_NAMES[$typeName] ?? 0;
            $size = $r->i32();
            $idx = $r->i32();
            $bool = 0;
            $struct = '';
            $enumName = '';
            if ($typeId === 10) {
                $sr = $this->readUE3FName($r);
                $struct = $this->nameByIndex($sr['index'], $sr['number']);
            } elseif ($typeId === 1 && (int)$this->header['version'] >= 500) {
                $er = $this->readUE3FName($r);
                $enumName = $this->nameByIndex($er['index'], $er['number']);
            } elseif ($typeId === 3) {
                $bool = ((int)$this->header['version'] >= 500) ? $r->u8() : $r->i32();
            }
            $valOff = $r->tell();
            $raw = '';
            $value = (bool)$bool;
            if ($typeId !== 3) { $raw = $r->bytes(min(max(0, $size), max(0, $end - $r->tell()))); $value = $this->decodeUE3PropertyValue($typeId, $raw, $struct); }
            $props[] = ['offset'=>$start,'length'=>$r->tell()-$start,'name'=>$name,'type'=>$typeName ?: (self::PROP_TYPES[$typeId] ?? ('Type' . $typeId)),'typeName'=>$typeName,'struct'=>$struct,'enumName'=>$enumName,'idx'=>$idx,'idxFromFile'=>$idx,'dataSize'=>$size,'value'=>$value,'rawHex'=>strtoupper(bin2hex($raw)),'valueOffset'=>$valOff,'tagFormat'=>'UE3'];
        }
        return $props;
    }
    private function decodeUE3PropertyValue(int $typeId, string $raw, string $struct = '')
    {
        try {
            $r = new UEFolderBinaryReader($raw);
            return match($typeId) {
                1 => strlen($raw) >= 1 ? $r->u8() : '',
                2 => $this->decodeIntegerRaw($raw),
                4 => strlen($raw) >= 4 ? $r->f32() : '',
                5,8 => strlen($raw) >= 4 ? $this->formatObjectRef($r->i32()) : '',
                6 => strlen($raw) >= 8 ? $this->nameByIndex($r->i32(), $r->i32()) : '',
                7,13 => strlen($raw) > 0 ? UEFolderBinaryReader::toUtf8(rtrim($raw, "\0")) : '',
                10 => $this->decodeStructProperty($struct, $raw),
                11 => strlen($raw) >= 12 ? $this->formatVector($raw) : strtoupper(bin2hex($raw)),
                12 => strlen($raw) >= 12 ? $this->formatRotator($raw) : strtoupper(bin2hex($raw)),
                default => strtoupper(bin2hex($raw)),
            };
        } catch (Throwable $e) { return strtoupper(bin2hex($raw)); }
    }
    private function decodeStructProperty(string $struct, string $raw)
    {
        if (strcasecmp($struct, 'Color') === 0 && strlen($raw) === 4) return $this->formatColor($raw);
        if ((strcasecmp($struct, 'Vector') === 0 || strcasecmp($struct, 'Plane') === 0) && strlen($raw) >= 12) return $this->formatVector($raw);
        if (strcasecmp($struct, 'Rotator') === 0 && strlen($raw) >= 12) return $this->formatRotator($raw);
        return strtoupper(bin2hex($raw));
    }
    private function decodeIntegerRaw(string $raw) { return match(strlen($raw)) { 1 => ord($raw), 2 => unpack('v', $raw)[1], 4 => $this->signed32((int)unpack('V', $raw)[1]), default => strtoupper(bin2hex($raw)) }; }
    private function signed32(int $v): int { return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
    private function formatObjectRef(int $ref): string { if ($ref === 0) return ''; $name = $this->displayNameFromRef($ref); return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')'; }
    private function formatVector(string $raw): string { $x = unpack('g', substr($raw,0,4))[1]; $y = unpack('g', substr($raw,4,4))[1]; $z = unpack('g', substr($raw,8,4))[1]; return sprintf('(X=%s,Y=%s,Z=%s)', $this->fmtFloat((float)$x), $this->fmtFloat((float)$y), $this->fmtFloat((float)$z)); }
    private function formatRotator(string $raw): string { $p = unpack('V', substr($raw,0,4))[1]; $y = unpack('V', substr($raw,4,4))[1]; $rr = unpack('V', substr($raw,8,4))[1]; return sprintf('(Pitch=%d,Yaw=%d,Roll=%d)', $p, $y, $rr); }
    private function formatColor(string $raw): string { $c = unpack('C4', $raw); return sprintf('(R=%d,G=%d,B=%d,A=%d)', $c[1], $c[2], $c[3], $c[4]); }
    private function fmtFloat(float $v): string { $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.'); return $s === '-0' ? '0' : $s; }
    private function decodeFlags(int $flags, array $map): array { $out = []; foreach ($map as $bit => $name) if (($flags & $bit) !== 0) $out[] = $name; return $out; }

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
