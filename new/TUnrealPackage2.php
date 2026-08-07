<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides `new` parser/viewer support for tunreal package2, including `UE_LZX`.
 * Why: It exists for `new` package-format inspection, experiments, or parser development separate from the main
 *      catalog UI.
 * Role: Legacy/reference parser tooling unless another file explicitly requires it.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
// ============================================================
// TUnrealPackage.php  —  Unified UE1 / UE2 / UE3 / UE4 reader
// All export/import rows use NORMALISED field names so that
// annotateTablesWithText() and the view work across all versions.
//
// Normalised export fields:
//   class, super, outer, nameIndex, nameNumber,
//   objectFlags, serialSize, serialOffset,
//   archetype (0 for UE1/UE2), exportFlags (0 for UE1/UE2)
//
// Normalised import fields:
//   classPackageNameIndex, classNameIndex, outer, objectNameIndex,
//   objectNameNumber (0 for UE1/UE2)
// ============================================================

// ---- Package flags (combined UE1/UE2/UE3) -------------------
const PKG_FLAGS = [
    0x00000001 => 'PKG_AllowDownload',
    0x00000002 => 'PKG_ClientOptional',
    0x00000004 => 'PKG_ServerSideOnly',
    0x00000008 => 'PKG_BrokenLinks',    // UE1; UE3 calls this PKG_NoExportAllowed
    0x00000010 => 'PKG_Unsecure',       // UE1; UE3 calls this PKG_Cooked
    0x00000020 => 'PKG_Encrypted',      // UE3+
    0x00008000 => 'PKG_Need',           // UE1: client must download
];

// ---- Object flags (RF_*) ------------------------------------
const RF_FLAGS = [
    0x00000001 => 'RF_Transactional',
    0x00000002 => 'RF_Unreachable',
    0x00000004 => 'RF_Public',
    0x00000008 => 'RF_TagImp',
    0x00000010 => 'RF_TagExp',
    0x00000020 => 'RF_SourceModified',
    0x00000040 => 'RF_TagGarbage',
    0x00000200 => 'RF_NeedLoad',
    0x00000400 => 'RF_HighlightedName',
    0x00000800 => 'RF_InSingularFunc',
    0x00001000 => 'RF_Suppress',
    0x00002000 => 'RF_InEndState',
    0x00004000 => 'RF_Transient',
    0x00008000 => 'RF_PreLoading',
    0x00010000 => 'RF_LoadForClient',
    0x00020000 => 'RF_LoadForServer',
    0x00040000 => 'RF_LoadForEdit',
    0x00080000 => 'RF_Standalone',
    0x00100000 => 'RF_NotForClient',
    0x00200000 => 'RF_NotForServer',
    0x00400000 => 'RF_NotForEdit',
    0x00800000 => 'RF_Destroyed',
    0x01000000 => 'RF_NeedPostLoad',
    0x02000000 => 'RF_HasStack',
    0x04000000 => 'RF_Native',
    0x08000000 => 'RF_Marked',
    0x10000000 => 'RF_ErrorShutdown',
    0x20000000 => 'RF_DebugPostLoad',
    0x40000000 => 'RF_DebugSerialize',
    0x80000000 => 'RF_DebugDestroy',
];

// ---- Export flags (UE3+) ------------------------------------
const EF_FLAGS = [
    0x00000001 => 'EF_ForcedExport',
    0x00000002 => 'EF_ScriptPatcherExport',
    0x00000004 => 'EF_MemberFieldPatchPending',
];

// Decode a bitmask into flag name strings
function decodeFlagBits(int $flags, array $map): array {
    $set = [];
    foreach ($map as $bit => $name) {
        if ($flags & $bit) $set[] = $name;
    }
    return $set;
}

// ============================================================
// TReader  — low-level binary reader
// ============================================================
final class TReader {
    private string $buf;
    private int    $len;
    private int    $pos = 0;
    private int    $lo  = 0;
    private int    $hi;

    public function __construct(string $bytes) {
        $this->buf = $bytes;
        $this->len = strlen($bytes);
        $this->lo  = 0;
        $this->hi  = $this->len;
        $this->pos = 0;
    }

    public function u8():  int { return ord($this->bytes(1)); }
    public function i8():  int { $v = $this->u8();  return $v >= 0x80 ? $v - 0x100      : $v; }
    public function u16(): int { return (int)unpack('v', $this->bytes(2))[1]; }
    public function i16(): int { $u = $this->u16(); return ($u & 0x8000) ? $u - 0x10000 : $u; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $u = $this->u32(); return ($u & 0x80000000) ? $u - 0x100000000 : $u; }
    public function u64(): int { $lo = $this->u32(); $hi = $this->u32(); return ($hi << 32) | $lo; }
    public function i64(): int { $v = $this->u64(); return ($v & (1 << 63)) ? $v - (PHP_INT_MAX + 1) * 2 : $v; }

    /**
     * UE1/UE2 Compact Index (variable-length signed integer, 1–5 bytes).
     * Do NOT call this for UE3+ packages; use i32() instead.
     *
     *  Byte 0: bit7 = sign, bit6 = more bytes follow, bits5-0 = val[5:0]
     *  Byte 1+: bit7 = more bytes follow, bits6-0 = next 7 bits of val
     */
    public function compactIndex(): int {
        $b0  = $this->u8();
        $neg = ($b0 & 0x80) !== 0;
        $con = ($b0 & 0x40) !== 0;
        $val = $b0 & 0x3F;

        if ($con) {
            $b1  = $this->u8(); $val |= ($b1 & 0x7F) << 6;
            if ($b1 & 0x80) {
                $b2  = $this->u8(); $val |= ($b2 & 0x7F) << 13;
                if ($b2 & 0x80) {
                    $b3  = $this->u8(); $val |= ($b3 & 0x7F) << 20;
                    if ($b3 & 0x80) {
                        $b4  = $this->u8(); $val |= $b4 << 27;
                    }
                }
            }
        }
        return $neg ? -$val : $val;
    }

    /** FString: signed i32 length; >0 = ANSI+NUL; <0 = UTF-16LE+NUL; 0 = empty. */
    public function fstring(): string {
        $len = $this->i32();
        if ($len === 0) return '';
        if ($len > 0) {
            $raw = $this->bytes($len);
            return rtrim($raw, "\x00");
        }
        $cu  = -$len;
        $raw = $this->bytes($cu * 2);
        $raw = substr($raw, 0, -2); // drop NUL
        return (string)@mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }

    public function bytes(int $n): string {
        if ($n < 0) throw new \InvalidArgumentException("bytes($n)");
        if ($this->pos + $n > $this->hi)
            throw new \OutOfBoundsException("overrun: need $n, have ".($this->hi-$this->pos)." (pos {$this->pos})");
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    public function seek(int $pos): void { $this->pos = max($this->lo, min($pos, $this->hi)); }
    public function tell():      int    { return $this->pos; }
    public function remaining(): int    { return $this->hi - $this->pos; }
    public function length():    int    { return $this->hi - $this->lo; }

    public function setBounds(int $lo, int $hi): void {
        $this->lo = $lo; $this->hi = $hi; $this->pos = $lo;
    }
    public function fork(): TReader {
        $r = new TReader($this->buf);
        $r->lo = $this->lo; $r->hi = $this->hi; $r->pos = $this->pos;
        return $r;
    }
    public function physSlice(int $start, int $size): string {
        if ($start < 0 || $size < 0 || ($start + $size) > $this->len)
            throw new \OutOfBoundsException("physSlice($start,$size) out of len {$this->len}");
        return substr($this->buf, $start, $size);
    }
    public function peekU32(): int {
        if ($this->pos + 4 > $this->hi) throw new \OutOfBoundsException("peekU32 past end");
        return (int)unpack('V', substr($this->buf, $this->pos, 4))[1];
    }
}

// ============================================================
// TPackageReader  — factory
// ============================================================
final class TPackageReader {
    public static function open(string $path): AbstractUE {
        if (!is_file($path)) throw new \InvalidArgumentException("File not found: $path");
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException("Cannot read: $path");

        // Peek at the version word (bytes 4–7)
        if (strlen($bytes) < 8) throw new \RuntimeException("File too small");

        $sig = unpack('V', substr($bytes, 0, 4))[1];
        if ($sig !== 0x9E2A83C1) throw new \RuntimeException(sprintf("Bad signature 0x%08X", $sig));

        // Bytes 4–7: lo16 = version, hi16 = licensee
        $verWord = unpack('V', substr($bytes, 4, 4))[1];
        $ver     = $verWord & 0xFFFF;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ver >= 500 || in_array($ext, ['uasset','umap','pak'], true)) {
            $pkg = new TUE4($path, $bytes);
        } elseif ($ver >= 300 || in_array($ext, ['ut3','upk'], true)) {
            $pkg = new TUE3($path, $bytes);
        } elseif ($ver >= 100) {
            $pkg = new TUE2($path, $bytes);
        } else {
            $pkg = new TUE1($path, $bytes);
        }

        $pkg->load();
        return $pkg;
    }
}

// ============================================================
// AbstractUE  — shared base
// ============================================================
abstract class AbstractUE {
    protected TReader $R;
    protected string  $path             = '';
    protected string  $bytes            = '';
    protected array   $header           = [];
    protected array   $names            = [];
    protected array   $imports          = [];
    protected array   $exports          = [];
    protected array   $depends          = [];
    protected bool    $compressed       = false;
    protected int     $compressionFlags = 0;
    protected array   $chunks           = [];
    public    array   $chunkMeta        = [];

    public function __construct(string $path, string $bytes) {
        $this->path  = $path;
        $this->bytes = $bytes;
    }

    public function load(): void {
        $this->R = new TReader($this->bytes);
        $this->readHeader();
        $this->readNameTable();
        $this->readImportTable();
        $this->readExportTable();
        $this->annotateTablesWithText();
    }

    abstract protected function readHeader():      void;
    abstract protected function readNameTable():   void;
    abstract protected function readImportTable(): void;
    abstract protected function readExportTable(): void;

    // ---- public accessors ----
    public function getHeader():  array { return $this->header; }
    public function getNames():   array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getDepends(): array { return $this->depends; }
    public function getVersion(): int   { return (int)($this->header['version'] ?? 0); }
    public function isCompressed(): bool { return $this->compressed; }

    // ---- name helpers ----
    public function nameText(int $idx): string {
        if ($idx < 0 || $idx >= count($this->names)) return '';
        $n = $this->names[$idx] ?? null;
        if (!$n) return '';
        return is_array($n) ? (string)($n['name'] ?? '') : (string)$n;
    }

    /** Decode a package index (0=null, +N=export N-1, -N=import N-1) to display text. */
    public function refText(int $ref): string {
        if ($ref === 0) return 'None';
        if ($ref > 0) {
            $e = $this->exports[$ref - 1] ?? null;
            if (!$e) return "Export#".($ref-1)."(?)";
            return 'Export#'.($ref-1).'('.$this->nameText((int)($e['nameIndex'] ?? 0)).')';
        }
        $im = $this->imports[(-$ref) - 1] ?? null;
        if (!$im) return "Import#".((-$ref)-1)."(?)";
        return 'Import#'.((-$ref)-1).'('.$this->nameText((int)($im['objectNameIndex'] ?? 0)).')';
    }

    // ---- annotation (works for all versions via normalised field names) ----
    public function annotateTablesWithText(): void {
        // Quick lookup: import i → display name string
        $iName = [];
        foreach ($this->imports as $i => $im) {
            $iName[$i] = $this->nameText((int)($im['objectNameIndex'] ?? 0));
        }

        $eName = [];
        foreach ($this->exports as $i => $ex) {
            $eName[$i] = $this->nameText((int)($ex['nameIndex'] ?? 0));
        }

        $label = function(int $ref) use ($iName, $eName): string {
            if ($ref === 0) return 'None';
            if ($ref > 0)  return ($eName[$ref - 1] ?? '?');
            return ($iName[(-$ref) - 1] ?? '?');
        };

        foreach ($this->imports as $i => &$im) {
            $im['text'] = [
                'classPackage' => $this->nameText((int)($im['classPackageNameIndex'] ?? 0)),
                'className'    => $this->nameText((int)($im['classNameIndex']        ?? 0)),
                'objectName'   => $this->nameText((int)($im['objectNameIndex']       ?? 0)),
                'outer'        => $label((int)($im['outer'] ?? 0)),
            ];
        }
        unset($im);

        foreach ($this->exports as $i => &$ex) {
            $num  = (int)($ex['nameNumber'] ?? 0);
            $base = $this->nameText((int)($ex['nameIndex'] ?? 0));
            $ex['text'] = [
                'name'      => $num ? $base.'_'.$num : $base,
                'class'     => $label((int)($ex['class']     ?? 0)),
                'super'     => $label((int)($ex['super']     ?? 0)),
                'outer'     => $label((int)($ex['outer']     ?? 0)),
                'archetype' => $label((int)($ex['archetype'] ?? 0)),
            ];
        }
        unset($ex);
    }

    // ---- helpers shared by subclasses ----
    protected function nextTableStart(int $after): int {
        $cands = [];
        foreach (['nameOffset','importOffset','exportOffset','dependsOffset'] as $k) {
            $v = $this->header[$k] ?? 0;
            if ($v > $after) $cands[] = (int)$v;
        }
        return $cands ? min($cands) : $this->R->length();
    }
}

// ============================================================
// TUE1  — Unreal Engine 1  (version < 100, e.g. UT99 = 68)
// Uses compact indices for name table, import table, export table.
// ============================================================
final class TUE1 extends AbstractUE {

    protected function readHeader(): void {
        $R = $this->R;
        $R->seek(0);

        $this->header['tag'] = $R->u32();

        // One DWORD: lo16 = package version, hi16 = licensee version
        $verWord = $R->u32();
        $this->header['version']         = $verWord & 0xFFFF;
        $this->header['licenseeVersion'] = ($verWord >> 16) & 0xFFFF;

        $this->header['packageFlags'] = $R->u32();
        $this->header['nameCount']    = $R->u32();
        $this->header['nameOffset']   = $R->u32();
        $this->header['exportCount']  = $R->u32();
        $this->header['exportOffset'] = $R->u32();
        $this->header['importCount']  = $R->u32();
        $this->header['importOffset'] = $R->u32();

        $ver = (int)$this->header['version'];

        if ($ver < 68) {
            // Very early UE1: Heritage table instead of GUID+generations
            $heritageCount  = $R->u32();
            $heritageOffset = $R->u32();
            $this->header['heritageCount']  = $heritageCount;
            $this->header['heritageOffset'] = $heritageOffset;
            // Read GUIDs from the heritage table
            if ($heritageCount > 0 && $heritageOffset > 0) {
                $save = $R->tell();
                $R->seek($heritageOffset);
                $guids = [];
                for ($i = 0; $i < $heritageCount && $i < 32; $i++) {
                    $guids[] = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
                }
                $this->header['guid'] = end($guids) ?: [0,0,0,0];
                $R->seek($save);
            } else {
                $this->header['guid'] = [0,0,0,0];
            }
            $this->header['generations'] = [];
        } else {
            // UE1 (v68+): GUID + generation table
            $this->header['guid'] = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
            $genCount = $R->u32();
            $gens = [];
            for ($i = 0; $i < $genCount && $i < 1024; $i++) {
                $gens[] = ['exportCount' => $R->u32(), 'nameCount' => $R->u32()];
            }
            $this->header['generations'] = $gens;
        }

        $this->compressed             = false;
        $this->header['compressed']   = false;
        $this->header['engineVersion'] = 0;
    }

    /**
     * UE1 name table.
     *  v < 64  : ASCIIZ
     *  v 64-117: u8 length (incl. NUL) + bytes + u32 flags
     *  v >= 64  compact index length (incl. NUL) + bytes + u32 flags
     *
     * Note: length field INCLUDES the trailing NUL byte.
     */
    protected function readNameTable(): void {
        $this->names = [];
        $R   = $this->R;
        $ver = (int)$this->header['version'];
        $R->seek((int)$this->header['nameOffset']);

        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            if ($ver < 64) {
                // ASCIIZ
                $name = '';
                while ($R->remaining() > 0) {
                    $b = $R->u8();
                    if ($b === 0) break;
                    $name .= chr($b);
                }
            } else {
                $len  = ($ver > 117) ? $R->compactIndex() : $R->u8();
                $raw  = ($len > 0 && $len <= 2048) ? $R->bytes($len) : '';
                $name = rtrim($raw, "\x00");
            }
            $flags = $R->u32();
            $this->names[] = ['name' => $name, 'flags' => $flags];
        }
    }

    /** UE1 imports use compact indices for name refs; outer is a plain DWORD. */
    protected function readImportTable(): void {
        $this->imports = [];
        $R = $this->R;
        $R->seek((int)$this->header['importOffset']);

        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $classPackageNameIndex = $R->compactIndex();
            $classNameIndex        = $R->compactIndex();
            $outer                 = $R->i32();           // DWORD signed object ref
            $objectNameIndex       = $R->compactIndex();

            $this->imports[] = [
                'classPackageNameIndex' => $classPackageNameIndex,
                'classNameIndex'        => $classNameIndex,
                'outer'                 => $outer,
                'objectNameIndex'       => $objectNameIndex,
                'objectNameNumber'      => 0,
            ];
        }
    }

    /** UE1 exports use compact indices; objectFlags is a DWORD. */
    protected function readExportTable(): void {
        $this->exports = [];
        $R = $this->R;
        $R->seek((int)$this->header['exportOffset']);

        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class        = $R->compactIndex();
            $super        = $R->compactIndex();
            $outer        = $R->i32();           // DWORD signed object ref
            $nameIndex    = $R->compactIndex();
            $objectFlags  = $R->u32();
            $serialSize   = $R->compactIndex();
            $serialOffset = ($serialSize > 0) ? $R->compactIndex() : 0;

            $this->exports[] = [
                'class'        => $class,
                'super'        => $super,
                'outer'        => $outer,
                'nameIndex'    => $nameIndex,
                'nameNumber'   => 0,
                'objectFlags'  => $objectFlags,
                'serialSize'   => $serialSize,
                'serialOffset' => $serialOffset,
                'archetype'    => 0,
                'exportFlags'  => 0,
            ];
        }
    }
}

// ============================================================
// TUE2  — Unreal Engine 2  (version 100–299, e.g. UT2004 = 128)
// Same compact-index scheme as UE1; objectFlags are 32-bit DWORD.
// ============================================================
final class TUE2 extends AbstractUE {

    protected function readHeader(): void {
        $R = $this->R;
        $R->seek(0);

        $this->header['tag'] = $R->u32();

        $verWord = $R->u32();
        $this->header['version']         = $verWord & 0xFFFF;
        $this->header['licenseeVersion'] = ($verWord >> 16) & 0xFFFF;

        $this->header['packageFlags'] = $R->u32();
        $this->header['nameCount']    = $R->u32();
        $this->header['nameOffset']   = $R->u32();
        $this->header['exportCount']  = $R->u32();
        $this->header['exportOffset'] = $R->u32();
        $this->header['importCount']  = $R->u32();
        $this->header['importOffset'] = $R->u32();

        // UE2 always has GUID + generations (version always >= 68 by factory routing)
        $this->header['guid'] = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
        $genCount = $R->u32();
        $gens = [];
        for ($i = 0; $i < $genCount && $i < 1024; $i++) {
            $gens[] = ['exportCount' => $R->u32(), 'nameCount' => $R->u32()];
        }
        $this->header['generations'] = $gens;

        $this->compressed           = false;
        $this->header['compressed'] = false;
        $this->header['engineVersion'] = 0;
    }

    /** UE2 names: compact index length + bytes (incl NUL) + u32 flags. */
    protected function readNameTable(): void {
        $this->names = [];
        $R = $this->R;
        $R->seek((int)$this->header['nameOffset']);

        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $len  = $R->compactIndex();
            $raw  = ($len > 0 && $len <= 2048) ? $R->bytes($len) : '';
            $name = rtrim($raw, "\x00");
            $flags = $R->u32();
            $this->names[] = ['name' => $name, 'flags' => $flags];
        }
    }

    /** UE2 imports: compact indices for name refs, DWORD for outer. */
    protected function readImportTable(): void {
        $this->imports = [];
        $R = $this->R;
        $R->seek((int)$this->header['importOffset']);

        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $this->imports[] = [
                'classPackageNameIndex' => $R->compactIndex(),
                'classNameIndex'        => $R->compactIndex(),
                'outer'                 => $R->i32(),
                'objectNameIndex'       => $R->compactIndex(),
                'objectNameNumber'      => 0,
            ];
        }
    }

    /** UE2 exports: compact indices, 32-bit objectFlags. */
    protected function readExportTable(): void {
        $this->exports = [];
        $R = $this->R;
        $R->seek((int)$this->header['exportOffset']);

        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class        = $R->compactIndex();
            $super        = $R->compactIndex();
            $outer        = $R->i32();
            $nameIndex    = $R->compactIndex();
            $objectFlags  = $R->u32();
            $serialSize   = $R->compactIndex();
            $serialOffset = ($serialSize > 0) ? $R->compactIndex() : 0;

            $this->exports[] = [
                'class'        => $class,
                'super'        => $super,
                'outer'        => $outer,
                'nameIndex'    => $nameIndex,
                'nameNumber'   => 0,
                'objectFlags'  => $objectFlags,
                'serialSize'   => $serialSize,
                'serialOffset' => $serialOffset,
                'archetype'    => 0,
                'exportFlags'  => 0,
            ];
        }
    }
}

// ============================================================
// TUE3  — Unreal Engine 3  (version 300+, e.g. UT3 = 512)
// All indices are plain i32. Names use FString. Exports are complex.
// ============================================================
class TUE3 extends AbstractUE {

    protected function readHeader(): void {
        $R = $this->R;
        $R->seek(0);

        $tag = $R->u32();
        if ($tag !== 0x9E2A83C1)
            throw new \RuntimeException(sprintf("UE3 bad tag 0x%08X", $tag));

        $this->header['tag']             = $tag;
        $this->header['version']         = $R->u16();
        $this->header['licenseeVersion'] = $R->u16();
        $this->header['headerSize']      = $R->u32();
        $this->header['folderName']      = $R->fstring();
        $this->header['packageFlags']    = $R->u32();
        $this->header['nameCount']       = $R->u32();
        $this->header['nameOffset']      = $R->u32();
        $this->header['exportCount']     = $R->u32();
        $this->header['exportOffset']    = $R->u32();
        $this->header['importCount']     = $R->u32();
        $this->header['importOffset']    = $R->u32();
        $this->header['dependsOffset']   = ($this->header['version'] >= 415) ? $R->u32() : 0;
        $this->header['guid']            = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];

        $genCount = $R->u32();
        if ($genCount > 4096) throw new \RuntimeException("UE3 bad genCount $genCount");
        $gens = [];
        for ($i = 0; $i < $genCount; $i++) {
            $gens[] = [
                'exportCount'    => $R->u32(),
                'nameCount'      => $R->u32(),
                'netObjectCount' => ($this->header['version'] >= 334) ? $R->u32() : 0,
            ];
        }
        $this->header['generations']   = $gens;
        $this->header['engineVersion'] = $R->u32();
        $this->header['cookerVersion'] = $R->u32();

        $this->compressionFlags           = (int)$R->u32();
        $this->compressed                 = ($this->compressionFlags !== 0);
        $this->header['compressionFlags'] = $this->compressionFlags;
        $this->header['compressed']       = $this->compressed;

        if ($this->compressed) {
            $cc = $R->u32();
            $this->header['chunkCount'] = $cc;
            if ($cc > 1_000_000) throw new \RuntimeException("UE3 unreasonable chunkCount $cc");
            $this->chunks = [];
            for ($i = 0; $i < $cc; $i++) {
                $this->chunks[] = [
                    'uOff'  => $R->u32(),
                    'uSize' => $R->u32(),
                    'cOff'  => $R->u32(),
                    'cSize' => $R->u32(),
                ];
            }
        }
        $this->header['chunks'] = $this->chunks;
    }

    protected function readNameTable(): void {
        $nameCount  = (int)($this->header['nameCount']  ?? 0);
        $nameOffset = (int)($this->header['nameOffset'] ?? 0);
        if ($nameCount <= 0) { $this->names = []; return; }

        $R = ($this->compressed && !empty($this->chunks))
            ? $this->makeFullLogicalReader() : $this->R;
        $R->seek($nameOffset);

        $this->names = [];
        for ($i = 0; $i < $nameCount; $i++) {
            $name  = $R->fstring();
            $flags = $R->u64();         // UE3 name flags are 64-bit
            $this->names[] = ['name' => $name, 'flags' => $flags];
        }
    }

    protected function readImportTable(): void {
        $count  = (int)($this->header['importCount']  ?? 0);
        $offset = (int)($this->header['importOffset'] ?? 0);
        if ($count <= 0) { $this->imports = []; return; }

        $R = ($this->compressed && !empty($this->chunks))
            ? $this->makeFullLogicalReader() : $this->R;
        $R->seek($offset);

        $this->imports = [];
        for ($i = 0; $i < $count; $i++) {
            // Each FName = i32 NameIndex + i32 Number
            $classPackageNameIndex = $R->i32(); $R->i32(); // number (unused)
            $classNameIndex        = $R->i32(); $R->i32();
            $outer                 = $R->i32();
            $objectNameIndex       = $R->i32();
            $objectNameNumber      = $R->i32();

            $this->imports[] = [
                'classPackageNameIndex' => $classPackageNameIndex,
                'classNameIndex'        => $classNameIndex,
                'outer'                 => $outer,
                'objectNameIndex'       => $objectNameIndex,
                'objectNameNumber'      => $objectNameNumber,
            ];
        }
    }

    protected function readExportTable(): void {
        $count  = (int)($this->header['exportCount']  ?? 0);
        $offset = (int)($this->header['exportOffset'] ?? 0);
        if ($count <= 0) { $this->exports = []; return; }

        $R = ($this->compressed && !empty($this->chunks))
            ? $this->makeFullLogicalReader() : $this->R;
        $R->seek($offset);

        $arVer     = (int)($this->header['version'] ?? 0);
        $nameCount = (int)($this->header['nameCount'] ?? 0);
        $have      = fn(int $n): bool => $R->remaining() >= $n;

        // Heuristic for 64-bit flags byte order (auto-detected on row 0)
        $flagsHiFirst = null;

        $this->exports = [];

        for ($i = 0; $i < $count; $i++) {
            if (!$have(20)) break; // class+super+outer+nameIdx+nameNum

            $class      = $R->i32();
            $super      = $R->i32();
            $outer      = $R->i32();
            $nameIndex  = $R->i32();
            $nameNumber = $R->i32();

            $archetype = 0;
            if ($arVer >= 220 && $have(4)) $archetype = $R->i32();

            // 64-bit objectFlags
            $objectFlagsLo = 0;
            $objectFlagsHi = 0;
            if ($arVer >= 195 && $have(8)) {
                $a = $R->u32(); $b = $R->u32();
                if ($flagsHiFirst === null) {
                    $flagsHiFirst = ($b <= 0x00FFFFFF && $a > 0x00FFFFFF);
                }
                if ($flagsHiFirst) { $objectFlagsHi = $a; $objectFlagsLo = $b; }
                else               { $objectFlagsLo = $a; $objectFlagsHi = $b; }
            } elseif ($have(4)) {
                $objectFlagsLo = $R->u32();
            }
            $objectFlags = (($objectFlagsHi & 0xFFFFFFFF) << 32) | ($objectFlagsLo & 0xFFFFFFFF);

            $serialSize   = $have(4) ? $R->i32() : 0;
            $serialOffset = 0;
            if ($serialSize != 0 || $arVer >= 249) {
                if ($have(4)) $serialOffset = $R->i32();
            }

            // Component map (version < 543 only)
            $componentCount = 0;
            if ($arVer < 543 && $have(4)) {
                $cc = $R->i32();
                if ($cc >= 0 && $cc <= 4096 && $have($cc * 12)) {
                    $componentCount = $cc;
                    for ($c = 0; $c < $cc; $c++) { $R->i32(); $R->i32(); $R->i32(); }
                }
            }

            $exportFlags = ($arVer >= 247 && $have(4)) ? $R->u32() : 0;

            // Net-object count + per-object GUID (version >= 322)
            if ($arVer >= 322 && $have(4)) {
                $nc = $R->i32();
                if ($nc >= 0 && $nc <= 65536 && $have($nc * 4 + 16)) {
                    for ($k = 0; $k < $nc; $k++) $R->i32();
                    $R->u32(); $R->u32(); $R->u32(); $R->u32(); // GUID
                }
                if ($arVer >= 475 && $have(4)) $R->i32(); // u3unk6c
            }

            $this->exports[] = [
                'class'          => $class,
                'super'          => $super,
                'outer'          => $outer,
                'nameIndex'      => $nameIndex,
                'nameNumber'     => $nameNumber,
                'archetype'      => $archetype,
                'objectFlags'    => $objectFlags,
                'objectFlagsLo'  => $objectFlagsLo,
                'objectFlagsHi'  => $objectFlagsHi,
                'serialSize'     => $serialSize,
                'serialOffset'   => $serialOffset,
                'componentCount' => $componentCount,
                'exportFlags'    => $exportFlags,
            ];
        }
    }

    // ---- decompression ----------------------------------------
    protected function makeFullLogicalReader(): TReader {
        if (!$this->compressed || empty($this->chunks)) {
            $r = new TReader($this->bytes);
            $r->setBounds(0, strlen($this->bytes));
            return $r;
        }

        $chunks = $this->chunks;
        usort($chunks, fn($a,$b) => (int)$a['uOff'] <=> (int)$b['uOff']);

        $total = 0;
        foreach ($chunks as $ch) {
            $end = (int)$ch['uOff'] + (int)$ch['uSize'];
            if ($end > $total) $total = $end;
        }

        $buf = str_repeat("\x00", $total);
        $this->chunkMeta = [];

        foreach ($chunks as $idx => $ch) {
            $uOff = (int)$ch['uOff']; $uSize = (int)$ch['uSize'];
            $cOff = (int)$ch['cOff']; $cSize = (int)$ch['cSize'];
            if ($uSize === 0) continue;

            $meta = [];
            $part = $this->decompressChunkFramed($cOff, $cSize, $uSize, $meta);
            if (strlen($part) < $uSize) $part = str_pad($part, $uSize, "\x00");
            if (strlen($part) > $uSize) $part = substr($part, 0, $uSize);
            $buf  = substr_replace($buf, $part, $uOff, $uSize);
            $this->chunkMeta[$idx] = ['uOff'=>$uOff,'uSize'=>$uSize,'cOff'=>$cOff,'cSize'=>$cSize,'meta'=>$meta];
        }

        $r = new TReader($buf);
        $r->setBounds(0, strlen($buf));
        return $r;
    }

    protected function decompressChunkFramed(int $cOff, int $cSize, int $uSize, array &$meta = []): string {
        $raw = $this->R->physSlice($cOff, $cSize);
        $rr  = new TReader($raw);
        $rr->setBounds(0, strlen($raw));
        $out = '';

        if ($rr->remaining() >= 16) {
            $save    = $rr->tell();
            $tag     = $rr->u32(); $blkSize = $rr->u32();
            $compTot = $rr->i32(); $uncTot  = $rr->i32();

            if ($tag === 0x9E2A83C1 && $blkSize > 0 && $uncTot > 0) {
                $num = (int)ceil($uncTot / $blkSize);
                if ($rr->remaining() >= $num * 8) {
                    $pairs = [];
                    for ($i = 0; $i < $num; $i++) $pairs[] = [$rr->i32(), $rr->i32()];
                    foreach ($pairs as [$cs, $us]) {
                        $out .= UE_Decompress::inflate((int)$this->compressionFlags, $rr->bytes($cs), $us);
                    }
                    $meta = ['layout'=>'headered','blockSize'=>$blkSize,'blockCount'=>$num];
                    goto done;
                }
            }
            $rr->seek($save);
        }

        // Fallback: raw [cSize][uSize][data]* pairs
        $r2 = new TReader($raw); $r2->setBounds(0, strlen($raw));
        $blocks = 0;
        while ($r2->remaining() >= 8 && strlen($out) < $uSize) {
            $cs = $r2->i32(); $us = $r2->i32();
            if ($cs <= 0 || $us <= 0 || $r2->remaining() < $cs) break;
            $out .= UE_Decompress::inflate((int)$this->compressionFlags, $r2->bytes($cs), $us);
            $blocks++;
        }
        $meta = ['layout'=>'raw','blockCount'=>$blocks];

        done:
        return $out;
    }

    public function describeCompressionFlags(int $flags): string {
        $parts = [];
        if ($flags & 0x01) $parts[] = 'ZLIB';
        if ($flags & 0x02) $parts[] = 'LZO';
        if ($flags & 0x04) $parts[] = 'LZX';
        if ($flags & 0x10) $parts[] = 'LZO_ENC';
        return $parts ? implode('|', $parts) : ($flags ? sprintf('0x%04X',$flags) : 'None');
    }
}

// ============================================================
// TUE4  — Unreal Engine 4  (version 500+)
// Stub — extend per your UE4 .uasset spec as needed.
// ============================================================
final class TUE4 extends AbstractUE {

    protected function readHeader(): void {
        $R = $this->R;
        $R->seek(0);

        $this->header['tag']     = $R->u32();
        $verWord = $R->u32();
        $this->header['version']         = $verWord & 0xFFFF;
        $this->header['licenseeVersion'] = ($verWord >> 16) & 0xFFFF;
        $this->header['packageFlags'] = $R->u32();
        $this->header['nameCount']    = $R->u32();
        $this->header['nameOffset']   = $R->u32();
        $this->header['exportCount']  = $R->u32();
        $this->header['exportOffset'] = $R->u32();
        $this->header['importCount']  = $R->u32();
        $this->header['importOffset'] = $R->u32();
        $this->header['dependsOffset']= $R->u32();
        $this->header['guid']         = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
        $genCount = $R->u32();
        $gens = [];
        for ($i = 0; $i < $genCount && $i < 1024; $i++) {
            $gens[] = ['exportCount' => $R->u32(), 'nameCount' => $R->u32()];
        }
        $this->header['generations'] = $gens;
        $this->compressed = false;
        $this->header['compressed'] = false;
    }

    protected function readNameTable(): void {
        $this->names = [];
        $R = $this->R;
        $R->seek((int)$this->header['nameOffset']);
        for ($i = 0; $i < (int)$this->header['nameCount']; $i++) {
            $name  = $R->fstring();
            $flags = $R->u32();
            $this->names[] = ['name' => $name, 'flags' => $flags];
        }
    }

    protected function readImportTable(): void {
        $this->imports = [];
        $R = $this->R;
        $R->seek((int)$this->header['importOffset']);
        for ($i = 0; $i < (int)$this->header['importCount']; $i++) {
            $this->imports[] = [
                'classPackageNameIndex' => $R->i32(),
                'classNameIndex'        => $R->i32(),
                'outer'                 => $R->i32(),
                'objectNameIndex'       => $R->i32(),
                'objectNameNumber'      => 0,
            ];
        }
    }

    protected function readExportTable(): void {
        $this->exports = [];
        $R = $this->R;
        $R->seek((int)$this->header['exportOffset']);
        for ($i = 0; $i < (int)$this->header['exportCount']; $i++) {
            $class        = $R->i32();
            $super        = $R->i32();
            $outer        = $R->i32();
            $nameIndex    = $R->i32();
            $objectFlags  = $R->i64();
            $serialSize   = $R->i32();
            $serialOffset = ($serialSize > 0) ? $R->i32() : 0;
            $this->exports[] = [
                'class'        => $class,
                'super'        => $super,
                'outer'        => $outer,
                'nameIndex'    => $nameIndex,
                'nameNumber'   => 0,
                'objectFlags'  => $objectFlags,
                'serialSize'   => $serialSize,
                'serialOffset' => $serialOffset,
                'archetype'    => 0,
                'exportFlags'  => 0,
            ];
        }
    }
}

// ============================================================
// UE_Decompress  — codec router
// ============================================================
final class UE_Decompress {
    private static array $codecs = [];

    public static function register(int $id, callable $fn): void {
        self::$codecs[$id] = $fn;
    }

    public static function inflate(int $flags, string $payload, int $expectedLen): string {
        if (($flags & 0x02) && isset(self::$codecs[2]))
            return (self::$codecs[2])($payload, $expectedLen);
        if (($flags & 0x01) && isset(self::$codecs[1]))
            return (self::$codecs[1])($payload, $expectedLen);
        if (($flags & 0x04) && isset(self::$codecs[4]))
            return (self::$codecs[4])($payload, $expectedLen);
        throw new \RuntimeException("No codec registered for flags 0x".dechex($flags));
    }
}

// ZLIB (codec flag 0x01)
UE_Decompress::register(1, function(string $data, int $expected): string {
    $out = @gzuncompress($data) ?: @gzinflate($data);
    if ($out === false) throw new \RuntimeException("ZLIB decompression failed");
    return $out;
});

// LZO (codec flag 0x02) — uses UE_LZO1X pure-PHP below
UE_Decompress::register(2, function(string $data, int $expected): string {
    if (function_exists('lzo1x_decompress'))
        return lzo1x_decompress($data, $expected);
    return UE_LZO1X::decompress($data, $expected);
});

// LZX (codec flag 0x04)
UE_Decompress::register(4, function(string $data, int $expected): string {
    return UE_LZX::decompress($data, $expected);
});

// ============================================================
// UE_LZO1X  — minimal pure-PHP LZO1X decompressor
// ============================================================
final class UE_LZO1X {
    public static function decompress(string $c, int $expected): string {
        $i = 0; $n = strlen($c); $o = '';
        while ($i < $n && strlen($o) < $expected) {
            $ctrl = ord($c[$i++]);
            if ($ctrl >= 0xE0) {
                $lit = (($ctrl & 0x1F) << 2);
                if ($i < $n) $lit |= (ord($c[$i++]) >> 6);
                for ($k = 0; $k < $lit && $i < $n; $k++) $o .= $c[$i++];
                continue;
            }
            if ($ctrl >= 0xC0) {
                $len = ($ctrl & 0x1F) + 3;
                $dist = ord($c[$i++]) | ((($ctrl & 0x20) ? 1 : 0) << 8);
                self::copy($o, $dist + 1, $len); continue;
            }
            if ($ctrl >= 0x80) {
                $lit = ($ctrl & 0x1F);
                for ($k = 0; $k < $lit && $i < $n; $k++) $o .= $c[$i++];
                if ($i + 1 >= $n) break;
                $len  = 3 + (ord($c[$i]) >> 5);
                $dist = ((ord($c[$i]) & 0x1F) << 8) | ord($c[$i+1]); $i += 2;
                self::copy($o, $dist + 1, $len); continue;
            }
            for ($k = 0; $k < ($ctrl & 0x7F) && $i < $n; $k++) $o .= $c[$i++];
        }
        return $o;
    }
    private static function copy(string &$o, int $dist, int $len): void {
        $L = strlen($o); $src = $L - $dist;
        for ($k = 0; $k < $len; $k++)
            $o .= ($src + $k >= 0 && $src + $k < $L) ? $o[$src + $k] : "\x00";
    }
}

// ============================================================
// UE_LZX  — compact pure-PHP LZX/XMem inflator
// ============================================================
final class UE_LZX {
    public static function decompress(string $c, int $expected): string {
        return (new self($c, $expected))->run();
    }
    private string $c; private int $clen; private int $want;
    private int $pos=0,$bitbuf=0,$bits=0; private string $out='';
    function __construct(string $c, int $e) { $this->c=$c; $this->clen=strlen($c); $this->want=$e; }
    private function needBits(int $n): void {
        while ($this->bits < $n) { $b=($this->pos<$this->clen)?ord($this->c[$this->pos++]):0; $this->bitbuf=($this->bitbuf<<8)|$b; $this->bits+=8; }
    }
    private function readBits(int $n): int { $this->needBits($n); $v=($this->bitbuf>>($this->bits-$n))&((1<<$n)-1); $this->bits-=$n; return $v; }
    private function alignByte(): void { $k=$this->bits%8; if($k) $this->readBits($k); }
    private function readTree(array &$l, int $n): void {
        $l=array_fill(0,$n,0);
        for ($i=0;$i<$n;) { $v=$this->readBits(3); if($v===7){$run=$this->readBits(5);$sym=$this->readBits(3);for($k=0;$k<$run&&$i<$n;$k++,$i++)$l[$i]=$sym;}else{$l[$i++]=$v;} }
    }
    private static function buildHuff(array $ls, int $mb=12): array {
        $n=count($ls);$cnt=array_fill(0,$mb+1,0);
        foreach($ls as $ln) if($ln) $cnt[$ln]++;
        $code=0;$nxt=array_fill(0,$mb+1,0);
        for($i=1;$i<=$mb;$i++){$code=($code+$cnt[$i-1])<<1;$nxt[$i]=$code;}
        $tab=array_fill(0,1<<$mb,-1);
        for($i=0;$i<$n;$i++){$ln=$ls[$i]??0;if(!$ln)continue;$c=$nxt[$ln]++;$fill=1<<($mb-$ln);for($j=0;$j<$fill;$j++)$tab[($c<<($mb-$ln))|$j]=$i;}
        return $tab;
    }
    private function sym(array $tab, array $ls, int $mb=12): int {
        $idx=$this->readBits($mb); $s=$tab[$idx]; if($s>=0) return $s;
        $this->bits+=$mb; $code=0; $bl=0;
        while(true){$code=($code<<1)|$this->readBits(1);$bl++;$acc=0;
            for($i=0;$i<count($ls);$i++){if($ls[$i]===$bl){if($acc===$code)return $i;$acc++;}}}
    }
    private function copyM(int $dist, int $len): void {
        $L=strlen($this->out);$src=$L-$dist;
        for($i=0;$i<$len;$i++) $this->out.=($src+$i>=0&&$src+$i<$L)?$this->out[$src+$i]:"\x00";
    }
    public function run(): string {
        $this->alignByte();
        while(strlen($this->out)<$this->want){
            $isC=$this->readBits(1);$bO=$this->readBits(24);if($bO<=0)break;
            if($isC){
                $mL=[];$lL=[];$dL=[];
                $this->readTree($mL,256+(8*8));$this->readTree($lL,249);$this->readTree($dL,16*8);
                $mT=self::buildHuff($mL);$lT=self::buildHuff($lL);$dT=self::buildHuff($dL);
                $rem=$bO;
                while($rem>0){$s=$this->sym($mT,$mL);if($s<256){$this->out.=chr($s);$rem--;}
                    else{$len=($s-256&7)+3;$ds=$this->sym($dT,$dL);$d=($ds<<3)|($this->sym($lT,$lL,3)&7);$this->copyM($d+1,$len);$rem-=$len;}}
            } else {
                $this->alignByte();
                for($i=0;$i<$bO;$i++){$this->needBits(8);$this->out.=chr($this->readBits(8));}
            }
            $this->alignByte();
        }
        return $this->out;
    }
}
