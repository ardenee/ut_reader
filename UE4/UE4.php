<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader4.php';

$uploadDir = __DIR__ . '/uploads';
$uploadRelDir = 'uploads';
$allowedExt = ['uasset', 'umap'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx($v): string { return sprintf('0x%08X', (int)$v); }
function safe_package_name(string $name): string
{
    $base = basename(str_replace('\\', '/', rawurldecode($name)));
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? '';
    return trim($base, " .\t\n\r\0\x0B");
}
function upload_file_list(string $uploadDir, string $uploadRelDir, array $allowedExt): array
{
    if (!is_dir($uploadDir)) return [];
    $out = [];
    foreach (scandir($uploadDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $full = $uploadDir . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($full) || !in_array($ext, $allowedExt, true)) continue;
        $out[] = ['name'=>$file, 'rel'=>$uploadRelDir . '/' . rawurlencode($file), 'path'=>$full, 'size'=>filesize($full) ?: 0, 'mtime'=>filemtime($full) ?: 0];
    }
    usort($out, static fn(array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}
function resolve_package_path(string $fileParam, string $uploadDir, array $uploadedFiles): string
{
    $root = realpath(__DIR__);
    if ($root === false) return '';
    if ($fileParam !== '') {
        $decoded = rawurldecode($fileParam);
        $base = safe_package_name($decoded);
        if ($base !== '') {
            $uploadCandidate = $uploadDir . DIRECTORY_SEPARATOR . $base;
            if (is_file($uploadCandidate)) return $uploadCandidate;
        }
        $localCandidate = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $decoded), DIRECTORY_SEPARATOR);
        $localReal = realpath($localCandidate);
        if ($localReal !== false && is_file($localReal) && str_starts_with($localReal, $root . DIRECTORY_SEPARATOR)) return $localReal;
    }
    foreach (['test.uasset', 'test.umap'] as $defaultName) {
        $default = __DIR__ . DIRECTORY_SEPARATOR . $defaultName;
        if (is_file($default)) return $default;
    }
    return $uploadedFiles[0]['path'] ?? '';
}
function object_ref_target_id(int $ref): string { return $ref < 0 ? 'ref-import-' . abs($ref) : 'ref-export-' . $ref; }
function name_ref_target_id(int $idx): string { return 'ref-name-' . $idx; }
function ref_html(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) return '';
    $name = $pkg->displayNameFromRef($ref);
    $label = $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
    return '<a class="ref-link mono" href="#' . h(object_ref_target_id($ref)) . '">' . h($label) . '</a>';
}
function name_html(UnrealPackageReader4 $pkg, array $name): string
{
    $idx = (int)($name['index'] ?? -1);
    $num = (int)($name['number'] ?? 0);
    $text = (string)($name['text'] ?? ($idx >= 0 ? $pkg->nameByIndex($idx, $num) : ''));
    if ($idx < 0) return h($text);
    return '<a class="name-link mono" href="#' . h(name_ref_target_id($idx)) . '">' . h($text) . '</a><span class="tag">#' . h($idx) . '</span>';
}

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : '';
$filePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);
if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE4.php: no package file is available.\n";
    echo "Use UE4/upload.php, put a .uasset/.umap into UE4/uploads/, or keep test.uasset beside UE4.php.\n";
    exit;
}
$currentRel = str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR) ? $uploadRelDir . '/' . basename($filePath) : basename($filePath);
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
<title>UE4 Explorer — <?= h(basename($filePath)) ?></title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#eef6f8;color:#071629;margin:0;padding:12px;font-size:14px}.viewer{background:#fff;border:1px solid #cfd7df;min-height:700px}.toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid #cfd7df;background:#fbfbfb;flex-wrap:wrap}.file-select{min-width:360px;padding:6px 8px}.btn{border:1px solid #cfd7df;background:#fff;border-radius:5px;padding:5px 9px;text-decoration:none;color:#071629;cursor:pointer}.tabs{display:flex;border-bottom:1px solid #cfd7df;background:#f8fafb}.tab{border:0;border-right:1px solid #cfd7df;background:transparent;padding:10px 18px;font-weight:700;cursor:pointer}.tab.active{background:#fff;color:#0969da;box-shadow:inset 0 -2px 0 #0969da}.panel{display:none;padding:16px}.panel.active{display:block}.grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:10px 18px;max-width:1180px}.label{font-weight:700}.value{border:1px solid #cfd7df;background:#fbfbfb;padding:6px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mono{font-family:Consolas,Menlo,monospace}.raw{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}.data{border-collapse:collapse;width:100%;margin-top:12px}.data th,.data td{border:1px solid #cfd7df;padding:7px 9px;vertical-align:top}.data th{background:#f5f7f9;text-align:left}.warn{border:1px solid #d1242f;background:#fff8f8;padding:8px 12px;margin-top:14px}.tag{display:inline-block;margin-left:4px;color:#2f6f9f;background:#edf6ff;border:1px solid #c7dff2;border-radius:3px;padding:0 3px;font-family:Consolas,Menlo,monospace;font-size:.92em}.ref-link,.name-link{text-decoration:none;color:#0969da}.ref-link:hover,.name-link:hover{text-decoration:underline}.is-target td{background:#fff3cd!important;outline:2px solid #f0c36d}.small{font-size:12px;color:#536471}
</style>
<script>
function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
function clearTargetState(){document.querySelectorAll('.is-target').forEach(e=>e.classList.remove('is-target'));}
function jumpToRef(targetId){if(!targetId)return;if(targetId.indexOf('ref-import-')===0)showPanel('imports-panel');else if(targetId.indexOf('ref-export-')===0)showPanel('exports-panel');else if(targetId.indexOf('ref-name-')===0)showPanel('names-panel');setTimeout(()=>{const t=document.getElementById(targetId);if(!t)return;clearTargetState();t.classList.add('is-target');t.scrollIntoView({behavior:'smooth',block:'center'});},60);}
document.addEventListener('click',function(ev){const a=ev.target.closest&&ev.target.closest('a.ref-link,a.name-link');if(!a)return;const href=a.getAttribute('href')||'';if(href.charAt(0)!=='#')return;ev.preventDefault();jumpToRef(href.substring(1));});
</script>
</head>
<body>
<div class="viewer"><div class="toolbar"><form method="get"><select class="file-select" name="file"><?php foreach ($uploadedFiles as $up): ?><option value="<?= h($up['rel']) ?>"<?= basename($filePath) === $up['name'] ? ' selected' : '' ?>><?= h($up['name']) ?> (<?= h(number_format((int)$up['size'])) ?> bytes)</option><?php endforeach; ?><?php foreach (['test.uasset','test.umap'] as $localDefault): if (is_file(__DIR__ . '/' . $localDefault)): ?><option value="<?= h($localDefault) ?>"<?= $currentRel === $localDefault ? ' selected' : '' ?>><?= h($localDefault) ?></option><?php endif; endforeach; ?></select> <button class="btn" type="submit">Open</button> <a class="btn" href="upload.php">Upload</a></form><span><?= h($currentRel) ?> (<?= h($pkg->getFileSize()) ?>)</span></div>
<div class="tabs"><button class="tab active" data-panel="summary-panel" onclick="showPanel('summary-panel')">Summary</button><button class="tab" data-panel="names-panel" onclick="showPanel('names-panel')">Names</button><button class="tab" data-panel="imports-panel" onclick="showPanel('imports-panel')">Imports</button><button class="tab" data-panel="exports-panel" onclick="showPanel('exports-panel')">Exports</button></div>
<section id="summary-panel" class="panel active"><h2>UE4 Package Summary</h2><div class="grid"><div class="label">GUID</div><div class="value mono"><?= h($hdr['guid'] ?? '') ?></div><div class="label">Legacy Version</div><div class="value mono"><?= h($hdr['legacyFileVersion'] ?? '') ?></div><div class="label">Legacy UE3 Version</div><div class="value mono"><?= h($hdr['legacyUE3Version'] ?? '') ?></div><div class="label">UE4 Version</div><div class="value mono"><?= h($hdr['version'] ?? '') ?><?= !empty($hdr['unversioned']) ? ' (assumed; unversioned)' : '' ?></div><div class="label">Licensee Version</div><div class="value mono"><?= h($hdr['licenseeVersion'] ?? '') ?></div><div class="label">Package Flags</div><div class="value mono"><?= h(hx($hdr['packageFlags'] ?? 0)) ?></div><div class="label">Folder</div><div class="value mono"><?= h($hdr['folderName'] ?? '') ?></div><div class="label">Total Header Size</div><div class="value mono"><?= h($hdr['totalHeaderSize'] ?? '') ?></div><div class="label">Counts</div><div class="value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div><div class="label">Offsets</div><div class="value mono">N <?= h($hdr['nameOffset'] ?? '') ?> / I <?= h($hdr['importOffset'] ?? '') ?> / E <?= h($hdr['exportOffset'] ?? '') ?></div><div class="label">Depends Offset</div><div class="value mono"><?= h($hdr['dependsOffset'] ?? '') ?></div><div class="label">Asset Registry Offset</div><div class="value mono"><?= h($hdr['assetRegistryDataOffset'] ?? '') ?></div><div class="label">Bulk Data Start</div><div class="value mono"><?= h($hdr['bulkDataStartOffset'] ?? '') ?></div><div class="label">Preload Dependencies</div><div class="value mono"><?= h($hdr['preloadDependencyCount'] ?? '') ?> @ <?= h($hdr['preloadDependencyOffset'] ?? '') ?></div><div class="label">UEXP Pair</div><div class="value mono"><?= !empty($hdr['hasUexp']) ? h(basename((string)$hdr['uexpPath'])) : 'not found' ?></div></div><?php if ($issues): ?><div class="warn"><strong>Validation / Notes</strong><ul><?php foreach ($issues as $w): ?><li class="mono raw"><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?><h2>Version Details</h2><pre class="raw"><?= h(json_encode(['savedByEngineVersion'=>$hdr['savedByEngineVersion'] ?? [], 'compatibleWithEngineVersion'=>$hdr['compatibleWithEngineVersion'] ?? [], 'customVersions'=>array_slice($hdr['customVersions'] ?? [], 0, 20)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></section>
<section id="names-panel" class="panel"><h2>Name Map</h2><table class="data"><thead><tr><th>#</th><th>Name</th><th>Offset</th><th>Hashes</th></tr></thead><tbody><?php foreach ($names as $n): ?><tr id="<?= h(name_ref_target_id((int)$n['index'])) ?>"><td class="mono"><?= h($n['index']) ?></td><td class="mono"><?= h($n['name']) ?></td><td class="mono"><?= h($n['offset']) ?></td><td class="mono"><?= h(($n['nonCaseHash'] ?? '') . ' / ' . ($n['caseHash'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section id="imports-panel" class="panel"><h2>Import Map</h2><table class="data"><thead><tr><th>Ref</th><th>Object</th><th>Class Package</th><th>Class</th><th>Outer</th><th>Offset</th></tr></thead><tbody><?php foreach ($imports as $im): $ref=(int)$im['ref']; ?><tr id="<?= h(object_ref_target_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td><?= name_html($pkg, $im['objectName']) ?></td><td><?= name_html($pkg, $im['classPackage']) ?></td><td><?= name_html($pkg, $im['className']) ?></td><td><?= ref_html($pkg, (int)$im['outerIndex']) ?></td><td class="mono"><?= h($im['offset']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section id="exports-panel" class="panel"><h2>Export Map</h2><table class="data"><thead><tr><th>Ref</th><th>Object</th><th>Class</th><th>Outer</th><th>Template</th><th>Flags</th><th>Serial</th><th>Booleans</th><th>Preload</th></tr></thead><tbody><?php foreach ($exports as $ex): $ref=(int)$ex['ref']; ?><tr id="<?= h(object_ref_target_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td><?= name_html($pkg, $ex['objectName']) ?></td><td><?= ref_html($pkg, (int)$ex['classIndex']) ?></td><td><?= ref_html($pkg, (int)$ex['outerIndex']) ?></td><td><?= ref_html($pkg, (int)$ex['templateIndex']) ?></td><td class="mono"><?= h(hx($ex['objectFlags'] ?? 0)) ?></td><td class="mono">size <?= h($ex['serialSize']) ?><br>offset <?= h($ex['serialOffset']) ?></td><td class="small">forced <?= !empty($ex['forcedExport'])?'Y':'N' ?><br>client <?= !empty($ex['notForClient'])?'no':'yes' ?><br>server <?= !empty($ex['notForServer'])?'no':'yes' ?><br>asset <?= $ex['isAsset'] === null ? '?' : (!empty($ex['isAsset'])?'Y':'N') ?></td><td class="mono small"><?= h($ex['preload'] ? json_encode($ex['preload'], JSON_UNESCAPED_SLASHES) : '') ?></td></tr><?php endforeach; ?></tbody></table></section>
</div>
</body>
</html>
