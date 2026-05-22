<?php
declare(strict_types=1);

/* ===========================
   CSS (outside any class)
   =========================== */
echo <<<CSS
<style>
table { border-collapse: collapse; font-family: Consolas, monospace; font-size: 12px; }
th, td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; }
th { background:#f3f3f3; text-align:left; }
h3, h4 { font-family: Segoe UI, sans-serif; }
.small { color:#666; font-size:11px; }
.idx0 { color:#c00; font-weight:600; } /* inferred idx 0 (not stored in file) */
</style>
CSS;

/* ===========================
   Tiny markup helpers
   =========================== */
function th(string $s): string { return "<th>{$s}</th>"; }
function td(string $s, string $cls=''): string {
    $attr = $cls ? " class=\"{$cls}\"" : "";
    return "<td{$attr}>{$s}</td>";
}

/* ===========================
   Flag helpers (GLOBAL)
   =========================== */
function flags_pkg_to_text(int $flags): string {
    $map = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000020 => 'PKG_Encrypted',
    ];
    $out = [];
    foreach ($map as $bit=>$name) if ($flags & $bit) $out[] = $name;
    return implode(', ', $out);
}

function flags_object_to_text(int $flags): string {
    $map = [
        0x00000001 => 'RF_Public',
        0x00000004 => 'RF_Standalone',
        0x00000010 => 'RF_LoadForClient',
        0x00000020 => 'RF_LoadForServer',
        0x00000040 => 'RF_LoadForEdit',
        0x00010000 => 'RF_HighlightedName',
        0x00040000 => 'RF_InSingularFunc',
        0x00080000 => 'RF_Native',
        0x20000000 => 'RF_HasStack',
        0x40000000 => 'RF_ErrorShutdown',
    ];
    $out = [];
    foreach ($map as $bit=>$name) if ($flags & $bit) $out[] = $name;
    return implode(',', $out);
}

/* ===========================
   UE Reader
   =========================== */
final class UEReader {
    private string $buf;
    private int $i = 0;

    public function __construct(string $bytes) { $this->buf = $bytes; }

    /* ---- safe IO ---- */
    public function tell(): int { return $this->i; }
    public function seek(int $off): void {
        $len = strlen($this->buf);
        if ($off < 0 || $off > $len) throw new RuntimeException("seek out of range: $off (len=$len)");
        $this->i = $off;
    }
    private function readExact(int $n): string {
        $remain = strlen($this->buf) - $this->i;
        if ($remain < $n) throw new RuntimeException("EOF: need $n bytes at {$this->i}, have $remain");
        $s = substr($this->buf, $this->i, $n);
        $this->i += $n;
        return $s;
    }
    public function read(int $n): string { return $this->readExact($n); }
    public function U8(): int { return ord($this->readExact(1)); }
    public function U16(): int { $u = unpack('v', $this->readExact(2)); return $u[1]; }
    public function U32(): int { $u = unpack('V', $this->readExact(4)); return $u[1]; }
    public function I32(): int { $u = $this->U32(); return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u; }
    public function GUID(): string {
        $g = bin2hex($this->readExact(16));
        return strtoupper(implode('-', [substr($g,0,8),substr($g,8,4),substr($g,12,4),substr($g,16,4),substr($g,20)]));
    }

    /* ---- INDEX (FCompactIndex) ---- */
    public function INDEX(): int {
        $b0 = $this->U8();
        $neg = (($b0 & 0x80) !== 0);
        $con = (($b0 & 0x40) !== 0);
        $val = 0;
        if ($con) {
            $b1 = $this->U8(); $val = ($val<<7) + ($b1 & 0x7F);
            if ($b1 & 0x80) {
                $b2 = $this->U8(); $val = ($val<<7) + ($b2 & 0x7F);
                if ($b2 & 0x80) {
                    $b3 = $this->U8(); $val = ($val<<7) + ($b3 & 0x7F);
                    if ($b3 & 0x80) { $b4 = $this->U8(); $val = $b4; }
                }
            }
        }
        $val = ($val<<6) + ($b0 & 0x3F);
        return $neg ? -$val : $val;
    }

    /* ---- Names (v<64 ASCIIZ, v>=64 INDEX len+1) ---- */
    private function readCString(): string {
        $start = $this->i;
        $len = strlen($this->buf);
        while ($this->i < $len) {
            if ($this->buf[$this->i] === "\x00") {
                $s = substr($this->buf, $start, $this->i - $start);
                $this->i++;
                return $s;
            }
            $this->i++;
        }
        throw new RuntimeException("EOF scanning C-string at $start");
    }

    public function parseNames(int $version, int $count, int $offset): array {
        $this->seek($offset);
        $names = [];
        if ($version < 64) {
            for ($i=0; $i<$count; $i++) {
                $s = $this->readCString();
                $flags = $this->U32();
                $names[] = ['text'=>$s,'flags'=>$flags];
            }
        } else {
            for ($i=0; $i<$count; $i++) {
                $lenPlusOne = $this->INDEX();
                if ($lenPlusOne <= 0) throw new RuntimeException("Bad name length at name[$i]");
                $s = $this->readExact($lenPlusOne);
                $s = rtrim($s, "\x00");
                $flags = $this->U32();
                $names[] = ['text'=>$s,'flags'=>$flags];
            }
        }
        return $names;
    }

    /* ---- Exports / Imports ---- */
    public function parseExports(int $count, int $offset): array {
        $this->seek($offset);
        $out = [];
        for ($i=0; $i<$count; $i++) {
            $ClassIndex    = $this->INDEX();
            $SuperIndex    = $this->INDEX();
            $PackageDWORD  = $this->U32();
            $ObjectNameIdx = $this->INDEX();
            $ObjectFlags   = $this->U32();
            $SerialSize    = $this->INDEX();
            $SerialOffset  = ($SerialSize > 0) ? $this->INDEX() : 0;
            $out[] = compact('ClassIndex','SuperIndex','PackageDWORD','ObjectNameIdx','ObjectFlags','SerialSize','SerialOffset');
        }
        return $out;
    }
    // Import: INDEX, INDEX, DWORD(signed object ref), INDEX
    public function parseImports(int $count, int $offset): array {
        $this->seek($offset);
        $out = [];
        for ($i=0; $i<$count; $i++) {
            $ClassPackageIdx = $this->INDEX();
            $ClassNameIdx    = $this->INDEX();
            $PackageIndex    = $this->I32();
            $ObjectNameIdx   = $this->INDEX();
            $out[] = [
                'ClassPackage'    => [$ClassPackageIdx,0],
                'ClassName'       => [$ClassNameIdx,0],
                'PackageIndex'    => $PackageIndex,
                'ObjectName'      => [$ObjectNameIdx,0],
                'ClassPackageIdx' => $ClassPackageIdx,
                'ClassNameIdx'    => $ClassNameIdx,
                'ObjectNameIdx'   => $ObjectNameIdx,
            ];
        }
        return $out;
    }

    public static function nameText(array $names, int $idx): string {
        return $names[$idx]['text'] ?? "Name[$idx]";
    }
    public static function fnameText(array $names, array $fname): string {
        [$ni,$num] = $fname;
        $t = self::nameText($names,$ni);
        return $num>0 ? "{$t}_{$num}" : $t;
    }
    public static function classNameFromRef(int $ref, array $names, array $imports, array $exports): string {
        if ($ref === 0) return 'None';
        if ($ref < 0) { $i = -$ref - 1; return isset($imports[$i]) ? self::fnameText($names,$imports[$i]['ObjectName']) : "Import[$i]?"; }
        $e = $ref - 1; return isset($exports[$e]) ? self::nameText($names,$exports[$e]['ObjectNameIdx']) : "Export[$e]?";
    }
    public static function pkgNameFromRef(int $ref, array $names, array $imports, array $exports): string {
        if ($ref === 0) return '';
        if ($ref < 0) { $i = -$ref - 1; return isset($imports[$i]) ? self::fnameText($names,$imports[$i]['ObjectName']) : "Import[$i]?"; }
        $e = $ref - 1; return isset($exports[$e]) ? self::nameText($names,$exports[$e]['ObjectNameIdx']) : "Export[$e]?";
    }

    /* ---- UE2 Properties (Version >= 64) ---- */
    private static function propTypeName(int $t): string {
        static $map = [
            0x01=>'ByteProperty', 0x02=>'IntegerProperty', 0x03=>'BooleanProperty', 0x04=>'FloatProperty',
            0x05=>'ObjectProperty', 0x06=>'NameProperty', 0x07=>'StringProperty', 0x08=>'ClassProperty',
            0x09=>'ArrayProperty', 0x0A=>'StructProperty', 0x0B=>'VectorProperty', 0x0C=>'RotatorProperty',
            0x0D=>'StrProperty', 0x0E=>'MapProperty', 0x0F=>'FixedArrayProperty',
        ];
        return $map[$t] ?? sprintf('Type(0x%02X)',$t);
    }
    private function readArrayIndex(): int {
        $b = $this->U8();
        if ($b < 0x80) return $b;
        if ($b < 0xC0) return (($b & 0x3F) << 8) | $this->U8();
        return (($b & 0x3F) << 24) | ($this->U8() << 16) | ($this->U8() << 8) | $this->U8();
    }
    private function readOneProperty(array $names, int $noneIdx): array {
        $propStart = $this->tell();

        $pre = $this->tell();
        $nameIdx = $this->INDEX();
        if ($nameIdx === $noneIdx) {
            $recLen = $this->tell() - $pre;
            return [ $recLen, [
                'isTerminal'=>true,'offsetInObj'=>$propStart,'nameIdx'=>$nameIdx,'name'=>'None',
                'info'=>0,'typeCode'=>0x00,'typeName'=>'Type(0x00)',
                'sizeCode'=>0,'sizeBytes'=>0,'recordLen'=>$recLen,
                'arrayFlag'=>0,'arrayIndex'=>null,'structNameIdx'=>null,
                'decoded'=>null,'display'=>'',
            ]];
        }

        $info = $this->U8();
        $type = $info & 0x0F;
        $szc  = ($info >> 4) & 0x07;
        $arrF = (bool)($info & 0x80);

        $structNameIdx = null;
        if ($type === 0x0A) $structNameIdx = $this->INDEX();

        $arrayIndex = null;
        if ($arrF && $type !== 0x03) $arrayIndex = $this->readArrayIndex();

        $valueBytes = 0;
        switch ($szc) {
            case 0: $valueBytes=1; break;
            case 1: $valueBytes=2; break;
            case 2: $valueBytes=4; break;
            case 3: $valueBytes=12; break;
            case 4: $valueBytes=16; break;
            case 5: $valueBytes=$this->U8(); break;
            case 6: $valueBytes=$this->U16(); break;
            case 7: $valueBytes=$this->U32(); break;
        }

        $valueStart = $this->tell();
        $decoded = null; $display = '';

        if ($type === 0x03) { // boolean: bit7 = value
            $decoded = ['bool' => ($arrF ? 1 : 0)];
            $display = $decoded['bool'] ? 'true' : 'false';
            $arrF = false;
        } else {
            switch ($type) {
                case 0x01: $b=$this->U8(); $decoded=['byte'=>$b]; $display=(string)$b; $valueBytes=1; break;
                case 0x02: $raw=$this->I32(); $decoded=['int'=>$raw,'hex'=>sprintf('0x%08X',$raw & 0xFFFFFFFF)]; $display=$raw.' ('.$decoded['hex'].')'; $valueBytes=4; break;
                case 0x04: $u=$this->U32(); $f=unpack('f', pack('V',$u))[1]; $decoded=['float'=>$f]; $display=(string)$f; $valueBytes=4; break;
                case 0x05: $ref=$this->INDEX(); $decoded=['objectRef'=>$ref]; $display=(string)$ref; $valueBytes=$this->tell()-$valueStart; break;
                case 0x06: $ni=$this->INDEX(); $decoded=['nameIndex'=>$ni]; $display=self::nameText($names,$ni); $valueBytes=$this->tell()-$valueStart; break;
                case 0x0D: $len=$this->INDEX(); $buf=''; for($k=0;$k<$len;$k++) $buf.=chr($this->U8()); $decoded=['len'=>$len,'str'=>rtrim($buf,"\x00")]; $display=$decoded['str']; break;
                case 0x0A:
                    $structName = self::nameText($names,$structNameIdx ?? 0);
                    if (strcasecmp($structName,'Color')===0 && $valueBytes>=4) {
                        $r=$this->U8(); $g=$this->U8(); $b=$this->U8(); $a=$this->U8();
                        for($k=4;$k<$valueBytes;$k++) $this->U8();
                        $decoded=['struct'=>'Color','r'=>$r,'g'=>$g,'b'=>$b,'a'=>$a];
                        $display="Color (R={$r},G={$g},B={$b},A={$a})";
                    } else {
                        for($k=0;$k<$valueBytes;$k++) $this->U8();
                        $decoded=['struct'=>$structName,'bytes'=>$valueBytes];
                        $display=$structName.' ['.$valueBytes.' bytes]';
                    }
                    break;
                default:
                    for($k=0;$k<$valueBytes;$k++) $this->U8();
                    $decoded=['bytes'=>$valueBytes]; $display='';
            }
        }

        $recordLen = $this->tell() - $propStart;
        return [ $recordLen, [
            'isTerminal'=>false,
            'offsetInObj'=>$propStart,
            'nameIdx'=>$nameIdx, 'name'=>self::nameText($names,$nameIdx),
            'info'=>$info, 'typeCode'=>$type, 'typeName'=>self::propTypeName($type),
            'sizeCode'=>$szc, 'sizeBytes'=>$valueBytes, 'recordLen'=>$recordLen,
            'arrayFlag'=> ($type===0x03 ? 0 : ($arrF?1:0)),
            'arrayIndex'=>$arrayIndex, 'structNameIdx'=>$structNameIdx,
            'decoded'=>$decoded, 'display'=>$display,
        ]];
    }

    public function parseExportProperties(int $serialOffset, int $serialSize, array $names): array {
        $save = $this->tell();
        $this->seek($serialOffset);
        $props = [];
        $noneIdx = 0;
        $start = $this->tell();
        while (($this->tell() - $start) < $serialSize) {
            [$recLen, $p] = $this->readOneProperty($names, $noneIdx);
            $p['relOffset'] = $p['offsetInObj'] - $serialOffset;
            $props[] = $p;
            if ($p['isTerminal']) break;
        }
        $this->seek($save);
        return $props;
    }

    public static function inferArrayIndexZero(array &$props): void {
        for ($i=1; $i<count($props); $i++) {
            $cur = $props[$i];
            if (!empty($cur['isTerminal'])) break;
            if ($cur['arrayIndex'] !== null && $cur['arrayIndex'] >= 1) {
                $j = $i - 1;
                if ($j >= 0) {
                    $prev =& $props[$j];
                    if (empty($prev['isTerminal'])
                        && $prev['nameIdx']  === $cur['nameIdx']
                        && $prev['typeCode'] === $cur['typeCode']
                        && empty($prev['arrayFlag'])) {
                        $prev['arrayFlag']  = 1;
                        $prev['arrayIndex'] = 0;
                        $prev['idxInferred']= true;
                    }
                }
            }
        }
    }
}

/* ===========================
   RUN
   =========================== */
$path = __DIR__ . '/test.utx';
if (isset($_GET['file'])) {
    $candidate = __DIR__ . '/' . basename($_GET['file']);
    if (is_file($candidate)) $path = $candidate;
}
$bytes = file_get_contents($path);
$R = new UEReader($bytes);

echo "Unreal File found. (".filesize($path).") KB\n\n";

/* ----- YOUR header + generations logic (unchanged) ----- */
$tag          = $R->U32();                 // 0x9E2A83C1 for UE2
$verLic       = $R->U32();                 // low word=Version, high word=Licensee
$version      = $verLic & 0xFFFF;
$licensee     = ($verLic >> 16) & 0xFFFF;
$pkgFlags     = $R->U32();
$nameCount    = $R->U32();
$nameOffset   = $R->U32();
$exportCount  = $R->U32();
$exportOffset = $R->U32();
$importCount  = $R->U32();
$importOffset = $R->U32();
$guid = '';
$generations = [];

if ($version < 68) {
    $heritageCount  = $R->U32();
    $heritageOffset = $R->U32();
    $save = $R->tell();
    if ($heritageCount > 0) {
        $R->seek($heritageOffset);
        for ($i=0;$i<$heritageCount;$i++) $guid = $R->GUID(); // keep last
    }
    $R->seek($save);
} else {
    $guid     = $R->GUID();
    $genCount = $R->U32();
    for ($i=0; $i<$genCount; $i++) {
        $e = $R->U32();
        $n = $R->U32();
        $generations[] = ['e'=>$e,'n'=>$n];
    }
}

/* ----- File Header table ----- */
echo "<h3>*******File Header</h3>";
echo "<table><tr>".th('Var').th('Value').th('Additional')."</tr>";
echo " <tr>".td('Version').td((string)$version).td('')."</tr>";
echo " <tr>".td('License mode').td((string)$licensee).td('')."</tr>";
echo " <tr>".td('Package flags').td(sprintf("0x%08X",$pkgFlags),'hex').td('('.$pkgFlags.') '.flags_pkg_to_text($pkgFlags))."</tr>";
echo " <tr>".td('Name count').td((string)$nameCount).td('')."</tr>";
echo " <tr>".td('Name offset').td((string)$nameOffset).td('')."</tr>";
echo " <tr>".td('Export count').td((string)$exportCount).td('')."</tr>";
echo " <tr>".td('Export offset').td((string)$exportOffset).td('')."</tr>";
echo " <tr>".td('Import count').td((string)$importCount).td('')."</tr>";
echo " <tr>".td('Import offset').td((string)$importOffset).td('')."</tr>";
echo "</table>";

/* ----- Generations / Heritage block ----- */
echo "<h3>*******Generations</h3>";
if ($generations) {
    echo "<table><tr>".th('Num.').th('Val.').th('Value')."</tr>";
    echo "<tr>".td('').td('GUID').td($guid)."</tr>";
    echo "<tr>".td('').td('Generation count').td((string)count($generations))."</tr>";
    foreach ($generations as $i=>$g) {
        echo "<tr>".td((string)$i).td('Import offset').td((string)$g['e'])."</tr>";
        echo "<tr>".td((string)$i).td('mport count').td((string)$g['n'])."</tr>";
    }
    echo "</table>";
} else {
    echo "<table><tr>".th('Num').th('Val.').th('Value')."</tr>";
    echo "<tr>".td('GUID').td('HeritageCount').td($guid)."</tr>";
    echo "<tr>".td('').td('HeritageOffset').td(isset($heritageOffset)?(string)$heritageOffset:'')."</tr>";
    echo "</table>";
}

/* ----- Name table (as before) ----- */
$names = $R->parseNames($version, $nameCount, $nameOffset);
echo "<h3>*******Name Table ({$nameCount}:{$nameOffset})</h3>";
echo "<table><tr><th>Num.</th><th>Name</th><th>Len</th><th>Flags (hex)</th><th>Flags (decoded)</th></tr>";
for ($i=0; $i<count($names); $i++) {
    $t = $names[$i]['text']; $f = $names[$i]['flags']; $len = strlen($t);
    echo "<tr><td>{$i}</td><td>".htmlspecialchars($t)."</td><td>{$len}</td><td>0x".sprintf("%08X",$f)."</td><td>".flags_object_to_text($f)."</td></tr>";
}
echo "</table>";

/* ----- Exports / Imports ----- */
$exports = $R->parseExports($exportCount, $exportOffset);
$imports = $R->parseImports($importCount, $importOffset);

/* ----- Export table ----- */
echo "<h3>*******Export Table ({$exportCount}:{$exportOffset})</h3>";
echo "<table><tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags (hex)</th><th>Flags</th></tr>";
foreach ($exports as $i=>$e) {
    $group = UEReader::pkgNameFromRef($e['PackageDWORD'], $names, $imports, $exports);
    $name  = UEReader::nameText($names, $e['ObjectNameIdx']);
    $class = UEReader::classNameFromRef($e['ClassIndex'], $names, $imports, $exports);
    $num   = sprintf("%d (0x%02X)", $i, $i);
    $sup   = $e['SuperIndex'];
    $size  = $e['SerialSize'];
    $off   = "0x".sprintf("%08X", $e['SerialOffset']);
    $flagsHex = "0x".sprintf("%08X", $e['ObjectFlags']);
    $flagsTxt = flags_object_to_text($e['ObjectFlags']);
    echo "<tr><td>{$group}</td><td>{$name}</td><td>{$class}</td><td>{$num}</td><td>{$sup}</td><td>{$size}</td><td>{$off}</td><td>{$flagsHex}</td><td>{$flagsTxt}</td></tr>";
}
echo "</table>";

/* ----- Import table (viewer-style) ----- */
echo "<h3>*******Import Table ({$importCount}:{$importOffset})</h3>";
echo "<table><tr><th>#</th><th>Package &amp; Group</th><th>Name</th><th>Class</th><th>Class Package</th><th>Num.</th></tr>";
for ($i=0; $i<count($imports); $i++) {
    $imp = $imports[$i];
    $pkg = UEReader::pkgNameFromRef($imp['PackageIndex'], $names, $imports, $exports);
    $nm  = UEReader::fnameText($names, $imp['ObjectName']);
    $cl  = UEReader::fnameText($names, $imp['ClassName']);
    $clp = UEReader::fnameText($names, $imp['ClassPackage']);
    $num = sprintf("%d (0x%02X)", $i, $i);
    echo "<tr><td>{$i}</td><td>".($pkg===''?'None':$pkg)."</td><td>{$nm}</td><td>{$cl}</td><td>{$clp}</td><td>{$num}</td></tr>";
}
echo "</table>";

/* ----- Properties (UE2 only) ----- */
echo "<h3>*******Properties by Export</h3>";
for ($row=0; $row<count($exports); $row++) {
    $e = $exports[$row];
    $serialSize   = (int)$e['SerialSize'];
    $serialOffset = (int)$e['SerialOffset'];
    if ($serialSize <= 0) continue;

    $ename = UEReader::nameText($names, $e['ObjectNameIdx']);
    echo "<h4>[{$row}] ".htmlentities($ename)." — offset 0x".sprintf("%08X",$serialOffset).", size {$serialSize}</h4>";

    if ($version < 64) {
        // v61 etc – show safe preview, no guessing
        $R->seek($serialOffset);
        $to = min($serialSize, 64);
        $hex = [];
        for ($k=0; $k<$to; $k++) $hex[] = sprintf("%02X", $R->U8());
        echo "<p class='small'>v{$version} property stream not decoded yet. First {$to} bytes: ".implode(' ',$hex).($serialSize>$to?' ...':'')."</p>";
        continue;
    }

    $props = $R->parseExportProperties($serialOffset, $serialSize, $names);
    UEReader::inferArrayIndexZero($props);

    echo "<table><tr><th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Struct</th><th>Array?</th><th>Idx</th><th>Value</th></tr>";
    foreach ($props as $p) {
        $off = "0x".sprintf("%08X",$p['relOffset']);
        $len = (int)$p['recordLen'];
        $nm  = htmlspecialchars($p['name']);
        $tp  = htmlspecialchars($p['typeName']);
        $st  = isset($p['structNameIdx']) && $p['structNameIdx'] !== null ? htmlspecialchars(UEReader::nameText($names,$p['structNameIdx'])) : '';
        $arr = $p['arrayFlag'] ? 'Yes' : 'No';
        $idx = '';
        if ($p['arrayIndex'] !== null) {
            if (!empty($p['idxInferred']) && $p['arrayIndex'] === 0) $idx = '<span class="idx0">0</span>';
            else $idx = (string)$p['arrayIndex'];
        }
        $val = htmlspecialchars($p['display']);
        echo "<tr><td>{$off}</td><td>{$len}</td><td>{$nm}</td><td>{$tp}</td><td>{$st}</td><td>{$arr}</td><td>{$idx}</td><td>{$val}</td></tr>";
    }
    echo "</table>";

    // Tail bytes after None
    $consumed = 0;
    if (!empty($props)) { $last = end($props); $consumed = $last['relOffset'] + $last['recordLen']; }
    $tail = max(0, $serialSize - $consumed);
    if ($tail > 0) {
        $classTxt = UEReader::classNameFromRef($e['ClassIndex'], $names, $imports, $exports);
        $tailOff  = $serialOffset + $consumed;
        $R->seek($tailOff);
        $peek = []; $n = min($tail, 16);
        for ($k=0; $k<$n; $k++) $peek[] = sprintf("%02X", $R->U8());
        echo "<p><i>Post-property data:</i> {$tail} byte(s) at 0x".sprintf("%08X",$tailOff)." — hex: ".implode(' ',$peek).($tail>16?' ...':'')."</p>";
        if (strcasecmp($classTxt,'Texture')===0 && $tail>=1) {
            $mipCount = hexdec($peek[0]);
            echo "<p><b>Texture hint:</b> MipMapCount = {$mipCount}</p>";
        }
    }
}
?>
