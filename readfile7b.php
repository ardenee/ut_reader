<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$path = "test.utx";
$path = "oldtest2.utx";

// ---------- tiny HTML helpers ----------
function h($s){ 
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}

function td($s, $cls=''){ 
	$c = $cls ? " class=\"$cls\"" : ""; 
	
	return "<td$c>".h($s)."</td>"; 
}

function th($s, $cls=''){ 
	$c = $cls ? " class=\"$cls\"" : ""; 
	
	return "<th$c>".h($s)."</th>"; 
}

// ---------- reader ----------
final class UEReader {
    private string $buf;
    private int $pos = 0;
    private int $len = 0;

    public function __construct(string $bytes){ 
		$this->buf = $bytes; 
		$this->len = strlen($bytes); 
	}

    public function tell(): int { 
		return $this->pos; 
	}
	
    public function seek(int $off): void {
        if ($off < 0 || $off > $this->len) 
			throw new RuntimeException("Seek OOB to $off of {$this->len}");
		
        $this->pos = $off;
    }
	
    private function need(int $n): void {
        if ($this->pos + $n > $this->len) 
			throw new RuntimeException(sprintf("ERROR: Unexpected EOF at %d reading %d bytes", $this->pos, $n));
    }
	
    public function bytes(int $n): string { 
		$this->need($n); 
		$s = substr($this->buf, $this->pos, $n); 
		$this->pos += $n; return $s; 
	}
	
    public function U8(): int { 
		return ord($this->bytes(1)); 
	}
	
    public function U32(): int {
        $b = $this->bytes(4);
        $v = (ord($b[0])      ) | (ord($b[1])<<8) | (ord($b[2])<<16) | (ord($b[3])<<24);
        return $v & 0xFFFFFFFF;
    }
    public function I32(): int { 
		$u = $this->U32(); 
		
		return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u; 
	}

    /**
     * Unreal INDEX (varint, UE2):
     *  - first byte: 6 data bits, bit6=sign, bit7=continue
     *  - subsequent bytes: 7 data bits, bit7=continue
     */
	 public function INDEX(): int {
		// FCompactIndex decode (exact UE2/C++ semantics)
		$b0  = $this->U8();
		$neg = (($b0 & 0x80) !== 0);
		$con = (($b0 & 0x40) !== 0);
		$val = 0;
		
		if ($con) {
			$b1 = $this->U8(); $val = ($val << 7) + ($b1 & 0x7F);
			
			if ($b1 & 0x80) {
				$b2 = $this->U8(); $val = ($val << 7) + ($b2 & 0x7F);
				
				if ($b2 & 0x80) {
					$b3 = $this->U8(); $val = ($val << 7) + ($b3 & 0x7F);
					
					if ($b3 & 0x80) {
						$b4 = $this->U8(); $val = $b4; // final 8 bits
					}
				}
			}
		}
		
		$val = ($val << 6) + ($b0 & 0x3F);
		
		return $neg ? -$val : $val;
	}

	
    /**
     * GUID: Data1,Data2,Data3 little-endian; remaining 8 bytes raw
     */
    public function GUID(): string {
        $s = $this->bytes(16);
        $b = array_map('ord', str_split($s));
		
        return sprintf(
            '%02X%02X%02X%02X-%02X%02X-%02X%02X-%02X%02X-%02X%02X%02X%02X%02X%02X',
            $b[3],$b[2],$b[1],$b[0],
            $b[5],$b[4],
            $b[7],$b[6],
            $b[8],$b[9],
            $b[10],$b[11],$b[12],$b[13],$b[14],$b[15]
        );
    }
	
	// Reads the compact array index that follows when the info byte's array bit is set.
	// (byte | word with MSB=1 | dword with top two bits = 11)
	private function readArrayIndex(): int {
		$b = $this->U8();
		if ($b < 0x80) return $b;                           // 0..127
		if ($b < 0xC0) return (($b & 0x3F) << 8) | $this->U8();            // 0x80..0xBF : 14-bit
		return (($b & 0x3F) << 24) | ($this->U8() << 16) | ($this->U8() << 8) | $this->U8(); // 0xC0.. : 30-bit
	}
	
	public static function inferArrayIndexZero(array &$props): void {
		// If we see an element with arrayIndex>=1 and the immediately previous
		// property has the same name+type but no array flag, mark that previous as idx 0.
		for ($i = 1; $i < count($props); $i++) {
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
						$prev['arrayIndex'] = 0;           // inferred
						$prev['idxInferred']= true;        // mark so UI can color it
					}
				}
			}
		}
	}	
}

function flags_obj($v) : string {
    $s='';
    if ( $v & 0x00000001 ) $s.="RF_Transactional,";
    if ( $v & 0x00000002 ) $s.="RF_Unreachable,";
    if ( $v & 0x00000004 ) $s.="RF_Public,";
    if ( $v & 0x00000008 ) $s.="RF_TagImp,";
    if ( $v & 0x00000010 ) $s.="RF_TagExp,";
    if ( $v & 0x00000020 ) $s.="RF_SourceModified,";
    if ( $v & 0x00000040 ) $s.="RF_TagGarbage,";
    if ( $v & 0x00000200 ) $s.="RF_NeedLoad,";
    if ( $v & 0x00000400 ) $s.="RF_HighlightedName,";
    if ( $v & 0x00000800 ) $s.="RF_InSingularFunc,";
    if ( $v & 0x00001000 ) $s.="RF_Suppress,";
    if ( $v & 0x00002000 ) $s.="RF_InEndState,";
    if ( $v & 0x00004000 ) $s.="RF_Transient,";
    if ( $v & 0x00008000 ) $s.="RF_PreLoading,";
    if ( $v & 0x00010000 ) $s.="RF_LoadForClient,";
    if ( $v & 0x00020000 ) $s.="RF_LoadForServer,";
    if ( $v & 0x00040000 ) $s.="RF_LoadForEdit,";
    if ( $v & 0x00080000 ) $s.="RF_Standalone,";
    if ( $v & 0x00100000 ) $s.="RF_NotForClient,";
    if ( $v & 0x00200000 ) $s.="RF_NotForServer,";
    if ( $v & 0x00400000 ) $s.="RF_NotForEdit,";
    if ( $v & 0x00800000 ) $s.="RF_Destroyed,";
    if ( $v & 0x01000000 ) $s.="RF_NeedPostLoad,";
    if ( $v & 0x02000000 ) $s.="RF_HasStack,";
    if ( $v & 0x04000000 ) $s.="RF_Native,";
    if ( $v & 0x08000000 ) $s.="RF_Marked,";
    if ( $v & 0x10000000 ) $s.="RF_ErrorShutdown,";
    if ( $v & 0x20000000 ) $s.="RF_DebugPostLoad,";
    if ( $v & 0x40000000 ) $s.="RF_DebugSerialize,";
    if ( $v & 0x80000000 ) $s.="RF_DebugDestroy,";
	
    return rtrim($s,',');
}

// ---------- flags ----------
function flags_object_to_text(int $v): string {
    $out = [];
    ($v & 0x00000001) && $out[]="RF_Transactional";
    ($v & 0x00000002) && $out[]="RF_Unreachable";
    ($v & 0x00000004) && $out[]="RF_Public";
    ($v & 0x00000008) && $out[]="RF_TagImp";
    ($v & 0x00000010) && $out[]="RF_TagExp";
    ($v & 0x00000020) && $out[]="RF_SourceModified";
    ($v & 0x00000040) && $out[]="RF_TagGarbage";
    ($v & 0x00000200) && $out[]="RF_NeedLoad";
    ($v & 0x00000400) && $out[]="RF_HighlightedName";
    ($v & 0x00000800) && $out[]="RF_InSingularFunc";
    ($v & 0x00001000) && $out[]="RF_Suppress";
    ($v & 0x00002000) && $out[]="RF_InEndState";
    ($v & 0x00004000) && $out[]="RF_Transient";
    ($v & 0x00008000) && $out[]="RF_PreLoading";
    ($v & 0x00010000) && $out[]="RF_LoadForClient";
    ($v & 0x00020000) && $out[]="RF_LoadForServer";
    ($v & 0x00040000) && $out[]="RF_LoadForEdit";
    ($v & 0x00080000) && $out[]="RF_Standalone";
    ($v & 0x00100000) && $out[]="RF_NotForClient";
    ($v & 0x00200000) && $out[]="RF_NotForServer";
    ($v & 0x00400000) && $out[]="RF_NotForEdit";
    ($v & 0x00800000) && $out[]="RF_Destroyed";
    ($v & 0x01000000) && $out[]="RF_NeedPostLoad";
    ($v & 0x02000000) && $out[]="RF_HasStack";
    ($v & 0x04000000) && $out[]="RF_Native";
    ($v & 0x08000000) && $out[]="RF_Marked";
    ($v & 0x10000000) && $out[]="RF_ErrorShutdown";
    ($v & 0x20000000) && $out[]="RF_DebugPostLoad";
    ($v & 0x40000000) && $out[]="RF_DebugSerialize";
    ($v & 0x80000000) && $out[]="RF_DebugDestroy";
	
    return implode(', ', $out);
}

function flags_pkg_to_text(int $f): string {
    $map = [
        0x00000001=>'PKG_AllowDownload',
        0x00000002=>'PKG_ClientOptional',
        0x00000004=>'PKG_ServerSideOnly',
        0x00000008=>'PKG_Cooked',
        0x00000010=>'PKG_Unsecure',
        0x00000020=>'PKG_Encrypted',
        0x00000040=>'PKG_CompiledIn',
    ];
	
    $out=[]; 
	
	foreach ($map as $bit=>$name) 
	
	if ($f & $bit) 
		$out[]=$name;
	
    return $out ? implode(', ', $out) : '';
}

// ---------- name helpers ----------
function readNAME(UEReader $R, int $version): string {
    if ($version >= 64) {
        $lenPlusOne = $R->U8(); // includes trailing NUL
        $len        = max(0, $lenPlusOne - 1);
		
        if ($len === 0) { 
			if ($lenPlusOne>0) 
				$R->U8(); 
			
			return ""; 
		}
		
        $s = $R->bytes($len);
        $R->U8(); // trailing 0x00
		
        return $s;
    } else {
        $s = '';
        while (true) { $c = $R->U8(); 
		
		if ($c === 0) 
			break; 
		
		$s .= chr($c); }
		
        return $s;
    }
}
// FName = INDEX (name idx) + INDEX (instance number)
function readFName(UEReader $R): array {
    $idx = $R->INDEX();
    $num = $R->INDEX();
	
    return [$idx, $num];
}
function fnameText(array $names, array $fname): string {
    [$idx,$num] = $fname;
	
    if (!array_key_exists($idx, $names)) 
		return "(bad:$idx)";
	
    return $num ? ($names[$idx]['text'].'_'.$num) : $names[$idx]['text'];
}
// Outer/group resolver for pretty output
function resolveGroup(array $imports, array $exports, array $names, int $ref): string {
    $guard=0; $last='None';
    while ($ref !== 0 && $guard++ < 64) {
        if ($ref > 0) {
            $e = $exports[$ref-1] ?? null; 
			
			if (!$e) 
				break;
			
            $last = fnameText($names, $e['ObjectName']);
            $ref  = $e['PackageIndex'];
        } else {
            $i = $imports[-$ref-1] ?? null; 
			
			if (!$i) 
				break;
			
            $last = fnameText($names, $i['ObjectName']);
            $ref  = $i['PackageIndex'];
        }
    }
	
    return $last;
}
function resolveClassName(array $imports, array $exports, array $names, int $classIndex): string {
    if ($classIndex === 0) 
		return 'None';
	
    if ($classIndex < 0) {
        $imp = $imports[-$classIndex-1] ?? null;
		
        if (!$imp) 
			return "(bad:$classIndex)";
		
        $clsName = fnameText($names, $imp['ClassName']);   // usually "Class"
        $objName = fnameText($names, $imp['ObjectName']);  // e.g. "Texture"
		
        return ($clsName === 'Class') ? $objName : $clsName;
    }
	
    $exp = $exports[$classIndex-1] ?? null;
	
    return $exp ? fnameText($names, $exp['ObjectName']) : "(bad:$classIndex)";
}

/* Group from DWORD object ref (exports’ Package field) */
function groupFromDWORD(int $ref, array $names, array $imports, array $exports) : string {
    if ($ref === 0) return '';
    if ($ref < 0) {
        $i = -$ref - 1;
		
        if (!isset($imports[$i])) 
			return "Import[$i]?";
		
        return fnameText($names, $imports[$i]['ObjectName']); // group/package name
    }
	
    $e = $ref - 1;
	
    if (!isset($exports[$e])) 
		return "Export[$e]?";
	
    return nameText($names, $exports[$e]['ObjectNameIdx']);
}

/* ---- resolvers ---- */
function nameText(array $names, int $idx) : string {
    return ($idx>=0 && $idx<count($names)) ? $names[$idx]['text'] : "(bad:$idx)";
}

/* Class resolver: import => use Import.ObjectName (e.g., Texture); export => that export’s ObjectName */
function classNameFromRef(int $ref, array $names, array $imports, array $exports) : string {
    if ($ref === 0) return 'None';
    if ($ref < 0) {
        $i = -$ref - 1;
		
        if (!isset($imports[$i])) 
			return "Import[$i]?";
		
        return fnameText($names, $imports[$i]['ObjectName']); // **THIS** is the class name in imports
    }
    $e = $ref - 1;
	
    if (!isset($exports[$e])) 
		return "Export[$e]?";
	
    return nameText($names, $exports[$e]['ObjectNameIdx']);
}

function pkgNameFromRef(int $ref, array $names, array $imports, array $exports) : string {
    if ($ref === 0) return ''; // top-level (no package)
    if ($ref < 0) {
        $i = -$ref - 1;
		
        return isset($imports[$i]) ? fnameText($names, $imports[$i]['ObjectName']) : "Import[$i]?";
    }
    // rarely used for imports, but keep complete:
    $e = $ref - 1;
	
    return isset($exports[$e]) ? nameText($names, $exports[$e]['ObjectNameIdx']) : "Export[$e]?";
}

/* ---------- Property stream helpers (per PDF) ---------- */

function propTypeName(int $t): string {
    static $map = [
        0x01 => 'ByteProperty',
        0x02 => 'IntegerProperty',
        0x03 => 'BooleanProperty',
        0x04 => 'FloatProperty',
        0x05 => 'ObjectProperty',
        0x06 => 'NameProperty',
        0x07 => 'StringProperty',
        0x08 => 'ClassProperty',
        0x09 => 'ArrayProperty',
        0x0A => 'StructProperty',
        0x0B => 'VectorProperty',
        0x0C => 'RotatorProperty',
        0x0D => 'StrProperty',
        0x0E => 'MapProperty',
        0x0F => 'FixedArrayProperty',
    ];
    return $map[$t] ?? sprintf('Type(0x%02X)',$t);
}

// per PDF: array-index encoding for bit7=1 (non-boolean) properties
function readArrayIndex(UEReader $R): int {
    $b = $R->U8();
    if ($b < 0x80) {
        return $b; // 0..127
    } elseif ($b < 0xC0) {
        // high bit set to 0x80; 14-bit value: top 6 bits are (b & 0x3F)
        return (($b & 0x3F) << 8) | $R->U8();
    } else {
        // high bits set to 0xC0; 30-bit value: top 6 bits are (b & 0x3F)
        return (($b & 0x3F) << 24) | ($R->U8() << 16) | ($R->U8() << 8) | $R->U8();
    }
}

// resolve a Name table index as text (reuses your fname/name helpers)
function nameIdxText(array $names, int $idx): string {
    return nameText($names, $idx);
}

// read one property header+payload; returns [bytesRead, assoc array]
function readOneProperty(UEReader $R, array $names, int $noneNameIndex): array {
    $start = $R->tell();

    // 1) Property name (INDEX). "None" terminates the list.
	$pre = $R->tell();
	$nameIdx = $R->INDEX();
	
    if ($nameIdx === $noneNameIndex) {		
		$recLen = $R->tell() - $pre; // should be 1 for your file
		
        return [ $recLen, [
			'isTerminal' => true,
			'nameIdx'    => $nameIdx,
			'name'       => 'None',
			'info'       => 0,
			'typeCode'   => 0x00,
			'typeName'   => 'Type(0x00)',
			'sizeCode'   => 0,
			'sizeBytes'  => 0,
			'recordLen'  => $recLen,
			'arrayFlag'  => 0,
			'arrayIndex' => null,
			'decoded'    => null,
			'display'    => '',
		]];
    }

    // 2) Info byte: low 4 = type, 4..6 = size code, 7 = array flag
    $info  = $R->U8();
    $type  =  $info & 0x0F;
    $szc   = ($info >> 4) & 0x07;
    $arrF  =  ($info & 0x80) !== 0;

    // 3) Struct name follows for StructProperty (0x0A)
    $structNameIdx = null;
	
    if ($type === 0x0A) {
        $structNameIdx = $R->INDEX();
    }

    // 4) Array index if flag set and not Boolean (PDF rule)
    $arrayIndex = null;
	
    if ($arrF && $type !== 0x03) {
        $arrayIndex = readArrayIndex($R);
    }

    // 5) Value size from size code
    $valueBytes = 0;
    switch ($szc) {
        case 0: $valueBytes = 1;  break;
        case 1: $valueBytes = 2;  break;
        case 2: $valueBytes = 4;  break;
        case 3: $valueBytes = 12; break;
        case 4: $valueBytes = 16; break;
        case 5: $valueBytes = $R->U8();   break; // next BYTE
        case 6: $valueBytes = $R->U16();  break; // next WORD
        case 7: $valueBytes = $R->U32();  break; // next DWORD
    }

    // 6) Decode value (booleans use info bit7 as the value)
    $valueStart = $R->tell();
    $decoded    = null;
    $display    = '';

    if ($type === 0x03) {
        // BooleanProperty: value is bit7 in info byte
        $decoded = [ 'bool' => ($arrF ? 1 : 0) ];
        $display = $decoded['bool'] ? 'true' : 'false';
		$arrF = false;                 // very important: bit 7 carried the value, NOT array
    } else {
        switch ($type) {
            case 0x01: // ByteProperty
                $b          = $R->U8();
                $decoded    = [ 'byte' => $b ];
                $display    = (string)$b;
                $valueBytes = 1;
                break;

            case 0x02: // IntegerProperty
                // UE2 ints are 4 bytes; show signed + hex like the viewer
                $raw        = $R->I32();
                $decoded    = [ 'int' => $raw, 'hex' => sprintf('0x%08X', $raw & 0xFFFFFFFF) ];
                $display    = $raw . ' (' . $decoded['hex'] . ')';
                $valueBytes = 4;
                break;

            case 0x04: // FloatProperty
                $u          = $R->U32();
                $f          = unpack('f', pack('V', $u))[1];
                $decoded    = [ 'float' => $f ];
                $display    = (string)$f;
                $valueBytes = 4;
                break;

            case 0x05: // ObjectProperty (INDEX object ref)
                $ref        = $R->INDEX();
                $decoded    = [ 'objectRef' => $ref ];
                $display    = (string)$ref;
                $valueBytes = $R->tell() - $valueStart;
                break;

            case 0x06: // NameProperty (INDEX name index)
                $ni         = $R->INDEX();
                $decoded    = [ 'nameIndex' => $ni ];
                $display    = nameIdxText($names, $ni);
                $valueBytes = $R->tell() - $valueStart;
                break;

            case 0x0A: // StructProperty
                $structName = nameIdxText($names, $structNameIdx ?? 0);
                if (strcasecmp($structName, 'Color') === 0 && $valueBytes >= 4) {
                    $r = $R->U8(); $g = $R->U8(); $b = $R->U8(); $a = $R->U8();
                    // consume remaining padding, if any
                    for ($k = 4; $k < $valueBytes; $k++) $R->U8();
                    $decoded = [ 'struct' => 'Color', 'r'=>$r,'g'=>$g,'b'=>$b,'a'=>$a ];
                    $display = "Color (R={$r},G={$g},B={$b},A={$a})";
                } else {
                    // Unknown struct: skip bytes
                    for ($k = 0; $k < $valueBytes; $k++) $R->U8();
                    $decoded = [ 'struct' => $structName, 'bytes' => $valueBytes ];
                    $display = $structName . " [".$valueBytes." bytes]";
                }
                break;

            case 0x0D: // StrProperty: INDEX length + ASCIIZ
                $len = $R->INDEX();
                $buf = '';
				
                for ($k=0; $k<$len; $k++) 
					$buf .= chr($R->U8());
				
                $decoded    = [ 'len' => $len, 'str' => rtrim($buf, "\x00") ];
                $display    = $decoded['str'];
                $valueBytes = $len + ($R->tell() - $valueStart) - $len; // length only
                break;

            default: // Unhandled type: skip value bytes
                for ($k = 0; $k < $valueBytes; $k++) 
					$R->U8();				
				
                $decoded = [ 'bytes' => $valueBytes ];
                $display = '';
                break;
        }
    }

    $valueEnd  = $R->tell();
    $recordLen = $valueEnd - $start;

    return [ $recordLen, [
        'isTerminal'    => false,
        'offsetInObj'   => $start,
        'nameIdx'       => $nameIdx,
        'name'          => null, // filled by caller
        'info'          => $info,
        'typeCode'      => $type,
        'typeName'      => propTypeName($type),
        'sizeCode'      => $szc,
        'sizeBytes'     => $valueBytes,   // payload only
        'recordLen'     => $recordLen,    // header + payload (matches viewer offsets)
        'arrayFlag'     => $arrF ? 1 : 0,
        'arrayIndex'    => $arrayIndex,
        'structNameIdx' => $structNameIdx,
        'decoded'       => $decoded,
        'display'       => $display,
    ]];
}

function inferArrayIndexZero(array &$props): void {
    for ($i = 1; $i < count($props); $i++) {
        $cur = $props[$i];
		
        if (!empty($cur['isTerminal'])) 
			break;

        // If current has an explicit array index >=1 and same name+type as previous,
        // mark previous as array element with inferred idx 0.
        if ($cur['arrayIndex'] !== null && $cur['arrayIndex'] >= 1) {
            $prevIdx = $i - 1;
			
            if ($prevIdx >= 0) {
                $prev =& $props[$prevIdx];
				
                if (empty($prev['isTerminal']) && $prev['nameIdx'] === $cur['nameIdx'] && $prev['typeCode'] === $cur['typeCode'] && empty($prev['arrayFlag'])) {
                    $prev['arrayFlag']   = 1;          // treat as array element
                    $prev['arrayIndex']  = 0;          // inferred
                    $prev['idxInferred'] = true;       // mark as inferred (not in file)
                }
            }
        }
    }
}


// Parse all properties of an export, starting at file offset $serialOffset, within $serialSize bytes.
function parseExportProperties(UEReader $R, int $serialOffset, int $serialSize, array $names): array {
    // In most UT2004 packages, the property stream begins at the export’s SerialOffset and
    // ends when the "None" property name appears. We stop at either condition.
    $saved   = $R->tell();
    $R->seek($serialOffset);
    $props   = [];
	$noneIdx = 0;               // Name[0] = "None" in your dumps
	$start   = $R->tell();
	while (($R->tell() - $start) < $serialSize) {
		list($taken, $p) = readOneProperty($R, $names, $noneIdx);
		$p['name'] = nameIdxText($names, $p['nameIdx']); // resolve texts
		
		if ($p['structNameIdx'] !== null) {
			$p['structName'] = nameIdxText($names, $p['structNameIdx']);
		}
		
		$p['relOffset'] = ($p['offsetInObj'] - $serialOffset);
		$props[]        = $p;                  // <-- always add (including "None")
		
		if ($p['isTerminal']) 
			break;    // <-- then stop
	}
	
    $R->seek($saved);
	
    return $props;
}



// ---------- parse & render ----------
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<meta charset="utf-8">
<style>
body{font:13px/1.35 monospace;}
table{border-collapse:collapse;margin:10px 0;}
th,td{border:1px solid #999;padding:4px 6px;vertical-align:top;}
th{background:#eee;}
.small{font-size:12px;color:#444;}
.right{text-align:right;}
.hex{font-family:monospace;}



h3, h4 { font-family: Segoe UI, sans-serif; }
.small { color:#666; font-size:11px; }
.idx0 { color:#c00; font-weight:600; } /* inferred idx 0 (not stored in file) */
</style>
<pre>

<?php
try {
    if (!is_file($path)) throw new RuntimeException("File not found: {$path}");
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException("Failed to read: {$path}");
    $R = new UEReader($bytes);

    echo "Unreal File found. (".filesize($path).") KB\n\n";
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

    echo "<h3>*******File Header</h3>";
    echo "<table><tr>".th('Var').th('Value').th('Additional')."</tr>";
    echo " <tr>".td('Version').td($version).td('')."</tr>";
	echo " <tr>".td('License mode').td($licensee).td('')."</tr>";
	echo " <tr>".td('Package flags').td(sprintf("0x%08X",$pkgFlags),'hex').td('('.$pkgFlags.') '.flags_pkg_to_text($pkgFlags))."</tr>";
	echo " <tr>".td('Name count').td($nameCount).td('')."</tr>";
	echo " <tr>".td('Name offset').td($nameOffset).td('')."</tr>";
	echo " <tr>".td('Export count').td($exportCount).td('')."</tr>";
	echo " <tr>".td('Export offset').td($exportOffset).td('')."</tr>";
	echo " <tr>".td('Import count').td($importCount).td('')."</tr>";
	echo " <tr>".td('Import offset').td($importOffset).td('')."</tr>";
    echo "</table>";	

    if ($generations) {		
		echo "<h3>*******Generations</h3>";
		echo "<table><tr>".th('Num.').th('Val.').th('Value')."</tr>";
		echo "<tr>".td('').td('GUID').td($guid)."</tr>";
        echo "<tr>".td('').td('Generation count').td(count($generations))."</tr>";	
		
		foreach ($generations as $i=>$g) {
			echo "<tr>".td($i).td('Import offset').td($g['e'])."</tr>";
			echo "<tr>".td($i).td('mport count').td($g['n'])."</tr>";
		}	
		
		echo "</table>";
    }
	else{
		echo "<h3>*******Generations</h3>";
		echo "<table><tr>".th('Num').th('Val.').th('Value')."</tr>";
		echo "<tr>".td('GUID').td('HeritageCount').td($guid)."</tr>";
        echo "<tr>".td('').td('HeritageOffset').td($heritageOffset)."</tr>";		   
		echo "</table>";
	}
	
	
	
	
	
	

// ------------- Name Table -------------
    $R->seek($nameOffset);
    $names = [];
    echo "<h3>*******Name Table (".h($nameCount).":".h($nameOffset).")</h3>";
    echo "<table><tr>".th('Num.').th('Name').th('Len').th('Flags (hex)').th('Flags (decoded)')."</tr>";
	
    for ($i=0; $i<$nameCount; $i++) {
        $txt       = readNAME($R, $version);
        $flags     = $R->U32();
        $names[$i] = ['text'=>$txt, 'flags'=>$flags];
		$numTxt    = sprintf("%d (0x%02X)", $i, $i & 0xFF); 
		
		//$flagTxt  = flags_obj($e['flags']);
		
        echo "<tr>".td($numTxt).td($txt).td(strlen($txt)). td(sprintf("0x%08X",$flags),'hex'). td(flags_object_to_text($flags)) ."</tr>";
    }
	
    echo "</table>";		

//print_r($names);



	
//----- Read Exports table data
	$exports = [];
	$R->seek($exportOffset); 
	
	for ($i=0;$i<$exportCount;$i++) {
		$ClassIndex    = $R->INDEX();    
		$SuperIndex    = $R->INDEX();    
		$PackageDWORD  = $R->I32();    
		$ObjectNameIdx = $R->INDEX();   
		$ObjectFlags   = $R->U32();    
		$SerialSize    = $R->INDEX();    
		$SerialOffset  = ($SerialSize > 0) ? $R->INDEX() : 0;    

		$exports[] = [
			'ClassIndex'    => $ClassIndex,
			'SuperIndex'    => $SuperIndex,
			'PackageDWORD'  => $PackageDWORD,
			'ObjectNameIdx' => $ObjectNameIdx,
			'ObjectFlags'   => $ObjectFlags,
			'SerialSize'    => $SerialSize,
			'SerialOffset'  => $SerialOffset
		];
	}
	

//print_r($exports);




//----- Read Imports table data
	$imports = [];
	$R->seek($importOffset); 
	
	for ($i=0;$i<$importCount;$i++) {
		$ClassPackageIdx = $R->INDEX(); 
		$ClassNameIdx    = $R->INDEX();  
		$PackageIndex    = $R->I32();   
		$ObjectNameIdx   = $R->INDEX();  
		$ClassPackage    = [$ClassPackageIdx, 0];
		$ClassName       = [$ClassNameIdx, 0];
		$ObjectName      = [$ObjectNameIdx, 0];

		$imports[] = [
			'ClassPackage'    => $ClassPackage,
			'ClassName'       => $ClassName,
			'PackageIndex'    => $PackageIndex,
			'ObjectName'      => $ObjectName,
			'ClassPackageIdx' => $ClassPackageIdx,
			'ClassNameIdx'    => $ClassNameIdx,
			'ObjectNameIdx'   => $ObjectNameIdx,
		];
	}

	
	
	
	
	
	
//----- Print Exports table data
	echo '<h3>*******Export Table ('.$exportCount.':'.$exportOffset.')</h3>';
	echo '<table border="1" cellpadding="4" cellspacing="0">';
	echo '<tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th></tr>';
	
	for ($i=0;$i<$exportCount;$i++) {
		$e = $exports[$i];

		$groupTxt = groupFromDWORD($e['PackageDWORD'], $names, $imports, $exports);
		$nameTxt  = nameText($names, $e['ObjectNameIdx']);
		$classTxt = classNameFromRef($e['ClassIndex'], $names, $imports, $exports);  
		$numTxt   = sprintf("%d (0x%02X)", $i, $i & 0xFF);                           
		$superTxt = ($e['SuperIndex']===0) ? '0' : (($e['SuperIndex']<0) ? 'Import['.(-$e['SuperIndex']-1).']' : 'Export['.($e['SuperIndex']-1).']');
		$sizeTxt  = $e['SerialSize'];
		$offTxt   = '0x'.str_pad(strtoupper(dechex($e['SerialOffset'])),8,'0',STR_PAD_LEFT);
		$flagTxt  = flags_obj($e['ObjectFlags']);
	
		echo "<tr>".td($groupTxt).td($nameTxt).td($classTxt).td($numTxt).td($superTxt).td($sizeTxt).td($offTxt).td($flagTxt)."</tr>";		
	}
	
	echo '</table>';
	
	
	
	
	
	
	
	
	
//----- Print Imports table data
	echo "<h3>*******Import Table ({$importCount}:{$importOffset})</h3>";
	echo "<table border='1' cellspacing='0' cellpadding='4'>";
	echo "<tr><th>Package &amp; Group</th><th>Name</th><th>Class</th><th>Class Package</th><th>Num.</th></tr>";
	
	for ($i = 0; $i < count($imports); $i++) {
		$imp  = $imports[$i];
		$pkg  = pkgNameFromRef($imp['PackageIndex'], $names, $imports, $exports);
		$name = fnameText($names, $imp['ObjectName']);
		$cls  = fnameText($names, $imp['ClassName']);
		$clsp = fnameText($names, $imp['ClassPackage']);
		$num  = sprintf("%d (0x%02X)", $i, $i);

		echo "<tr>".td($pkg === '' ? '' : $pkg).td($name).td($cls).td($clsp).td($num)."</tr>";
	}
	
	echo "</table>";
	
	
	
	
	
	
	
/* ---------- Properties for ALL exports that have a property stream ---------- */
	echo "<h3>*******Properties by Export</h3>";

	for ($row = 0; $row < count($exports); $row++) {
		$e            = $exports[$row];
		$serialSize   = (int)$e['SerialSize'];
		$serialOffset = (int)$e['SerialOffset'];
		
		if ($serialSize <= 0) 
			continue; // nothing serialized for this export

		$ename = nameText($names, $e['ObjectNameIdx']);
		$eh    = sprintf("[%d] %s — offset 0x%08X, size %d", $row, htmlspecialchars($ename), $serialOffset, $serialSize);

		echo "<h4>{$eh}</h4>";

		$props    = parseExportProperties($R, $serialOffset, $serialSize, $names);		
		UEReader::inferArrayIndexZero($props);
		$endPos   = $R->tell();
		$consumed = $endPos - $serialOffset;
		$tailLen  = max(0, $serialSize - $consumed);

		if ($tailLen > 0) {
			// determine export class text
			$classTxt = classNameFromRef($e['ClassIndex'], $names, $imports, $exports);

			echo "<p><b>Post-property data:</b> {$tailLen} byte(s) at offset 0x" . sprintf("%08X", $serialOffset + $consumed) . "</p>";

			// show first few bytes
			$R->seek($serialOffset + $consumed);
			$peek = [];
			$toRead = min($tailLen, 16);
			for ($k=0; $k<$toRead; $k++) $peek[] = sprintf("%02X", $R->U8());
			echo "<pre>hex: " . implode(' ', $peek) . ($tailLen > 16 ? " ..." : "") . "</pre>";

			// Texture-specific hint: first byte is mip count in UT2004 textures
			if (strcasecmp($classTxt, 'Texture') === 0 && $tailLen >= 1) {
				$mipCount = hexdec($peek[0]);
				echo "<p><i>Texture hint:</i> MipMapCount = {$mipCount}</p>";
			}
		}

		echo "<table border='1' cellspacing='0' cellpadding='4'>";
		echo "<tr><th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Struct</th><th>Array?</th><th>Idx</th><th>Value</th></tr>";

		foreach ($props as $p) {
			$off = "0x" . sprintf("%08X", $p['relOffset']);
			$len = (int)$p['recordLen'];                 // full record length
			$nm  = htmlspecialchars($p['name']);
			$tp  = htmlspecialchars($p['typeName']);
			$stn = isset($p['structName']) ? htmlspecialchars($p['structName']) : '';
			$arr = $p['arrayFlag'] ? 'Yes' : 'No';
			if ($p['arrayFlag'] && $p['typeCode'] === 0x03) { $arr = 'No'; } // boolean: bit7 is value, not array

			$idxHtml = '';
			if ($p['arrayIndex'] !== null) {
				if (!empty($p['idxInferred']) && $p['arrayIndex'] === 0) {
					$idxHtml = '<span class="idx0">0</span>'; // inferred idx 0 (not stored in file)
				} else {
					$idxHtml = (string)$p['arrayIndex'];            // explicit idx >=1
				}
			}

			$val = htmlspecialchars($p['display']);
			echo "<tr><td>{$off}</td><td>{$len}</td><td>{$nm}</td><td>{$tp}</td><td>{$stn}</td><td>{$arr}</td><td>{$idxHtml}</td><td>{$val}</td></tr>";
		}

		echo "</table>";
	}

} catch (Throwable $ex) {
    echo "<pre>".h($ex->getMessage())."</pre>";
}
?>