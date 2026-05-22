<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300');

require_once __DIR__ . '/TUnrealPackage.php';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hx($v): string {
    return sprintf('0x%08X', (int)$v);
}

function row_raw(array $row): array {
    return isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row;
}

function row_view(array $row): array {
    return isset($row['view']) && is_array($row['view']) ? $row['view'] : [];
}

function row_int(array $row, array $keys, int $default = 0): int {
    $raw = row_raw($row);
    foreach ($keys as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) return (int)$row[$k];
        if (isset($raw[$k]) && is_numeric($raw[$k])) return (int)$raw[$k];
    }
    return $default;
}

function row_text(array $row, array $keys, string $default = ''): string {
    $view = row_view($row);
    $raw = row_raw($row);
    foreach ($keys as $k) {
        foreach ([$view, $row, $raw] as $src) {
            if (!array_key_exists($k, $src)) continue;
            $v = $src[$k];
            if (is_string($v) || is_numeric($v)) return (string)$v;
            if (is_array($v)) {
                foreach (['text', 'base', 'name'] as $tk) {
                    if (!empty($v[$tk])) return (string)$v[$tk];
                }
            }
        }
    }
    return $default;
}

function name_at(array $names, int $idx): string {
    if ($idx < 0 || !isset($names[$idx])) return '';
    $row = (array)$names[$idx];
    $raw = row_raw($row);
    return (string)($row['name'] ?? $raw['name'] ?? '');
}

function object_ref_name(int $ref, array $imports, array $exports, array $names): string {
    if ($ref === 0) return '';
    if ($ref > 0) {
        $i = $ref - 1;
        if (!isset($exports[$i])) return '';
        $ex = (array)$exports[$i];
        $txt = row_text($ex, ['objectNameText', 'ObjectNameText', 'name', 'Name'], '');
        if ($txt !== '' && !is_numeric($txt)) return $txt;
        return name_at($names, row_int($ex, ['objectName', 'ObjectName', 'nameIndex', 'NameIndex'], -1));
    }

    $i = -$ref - 1;
    if (!isset($imports[$i])) return '';
    $im = (array)$imports[$i];
    $txt = row_text($im, ['objectNameText', 'ObjectNameText', 'name', 'Name'], '');
    if ($txt !== '' && !is_numeric($txt)) return $txt;
    return name_at($names, row_int($im, ['objectName', 'ObjectName', 'nameIndex', 'NameIndex'], -1));
}

function object_ref_path(int $ref, array $imports, array $exports, array $names): string {
    $parts = [];
    $seen = [];
    for ($depth = 0; $ref !== 0 && $depth < 64; $depth++) {
        if (isset($seen[$ref])) {
            $parts[] = '__CYCLE__';
            break;
        }
        $seen[$ref] = true;
        $name = object_ref_name($ref, $imports, $exports, $names);
        if ($name !== '') $parts[] = $name;

        if ($ref > 0) {
            $i = $ref - 1;
            if (!isset($exports[$i])) break;
            $ref = row_int((array)$exports[$i], ['outerIndex', 'OuterIndex', 'packageIndex', 'PackageIndex', 'outer'], 0);
        } else {
            $i = -$ref - 1;
            if (!isset($imports[$i])) break;
            $ref = row_int((array)$imports[$i], ['outerIndex', 'OuterIndex', 'outer'], 0);
        }
    }
    return implode('.', array_reverse(array_filter($parts, fn($s) => $s !== '')));
}

$filePath = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
if ($filePath === '') {
    foreach ([__DIR__ . '/test.ut2', __DIR__ . '/test.utx', __DIR__ . '/test.ut3'] as $candidate) {
        if (is_file($candidate)) {
            $filePath = $candidate;
            break;
        }
    }
}

$err = null;
$pkg = null;
$hdr = [];
$names = [];
$imports = [];
$exports = [];

if ($filePath !== '') {
    try {
        if (!is_file($filePath)) {
            throw new RuntimeException('File not found: ' . $filePath);
        }
        if (!is_readable($filePath)) {
            throw new RuntimeException('File is not readable by PHP/Web Station: ' . $filePath);
        }

        $pkg = TPackageReader::open($filePath);
        if (method_exists($pkg, 'annotateTablesWithText')) {
            $pkg->annotateTablesWithText();
        }
        $hdr = $pkg->getHeader();
        $names = $pkg->getNames();
        $imports = $pkg->getImports();
        $exports = $pkg->getExports();
    } catch (Throwable $t) {
        $err = $t->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE2 Package Viewer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--b:#d0d7de;--bg:#fff;--muted:#f6f8fa;--text:#24292f;--sub:#57606a;--accent:#0969da;--err:#b00020}html,body{background:var(--bg);color:var(--text)}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.35;padding:16px}h1,h2{margin:.4em 0}.small{font-size:12px;color:var(--sub)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}table{border-collapse:collapse;width:100%;margin:12px 0 24px}th,td{border:1px solid var(--b);padding:6px 8px;vertical-align:top;font-size:14px}th{background:var(--muted);text-align:left}.warn{background:#fff7ed;border:1px solid #fed7aa;padding:10px;border-radius:8px}.err{background:#fff1f2;color:var(--err);border:1px solid #fecdd3;padding:10px;border-radius:8px}.toggle-btn{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--b);border-radius:6px;padding:3px 8px;cursor:pointer;font-size:13px;color:var(--text)}.toggle-btn:hover{border-color:var(--accent);color:var(--accent)}.props-row{display:none;background:#fcfcfd}.props-wrap{padding:10px 4px}.nested{width:100%;border-collapse:collapse;margin:6px 0 0}.nested th,.nested td{border:1px dashed var(--b);font-size:13px;background:#E5F2FF}.nested th{background:#69BEFF}.pill{display:inline-block;padding:2px 6px;border-radius:999px;background:#e7f5ff;color:#0b74c9;font-size:12px;border:1px solid #b6dfff}.raw{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;max-width:48rem}form{margin:0 0 16px}input[type=text]{width:620px;max-width:90%;padding:6px 8px}</style>
</head>
<body>
<h1>UE2 Package Viewer</h1>
<form method="get">
    <label>File: <input type="text" name="file" value="<?=h($filePath)?>"></label>
    <input type="submit" value="Open">
</form>

<?php if ($err): ?>
    <div class="err"><strong>Error:</strong> <?=h($err)?></div>
<?php endif; ?>

<?php if (!$pkg): ?>
    <p class="small">Enter a full Synology path, for example <span class="mono">/volume1/web/ut_reader/uploads/test.utx</span>.</p>
</body>
</html>
<?php exit; endif; ?>

<div class="small"><?=h($filePath)?><?=is_file($filePath) ? ' (' . number_format(filesize($filePath)) . ' bytes)' : ''?></div>

<h2>Header</h2>
<table>
<tbody>
<tr><th>Signature</th><td class="mono"><?=h(hx((int)($hdr['signature'] ?? $hdr['tag'] ?? 0)))?></td><td class="small"><?=h($hdr['signature'] ?? $hdr['tag'] ?? '')?></td></tr>
<tr><th>Version</th><td class="mono"><?=h($hdr['version'] ?? '')?></td><td class="small"><?=h($hdr['version'] ?? '')?></td></tr>
<tr><th>Licensee</th><td class="mono"><?=h($hdr['licensee'] ?? $hdr['licenseeVersion'] ?? '')?></td><td class="small"><?=h($hdr['licensee'] ?? $hdr['licenseeVersion'] ?? '')?></td></tr>
<tr><th>Package Flags</th><td class="mono"><?=h(hx((int)($hdr['pkgFlags'] ?? $hdr['packageFlags'] ?? 0)))?></td><td class="small"><?=h($hdr['pkgFlags'] ?? $hdr['packageFlags'] ?? '')?></td></tr>
<tr><th>Name Count / Offset</th><td class="mono"><?=h($hdr['nameCount'] ?? count($names))?> / <?=h($hdr['nameOffset'] ?? '')?></td><td class="small"><?=h($hdr['nameCount'] ?? count($names))?> / <?=h($hdr['nameOffset'] ?? '')?></td></tr>
<tr><th>Export Count / Offset</th><td class="mono"><?=h($hdr['exportCount'] ?? count($exports))?> / <?=h($hdr['exportOffset'] ?? '')?></td><td class="small"><?=h($hdr['exportCount'] ?? count($exports))?> / <?=h($hdr['exportOffset'] ?? '')?></td></tr>
<tr><th>Import Count / Offset</th><td class="mono"><?=h($hdr['importCount'] ?? count($imports))?> / <?=h($hdr['importOffset'] ?? '')?></td><td class="small"><?=h($hdr['importCount'] ?? count($imports))?> / <?=h($hdr['importOffset'] ?? '')?></td></tr>
<?php if (!empty($hdr['guid'])): ?><tr><th>GUID</th><td class="mono"><?=h($hdr['guid'])?></td><td class="small"><?=h($hdr['guid'])?></td></tr><?php endif; ?>
</tbody>
</table>

<?php if (!empty($hdr['generations']) && is_array($hdr['generations'])): ?>
<h2>Generations (<?=count($hdr['generations'])?>)</h2>
<table><thead><tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th class="small">Raw</th></tr></thead><tbody>
<?php foreach ($hdr['generations'] as $i => $g): $g=(array)$g; ?>
<tr><td><?=h($g['exportCount'] ?? '')?></td><td><?=h($g['nameCount'] ?? '')?></td><td class="mono"><?=h($i)?></td><td class="small mono"><?=h(json_encode($g))?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Names (<?=count($names)?>)</h2>
<table>
<thead><tr><th>Name</th><th>Flags</th><th>Num.</th><th class="small">Raw</th></tr></thead>
<tbody>
<?php foreach ($names as $i => $n): $n=(array)$n; $raw=row_raw($n); $idx=(int)($n['index'] ?? $i); $flags=(int)($n['flags'] ?? $raw['flags'] ?? 0); ?>
<tr><td><?=h($n['name'] ?? $raw['name'] ?? '')?></td><td class="mono"><?=h(hx($flags))?></td><td class="mono"><?=h($idx . ' (' . hx($idx) . ')')?></td><td class="small mono"><?=h(json_encode($n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?></td></tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Imports (<?=count($imports)?>)</h2>
<table>
<thead><tr><th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th><th class="small">Raw refs</th></tr></thead>
<tbody>
<?php foreach ($imports as $i => $im):
    $im=(array)$im;
    $classPkgIdx=row_int($im, ['classPackage','ClassPackage'], -1);
    $classIdx=row_int($im, ['className','ClassName'], -1);
    $outerRef=row_int($im, ['outerIndex','OuterIndex','outer'], 0);
    $objIdx=row_int($im, ['objectName','ObjectName'], -1);
    $classPkg=name_at($names,$classPkgIdx);
    $className=name_at($names,$classIdx);
    $packageName=object_ref_path($outerRef,$imports,$exports,$names);
    $objectName=name_at($names,$objIdx);
?>
<tr><td><?=h($classPkg)?></td><td><?=h($className)?></td><td><?=h($packageName)?></td><td><?=h($objectName)?></td><td class="mono"><?=h($i . ' (' . hx($i) . ')')?></td><td class="mono small"><?=h($classPkgIdx . ' / ' . $classIdx . ' / ' . $outerRef . ' / ' . $objIdx)?></td></tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Exports (<?=count($exports)?>)</h2>
<table>
<thead><tr><th>Class Index</th><th>Super Index</th><th>Package Index</th><th>Object Name</th><th>Object Flags</th><th>Serial Size</th><th>Serial Offset</th><th>Num.</th><th>Properties</th><th class="small">Raw refs</th></tr></thead>
<tbody>
<?php foreach ($exports as $i => $ex):
    $ex=(array)$ex;
    $classRef=row_int($ex, ['classIndex','ClassIndex','class'], 0);
    $superRef=row_int($ex, ['superIndex','SuperIndex','super'], 0);
    $pkgRef=row_int($ex, ['packageIndex','PackageIndex','outerIndex','OuterIndex','outer'], 0);
    $objIdx=row_int($ex, ['objectName','ObjectName','nameIndex','NameIndex'], -1);
    $flags=row_int($ex, ['objectFlags','ObjectFlags','flags'], 0);
    $serialSize=row_int($ex, ['serialSize','SerialSize','size'], 0);
    $serialOffset=row_int($ex, ['serialOffset','SerialOffset','offset'], 0);

    $className=object_ref_name($classRef,$imports,$exports,$names);
    $superName=object_ref_name($superRef,$imports,$exports,$names);
    $packageName=object_ref_path($pkgRef,$imports,$exports,$names);
    $objectName=name_at($names,$objIdx);
    if ($objectName === '') $objectName = row_text($ex, ['objectNameText','name'], 'Export #' . $i);
    $props=[];
    $propError=null;
    if (method_exists($pkg, 'getExportProperties')) {
        try {
            $props = (array)($pkg->getExportProperties($i) ?? []);
        } catch (Throwable $t) {
            $propError = $t->getMessage();
        }
    }
    $hasProps=!empty($props) || $propError !== null;
?>
<tr>
<td class="mono"><?=h($className)?></td>
<td class="mono"><?=h($superName)?></td>
<td class="mono"><?=h($packageName)?></td>
<td class="mono"><?=h($objectName)?></td>
<td class="mono"><?=h(hx($flags))?></td>
<td class="mono"><?=h($serialSize)?></td>
<td class="mono"><?=h(hx($serialOffset))?></td>
<td class="mono"><?=h($i . ' (' . hx($i) . ')')?></td>
<td><?php if ($hasProps): ?><button type="button" class="toggle-btn" onclick="toggleProps(<?= (int)$i ?>)"><span>▶</span> Properties <span class="pill"><?=count($props)?></span></button><?php else: ?><span class="small mono">—</span><?php endif; ?></td>
<td class="mono small"><?=h($classRef . ' / ' . $superRef . ' / ' . $pkgRef . ' / ' . $objIdx . ' / ' . $flags . ' / ' . $serialSize . ' / ' . $serialOffset)?></td>
</tr>
<?php if ($hasProps): ?>
<tr id="props-<?= (int)$i ?>" class="props-row"><td colspan="10"><div class="props-wrap">
<?php if ($propError): ?><div class="warn">Property parse error: <?=h($propError)?></div><?php endif; ?>
<?php if ($props): ?>
<table class="nested"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>
<?php foreach ($props as $pi => $p): ?>
<tr><td class="mono"><?=h($pi)?></td><td class="raw mono"><?=h(is_scalar($p) ? (string)$p : json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div></td></tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>

<script>
function toggleProps(i){
  const row=document.getElementById('props-'+i);
  if(!row)return;
  row.style.display=(row.style.display==='table-row')?'none':'table-row';
}
</script>
</body>
</html>
