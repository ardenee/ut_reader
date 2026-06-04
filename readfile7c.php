<?php
/* UE2 package reader – follows the PDF export table layout you specified.
 * Keeps formatting (HTML tables) and $path="test.utx".
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$path = "test.utx";

/* ------------ low-level io helpers ------------ */
function die_eof($fp, $need) {
    $pos = ftell($fp);
    $sz  = fstat($fp)['size'] ?? -1;
    die(sprintf("ERROR: Unexpected EOF at %d reading %d bytes", $pos, $need));
}
function readRaw($fp, $n) {
    $s = fread($fp, $n);
    if ($s === false || strlen($s) !== $n) die_eof($fp,$n);
    return $s;
}
function readUnpack($fp, $n, $fmt) { return array_values(unpack($fmt, readRaw($fp,$n)))[0]; }

function U8($fp){ return readUnpack($fp,1,'C'); }
function U16le($fp){ return readUnpack($fp,2,'v'); }
function U32le($fp){ return readUnpack($fp,4,'V'); }
function I32le($fp){
    $v = U32le($fp);
    if ($v & 0x80000000) $v -= 0x100000000;
    return $v;
}

/* UE compact INDEX (Unreal’s “Compact Int”) */
function INDEX_READ($fp) : int {
    $b0  = U8($fp);
    $neg = ($b0 & 0x80) !== 0;
    $con = ($b0 & 0x40) !== 0;
    $val = ($b0 & 0x3F);
    if ($con) {
        $b1 = U8($fp); $val = ($val << 7) | ($b1 & 0x7F);
        if ($b1 & 0x80) {
            $b2 = U8($fp); $val = ($val << 7) | ($b2 & 0x7F);
            if ($b2 & 0x80) {
                $b3 = U8($fp); $val = ($val << 7) | ($b3 & 0x7F);
                if ($b3 & 0x80) {
                    $b4 = U8($fp); $val = ($val << 8) | $b4;
                }
            }
        }
    }
    return $neg ? -$val : $val;
}

/* strings */
function readNullStr($fp) : string {
    $s = '';
    while (true) {
        $c = U8($fp);
        if ($c === 0) break;
        $s .= chr($c);
    }
    return $s;
}
function readStrN($fp, $n) : string { return $n ? readRaw($fp,$n) : ''; }

/* NAME entry (v>=64: [len+1][bytes][0x00][Flags U32]; else ASCIIZ + Flags) */
function readNAME($fp, int $version, &$flagsOut=0) : string {
    if ($version >= 64) {
        $lenPlusOne = U8($fp);
        $len = max(0, $lenPlusOne - 1);
        $txt = $len ? readStrN($fp,$len) : '';
        $nul = U8($fp); // trailing 0
        $flagsOut = U32le($fp);
        return $txt;
    } else {
        $txt = readNullStr($fp);
        $flagsOut = U32le($fp);
        return $txt;
    }
}

/* GUID (little endian fields -> standard text) */
function readGUID($fp) : string {
    $d1 = U32le($fp);
    $d2 = U16le($fp);
    $d3 = U16le($fp);
    $tail = readRaw($fp,8);
    $hex  = strtoupper(bin2hex($tail));
    $hi   = substr($hex,0,4);
    $lo   = substr($hex,4,12);
    return sprintf("%08X-%04X-%04X-%s-%s", $d1, $d2, $d3, $hi, $lo);
}

/* FName (for import fields): INDEX name + INDEX number */
function readFName($fp) : array {
    $idx = INDEX_READ($fp);
    $num = INDEX_READ($fp);
    return ['index'=>$idx, 'number'=>$num];
}

/* ---- flags text ---- */
function flags_pkg($v) : string {
    $map = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000008 => 'PKG_ClientOptional',
        0x00000010 => 'PKG_ServerSideOnly',
        0x00000020 => 'PKG_Encrypted',
        0x02000000 => 'PKG_Need',
    ];
    $o=[];
    foreach($map as $bit=>$n) if ($v & $bit) $o[]=$n;
    return '['.implode(',',$o).']';
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

/* ---- resolvers ---- */
function nameText(array $names, int $idx) : string {
    return ($idx>=0 && $idx<count($names)) ? $names[$idx]['name'] : "(bad:$idx)";
}
function fnameText(array $names, array $fn) : string {
    $s = nameText($names, $fn['index']);
    if ($fn['number']>0) $s .= "_".$fn['number'];
    return $s;
}

/* object ref rule for DWORD-encoded package in exports (per your PDF): 0=None, <0=Import[-i-1], >0=Export[i-1] */
function refToText_DWORD(int $ref, array $names, array $imports, array $exports) : string {
    if ($ref === 0) return 'None';
    if ($ref < 0) {
        $i = -$ref - 1;
        if (!isset($imports[$i])) return "Import[$i]?";
        return fnameText($names, $imports[$i]['ObjectName']);
    }
    $e = $ref - 1;
    if (!isset($exports[$e])) return "Export[$e]?";
    // group objects typically have a plain name
    return fnameText($names, ['index'=>$exports[$e]['ObjectNameIdx'], 'number'=>0]);
}
function refToClassName(int $ref, array $names, array $imports, array $exports) : string {
    if ($ref === 0) return 'None';
    if ($ref < 0) {
        $i = -$ref - 1;
        if (!isset($imports[$i])) return "Import[$i]?";
        return fnameText($names, $imports[$i]['ClassName']);
    }
    $e = $ref - 1;
    if (!isset($exports[$e])) return "Export[$e]?";
    // If class is an export (rare for these), fall back to its name
    return fnameText($names, ['index'=>$exports[$e]['ObjectNameIdx'], 'number'=>0]);
}

/* ===================================================== */
/* open + read summary                                   */
/* ===================================================== */
$fp = @fopen($path,'rb');
if (!$fp) die("Cannot open $path");

$magic = U32le($fp);
if ($magic !== 0x9E2A83C1) die("Not an Unreal package");

$verPacked   = U32le($fp);     // 0x001D007F for your sample
$engineVer   = $verPacked & 0xFFFF;
$licenseVer  = ($verPacked >> 16) & 0xFFFF;

$pkgFlags    = U32le($fp);

$nameCount   = U32le($fp);
$nameOffset  = U32le($fp);
$exportCount = U32le($fp);
$exportOffset= U32le($fp);
$importCount = U32le($fp);
$importOffset= U32le($fp);

/* generations / heritage + GUID (PDF) */
fseek($fp, 0x24, SEEK_SET); // immediately after ImportOffset in summary
$guid = '';
$generations = [];

if ($engineVer < 68) {
    $heritageCount = U32le($fp);
    $heritageOffset= U32le($fp);
    $save = ftell($fp);
    fseek($fp, $heritageOffset, SEEK_SET);
    for ($i=0;$i<$heritageCount;$i++) $guid = readGUID($fp); // last one
    fseek($fp,$save,SEEK_SET);
} else {
    $guid = readGUID($fp);
    $gcount = U32le($fp);
    for ($i=0;$i<$gcount;$i++) {
        $exp = U32le($fp);
        $nam = U32le($fp);
        $generations[] = ['Exports'=>$exp,'Names'=>$nam];
    }
}

/* load names upfront (needed to resolve everything nicely) */
$names = [];
fseek($fp, $nameOffset, SEEK_SET);
for ($i=0;$i<$nameCount;$i++) {
    $fl=0;
    $txt = readNAME($fp, $engineVer, $fl);
    $names[] = ['name'=>$txt,'flags'=>$fl];
}

/* --- parse imports so export “Class/Group/Super” can use them --- */
$imports = [];
fseek($fp, $importOffset, SEEK_SET);
for ($i=0;$i<$importCount;$i++) {
    // UE2 Import: FName ClassPackage ; FName ClassName ; INDEX PackageIndex ; FName ObjectName
    $ClassPackage = readFName($fp);
    $ClassName    = readFName($fp);
    $PackageIndex = INDEX_READ($fp); // (this one is INDEX in imports)
    $ObjectName   = readFName($fp);
    $imports[] = compact('ClassPackage','ClassName','PackageIndex','ObjectName');
}

/* --- parse exports (PDF field types) --- */
$exports = [];
fseek($fp, $exportOffset, SEEK_SET);
for ($i=0;$i<$exportCount;$i++) {
    $ClassIndex    = INDEX_READ($fp);     // INDEX
    $SuperIndex    = INDEX_READ($fp);     // INDEX
    $PackageDWORD  = I32le($fp);          // **DWORD** (signed) per your PDF note
    $ObjectNameIdx = INDEX_READ($fp);     // INDEX into name table
    $ObjectFlags   = U32le($fp);          // DWORD
    $SerialSize    = INDEX_READ($fp);     // INDEX
    $SerialOffset  = ($SerialSize>0) ? INDEX_READ($fp) : 0; // INDEX if size>0

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

/* ======================= OUTPUT ======================= */
echo "<pre>";
echo "Unreal File found.\n\n";
echo "Version: $engineVer\n";
echo "License mode: $licenseVer\n";
echo "Package flags: 0x".str_pad(strtoupper(dechex($pkgFlags)),8,'0',STR_PAD_LEFT)." ".flags_pkg($pkgFlags)."\n";
echo "Name count: $nameCount\n";
echo "Name offset: $nameOffset\n";
echo "Export count: $exportCount\n";
echo "Export offset: $exportOffset\n";
echo "Import count: $importCount\n";
echo "Import offset: $importOffset\n\n";
echo "GUID: $guid\n\n";
if ($engineVer < 68) {
    echo "Heritage count: $heritageCount\n";
    echo "Heritage offset: $heritageOffset\n\n";
} else {
    echo "Generation count: ".count($generations)."\n";
    foreach ($generations as $i=>$g) {
        echo "Generation[$i] Exports={$g['Exports']} Names={$g['Names']}\n";
    }
    echo "\n";
}
echo "</pre>";

/* Names table */
echo '<h3>*******Name Table ('.$nameCount.':'.$nameOffset.')</h3>';
echo '<table border="1" cellpadding="4" cellspacing="0">';
echo '<tr><th>#</th><th>Name</th><th>Len</th><th>Flags (hex)</th><th>Flags</th></tr>';
for ($i=0;$i<$nameCount;$i++) {
    $n=$names[$i]['name']; $fl=$names[$i]['flags'];
    echo '<tr>';
    echo '<td>'.$i.'</td>';
    echo '<td>'.htmlspecialchars($n).'</td>';
    echo '<td>'.strlen($n).'</td>';
    echo '<td>0x'.str_pad(strtoupper(dechex($fl)),8,'0',STR_PAD_LEFT).'</td>';
    echo '<td>'.htmlspecialchars(flags_obj($fl)).'</td>';
    echo '</tr>';
}
echo '</table>';

/* Export table FIRST */
echo '<h3>*******Export Table ('.$exportCount.':'.$exportOffset.')</h3>';
echo '<table border="1" cellpadding="4" cellspacing="0">';
echo '<tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th></tr>';
for ($i=0;$i<$exportCount;$i++) {
    $e = $exports[$i];

    // Group from PackageDWORD (DWORD object ref rule)
    $groupTxt = refToText_DWORD($e['PackageDWORD'], $names, $imports, $exports);

    // Name from NameTable index
    $nameTxt  = nameText($names, $e['ObjectNameIdx']);

    // Class name from ClassIndex ref (prefer Import.ClassName)
    $className = refToClassName($e['ClassIndex'], $names, $imports, $exports);

    // “Num.” = raw class index value (decimal + hex) – matches your column spec
    $numTxt = sprintf("%d (0x%02X)", $e['ClassIndex'], $e['ClassIndex'] & 0xFF);

    // Super as object-ref text (show raw reference form)
    $superTxt = ($e['SuperIndex']===0) ? '0' : (($e['SuperIndex']<0) ? 'Import['.(-$e['SuperIndex']-1).']' : 'Export['.($e['SuperIndex']-1).']');
    $sizeTxt  = $e['SerialSize'];
    $offTxt   = '0x'.str_pad(strtoupper(dechex($e['SerialOffset'])),8,'0',STR_PAD_LEFT);
    $flagTxt  = flags_obj($e['ObjectFlags']);

    echo '<tr>';
    echo '<td>'.htmlspecialchars($groupTxt).'</td>';
    echo '<td>'.htmlspecialchars($nameTxt).'</td>';
    echo '<td>'.htmlspecialchars($className).'</td>';
    echo '<td>'.htmlspecialchars($numTxt).'</td>';
    echo '<td>'.htmlspecialchars($superTxt).'</td>';
    echo '<td>'.htmlspecialchars($sizeTxt).'</td>';
    echo '<td>'.htmlspecialchars($offTxt).'</td>';
    echo '<td>'.htmlspecialchars($flagTxt).'</td>';
    echo '</tr>';
}
echo '</table>';

/* Import table */
echo '<h3>*******Import Table ('.$importCount.':'.$importOffset.')</h3>';
echo '<table border="1" cellpadding="4" cellspacing="0">';
echo '<tr><th>#</th><th>Class</th><th>Pkg</th><th>Name</th></tr>';
for ($i=0;$i<$importCount;$i++) {
    $imp = $imports[$i];
    $cp  = fnameText($names, $imp['ClassPackage']);
    $cn  = fnameText($names, $imp['ClassName']);
    $nm  = fnameText($names, $imp['ObjectName']);
    $pkg = $imp['PackageIndex'];
    $pkgTxt = ($pkg===0) ? 'None' : (($pkg>0) ? ('Export['.($pkg-1).']') : ('Import['.(-$pkg-1).']'));

    echo '<tr>';
    echo '<td>'.$i.'</td>';
    echo '<td>'.htmlspecialchars($cp.'.'.$cn).'</td>';
    echo '<td>'.htmlspecialchars($pkgTxt).'</td>';
    echo '<td>'.htmlspecialchars($nm).'</td>';
    echo '</tr>';
}
echo '</table>';
?>
