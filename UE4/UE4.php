<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader.php';

$uploadDir = __DIR__ . '/uploads';
$uploadRelDir = 'uploads';
$allowedExt = ['uasset', 'umap', 'uexp'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx_any($v): string { return sprintf('0x%08X', (int)$v); }
function safe_package_name(string $name): string { $base = basename(str_replace('\\', '/', rawurldecode($name))); $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? ''; return trim($base, " .\t\n\r\0\x0B"); }
function upload_file_list(string $uploadDir, string $uploadRelDir, array $allowedExt): array
{
    if (!is_dir($uploadDir)) return [];
    $out = [];
    foreach (scandir($uploadDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $full = $uploadDir . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($full) || !in_array($ext, $allowedExt, true)) continue;
        $out[] = ['name'=>$file,'rel'=>$uploadRelDir . '/' . rawurlencode($file),'path'=>$full,'size'=>filesize($full) ?: 0,'mtime'=>filemtime($full) ?: 0];
    }
    usort($out, static fn(array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}
function resolve_package_path(string $fileParam, string $uploadDir, array $uploadedFiles): string
{
    $root = realpath(__DIR__);
    if ($root === false) return '';
    if ($fileParam !== '') {
        $base = safe_package_name($fileParam);
        if ($base !== '') {
            $uploadCandidate = $uploadDir . DIRECTORY_SEPARATOR . $base;
            if (is_file($uploadCandidate)) return $uploadCandidate;
        }
        $localCandidate = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($fileParam)), DIRECTORY_SEPARATOR);
        $localReal = realpath($localCandidate);
        if ($localReal !== false && is_file($localReal) && str_starts_with($localReal, $root . DIRECTORY_SEPARATOR)) return $localReal;
    }
    foreach (['test.uasset','test.umap'] as $defaultName) if (is_file(__DIR__ . DIRECTORY_SEPARATOR . $defaultName)) return __DIR__ . DIRECTORY_SEPARATOR . $defaultName;
    return $uploadedFiles[0]['path'] ?? '';
}
function ref_label(UnrealPackageReader $pkg, int $ref): string { if ($ref === 0) return ''; $n = $pkg->displayNameFromRef($ref); return $n !== '' ? $n . '(' . $ref . ')' : '(' . $ref . ')'; }

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : '';
$filePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);
if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE4.php: no UE4 package file is available.\n";
    echo "Use UE4/upload.php, put a .uasset/.umap file into UE4/uploads/, or keep test.uasset beside UE4.php.\n";
    exit;
}
$currentRel = str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR) ? $uploadRelDir . '/' . basename($filePath) : basename($filePath);
$pkg = new UnrealPackageReader($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
$pkgFlagsDecoded = $pkg->decodePKG((int)($hdr['pkgFlags'] ?? 0));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE4 Explorer — <?= h(basename($filePath)) ?></title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;color:#071629;margin:0;padding:14px}.viewer{background:#fff;border:1px solid #cfd7df;padding:14px}.top{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-bottom:1px solid #cfd7df;padding-bottom:10px;margin-bottom:12px}.mono{font-family:Consolas,Menlo,monospace}.raw{white-space:pre-wrap;overflow-wrap:anywhere}.btn,button{border:1px solid #cfd7df;background:#fff;border-radius:5px;padding:5px 9px;text-decoration:none;color:#071629;cursor:pointer}.file-select{min-width:320px;padding:6px;border:1px solid #9aa7b1}.tabs{display:flex;border-bottom:1px solid #cfd7df;margin-top:10px}.tab{border:0;border-right:1px solid #cfd7df;background:#f8fafb;padding:9px 16px;font-weight:700;cursor:pointer}.tab.active{background:#fff;color:#0969da;box-shadow:inset 0 -2px 0 #0969da}.panel{display:none;padding:14px 0}.panel.active{display:block}.grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:8px 14px;max-width:1000px}.label{font-weight:700}.value{border:1px solid #cfd7df;background:#fbfbfb;padding:6px 8px}.data{border-collapse:collapse;width:100%;margin-top:10px}.data th,.data td{border:1px solid #cfd7df;padding:6px 8px;text-align:left;vertical-align:top}.data th{background:#f5f7f9}.warn{border:1px solid #d1242f;background:#fff8f8;padding:8px;margin:12px 0}.note{border:1px solid #d0d7de;background:#f6f8fa;padding:8px;margin:12px 0;color:#536471}
</style>
<script>
function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
</script>
</head>
<body>
<div class="viewer">
<div class="top"><form method="get"><select class="file-select" name="file"><?php foreach ($uploadedFiles as $up): ?><option value="<?= h($up['rel']) ?>"<?= basename($filePath) === $up['name'] ? ' selected' : '' ?>><?= h($up['name']) ?> (<?= h(number_format((int)$up['size'])) ?> bytes)</option><?php endforeach; ?></select><button type="submit">Open</button><a class="btn" href="upload.php">Upload</a></form><div class="mono"><?= h($currentRel) ?> (<?= h($pkg->getFileSize()) ?>)</div></div>
<h1>UE4 Package Viewer</h1>
<div class="note">Initial UE4 reader: parses summary, names, imports, and a first-pass export table. Full UE4 property/object deserialization is not implemented yet.</div>
<div class="tabs"><button class="tab active" data-panel="summary" onclick="showPanel('summary')">Package</button><button class="tab" data-panel="names" onclick="showPanel('names')">Names</button><button class="tab" data-panel="imports" onclick="showPanel('imports')">Imports</button><button class="tab" data-panel="exports" onclick="showPanel('exports')">Exports</button></div>
<section id="summary" class="panel active"><div class="grid"><div class="label">GUID</div><div class="value mono"><?= h($hdr['guid'] ?? '') ?></div><div class="label">Legacy File Version</div><div class="value mono"><?= h($hdr['legacyFileVersion'] ?? '') ?></div><div class="label">UE4 Version</div><div class="value mono"><?= h($hdr['fileVersionUE4'] ?? '') ?></div><div class="label">Licensee UE4 Version</div><div class="value mono"><?= h($hdr['fileVersionLicenseeUE4'] ?? '') ?></div><div class="label">Header Size</div><div class="value mono"><?= h($hdr['totalHeaderSize'] ?? '') ?></div><div class="label">Folder</div><div class="value mono"><?= h($hdr['folderName'] ?? '') ?></div><div class="label">Package Flags</div><div class="value mono"><?= h(hx_any($hdr['pkgFlags'] ?? 0)) ?> <?= h($pkgFlagsDecoded ? '(' . implode(', ', $pkgFlagsDecoded) . ')' : '') ?></div><div class="label">Counts</div><div class="value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div><div class="label">Offsets</div><div class="value mono">N <?= h($hdr['nameOffset'] ?? '') ?> / I <?= h($hdr['importOffset'] ?? '') ?> / E <?= h($hdr['exportOffset'] ?? '') ?> / D <?= h($hdr['dependsOffset'] ?? '') ?></div><div class="label">Layouts</div><div class="value mono">N <?= h($hdr['nameTableLayout'] ?? '') ?> / E <?= h($hdr['exportTableLayout'] ?? '') ?></div></div><?php if ($issues): ?><div class="warn"><strong>Validation</strong><ul><?php foreach ($issues as $w): ?><li class="mono raw"><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?></section>
<section id="names" class="panel"><table class="data"><tr><th>#</th><th>Name</th><th>Hash</th></tr><?php foreach ($names as $n): ?><tr><td class="mono"><?= h($n['index']) ?></td><td class="mono"><?= h($n['name']) ?></td><td class="mono"><?= h(hx_any($n['hash'] ?? 0)) ?></td></tr><?php endforeach; ?></table></section>
<section id="imports" class="panel"><table class="data"><tr><th>#</th><th>Object</th><th>Class</th><th>Class Package</th><th>Outer</th></tr><?php foreach ($imports as $im): ?><tr><td class="mono">-<?= h(((int)$im['index']) + 1) ?></td><td class="mono"><?= h($im['objectNameText'] ?? '') ?></td><td class="mono"><?= h($im['classNameText'] ?? '') ?></td><td class="mono"><?= h($im['classPackageText'] ?? '') ?></td><td class="mono"><?= h(ref_label($pkg, (int)($im['outerIndex'] ?? 0))) ?></td></tr><?php endforeach; ?></table></section>
<section id="exports" class="panel"><table class="data"><tr><th>#</th><th>Object</th><th>Class</th><th>Super</th><th>Outer</th><th>Serial Size</th><th>Serial Offset</th><th>Flags</th></tr><?php foreach ($exports as $ex): ?><tr><td class="mono"><?= h(((int)$ex['index']) + 1) ?></td><td class="mono"><?= h($ex['objectNameText'] ?? '') ?></td><td class="mono"><?= h(ref_label($pkg, (int)($ex['classIndex'] ?? 0))) ?></td><td class="mono"><?= h(ref_label($pkg, (int)($ex['superIndex'] ?? 0))) ?></td><td class="mono"><?= h(ref_label($pkg, (int)($ex['outerIndex'] ?? 0))) ?></td><td class="mono"><?= h($ex['serialSize'] ?? '') ?></td><td class="mono"><?= h($ex['serialOffset'] ?? '') ?></td><td class="mono"><?= h($ex['objectFlags'] ?? '') ?></td></tr><?php endforeach; ?></table></section>
</div>
</body>
</html>
