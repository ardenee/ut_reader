<?php
declare(strict_types=1);

final class UE4BinaryReader
{
    private string $buf;
    private int $len;
    private int $pos = 0;
    public function __construct(string $buf) { $this->buf = $buf; $this->len = strlen($buf); }
    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { $this->pos = max(0, min($pos, $this->len)); }
    public function remaining(): int { return $this->len - $this->pos; }
    public function bytes(int $n): string { if ($n < 0 || $this->pos + $n > $this->len) throw new OutOfBoundsException("read overrun need=$n pos={$this->pos} len={$this->len}"); $s = substr($this->buf, $this->pos, $n); $this->pos += $n; return $s; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
    public function u64(): int { $p = unpack('Vlo/Vhi', $this->bytes(8)); return ((int)$p['hi'] << 32) | (int)$p['lo']; }
    public function i64(): int { return $this->u64(); }
    public function fstring(): string
    {
        $len = $this->i32();
        if ($len === 0) return '';
        if ($len > 0) {
            if ($len > 1048576 || $len > $this->remaining()) throw new OutOfBoundsException("bad FString length=$len pos={$this->pos}");
            $raw = $this->bytes($len);
            return self::toUtf8(rtrim($raw, "\0"));
        }
        $chars = -$len; $bytes = $chars * 2;
        if ($chars > 524288 || $bytes > $this->remaining()) throw new OutOfBoundsException("bad wide FString length=$len pos={$this->pos}");
        $raw = $this->bytes($bytes);
        if (substr($raw, -2) === "\0\0") $raw = substr($raw, 0, -2);
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        return $out === false ? '' : $out;
    }
    public function fname(): array { return ['index'=>$this->i32(), 'number'=>$this->i32()]; }
    public function guid(): string { $g = [$this->u32(), $this->u32(), $this->u32(), $this->u32()]; return sprintf('%08X-%08X-%08X-%08X', $g[0], $g[1], $g[2], $g[3]); }
    public static function toUtf8(string $raw): string { $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252'); return $out === false ? $raw : $out; }
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
    private const TAG = 0x9E2A83C1;
    private const PKG_FLAGS = [0x00000008=>'PKG_Cooked',0x00000040=>'PKG_EditorOnly',0x00002000=>'PKG_UnversionedProperties',0x00020000=>'PKG_ContainsMap',0x00200000=>'PKG_ContainsScript',0x00400000=>'PKG_ContainsDebugData',0x02000000=>'PKG_Compressed',0x04000000=>'PKG_FullyCompressed',0x20000000=>'PKG_NoExportsData',0x40000000=>'PKG_FilterEditorOnly'];

    public function __construct(string $path)
    {
        $this->path = $path;
        try { $this->bytes = file_get_contents($path) ?: ''; if ($this->bytes === '') throw new RuntimeException("Failed to read package: $path"); $this->parse(); }
        catch (Throwable $e) { $this->issues[] = get_class($e) . ': ' . $e->getMessage() . ' File: ' . $e->getFile() . ':' . $e->getLine(); if (!$this->header) $this->header = $this->blankHeader(); }
    }

    private function blankHeader(): array
    {
        return ['signature'=>0,'legacyFileVersion'=>0,'legacyUE3Version'=>0,'fileVersionUE4'=>0,'fileVersionLicenseeUE4'=>0,'customVersionCount'=>0,'totalHeaderSize'=>0,'folderName'=>'','packageFlags'=>0,'pkgFlags'=>0,'nameCount'=>0,'nameOffset'=>0,'exportCount'=>0,'exportOffset'=>0,'importCount'=>0,'importOffset'=>0,'dependsOffset'=>0,'guid'=>'','nameTableLayout'=>'','exportTableLayout'=>''];
    }
    private function tableReader(int $offset): UE4BinaryReader { $r = new UE4BinaryReader($this->bytes); $r->seek($offset); return $r; }

    private function parse(): void
    {
        $r = new UE4BinaryReader($this->bytes);
        $tag = $r->u32();
        if ($tag !== self::TAG) throw new RuntimeException(sprintf('Bad UE4 package tag 0x%08X', $tag));
        $h = $this->blankHeader();
        $h['signature'] = $tag;
        $h['legacyFileVersion'] = $r->i32();
        if ($h['legacyFileVersion'] < 0) { $h['legacyUE3Version'] = $r->i32(); $h['fileVersionUE4'] = $r->i32(); $h['fileVersionLicenseeUE4'] = $r->i32(); }
        $customCount = $r->i32();
        $h['customVersionCount'] = $customCount;
        if ($customCount < 0 || $customCount > 4096) throw new RuntimeException('Bad UE4 custom version count ' . $customCount);
        for ($i = 0; $i < $customCount; $i++) { $r->guid(); $r->i32(); }
        $h['totalHeaderSize'] = $r->i32();
        $h['folderName'] = $r->fstring();
        $h['packageFlags'] = $h['pkgFlags'] = $r->u32();
        $h['nameCount'] = $r->i32(); $h['nameOffset'] = $r->i32();
        $h['gatherableTextDataCount'] = $r->i32(); $h['gatherableTextDataOffset'] = $r->i32();
        $h['exportCount'] = $r->i32(); $h['exportOffset'] = $r->i32();
        $h['importCount'] = $r->i32(); $h['importOffset'] = $r->i32();
        $h['dependsOffset'] = $r->i32();
        if ($r->remaining() >= 16) $h['guid'] = $r->guid();
        $this->header = $h;
        $this->readNames();
        $this->readImports();
        $this->readExports();
    }

    private function readNames(): void
    {
        $count = (int)$this->header['nameCount']; $offset = (int)$this->header['nameOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) return;
        $this->header['nameTableLayout'] = 'fstring-hash';
        $r = $this->tableReader($offset);
        for ($i = 0; $i < $count && $r->remaining() > 4; $i++) {
            try { $this->names[] = ['index'=>$i,'name'=>$r->fstring(),'hash'=>$r->u32()]; }
            catch (Throwable $e) { $this->issues[] = 'Name parse stopped at ' . $i . ': ' . $e->getMessage(); break; }
        }
    }

    private function readImports(): void
    {
        $count = (int)$this->header['importCount']; $offset = (int)$this->header['importOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) return;
        $r = $this->tableReader($offset);
        for ($i = 0; $i < $count && $r->remaining() >= 28; $i++) {
            $cp = $r->fname(); $cn = $r->fname(); $outer = $r->i32(); $on = $r->fname();
            $this->imports[] = ['index'=>$i,'classPackage'=>$cp['index'],'classPackageText'=>$this->nameByIndex($cp['index'],$cp['number']),'className'=>$cn['index'],'classNameText'=>$this->nameByIndex($cn['index'],$cn['number']),'outerIndex'=>$outer,'objectName'=>$on['index'],'objectNameText'=>$this->nameByIndex($on['index'],$on['number'])];
        }
    }

    private function readExports(): void
    {
        $count = (int)$this->header['exportCount']; $offset = (int)$this->header['exportOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) return;
        $this->header['exportTableLayout'] = 'ue4-initial';
        $r = $this->tableReader($offset);
        for ($i = 0; $i < $count && $r->remaining() >= 72; $i++) {
            try {
                $class = $r->i32(); $super = $r->i32(); $template = $r->i32(); $outer = $r->i32(); $name = $r->fname();
                $this->exports[] = ['index'=>$i,'classIndex'=>$class,'superIndex'=>$super,'templateIndex'=>$template,'outerIndex'=>$outer,'objectName'=>$name['index'],'nameNumber'=>$name['number'],'objectNameText'=>$this->nameByIndex($name['index'],$name['number']),'objectFlags'=>$r->u64(),'serialSize'=>$r->i64(),'serialOffset'=>$r->i64(),'forcedExport'=>$r->u32(),'notForClient'=>$r->u32(),'notForServer'=>$r->u32(),'guid'=>$r->guid(),'packageFlags'=>$r->u32()];
            } catch (Throwable $e) { $this->issues[] = 'Export parse stopped at ' . $i . ': ' . $e->getMessage(); break; }
        }
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function validatePackage(): array { return $this->issues; }
    public function getDebugErrors(): array { return $this->issues; }
    public function getFileSize(): string { return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : ''; }
    public function decodePKG(int $flags): array { $out = []; foreach (self::PKG_FLAGS as $bit => $name) if (($flags & $bit) !== 0) $out[] = $name; return $out; }
    public function nameByIndex(int $index, int $number = 0): string { $name = ($index >= 0 && isset($this->names[$index])) ? (string)$this->names[$index]['name'] : ''; return ($number > 0 && $name !== '') ? $name . '_' . $number : $name; }
    public function displayNameFromRef(int $ref): string { if ($ref === 0) return ''; if ($ref > 0) { $ex = $this->exports[$ref - 1] ?? null; return is_array($ex) ? $this->nameByIndex((int)$ex['objectName'], (int)$ex['nameNumber']) : ''; } $im = $this->imports[-$ref - 1] ?? null; return is_array($im) ? (string)$im['objectNameText'] : ''; }
    public function getExportProperties(int $exportIndex): array { return []; }
}
