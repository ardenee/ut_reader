<?php
// readfile_ue2_fixed.php
// UE1/UE2 package reader mirroring UnLinker.h / UnName.cpp / UnObjBas.h

ini_set('display_errors', 1);
error_reporting(E_ALL);

class UEFile {
    private string $buf;
    private int $pos = 0;
    private int $len = 0;

    function __construct(string $bytes) {
        $this->buf = $bytes;
        $this->len = strlen($bytes);
    }
    function tell(): int { return $this->pos; }
    function seek(int $o): void { $this->pos = $o; }
    private function need(int $n): void {
        if ($this->pos + $n > $this->len) {
            $have = $this->len - $this->pos;
            throw new RuntimeException(sprintf("ERROR: Unexpected EOF at %d reading %d bytes (have %d)", $this->pos, $n, $have));
        }
    }
	
	    function readBytes(int $n): string {
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }
	
    function read(int $n): string {
        $this->need($n);
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }
    function U8(): int { return ord($this->read(1)); }
    function U32(): int {
        $b = $this->read(4);
        $v = (ord($b[0])      ) | (ord($b[1])<<8) | (ord($b[2])<<16) | (ord($b[3])<<24);
        return $v & 0xFFFFFFFF;
    }
    function I32(): int {
        $u = $this->U32();
        return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u;
    }
    // Unreal INDEX: 6+7+7+... bits, bit6 of first byte = sign, bit7 = continue
    function INDEX(): int {
        $b0  = $this->U8();
        $neg = ($b0 & 0x40) !== 0;
        $val = ($b0 & 0x3F);
        $shift = 6;
        if ($b0 & 0x80) {
            do {
                $b = $this->U8();
                $val |= (($b & 0x7F) << $shift);
                $shift += 7;
            } while ($b & 0x80);
        }
        return $neg ? -$val : $val;
    }
    // Correct Windows/UE GUID layout formatter
    function readGUID_string(): string {
        $s = $this->read(16);
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
	
	    function readGUID(): string {
        $s = $this->readBytes(16);
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
}

// ---------- fread-style helpers (exactly as you showed for name/flags) ----------
function read($fp, int $n, string $fmt) : int|string {
    $s = fread($fp, $n);
    if ($s === false || strlen($s) !== $n) {
        $have = ($s===false) ? 0 : strlen($s);
        throw new RuntimeException(sprintf("ERROR: Unexpected EOF at stream reading %d bytes (have %d)", $n, $have));
    }
    if ($fmt === 'C') {
        $a = unpack('Cv', $s);
        return $a['v'];
    } elseif ($fmt === 'V') {
        $a = unpack('Vv', $s);
        return $a['v'] & 0xFFFFFFFF;
    } else {
        return $s;
    }
}
function readBYTE($fp) : int { return read($fp, 1, 'C'); }
function readDWORD($fp) : int { return read($fp, 4, 'V'); }
function readStr($fp, $size, $fmt) : string {
    $s = fread($fp, $size);
    if ($s === false || strlen($s) !== $size) {
        $have = ($s===false) ? 0 : strlen($s);
        throw new RuntimeException(sprintf("ERROR: Unexpected EOF at stream reading %d bytes (have %d)", $size, $have));
    }
    return $s;
}
function readSTRING($fp, $size) : string { return readStr($fp, $size, 'C'.$size); }
function readNullSTRING($fp) : string {
    $out = '';
    while (!feof($fp)) {
        $c = fread($fp, 1);
        if ($c === '' || $c === false) break;
        if ($c === "\x00") break;
        $out .= $c;
    }
    return $out;
}
function readNAME($fp, int $version) : string {
    if ($version >= 64) {
        $lenPlusOne = readBYTE($fp);      // length+1 (includes trailing nul)
        $len        = max(0, $lenPlusOne - 1);
        if ($len === 0) {
            if ($lenPlusOne > 0) readBYTE($fp);
            return "";
        }
        $s = readSTRING($fp, $len);
        $nul = readBYTE($fp);             // trailing 0x00
        return $s;
    } else {
        return readNullSTRING($fp);
    }
}

// EXACT flag map you posted
function GetObjectFlags(int $val) : string {
    $Str = "";
    if ( $val & 0x00000001 ) $Str .= "RF_Transactional,";
    if ( $val & 0x00000002 ) $Str .= "RF_Unreachable,";
    if ( $val & 0x00000004 ) $Str .= "RF_Public,";
    if ( $val & 0x00000008 ) $Str .= "RF_TagImp,";
    if ( $val & 0x00000010 ) $Str .= "RF_TagExp,";
    if ( $val & 0x00000020 ) $Str .= "RF_SourceModified,";
    if ( $val & 0x00000040 ) $Str .= "RF_TagGarbage,";
    if ( $val & 0x00000200 ) $Str .= "RF_NeedLoad,";
    if ( $val & 0x00000400 ) $Str .= "RF_HighlightedName,";
    if ( $val & 0x00000800 ) $Str .= "RF_InSingularFunc,";
    if ( $val & 0x00001000 ) $Str .= "RF_Suppress,";
    if ( $val & 0x00002000 ) $Str .= "RF_InEndState,";
    if ( $val & 0x00004000 ) $Str .= "RF_Transient,";
    if ( $val & 0x00008000 ) $Str .= "RF_PreLoading,";
    if ( $val & 0x00010000 ) $Str .= "RF_LoadForClient,";
    if ( $val & 0x00020000 ) $Str .= "RF_LoadForServer,";
    if ( $val & 0x00040000 ) $Str .= "RF_LoadForEdit,";
    if ( $val & 0x00080000 ) $Str .= "RF_Standalone,";
    if ( $val & 0x00100000 ) $Str .= "RF_NotForClient,";
    if ( $val & 0x00200000 ) $Str .= "RF_NotForServer,";
    if ( $val & 0x00400000 ) $Str .= "RF_NotForEdit,";
    if ( $val & 0x00800000 ) $Str .= "RF_Destroyed,";
    if ( $val & 0x01000000 ) $Str .= "RF_NeedPostLoad,";
    if ( $val & 0x02000000 ) $Str .= "RF_HasStack,";
    if ( $val & 0x04000000 ) $Str .= "RF_Native,";
    if ( $val & 0x08000000 ) $Str .= "RF_Marked,";
    if ( $val & 0x10000000 ) $Str .= "RF_ErrorShutdown,";
    if ( $val & 0x20000000 ) $Str .= "RF_DebugPostLoad,";
    if ( $val & 0x40000000 ) $Str .= "RF_DebugSerialize,";
    if ( $val & 0x80000000 ) $Str .= "RF_DebugDestroy,";
    return rtrim($Str, ',');
}



// Package flags (includes PKG_Encrypted)
function GetPackageFlags(int $f): string {
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
    foreach ($map as $bit=>$name) if ($f & $bit) $out[]=$name;
    return $out ? implode(',', $out) : '';
}

function nameText(array $names, int $idx, int $num): string {
    if ($idx < 0 || $idx >= count($names)) return "(bad:$idx)";
    return $num ? ($names[$idx] . '_' . $num) : $names[$idx];
}
function refIndex(int $val): string {
    if ($val===0) return 'None';
    if ($val>0)   return 'Export['.($val-1).']';
    return 'Import['.(-$val-1).']';
}

// ---- main ----
$path = "test.utx";
echo "<pre>";
$bytes = file_get_contents($path);
$R = new UEFile($bytes);

try {
	echo "Unreal File found.\n\n";

    // FPackageFileSummary (UnLinker.h order for UE2 era)
    $tag = $R->U32(); // 0x9E2A83C1
     // will fill after we decode ver/lic
    if ($tag !== 0x9E2A83C1) {
        // Some UE1 builds used 0 or different tag at front; we still continue as you need.
        // But we print the raw tag like your earlier builds did.
        $tagStr = sprintf("0x%08X", $tag);
        // fall through
    }
    // WORD Version + WORD Licensee
    $verLic = $R->U32();
    $version =  $verLic & 0xFFFF;
    $license = ($verLic >> 16) & 0xFFFF;

    $pkgFlags    = $R->U32();
    $nameCount   = $R->U32();
    $nameOffset  = $R->U32();
    $exportCount = $R->U32();
    $exportOffset= $R->U32();
    $importCount = $R->U32();
    $importOffset= $R->U32();

    // ---- Heritage vs Newer GUID/Generations ----
    $guid = '';
    $generations = [];
	
	echo "*******File Header\n";
	echo "<table border=1>\n";
	echo " <tr>\n";
	echo "  <td>Name</td><td>Value</td><td>Additional</td>\n";
	echo " </tr>\n";	
	echo " <tr>\n";	
    printf("<tr><td>Version</td><td>%d</td><td>&nbsp;</td>\n", $version);
    printf("<tr><td>License mode</td><td>%d</td><td>&nbsp;</td>\n", $license);
    printf("<tr><td>Package flags</td><td>0x%08X</td><td>%s<.td></tr>\n", $pkgFlags, GetPackageFlags($pkgFlags));
    printf("<tr><td>Name count</td><td>%d</td><td>&nbsp;</td></tr>\n", $nameCount);
    printf("<tr><td>Name offset</td><td>%d</td><td>&nbsp;</td></tr>\n", $nameOffset);
    printf("<tr><td>Export count</td><td>%d</td><td>&nbsp;</td></tr>\n", $exportCount);
    printf("<tr><td>Export offset</td><td>%d</td><td>&nbsp;</td></tr>\n", $exportOffset);
    printf("<tr><td>Import count</td><td>%d</td><td>&nbsp;</td></tr>\n", $importCount);
    printf("<tr><td>Import offset</td><td>%d</td><td>&nbsp;</td></tr>\n", $importOffset);
	echo "</table>\n";
	
    if ($version < 68) {
        // Old format: HeritageCount, HeritageOffset; a table of GUIDs; last is the one to use.
        $heritageCount  = $R->readGUID(); // 16 Bytes GUID
        $heritageOffset = $R->U32();

        //printf("Heritage count:   %d\n", $heritageCount);
        //printf("Heritage offset:  %d\n", $heritageOffset);

		echo "*******No Generatin Block (Version($version) < 68)\n";
		echo "<table border=1>\n";
		echo " <tr>\n";
		echo "  <td>Heritage count (GUID)</td><td>$heritageCount</td>\n";
		echo " </tr>\n";	
		echo " <tr>\n";
		echo "  <td>Heritage offset</td><td>$heritageOffset</td>\n";
		echo " </tr>\n";
		echo "</table>\n";
		
        
       // printf("GUID:             %s\n\n", $guid);
        // No generation table in <68; continue to names.
    } else {
        // Newer format: GUID, then GenerationCount, then {ExportCount, NameCount} * N
        $guid        = $R->readGUID();
        $genCount    = $R->U32();
        for ($i=0; $i<$genCount; $i++) {
            $e = $R->U32();
            $n = $R->U32();
            $generations[] = [$e,$n];
        }
		echo "*******Generatin Block (Version($version) > 68)\n";
		echo "<table border=1>\n";
		echo " <tr>\n";
		echo "  <td>GUID</td><td>$guid</td>\n";
		echo " </tr>\n";	
		echo " <tr>\n";
		echo "  <td>Count</td><td>$genCount</td>\n";
		echo " </tr>\n";
		echo "</table>\n";

	    echo "*******Generatin Block (Version($version) > 68)\n";
		echo "<table border=1>\n";
		echo " <tr>\n";
		echo "  <td>Num</td><td>Exports</td><td>Names</td>\n";
		echo " </tr>\n";	
		
		for ($i=0; $i<$genCount; $i++) {
			echo "<tr>";
			printf("<td>%d</td><td>%d</td><td>%d</td>\n", $i, $generations[$i][0], $generations[$i][1]);
			echo "</tr>";
		}
		echo "</table>\n";
    }


    // Names table
    $R->seek($nameOffset);
    $names = [];
	$names2 = [];
    echo "*******Name Table ({$nameCount}:{$nameOffset})\n";

    // use a file resource to reuse your exact readNAME/readDWORD helpers
    $mem = fopen("php://memory", "r+");
    fwrite($mem, $bytes);
    rewind($mem);
    fseek($mem, $nameOffset);
	
	echo "<table border=1>\n";
	echo " <tr>\n";
	echo "  <td>Num</td><td>Name</td><td>Flags</td><td>Flag Value</td>\n";
	echo " </tr>\n";

    for ($i=0; $i<$nameCount; $i++) {
        $StrText = readNAME($mem, $version);
        $flags   = readDWORD($mem);
        $names[] = $StrText;
        $fgs     = GetObjectFlags($flags);
        $len     = strlen($StrText);
		$names2[] = ['name'=>$StrText,'flags'=>$fgs];
		
		echo " <tr>\n";
        echo "  <td>$i (0x".strtoupper(str_pad(dechex($i),2,'0',STR_PAD_LEFT)).")</td><td>".$StrText."</td><td>".$fgs."</td><td>0x".strtoupper(str_pad(dechex($flags),8,'0',STR_PAD_LEFT))."</td>";
		echo " </tr>\n";
    }
    echo "</table>\n";

// Exports
    $R->seek($exportOffset);
    echo "*******Export Table ({$exportCount}:{$exportOffset})\n";
	echo "<table border=1>\n";
	echo " <tr>\n";
	echo "  <td>Group</td><td>Name</td><td>Class</td><td>Num</td><td>Super</td><td>Size</td><td>Offset</td><td>Flags</td><td>Flags Value</td>\n";
	echo " </tr>\n";	
	
	
    for ($i=0; $i<$exportCount; $i++) {
        $cls = $R->INDEX();
        $sup = $R->INDEX();
        $pkg = $R->INDEX();
        $on_idx = $R->INDEX(); $on_num = $R->INDEX();
        $flg = $R->U32();
        $ssz = $R->INDEX();
        $sof = ($ssz > 0) ? $R->INDEX() : 0;

        $name = nameText($names2, $on_idx, $on_num);
		printf("<tr><td></td><td>%-20s</td><td></td><td></td><td></td><td>(0x%02X)</td><td>%5d</td><td>0x%08X</td><td>%s</td></tr>\n", $name, $cls, ($cls & 0xFF),$ssz, strtoupper(str_pad(dechex($flg),8,'0',STR_PAD_LEFT)), GetObjectFlags($flg));

        /*
		printf("%-12s %-6s %4d (0x%02X)  %5d  0x%08X  \n",
            refIndex($pkg),
            $name,
            "Class",
            $cls, ($cls & 0xFF),
            $ssz,
            $sof,    
        );
		*/
    }
    echo "</table>\n";
	

    // Imports
    $R->seek($importOffset);
    echo "*******Import Table ({$importCount}:{$importOffset})\n";
    for ($i=0; $i<$importCount; $i++) {
        $cp_idx = $R->INDEX(); $cp_num = $R->INDEX();
        $cn_idx = $R->INDEX(); $cn_num = $R->INDEX();
        $pkgIdx = $R->INDEX();
        $nm_idx = $R->INDEX(); $nm_num = $R->INDEX();

        $cpTxt = nameText($names, $cp_idx, $cp_num);
        $cnTxt = nameText($names, $cn_idx, $cn_num);
        $nmTxt = nameText($names, $nm_idx, $nm_num);

        $pkgRef = refIndex($pkgIdx);
        printf("[%2d] Class=%s.%s Pkg=%s Name=%s\n", $i, $cpTxt, $cnTxt, $pkgRef, $nmTxt);
    }
    echo "\n";

    

} catch (Throwable $e) {
    echo $e->getMessage()."\n";
}
