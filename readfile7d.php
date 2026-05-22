<?php
/* UE package reader/debug viewer.
 * Shows header, Names, Exports, Imports using a small low-level parser.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx($v): string { return '0x' . str_pad(strtoupper(dechex((int)$v)), 8, '0', STR_PAD_LEFT); }
function u32hex($v): string { return '0x' . str_pad(strtoupper(dechex((int)$v & 0xFFFFFFFF)), 8, '0', STR_PAD_LEFT); }

function die_eof($fp, int $need): void {
    $pos = ftell($fp);
    throw new RuntimeException(sprintf('Unexpected EOF at %d reading %d bytes', $pos === false ? -1 : $pos, $need));
}
function readRaw($fp, int $n): string {
    if ($n < 0) throw new InvalidArgumentException('Negative read length.');
    if ($n === 0) return '';
    $s = fread($fp, $n);
    if ($s === false || strlen($s) !== $n) die_eof($fp, $n);
    return $s;
}
function readUnpack($fp, int $n, string $fmt): int { return (int)array_values(unpack($fmt, readRaw($fp, $n)))[0]; }
function U8($fp): int { return readUnpack($fp, 1, 'C'); }
function U16le($fp): int { return readUnpack($fp, 2, 'v'); }
function U32le($fp): int { return readUnpack($fp, 4, 'V'); }
function I32le($fp): int { $v = U32le($fp); return ($v & 0x80000000) ? ($v - 0x100000000) : $v; }

function INDEX_READ($fp): int {
    $b0 = U8($fp);
    $neg = ($b0 & 0x80) !== 0;
    $con = ($b0 & 0x40) !== 0;
    $val = ($b0 & 0x3F);
    $shift = 6;
    if ($con) {
        for ($i = 0; $i < 4; $i++) {
            $b = U8($fp);
            $val |= (($b & 0x7F) << $shift);
            if (($b & 0x80) === 0) break;
            $shift += 7;
        }
    }
    return $neg ? -$val : $val;
}

function readNullStr($fp): string {
    $s = '';
    for ($i = 0; $i < 65536; $i++) {
        $c = U8($fp);
        if ($c === 0) break;
        $s .= chr($c);
    }
    return $s;
}
function readStrN($fp, int $n): string { return $n > 0 ? readRaw($fp, $n) : ''; }

function readNAME($fp, int $version, &$flagsOut = 0): string {
    if ($version >= 64) {
        $lenPlusOne = U8($fp);
        $len = max(0, $lenPlusOne - 1);
        $txt = $len ? readStrN($fp, $len) : '';
        U8($fp); // trailing null
        $flagsOut = U32le($fp);
        return $txt;
    }
    $txt = readNullStr($fp);
    $flagsOut = U32le($fp);
    return $txt;
}

function readGUID($fp): string {
    $d1 = U32le($fp);
    $d2 = U16le($fp);
    $d3 = U16le($fp);
    $tail = readRaw($fp, 8);
    $hex = strtoupper(bin2hex($tail));
    return sprintf('%08X-%04X-%04X-%s-%s', $d1, $d2, $d3, substr($hex, 0, 4), substr($hex, 4, 12));
}

function readFName($fp): array {
    return ['index' => INDEX_READ($fp), 'number' => INDEX_READ($fp)];
}

function flags_pkg(int $v): string {
    $map = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000008 => 'PKG_ClientOptional',
        0x00000010 => 'PKG_ServerSideOnly',
        0x00000020 => 'PKG_Encrypted',
        0x02000000 => 'PKG_Need',
    ];
    $o = [];
    foreach ($map as $bit => $n) if ($v & $bit) $o[] = $n;
    return implode(', ', $o);
}
function flags_obj(int $v): string {
    $map = [
        0x00000001=>'RF_Transactional',0x00000002=>'RF_Unreachable',0x00000004=>'RF_Public',0x00000008=>'RF_TagImp',
        0x00000010=>'RF_TagExp',0x00000020=>'RF_SourceModified',0x00000040=>'RF_TagGarbage',0x00000200=>'RF_NeedLoad',
        0x00000400=>'RF_HighlightedName',0x00000800=>'RF_InSingularFunc',0x00001000=>'RF_Suppress',0x00002000=>'RF_InEndState',
        0x00004000=>'RF_Transient',0x00008000=>'RF_PreLoading',0x00010000=>'RF_LoadForClient',0x00020000=>'RF_LoadForServer',
        0x00040000=>'RF_LoadForEdit',0x00080000=>'RF_Standalone',0x00100000=>'RF_NotForClient',0x00200000=>'RF_NotForServer',
        0x00400000=>'RF_NotForEdit',0x00800000=>'RF_Destroyed',0x01000000=>'RF_NeedPostLoad',0x02000000=>'RF_HasStack',
        0x04000000=>'RF_Native',0x08000000=>'RF_Marked',0x10000000=>'RF_ErrorShutdown',0x20000000=>'RF_DebugPostLoad',
        0x40000000=>'RF_DebugSerialize',0x80000000=>'RF_DebugDestroy',
    ];
    $o = [];
    foreach ($map as $bit => $n) if ($v & $bit) $o[] = $n;
    return implode(', ', $o);
}

function nameText(array $names, int $idx): string { return ($idx >= 0 && isset($names[$idx])) ? (string)$names[$idx]['name'] : "(bad:$idx)"; }
function fnameText(array $names, array $fn): string {
    $s = nameText($names, (int)($fn['index'] ?? -1));
    if ((int)($fn['number'] ?? 0) > 0) $s .= '_' . (int)$fn['number'];
    return $s;
}
function groupFromDWORD(int $ref, array $names, array $imports, array $exports): string {
    if ($ref === 0) return 'None';
    if ($ref < 0) {
        $i = -$ref - 1;
        return isset($imports[$i]) ? fnameText($names, $imports[$i]['ObjectName']) : "Import[$i]?";
    }
    $e = $ref - 1;
    return isset($exports[$e]) ? nameText($names, $exports[$e]['ObjectNameIdx']) : "Export[$e]?";
}
function classNameFromRef(int $ref, array $names, array $imports, array $exports): string {
    if ($ref === 0) return 'None';
    if ($ref < 0) {
        $i = -$ref - 1;
        return isset($imports[$i]) ? fnameText($names, $imports[$i]['ObjectName']) : "Import[$i]?";
    }
    $e = $ref - 1;
    return isset($exports[$e]) ? nameText($names, $exports[$e]['ObjectNameIdx']) : "Export[$e]?";
}

function parsePackage(string $path): array {
    if (!is_file($path)) throw new RuntimeException('File not found: ' . $path);
    if (!is_readable($path)) throw new RuntimeException('File is not readable by PHP/Web Station: ' . $path);

    $fp = fopen($path, 'rb');
    if (!$fp) throw new RuntimeException('Cannot open file: ' . $path);

    try {
        $magic = U32le($fp);
        if ($magic !== 0x9E2A83C1) throw new RuntimeException('Not an Unreal package. Signature: ' . u32hex($magic));

        $verPacked = U32le($fp);
        $engineVer = $verPacked & 0xFFFF;
        $licenseVer = ($verPacked >> 16) & 0xFFFF;
        $pkgFlags = U32le($fp);
        $nameCount = U32le($fp);
        $nameOffset = U32le($fp);
        $exportCount = U32le($fp);
        $exportOffset = U32le($fp);
        $importCount = U32le($fp);
        $importOffset = U32le($fp);

        $fileSize = filesize($path);
        foreach ([['nameOffset',$nameOffset],['exportOffset',$exportOffset],['importOffset',$importOffset]] as [$label,$offset]) {
            if ($offset < 0 || $offset >= $fileSize) throw new RuntimeException('Invalid ' . $label . ': ' . $offset);
        }
        foreach ([['nameCount',$nameCount],['exportCount',$exportCount],['importCount',$importCount]] as [$label,$count]) {
            if ($count < 0 || $count > 100000) throw new RuntimeException('Invalid ' . $label . ': ' . $count);
        }

        fseek($fp, 0x24, SEEK_SET);
        $guid = '';
        $generations = [];
        $heritageCount = null;
        $heritageOffset = null;

        if ($engineVer < 68) {
            $heritageCount = U32le($fp);
            $heritageOffset = U32le($fp);
            $save = ftell($fp);
            if ($heritageOffset > 0 && $heritageOffset < $fileSize && $heritageCount >= 0 && $heritageCount < 10000) {
                fseek($fp, $heritageOffset, SEEK_SET);
                for ($i = 0; $i < $heritageCount; $i++) $guid = readGUID($fp);
            }
            fseek($fp, $save, SEEK_SET);
        } else {
            $guid = readGUID($fp);
            $gcount = U32le($fp);
            if ($gcount < 0 || $gcount > 100000) throw new RuntimeException('Invalid generation count: ' . $gcount);
            for ($i = 0; $i < $gcount; $i++) $generations[] = ['Exports' => U32le($fp), 'Names' => U32le($fp)];
        }

        $names = [];
        fseek($fp, $nameOffset, SEEK_SET);
        for ($i = 0; $i < $nameCount; $i++) {
            $fl = 0;
            $txt = readNAME($fp, $engineVer, $fl);
            $names[] = ['name' => $txt, 'flags' => $fl];
        }

        $imports = [];
        fseek($fp, $importOffset, SEEK_SET);
        for ($i = 0; $i < $importCount; $i++) {
            $ClassPackage = readFName($fp);
            $ClassName = readFName($fp);
            $PackageIndex = INDEX_READ($fp);
            $ObjectName = readFName($fp);
            $imports[] = compact('ClassPackage', 'ClassName', 'PackageIndex', 'ObjectName');
        }

        $exports = [];
        fseek($fp, $exportOffset, SEEK_SET);
        for ($i = 0; $i < $exportCount; $i++) {
            $ClassIndex = INDEX_READ($fp);
            $SuperIndex = INDEX_READ($fp);
            $PackageDWORD = I32le($fp);
            $ObjectNameIdx = INDEX_READ($fp);
            $ObjectFlags = U32le($fp);
            $SerialSize = INDEX_READ($fp);
            $SerialOffset = ($SerialSize > 0) ? INDEX_READ($fp) : 0;
            $exports[] = compact('ClassIndex', 'SuperIndex', 'PackageDWORD', 'ObjectNameIdx', 'ObjectFlags', 'SerialSize', 'SerialOffset');
        }

        return compact('magic','verPacked','engineVer','licenseVer','pkgFlags','nameCount','nameOffset','exportCount','exportOffset','importCount','importOffset','guid','generations','heritageCount','heritageOffset','names','imports','exports');
    } finally {
        fclose($fp);
    }
}

$path = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
if ($path === '') {
    foreach ([__DIR__ . '/test.utx', __DIR__ . '/oldtest.utx', __DIR__ . '/uploads/test.utx'] as $candidate) {
        if (is_file($candidate)) { $path = $candidate; break; }
    }
}

$data = null;
$err = null;
if ($path !== '') {
    try { $data = parsePackage($path); } catch (Throwable $t) { $err = $t->getMessage(); }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>readfile7d.php</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:24px;background:#111;color:#ddd}input{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444;border-radius:4px}table{border-collapse:collapse;width:100%;margin:12px 0 24px}th,td{border:1px solid #333;padding:6px 8px;vertical-align:top;text-align:left}th{background:#222}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}.err{color:#ff9f9f;background:#2a1116;border:1px solid #55303a;border-radius:8px;padding:10px}.muted{color:#999}</style>
</head>
<body>
<h1>readfile7d.php</h1>
<form method="get"><label>File: <input type="text" name="file" value="<?=h($path)?>" style="width:620px"></label><input type="submit" value="Open"></form>
<?php if ($err): ?><div class="err"><strong>Error:</strong> <?=h($err)?></div><?php endif; ?>
<?php if (!$data): ?><p class="muted">Enter a full Synology path, for example <span class="mono">/volume1/web/ut_reader/uploads/test.utx</span>.</p></body></html><?php exit; endif; ?>

<h3>Unreal File found.</h3>
<table>
<tr><th>Version</th><td><?=h($data['engineVer'])?></td></tr>
<tr><th>License mode</th><td><?=h($data['licenseVer'])?></td></tr>
<tr><th>Package flags</th><td class="mono"><?=h(u32hex($data['pkgFlags']) . ' ' . flags_pkg($data['pkgFlags']))?></td></tr>
<tr><th>Name count</th><td><?=h($data['nameCount'])?></td></tr>
<tr><th>Name offset</th><td><?=h($data['nameOffset'])?></td></tr>
<tr><th>Export count</th><td><?=h($data['exportCount'])?></td></tr>
<tr><th>Export offset</th><td><?=h($data['exportOffset'])?></td></tr>
<tr><th>Import count</th><td><?=h($data['importCount'])?></td></tr>
<tr><th>Import offset</th><td><?=h($data['importOffset'])?></td></tr>
<tr><th>GUID</th><td class="mono"><?=h($data['guid'])?></td></tr>
<?php if ($data['engineVer'] < 68): ?>
<tr><th>Heritage count</th><td><?=h($data['heritageCount'])?></td></tr>
<tr><th>Heritage offset</th><td><?=h($data['heritageOffset'])?></td></tr>
<?php else: ?>
<tr><th>Generation count</th><td><?=count($data['generations'])?></td></tr>
<?php endif; ?>
</table>

<?php if ($data['engineVer'] >= 68 && $data['generations']): ?>
<h3>Generations</h3>
<table><thead><tr><th>#</th><th>Names</th><th>Exports</th></tr></thead><tbody>
<?php foreach ($data['generations'] as $i => $g): ?>
<tr><td class="mono"><?=h($i)?></td><td><?=h($g['Names'])?></td><td><?=h($g['Exports'])?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h3>Name Table (<?=h($data['nameCount'])?>:<?=h($data['nameOffset'])?>)</h3>
<table><thead><tr><th>#</th><th>Name</th><th>Len</th><th>Flags</th><th>Flags text</th></tr></thead><tbody>
<?php foreach ($data['names'] as $i => $n): ?>
<tr><td class="mono"><?=h($i)?></td><td><?=h($n['name'])?></td><td><?=strlen($n['name'])?></td><td class="mono"><?=h(u32hex($n['flags']))?></td><td><?=h(flags_obj($n['flags']))?></td></tr>
<?php endforeach; ?>
</tbody></table>

<h3>Export Table (<?=h($data['exportCount'])?>:<?=h($data['exportOffset'])?>)</h3>
<table><thead><tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th></tr></thead><tbody>
<?php foreach ($data['exports'] as $i => $e):
    $groupTxt = groupFromDWORD($e['PackageDWORD'], $data['names'], $data['imports'], $data['exports']);
    $nameTxt = nameText($data['names'], $e['ObjectNameIdx']);
    $classTxt = classNameFromRef($e['ClassIndex'], $data['names'], $data['imports'], $data['exports']);
    $numTxt = sprintf('%d (0x%02X)', $i, $i & 0xFF);
    $superTxt = ($e['SuperIndex'] === 0) ? '0' : (($e['SuperIndex'] < 0) ? 'Import[' . (-$e['SuperIndex'] - 1) . ']' : 'Export[' . ($e['SuperIndex'] - 1) . ']');
?>
<tr><td><?=h($groupTxt)?></td><td><?=h($nameTxt)?></td><td><?=h($classTxt)?></td><td class="mono"><?=h($numTxt)?></td><td><?=h($superTxt)?></td><td class="mono"><?=h($e['SerialSize'])?></td><td class="mono"><?=h(u32hex($e['SerialOffset']))?></td><td><?=h(flags_obj($e['ObjectFlags']))?></td></tr>
<?php endforeach; ?>
</tbody></table>

<h3>Import Table (<?=h($data['importCount'])?>:<?=h($data['importOffset'])?>)</h3>
<table><thead><tr><th>#</th><th>Class</th><th>Pkg</th><th>Name</th></tr></thead><tbody>
<?php foreach ($data['imports'] as $i => $imp):
    $cp = fnameText($data['names'], $imp['ClassPackage']);
    $cn = fnameText($data['names'], $imp['ClassName']);
    $nm = fnameText($data['names'], $imp['ObjectName']);
    $pkg = $imp['PackageIndex'];
    $pkgTxt = ($pkg === 0) ? 'None' : (($pkg > 0) ? ('Export[' . ($pkg - 1) . ']') : ('Import[' . (-$pkg - 1) . ']'));
?>
<tr><td class="mono"><?=h($i)?></td><td><?=h($cp . '.' . $cn)?></td><td><?=h($pkgTxt)?></td><td><?=h($nm)?></td></tr>
<?php endforeach; ?>
</tbody></table>
</body>
</html>
