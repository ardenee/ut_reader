<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$path = "test.utx";
$path = "oldtest2.utx";

// ---------- reader ----------
final class UEReader {
    private string $buf;
    private int $pos = 0;
    private int $len = 0;
    private int $version = 0; // NEW

    public function __construct(string $bytes){
        $this->buf = $bytes;
        $this->len = strlen($bytes);
    }

    public function setVersion(int $v): void { // NEW
        $this->version = $v;
    }
	
	
    private function nameDecodeV($R) {
        if ($this->version < 64) {
            $bytes = [];
            while (true) {
                $b = $R->U8();
                if ($b === 0) break;
                $bytes[] = $b;
            }
            return implode('', array_map('chr', $bytes));
        } else {
            $len = $R->U8();
            if ($len === 0) return '';
            $str = $R->read($len);
            // $str includes trailing null; trim final 0x00 safely
            if ($str !== '' && ord($str[strlen($str)-1]) === 0) {
                $str = substr($str, 0, -1);
            }
            return $str;
        }
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
	
    public function u8(): int { 
		return ord($this->bytes(1)); 
	}
	
    public function u32(): int {
        $b = $this->bytes(4);
        $v = (ord($b[0])      ) | (ord($b[1])<<8) | (ord($b[2])<<16) | (ord($b[3])<<24);
        return $v & 0xFFFFFFFF;
    }
    public function i32(): int { 
		$u = $this->u32(); 
		
		return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u; 
	}

	public function index(): int {
        if ($this->version > 178) { // UE3+ uses 32-bit int for indices
            return $this->i32();
        }
		
		$b0  = $this->u8();
		$neg = (($b0 & 0x80) !== 0);
		$con = (($b0 & 0x40) !== 0);
		$val = 0;
		
		if ($con) {
			$b1 = $this->u8(); $val = ($val << 7) + ($b1 & 0x7F);
			
			if ($b1 & 0x80) {
				$b2 = $this->u8(); $val = ($val << 7) + ($b2 & 0x7F);
				
				if ($b2 & 0x80) {
					$b3 = $this->u8(); $val = ($val << 7) + ($b3 & 0x7F);
					
					if ($b3 & 0x80) {
						$b4 = $this->u8(); $val = $b4; // final 8 bits
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
    public function guid(): string {
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
	

	
	public function inferArrayIndexZero(array &$props): void {
		// If we see an element with arrayIndex>=1 and the immediately previous
		// property has the same name+type but no array flag, mark that previous as idx 0.
		for ($i = 1; $i < count($props); $i++) {
			$cur = $props[$i];
			if (!empty($cur['isTerminal'])) break;

			if ($cur['arrayIndex'] !== null && $cur['arrayIndex'] >= 1) {
				$j = $i - 1;
				if ($j >= 0) {
					$prev =& $props[$j];
					if (empty($prev['isTerminal'])&& $prev['nameIdx']  === $cur['nameIdx']&& $prev['typeCode'] === $cur['typeCode']&& empty($prev['arrayFlag'])) {
						$prev['arrayFlag']  = 1;
						$prev['arrayIndex'] = 0;           // inferred
						$prev['idxInferred']= true;        // mark so UI can color it
					}
				}
			}
		}
	}	
	



    // ---- Moved from global scope (now static methods) ----
    public function flagsObj($v) : string {
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

    public function flagsObjectToText(int $v): string {
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

    public function flagsPkgToText(int $f): string {
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

	public function readNAME(UEReader $R, int $version): string {
		if ($version < 64) {
			// NUL-terminated ASCII
			$s = '';
			while (true) { $c = $R->u8(); if ($c === 0) break; $s .= chr($c); }
			return $s;
		}

		// version >= 64
		$len = ($version > 117) ? $R->index() : $R->u8(); // IMPORTANT
		if ($len === 0) return '';

		if ($len < 0) {
			// UTF-16LE, byte count is -len * 2
			$bytes = $R->bytes(-$len * 2);
			// convert to UTF-8 (trim any trailing NULs)
			$s = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
			return rtrim($s ?? '', "\x00");
		} else {
			// Latin-1/ANSI, length includes the trailing NUL
			$bytes = $R->bytes($len);
			// trim trailing NUL if present
			return rtrim($bytes, "\x00");
		}
	}


	public function readFName(UEReader $R): array {
		// If you prefer, pass version in; otherwise rely on $R->setVersion()
		$idx = $R->index();
		$num = 0;
		if ($R->getVersion() >= 343) { // UE3
			$num = $R->i32();
		}
		return [$idx, $num];
	}

	// Add a small accessor in UEReader
	public function getVersion(): int { return $this->version; }


    public function fnameText(array $names, array $fname): string {
        [$idx,$num] = $fname;
    	
        if (!array_key_exists($idx, $names)) 
    		return "(bad:$idx)";
    	
        return $num ? ($names[$idx]['text'].'_'.$num) : $names[$idx]['text'];
    }

    public function resolveGroup(array $imports, array $exports, array $names, int $ref): string {
        $guard=0; $last='None';
        while ($ref !== 0 && $guard++ < 64) {
            if ($ref > 0) {
                $e = $exports[$ref-1] ?? null; 
    			
    			if (!$e) 
    				break;
    			
                $last = UEReader::fnameText($names, $e['ObjectName']);
                $ref  = $e['PackageIndex'];
            } else {
                $i = $imports[-$ref-1] ?? null; 
    			
    			if (!$i) 
    				break;
    			
                $last = UEReader::fnameText($names, $i['ObjectName']);
                $ref  = $i['PackageIndex'];
            }
        }
    	
        return $last;
    }

    public function resolveClassName(array $imports, array $exports, array $names, int $classIndex): string {
        if ($classIndex === 0) 
    		return 'None';
    	
        if ($classIndex < 0) {
            $imp = $imports[-$classIndex-1] ?? null;
    		
            if (!$imp) 
    			return "(bad:$classIndex)";
    		
            $clsName = UEReader::fnameText($names, $imp['ClassName']);   // usually "Class"
            $objName = UEReader::fnameText($names, $imp['ObjectName']);  // e.g. "Texture"
    		
            return ($clsName === 'Class') ? $objName : $clsName;
        }
    	
        $exp = $exports[$classIndex-1] ?? null;
    	
        return $exp ? UEReader::fnameText($names, $exp['ObjectName']) : "(bad:$classIndex)";
    }

    public function groupFromDWORD(int $ref, array $names, array $imports, array $exports) : string {
        if ($ref === 0) return '';
        if ($ref < 0) {
            $i = -$ref - 1;
    		
            if (!isset($imports[$i])) 
    			return "Import[$i]?";
    		
            return UEReader::fnameText($names, $imports[$i]['ObjectName']); // group/package name
        }
    	
        $e = $ref - 1;
    	
        if (!isset($exports[$e])) 
    		return "Export[$e]?";
    	
        return UEReader::nameText($names, $exports[$e]['ObjectNameIdx']);
    }

    public function nameText(array $names, int $idx) : string {
        return ($idx>=0 && $idx<count($names)) ? $names[$idx]['text'] : "(bad:$idx)";
    }

    public function classNameFromRef(int $ref, array $names, array $imports, array $exports) : string {
        if ($ref === 0) return 'None';
        if ($ref < 0) {
            $i = -$ref - 1;
    		
            if (!isset($imports[$i])) 
    			return "Import[$i]?";
    		
            return UEReader::fnameText($names, $imports[$i]['ObjectName']); // **THIS** is the class name in imports
        }
        $e = $ref - 1;
    	
        if (!isset($exports[$e])) 
    		return "Export[$e]?";
    	
        return UEReader::nameText($names, $exports[$e]['ObjectNameIdx']);
    }

    public function pkgNameFromRef(int $ref, array $names, array $imports, array $exports) : string {
        if ($ref === 0) return ''; // top-level (no package)
        if ($ref < 0) {
            $i = -$ref - 1;
    		
            return isset($imports[$i]) ? UEReader::fnameText($names, $imports[$i]['ObjectName']) : "Import[$i]?";
        }
        // rarely used for imports, but keep complete:
        $e = $ref - 1;
    	
        return isset($exports[$e]) ? UEReader::nameText($names, $exports[$e]['ObjectNameIdx']) : "Export[$e]?";
    }

    public function propTypeName(int $t): string {
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

	// Reads the compact array index that follows when the info byte's array bit is set.
	// (byte | word with MSB=1 | dword with top two bits = 11)
	private function readArrayIndex(): int {
		$b = $this->u8();
		if ($b < 0x80) return $b;                           // 0..127
		if ($b < 0xC0) return (($b & 0x3F) << 8) | $this->u8();            // 0x80..0xBF : 14-bit
		return (($b & 0x3F) << 24) | ($this->u8() << 16) | ($this->u8() << 8) | $this->u8(); // 0xC0.. : 30-bit
	}

    public function nameIdxText(array $names, int $idx): string {
        return UEReader::nameText($names, $idx);
    }

    public function readOneProperty(UEReader $R, array $names, int $noneNameIndex): array {
        $start = $R->tell();
    
        // 1) Property name (INDEX). "None" terminates the list.
    	$pre = $R->tell();
    	$nameIdx = $R->index();
    	
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
        $info  = $R->u8();
        $type  =  $info & 0x0F;
        $szc   = ($info >> 4) & 0x07;
        $arrF  =  ($info & 0x80) !== 0;
    
        // 3) Struct name follows for StructProperty (0x0A)
        $structNameIdx = null;
    	
        if ($type === 0x0A) {
            $structNameIdx = $R->index();
        }
    
        // 4) Array index if flag set and not Boolean (PDF rule)
        $arrayIndex = null;
    	
        if ($arrF && $type !== 0x03) {
            $arrayIndex = $R->readArrayIndex($R);
        }
    
        // 5) Value size from size code
        $valueBytes = 0;
        switch ($szc) {
            case 0: $valueBytes = 1;  break;
            case 1: $valueBytes = 2;  break;
            case 2: $valueBytes = 4;  break;
            case 3: $valueBytes = 12; break;
            case 4: $valueBytes = 16; break;
            case 5: $valueBytes = $R->u8();   break; // next BYTE
            case 6: $valueBytes = $R->U16();  break; // next WORD
            case 7: $valueBytes = $R->u32();  break; // next DWORD
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
                    $b          = $R->u8();
                    $decoded    = [ 'byte' => $b ];
                    $display    = (string)$b;
                    $valueBytes = 1;
                    break;
    
                case 0x02: // IntegerProperty
                    // UE2 ints are 4 bytes; show signed + hex like the viewer
                    $raw        = $R->i32();
                    $decoded    = [ 'int' => $raw, 'hex' => sprintf('0x%08X', $raw & 0xFFFFFFFF) ];
                    $display    = $raw . ' (' . $decoded['hex'] . ')';
                    $valueBytes = 4;
                    break;
    
                case 0x04: // FloatProperty
                    $u          = $R->u32();
                    $f          = unpack('f', pack('V', $u))[1];
                    $decoded    = [ 'float' => $f ];
                    $display    = (string)$f;
                    $valueBytes = 4;
                    break;
    
                case 0x05: // ObjectProperty (INDEX object ref)
                    $ref        = $R->index();
                    $decoded    = [ 'objectRef' => $ref ];
                    $display    = (string)$ref;
                    $valueBytes = $R->tell() - $valueStart;
                    break;
    
                case 0x06: // NameProperty (INDEX name index)
                    $ni         = $R->index();
                    $decoded    = [ 'nameIndex' => $ni ];
                    $display    = UEReader::nameIdxText($names, $ni);
                    $valueBytes = $R->tell() - $valueStart;
                    break;
    
                case 0x0A: // StructProperty
                    $structName = UEReader::nameIdxText($names, $structNameIdx ?? 0);
                    if (strcasecmp($structName, 'Color') === 0 && $valueBytes >= 4) {
                        $r = $R->u8(); $g = $R->u8(); $b = $R->u8(); $a = $R->u8();
                        // consume remaining padding, if any
                        for ($k = 4; $k < $valueBytes; $k++) $R->u8();
                        $decoded = [ 'struct' => 'Color', 'r'=>$r,'g'=>$g,'b'=>$b,'a'=>$a ];
                        $display = "Color (R={$r},G={$g},B={$b},A={$a})";
                    } else {
                        // Unknown struct: skip bytes
                        for ($k = 0; $k < $valueBytes; $k++) $R->u8();
                        $decoded = [ 'struct' => $structName, 'bytes' => $valueBytes ];
                        $display = $structName . " [".$valueBytes." bytes]";
                    }
                    break;
    
                case 0x0D: // StrProperty: INDEX length + ASCIIZ
                    $len = $R->index();
                    $buf = '';
    				
                    for ($k=0; $k<$len; $k++) 
    					$buf .= chr($R->u8());
    				
                    $decoded    = [ 'len' => $len, 'str' => rtrim($buf, "\x00") ];
                    $display    = $decoded['str'];
                    $valueBytes = $len + ($R->tell() - $valueStart) - $len; // length only
                    break;
    
                default: // Unhandled type: skip value bytes
                    for ($k = 0; $k < $valueBytes; $k++) 
    					$R->u8();				
    				
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
            'typeName'      => UEReader::propTypeName($type),
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



    public function parseExportProperties(UEReader $R, int $serialOffset, int $serialSize, array $names): array {
        // In most UT2004 packages, the property stream begins at the export’s SerialOffset and
        // ends when the "None" property name appears. We stop at either condition.
        $saved   = $R->tell();
        $R->seek($serialOffset);
        $props   = [];
    	$noneIdx = 0;               // Name[0] = "None" in your dumps
    	$start   = $R->tell();
    	while (($R->tell() - $start) < $serialSize) {
    		list($taken, $p) = UEReader::readOneProperty($R, $names, $noneIdx);
    		$p['name'] = UEReader::nameIdxText($names, $p['nameIdx']); // resolve texts
    		
    		if (($p['structNameIdx'] ?? null) !== null) {
    			$p['structName'] = UEReader::nameIdxText($names, ($p['structNameIdx'] ?? null));
    		}
    		
    		$p['relOffset'] = (($p['offsetInObj'] ?? null) - $serialOffset);
    		$props[]        = $p;                  // <-- always add (including "None")
    		
    		if ($p['isTerminal']) 
    			break;    // <-- then stop
    	}
    	
        $R->seek($saved);
    	
        return $props;
    }
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
	
	
try {
    if (!is_file($path)) throw new RuntimeException("File not found: {$path}");
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException("Failed to read: {$path}");
    $R = new UEReader($bytes);

    echo "Unreal File found. (".filesize($path).") KB\n\n";
    $tag          = $R->u32();                 // 0x9E2A83C1 for UE2
    $verLic       = $R->u32();                 // low word=Version, high word=Licensee
    $version      = $verLic & 0xFFFF;
	$R->setVersion($version); // move in to class
    $licensee     = ($verLic >> 16) & 0xFFFF;
    $pkgFlags     = $R->u32();
    $nameCount    = $R->u32();
    $nameOffset   = $R->u32();
    $exportCount  = $R->u32();
    $exportOffset = $R->u32();
    $importCount  = $R->u32();
    $importOffset = $R->u32();
    $guid = '';
    $generations = [];

    if ($version < 68) {
        $heritageCount  = $R->u32();
        $heritageOffset = $R->u32();
        $save = $R->tell();
        if ($heritageCount > 0) {
            $R->seek($heritageOffset);
            for ($i=0;$i<$heritageCount;$i++) $guid = $R->guid(); // keep last
        }
        $R->seek($save);
    } else {
        $guid     = $R->guid();
        $genCount = $R->u32();
        for ($i=0; $i<$genCount; $i++) {
            $e = $R->u32();
            $n = $R->u32();
            $generations[] = ['e'=>$e,'n'=>$n];
        }
    }

    echo "<h3>*******File Header</h3>";
    echo "<table><tr>".th('Var').th('Value').th('Additional')."</tr>";
    echo " <tr>".td('Version').td($version).td('')."</tr>";
	echo " <tr>".td('License mode').td($licensee).td('')."</tr>";
	echo " <tr>".td('Package flags').td(sprintf("0x%08X",$pkgFlags),'hex').td('('.$pkgFlags.') '.$R->flagsPkgToText($pkgFlags))."</tr>";
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
        $txt       = $R->readNAME($R, $version);
        $flags     = $R->u32();
        $names[$i] = ['text'=>$txt, 'flags'=>$flags];
		$numTxt    = sprintf("%d (0x%02X)", $i, $i & 0xFF); 
		
		//$flagTxt  = $R->flagsObj($e['flags']);
		
        echo "<tr>".td($numTxt).td($txt).td(strlen($txt)). td(sprintf("0x%08X",$flags),'hex'). td($R->flagsObjectToText($flags)) ."</tr>";
    }
	
    echo "</table>";		

//print_r($names);



	
//----- Read Exports table data
	$exports = [];
	$R->seek($exportOffset); 
	
	for ($i=0;$i<$exportCount;$i++) {
		$ClassIndex    = $R->index();    
		$SuperIndex    = $R->index();    
		$PackageDWORD  = $R->i32();    
		$ObjectNameIdx = $R->index();   
		$ObjectFlags   = $R->u32();    
		$SerialSize    = $R->index();    
		$SerialOffset  = ($SerialSize > 0) ? $R->index() : 0;    

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
		$ClassPackageIdx = $R->index(); 
		$ClassNameIdx    = $R->index();  
		$PackageIndex    = $R->i32();   
		$ObjectNameIdx   = $R->index();  
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
	
	
	
	
	$compressionFormat    = 0;
	$compressedChunkCount = 0;
	$chunks               = [];

	if ($version >= 334) {
		$compressionFormat    = $R->u32();        // 0=NONE, 2=LZO, etc.
		$compressedChunkCount = $R->u32();

		if ($compressionFormat !== 0 && $compressedChunkCount > 0) {
			for ($i=0; $i<$compressedChunkCount; $i++) {
				$uoff = $R->u32();
				$usize = $R->u32();
				$coff = $R->u32();
				$csize = $R->u32();
				$chunks[] = compact('uoff','usize','coff','csize');
			}
		}
	}


	
	
	
	
	
	
//----- Print Exports table data
	echo '<h3>*******Export Table ('.$exportCount.':'.$exportOffset.')</h3>';
	echo '<table border="1" cellpadding="4" cellspacing="0">';
	echo '<tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th></tr>';
	
	for ($i=0;$i<$exportCount;$i++) {
		$e = $exports[$i];

		$groupTxt = $R->groupFromDWORD($e['PackageDWORD'], $names, $imports, $exports);
		$nameTxt  = $R->nameText($names, $e['ObjectNameIdx']);
		$classTxt = $R->classNameFromRef($e['ClassIndex'], $names, $imports, $exports);  
		$numTxt   = sprintf("%d (0x%02X)", $i, $i & 0xFF);                           
		$superTxt = ($e['SuperIndex']===0) ? '0' : (($e['SuperIndex']<0) ? 'Import['.(-$e['SuperIndex']-1).']' : 'Export['.($e['SuperIndex']-1).']');
		$sizeTxt  = $e['SerialSize'];
		$offTxt   = '0x'.str_pad(strtoupper(dechex($e['SerialOffset'])),8,'0',STR_PAD_LEFT);
		$flagTxt  = $R->flagsObj($e['ObjectFlags']);
		$flav   = sprintf("%08X", $e['ObjectFlags']);
	
		echo "<tr>".td($groupTxt).td($nameTxt).td($classTxt).td($numTxt).td($superTxt).td($sizeTxt).td($offTxt).td($flagTxt).td($flav)."</tr>";		
	}
	
	echo '</table>';
	
	
	
	
	
	
	
	
	
//----- Print Imports table data
	echo "<h3>*******Import Table ({$importCount}:{$importOffset})</h3>";
	echo "<table border='1' cellspacing='0' cellpadding='4'>";
	echo "<tr><th>Package &amp; Group</th><th>Name</th><th>Class</th><th>Class Package</th><th>Num.</th></tr>";
	
	for ($i = 0; $i < count($imports); $i++) {
		$imp  = $imports[$i];
		$pkg  = $R->pkgNameFromRef($imp['PackageIndex'], $names, $imports, $exports);
		$name = $R->fnameText($names, $imp['ObjectName']);
		$cls  = $R->fnameText($names, $imp['ClassName']);
		$clsp = $R->fnameText($names, $imp['ClassPackage']);
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

		$ename = $R->nameText($names, $e['ObjectNameIdx']);
		$eh    = sprintf("[%d] %s — offset 0x%08X, size %d", $row, htmlspecialchars($ename), $serialOffset, $serialSize);

		echo "<h4>{$eh}</h4>";

		$props    = $R->parseExportProperties($R, $serialOffset, $serialSize, $names);		
		$R->inferArrayIndexZero($props);
		$endPos   = $R->tell();
		$consumed = $endPos - $serialOffset;
		$tailLen  = max(0, $serialSize - $consumed);

		if ($tailLen > 0) {
			// determine export class text
			$classTxt = $R->classNameFromRef($e['ClassIndex'], $names, $imports, $exports);

			echo "<p><b>Post-property data:</b> {$tailLen} byte(s) at offset 0x" . sprintf("%08X", $serialOffset + $consumed) . "</p>";

			// show first few bytes
			$R->seek($serialOffset + $consumed);
			$peek = [];
			$toRead = min($tailLen, 16);
			for ($k=0; $k<$toRead; $k++) $peek[] = sprintf("%02X", $R->u8());
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

