<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader4.php';

$uploadDir = __DIR__ . '/uploads';
$allowedExt = ['uasset', 'umap'];

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx($v): string { return sprintf('0x%08X', (int)$v); }
function clean_file_name(string $name): string { return trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename(str_replace('\\', '/', rawurldecode($name)))) ?? '', " .\t\n\r\0\x0B"); }
function package_files(string $uploadDir, array $allowedExt): array
{
    if (!is_dir($uploadDir)) return [];
    $files = [];
    foreach (scandir($uploadDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $uploadDir . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($path) || !in_array($ext, $allowedExt, true)) continue;
        $files[] = ['name'=>$file, 'path'=>$path, 'size'=>filesize($path) ?: 0, 'mtime'=>filemtime($path) ?: 0];
    }
    usort($files, static fn($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcasecmp($a['name'], $b['name']));
    return $files;
}
function resolve_package(string $uploadDir, array $files): string
{
    $param = isset($_GET['file']) ? clean_file_name((string)$_GET['file']) : '';
    if ($param !== '') {
        $candidate = $uploadDir . DIRECTORY_SEPARATOR . $param;
        if (is_file($candidate)) return $candidate;
    }
    foreach (['test.uasset', 'test.umap'] as $default) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . $default;
        if (is_file($candidate)) return $candidate;
    }
    return $files[0]['path'] ?? '';
}
function object_id(int $ref): string { return $ref < 0 ? 'import-' . abs($ref) : 'export-' . $ref; }
function name_id(int $idx): string { return 'name-' . $idx; }
function ref_text(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) return '';
    $name = $pkg->displayNameFromRef($ref);
    return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
}
function ref_link(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) return '';
    return '<a href="#' . h(object_id($ref)) . '">' . h(ref_text($pkg, $ref)) . '</a>';
}
function name_link(UnrealPackageReader4 $pkg, array $name): string
{
    $idx = (int)($name['index'] ?? -1);
    $num = (int)($name['number'] ?? 0);
    $text = (string)($name['text'] ?? ($idx >= 0 ? $pkg->nameByIndex($idx, $num) : ''));
    if ($idx < 0) return h($text);
    return '<a href="#' . h(name_id($idx)) . '">' . h($text) . '</a> <span class="tag">#' . h($idx) . '</span>';
}
function serial_preview(string $packagePath, array $hdr, array $ex): array
{
    $serialSize = (int)($ex['serialSize'] ?? 0);
    $serialOffset = (int)($ex['serialOffset'] ?? 0);
    $uassetSize = is_file($packagePath) ? (filesize($packagePath) ?: 0) : 0;
    $uexpPath = (string)($hdr['uexpPath'] ?? '');
    $totalHeaderSize = (int)($hdr['totalHeaderSize'] ?? 0);

    if ($serialSize <= 0) return ['state'=>'none', 'source'=>'', 'mode'=>'', 'start'=>0, 'end'=>0, 'fileSize'=>0, 'hex'=>'', 'warning'=>''];

    $candidates = [['mode'=>'uasset:absolute', 'path'=>$packagePath, 'offset'=>$serialOffset]];
    if ($uexpPath !== '' && is_file($uexpPath)) {
        $candidates[] = ['mode'=>'uexp:absolute', 'path'=>$uexpPath, 'offset'=>$serialOffset];
        $candidates[] = ['mode'=>'uexp:offset-totalHeader', 'path'=>$uexpPath, 'offset'=>$serialOffset - $totalHeaderSize];
        $candidates[] = ['mode'=>'uexp:offset-uassetSize', 'path'=>$uexpPath, 'offset'=>$serialOffset - $uassetSize];
    }

    foreach ($candidates as $c) {
        $path = (string)$c['path'];
        $offset = (int)$c['offset'];
        $fileSize = is_file($path) ? (filesize($path) ?: 0) : 0;
        if ($fileSize <= 0 || $offset < 0 || $offset >= $fileSize) continue;
        $readLen = min(64, $serialSize, $fileSize - $offset);
        $hex = '';
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            @fseek($fh, $offset);
            $data = $readLen > 0 ? (string)@fread($fh, $readLen) : '';
            @fclose($fh);
            $hex = strtoupper(trim(chunk_split(bin2hex($data), 2, ' ')));
        }
        return ['state'=>'ok', 'source'=>basename($path), 'mode'=>(string)$c['mode'], 'start'=>$offset, 'end'=>$offset + $serialSize, 'fileSize'=>$fileSize, 'hex'=>$hex, 'warning'=>($offset + $serialSize > $fileSize ? 'range exceeds file' : '')];
    }

    return ['state'=>'missing', 'source'=>'', 'mode'=>'', 'start'=>$serialOffset, 'end'=>$serialOffset + $serialSize, 'fileSize'=>$uassetSize, 'hex'=>'', 'warning'=>($uexpPath !== '' && is_file($uexpPath)) ? 'range not found' : 'missing .uexp'];
}

$files = package_files($uploadDir, $allowedExt);
$filePath = resolve_package($uploadDir, $files);
if ($filePath === '' || !is_file($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "No .uasset/.umap found. Upload one in UE4/upload.php.\n";
    exit;
}

$pkg = new UnrealPackageReader4($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE4 Clean Viewer - <?= h(basename($filePath)) ?></title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;margin:0;padding:12px;color:#071629}.viewer{background:#fff;border:1px solid #ccd6dd}.toolbar{display:flex;justify-content:space-between;gap:12px;padding:10px;border-bottom:1px solid #ccd6dd;background:#fbfbfb}.tabs{display:flex;background:#f6f8fa;border-bottom:1px solid #ccd6dd}.tabs button{padding:10px 16px;border:0;border-right:1px solid #ccd6dd;background:transparent;font-weight:700;cursor:pointer}.tabs button.active{background:#fff;color:#0969da;box-shadow:inset 0 -2px 0 #0969da}.panel{display:none;padding:16px}.panel.active{display:block}.mono{font-family:Consolas,Menlo,monospace}.small{font-size:12px;color:#57606a}.grid{display:grid;grid-template-columns:210px minmax(0,1fr);gap:8px 12px;max-width:1100px}.label{font-weight:700}.value{border:1px solid #ccd6dd;background:#fbfbfb;padding:6px 8px}.data{border-collapse:collapse;width:100%;margin-top:12px}.data th,.data td{border:1px solid #ccd6dd;padding:7px 9px;vertical-align:top}.data th{background:#f6f8fa;text-align:left}.data tr:nth-child(even){background:#fcfdff}.exports th:nth-child(1){width:55px}.exports th:nth-child(5){width:120px}.exports th:nth-child(6){width:90px}.exports th:nth-child(7){width:110px}.tag,.status{display:inline-block;border-radius:3px;padding:1px 5px;font-size:12px;border:1px solid #c7dff2;background:#edf6ff;color:#2f6f9f}.ok{background:#dafbe1;border-color:#aceebb;color:#116329}.bad{background:#fff1f0;border-color:#ffccc7;color:#8a1f11}.none{background:#f6f8fa;border-color:#d0d7de;color:#57606a}.warn{background:#fff8f8;border:1px solid #d1242f;padding:8px 12px;margin:12px 0}.raw{white-space:pre-wrap;overflow-wrap:anywhere}a{color:#0969da;text-decoration:none}a:hover{text-decoration:underline}details{min-width:310px}summary{cursor:pointer;color:#0969da;font-weight:600}.kv{display:grid;grid-template-columns:115px minmax(0,1fr);gap:3px 8px;margin-top:6px}.hex{white-space:pre-wrap;word-break:break-word;background:#f6f8fa;border:1px solid #d0d7de;padding:6px;margin:6px 0 0;max-height:150px;overflow:auto}.target{background:#fff3cd!important;outline:2px solid #f0c36d}
</style>
<script>
function show(id){document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('active',b.dataset.p===id));document.querySelectorAll('.panel').forEach(p=>p.classList.toggle('active',p.id===id));}
window.addEventListener('hashchange',()=>{const id=location.hash.slice(1);const el=document.getElementById(id);if(!el)return;document.querySelectorAll('.target').forEach(e=>e.classList.remove('target'));el.classList.add('target');if(id.startsWith('import-'))show('imports');else if(id.startsWith('export-'))show('exports');else if(id.startsWith('name-'))show('names');setTimeout(()=>el.scrollIntoView({block:'center'}),50);});
</script>
</head>
<body>
<div class="viewer">
<div class="toolbar"><form method="get"><select name="file"><?php foreach ($files as $f): ?><option value="<?= h($f['name']) ?>"<?= basename($filePath)===$f['name']?' selected':'' ?>><?= h($f['name']) ?> (<?= h(number_format((int)$f['size'])) ?> bytes)</option><?php endforeach; ?></select> <button type="submit">Open</button> <a href="upload.php">Upload</a></form><div class="mono"><?= h(basename($filePath)) ?></div></div>
<div class="tabs"><button class="active" data-p="summary" onclick="show('summary')">Summary</button><button data-p="names" onclick="show('names')">Names</button><button data-p="imports" onclick="show('imports')">Imports</button><button data-p="exports" onclick="show('exports')">Exports</button></div>
<section id="summary" class="panel active"><h2>UE4 Package Summary</h2><div class="grid"><div class="label">GUID</div><div class="value mono"><?= h($hdr['guid'] ?? '') ?></div><div class="label">Legacy Version</div><div class="value mono"><?= h($hdr['legacyFileVersion'] ?? '') ?></div><div class="label">UE4 Version</div><div class="value mono"><?= h($hdr['version'] ?? '') ?><?= !empty($hdr['unversioned'])?' (assumed; unversioned)':'' ?></div><div class="label">Package Flags</div><div class="value mono"><?= h(hx($hdr['packageFlags'] ?? 0)) ?></div><div class="label">Counts</div><div class="value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div><div class="label">Offsets</div><div class="value mono">N <?= h($hdr['nameOffset'] ?? '') ?> / I <?= h($hdr['importOffset'] ?? '') ?> / E <?= h($hdr['exportOffset'] ?? '') ?></div><div class="label">Total Header Size</div><div class="value mono"><?= h($hdr['totalHeaderSize'] ?? '') ?></div><div class="label">Asset Registry Offset</div><div class="value mono"><?= h($hdr['assetRegistryDataOffset'] ?? '') ?></div><div class="label">Bulk Data Start</div><div class="value mono"><?= h($hdr['bulkDataStartOffset'] ?? '') ?></div><div class="label">UEXP Pair</div><div class="value mono"><?= !empty($hdr['hasUexp']) ? h(basename((string)$hdr['uexpPath'])) : 'not found' ?></div></div><?php if ($issues): ?><div class="warn"><strong>Validation / Notes</strong><ul><?php foreach ($issues as $w): ?><li class="mono raw"><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?></section>
<section id="names" class="panel"><h2>Name Map</h2><table class="data"><thead><tr><th>#</th><th>Name</th><th>Offset</th><th>Hashes</th></tr></thead><tbody><?php foreach ($names as $n): ?><tr id="<?= h(name_id((int)$n['index'])) ?>"><td class="mono"><?= h($n['index']) ?></td><td class="mono"><?= h($n['name']) ?></td><td class="mono"><?= h($n['offset']) ?></td><td class="mono"><?= h(($n['nonCaseHash'] ?? '') . ' / ' . ($n['caseHash'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section id="imports" class="panel"><h2>Import Map</h2><table class="data"><thead><tr><th>Ref</th><th>Object</th><th>Class Package</th><th>Class</th><th>Outer</th><th>Offset</th></tr></thead><tbody><?php foreach ($imports as $im): $ref=(int)$im['ref']; ?><tr id="<?= h(object_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td><?= name_link($pkg, $im['objectName']) ?></td><td><?= name_link($pkg, $im['classPackage']) ?></td><td><?= name_link($pkg, $im['className']) ?></td><td><?= ref_link($pkg, (int)$im['outerIndex']) ?></td><td class="mono"><?= h($im['offset']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section id="exports" class="panel"><h2>Export Map</h2><table class="data exports"><thead><tr><th>Ref</th><th>Object</th><th>Class</th><th>Outer</th><th>Serial</th><th>Flags</th><th>Status</th><th>Details</th></tr></thead><tbody><?php foreach ($exports as $ex): $ref=(int)$ex['ref']; $p=serial_preview($filePath,$hdr,$ex); $statusClass=$p['state']==='ok'&&$p['warning']===''?'ok':($p['state']==='none'?'none':'bad'); ?><tr id="<?= h(object_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td><?= name_link($pkg, $ex['objectName']) ?></td><td><?= ref_link($pkg, (int)$ex['classIndex']) ?></td><td><?= ref_link($pkg, (int)$ex['outerIndex']) ?></td><td class="mono">size <?= h($ex['serialSize']) ?><br>offset <?= h($ex['serialOffset']) ?></td><td class="mono"><?= h(hx($ex['objectFlags'] ?? 0)) ?></td><td><span class="status <?= h($statusClass) ?>"><?= h($p['warning'] ?: strtoupper($p['state'])) ?></span></td><td><details><summary>Details</summary><div class="kv small"><div>Template</div><div><?= ref_link($pkg, (int)$ex['templateIndex']) ?: '<span class="status none">none</span>' ?></div><div>Source</div><div class="mono"><?= h($p['source'] ?: '-') ?></div><div>Mode</div><div class="mono"><?= h($p['mode'] ?: '-') ?></div><div>Local range</div><div class="mono"><?= h($p['start']) ?>..<?= h($p['end']) ?></div><div>File size</div><div class="mono"><?= h($p['fileSize']) ?></div><div>Booleans</div><div>forced <?= !empty($ex['forcedExport'])?'Y':'N' ?>, client <?= !empty($ex['notForClient'])?'no':'yes' ?>, server <?= !empty($ex['notForServer'])?'no':'yes' ?>, asset <?= $ex['isAsset'] === null ? '?' : (!empty($ex['isAsset'])?'Y':'N') ?></div><div>Package flags</div><div class="mono"><?= h(hx($ex['packageFlags'] ?? 0)) ?></div><div>Package GUID</div><div class="mono"><?= h($ex['packageGuid'] ?? '') ?></div></div><?php if ($p['hex'] !== ''): ?><pre class="hex"><?= h($p['hex']) ?></pre><?php endif; ?><?php if (!empty($ex['preload'])): ?><pre class="hex"><?= h(json_encode($ex['preload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?></details></td></tr><?php endforeach; ?></tbody></table></section>
</div>
</body>
</html>
