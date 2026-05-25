<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader.php';

$filePath = isset($_GET['file']) ? (string)$_GET['file'] : (isset($filePath) ? (string)$filePath : 'oldtest.utx');

if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE1.php: missing or invalid ?file= parameter.\n";
    echo "Example: UE1.php?file=oldtest.utx\n";
    exit;
}

$pkg = new UnrealPackageReader($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
$pkgFlagsDecoded = $pkg->decodePKG((int)($hdr['pkgFlags'] ?? 0));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx(int $v): string { return sprintf('0x%08X', $v); }
function hx2(int $v): string { return sprintf('0x%02X', $v); }
function flags_text(array $flags): string { return $flags ? ' (' . implode(', ', $flags) . ')' : ''; }

$importsByOuter = [];
foreach ($imports as $idx => $im) {
    $importsByOuter[(int)($im['outerIndex'] ?? 0)][] = $idx;
}

$exportsByOuter = [];
foreach ($exports as $idx => $ex) {
    $exportsByOuter[(int)($ex['packageIndex'] ?? $ex['outerIndex'] ?? 0)][] = $idx;
}

$renderImportNode = function (int $idx, int $depth = 0) use (&$renderImportNode, &$importsByOuter, $imports, $pkg): void {
    $im = $imports[$idx] ?? null;
    if (!is_array($im)) return;
    $ref = -($idx + 1);
    $object = $pkg->importObjectName((int)($im['objectName'] ?? -1));
    $class = $pkg->importClassName((int)($im['className'] ?? -1));
    $package = $pkg->importClassPackageName((int)($im['classPackage'] ?? -1));
    $outer = $pkg->importPackageName((int)($im['outerIndex'] ?? 0));
    $children = $importsByOuter[$ref] ?? [];
    ?>
    <details class="tree-node" open data-filter-row>
        <summary><span class="tree-icon">▣</span><span class="tree-title"><?= h($object) ?></span><span class="tree-ref">Import <?= h($ref) ?></span></summary>
        <div class="tree-lines">
            <div>Object: <span class="mono"><?= h($object) ?></span><?= h('(' . $ref . ')') ?></div>
            <div>Class: <span class="mono"><?= h($class) ?></span></div>
            <div>Class Package: <span class="mono"><?= h($package) ?></span></div>
            <?php if ($outer !== ''): ?><div>Outer: <span class="mono"><?= h($outer) ?></span></div><?php endif; ?>
            <div class="small mono">Raw: <?= h(($im['classPackage'] ?? '') . ' / ' . ($im['className'] ?? '') . ' / ' . ($im['outerIndex'] ?? '') . ' / ' . ($im['objectName'] ?? '')) ?></div>
            <?php foreach ($children as $childIdx): ?><?php $renderImportNode((int)$childIdx, $depth + 1); ?><?php endforeach; ?>
        </div>
    </details>
    <?php
};

$renderExportNode = function (int $idx, int $depth = 0, bool $withProps = false) use (&$renderExportNode, &$exportsByOuter, $exports, $pkg): void {
    $ex = $exports[$idx] ?? null;
    if (!is_array($ex)) return;
    $ref = $idx + 1;
    $object = $pkg->exportObjectName((int)($ex['objectName'] ?? -1));
    $class = $pkg->exportClassName((int)($ex['classIndex'] ?? 0));
    $super = $pkg->exportSuperName((int)($ex['superIndex'] ?? 0));
    $outer = $pkg->exportPackageName((int)($ex['packageIndex'] ?? 0));
    $children = $exportsByOuter[$ref] ?? [];
    $props = $withProps ? $pkg->getExportProperties($idx) : [];
    $hasProps = is_array($props) && !empty($props);
    ?>
    <details class="tree-node content-node" open data-filter-row>
        <summary><span class="tree-icon">≡</span><span class="tree-title"><?= h($object) ?></span><span class="tree-class"><?= h($class) ?></span><span class="tree-ref">Export <?= h($ref) ?></span></summary>
        <div class="tree-lines">
            <div>ObjectFlags: <span class="mono"><?= h(sprintf('%08X', (int)($ex['objectFlags'] ?? 0))) ?></span><?= h(flags_text($pkg->decodeRF((int)($ex['objectFlags'] ?? 0)))) ?></div>
            <div>Object: <span class="mono"><?= h($object) ?></span><?= h('(' . $ref . ')') ?></div>
            <div>Class: <span class="mono"><?= h($class) ?></span><?= (int)($ex['classIndex'] ?? 0) !== 0 ? h('(' . (int)$ex['classIndex'] . ')') : '' ?></div>
            <?php if ($super !== ''): ?><div>Super: <span class="mono"><?= h($super) ?></span></div><?php endif; ?>
            <?php if ($outer !== ''): ?><div>Package: <span class="mono"><?= h($outer) ?></span></div><?php endif; ?>
            <div>Object Size: <span class="mono"><?= h($ex['serialSize'] ?? '') ?></span></div>
            <div>Object Offset: <span class="mono"><?= h($ex['serialOffset'] ?? '') ?></span></div>
            <div class="small mono">Raw: <?= h(($ex['classIndex'] ?? '') . ' / ' . ($ex['superIndex'] ?? '') . ' / ' . ($ex['packageIndex'] ?? '') . ' / ' . ($ex['objectName'] ?? '') . ' / ' . ($ex['objectFlags'] ?? '') . ' / ' . ($ex['serialSize'] ?? '') . ' / ' . ($ex['serialOffset'] ?? '')) ?></div>
            <?php if ($hasProps): ?>
                <button class="toggle-btn" onclick="toggleProps(<?= (int)$idx ?>)"><span id="chev-<?= (int)$idx ?>" class="chev">▶</span> Properties <span class="pill"><?= count($props) ?></span></button>
                <div id="props-<?= (int)$idx ?>" class="props-block">
                    <div class="props-wrap"><?php render_props_table($props); ?></div>
                </div>
            <?php endif; ?>
            <?php foreach ($children as $childIdx): ?><?php $renderExportNode((int)$childIdx, $depth + 1, $withProps); ?><?php endforeach; ?>
        </div>
    </details>
    <?php
};

function render_props_table(array $props): void
{
    ?>
    <table class="nested">
        <thead><tr><th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Struct</th><th>isArray</th><th>idx</th><th>idxFromFile</th><th>Value</th><th>Num.</th><th class="small">Raw</th></tr></thead>
        <tbody>
        <?php foreach ($props as $pi => $p): ?>
            <?php $val = $p['value'] ?? ($p['val'] ?? ($p['data'] ?? '')); $raw = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
            <tr>
                <td class="mono raw-cell"><?= h($p['offset'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['length'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['name'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['type'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['struct'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['isArray'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['idx'] ?? '') ?></td>
                <td class="mono raw-cell"><?= h($p['idxFromFile'] ?? '') ?></td>
                <td class="raw-cell"><?= is_scalar($val) ? h((string)$val) : '<pre class="mono small">' . h(print_r($val, true)) . '</pre>' ?></td>
                <td class="mono small raw-cell"><?= h($pi) ?></td>
                <td class="mono small raw-cell"><?= h($raw) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>UE1 Explorer — <?= h(basename($filePath)) ?></title>
<style>
    :root { --b:#cfd7df; --bg:#eef6f8; --panel:#fff; --muted:#f4f6f8; --text:#1f2933; --sub:#667085; --accent:#0b74c9; --accent-soft:#e5f2ff; --warn:#d1242f; }
    * { box-sizing:border-box; }
    html,body { margin:0; background:var(--bg); color:var(--text); }
    body { font-family:Segoe UI,Tahoma,Arial,sans-serif; font-size:14px; line-height:1.35; }
    .mono { font-family:Consolas,Menlo,Monaco,monospace; }
    .small { font-size:12px; color:var(--sub); }
    .wrap,.wrap * { white-space:normal; overflow-wrap:anywhere; word-break:break-word; line-break:anywhere; }
    .raw-cell { white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; line-break:anywhere; max-width:34rem; }
    .window { min-height:100vh; display:flex; flex-direction:column; }
    .titlebar { height:48px; display:flex; align-items:center; gap:12px; padding:0 18px; background:linear-gradient(#e5f7f8,#d9f0f2); border-bottom:1px solid var(--b); }
    .title-dot { width:22px; height:22px; border-radius:50%; background:radial-gradient(circle at 35% 35%,#8ed0ff,#0b55b7 55%,#073a7d); box-shadow:inset 0 0 0 1px rgba(0,0,0,.18); }
    .title { font-size:20px; font-weight:600; }
    .menubar { height:34px; display:flex; align-items:center; gap:28px; padding:0 28px; background:#f7f7f7; border-bottom:1px solid var(--b); }
    .workspace { padding:12px; }
    .doc-tabs { display:flex; align-items:flex-end; gap:2px; margin-left:8px; }
    .doc-tab { padding:7px 18px; border:1px solid var(--b); border-bottom:0; background:#f4f7f9; border-radius:5px 5px 0 0; color:var(--sub); }
    .doc-tab.active { background:var(--panel); color:var(--text); font-weight:600; }
    .viewer { background:var(--panel); border:1px solid var(--b); min-height:600px; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    .toolbar { display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:10px; padding:8px 10px; border-bottom:1px solid var(--b); background:#fbfbfb; }
    .toolbtn { border:1px solid var(--b); background:white; border-radius:4px; padding:5px 10px; }
    .searchbox { width:100%; max-width:360px; padding:6px 8px; border:1px solid #9aa7b1; font-size:14px; }
    .package-name { color:var(--sub); }
    .main-tabs,.subtabs { display:flex; gap:0; padding:0 12px; border-bottom:1px solid var(--b); background:#f8fafb; }
    .subtabs { padding:0; margin-bottom:10px; }
    .main-tab,.subtab { border:0; border-right:1px solid var(--b); background:transparent; padding:9px 14px; font-weight:600; color:#344054; cursor:pointer; }
    .main-tab:first-child,.subtab:first-child { border-left:1px solid var(--b); }
    .main-tab.active,.subtab.active { background:white; color:var(--accent); box-shadow:inset 0 -2px 0 var(--accent); }
    .panel,.subpanel { display:none; padding:16px; }
    .subpanel { padding:0; }
    .panel.active,.subpanel.active { display:block; }
    .package-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; max-width:920px; }
    .field-grid { display:grid; grid-template-columns:170px 1fr; gap:8px 18px; align-items:center; }
    .field-label { color:#344054; font-weight:600; }
    .field-value { min-height:26px; border:1px solid #cdd5df; background:#fbfbfb; padding:4px 8px; }
    .flag-table, table.data { border-collapse:collapse; width:100%; margin:8px 0 18px; }
    .flag-table { max-width:780px; margin-top:24px; }
    .flag-table th,.flag-table td, table.data th, table.data td { border:1px solid var(--b); padding:6px 8px; vertical-align:top; }
    .flag-table th, table.data th { text-align:left; background:var(--muted); }
    .flag-true { background:#0078d7; color:white; }
    table.data { table-layout:fixed; }
    .names-table th:nth-child(1),.names-table td:nth-child(1){width:22%;}.names-table th:nth-child(2),.names-table td:nth-child(2){width:46%;}.names-table th:nth-child(3),.names-table td:nth-child(3){width:10%;}.names-table th:nth-child(4),.names-table td:nth-child(4){width:22%;}
    .imports-table th:nth-child(1),.imports-table td:nth-child(1){width:15%;}.imports-table th:nth-child(2),.imports-table td:nth-child(2){width:15%;}.imports-table th:nth-child(3),.imports-table td:nth-child(3){width:18%;}.imports-table th:nth-child(4),.imports-table td:nth-child(4){width:18%;}.imports-table th:nth-child(5),.imports-table td:nth-child(5){width:9%;}.imports-table th:nth-child(6),.imports-table td:nth-child(6){width:25%;}
    .exports-table th:nth-child(1),.exports-table td:nth-child(1){width:9%;}.exports-table th:nth-child(2),.exports-table td:nth-child(2){width:8%;}.exports-table th:nth-child(3),.exports-table td:nth-child(3){width:8%;}.exports-table th:nth-child(4),.exports-table td:nth-child(4){width:11%;}.exports-table th:nth-child(5),.exports-table td:nth-child(5){width:20%;}.exports-table th:nth-child(6),.exports-table td:nth-child(6){width:7%;}.exports-table th:nth-child(7),.exports-table td:nth-child(7){width:8%;}.exports-table th:nth-child(8),.exports-table td:nth-child(8){width:7%;}.exports-table th:nth-child(9),.exports-table td:nth-child(9){width:8%;}.exports-table th:nth-child(10),.exports-table td:nth-child(10){width:14%;}
    .tree { font-family:Segoe UI,Tahoma,Arial,sans-serif; max-width:980px; }
    .tree-node { margin:2px 0; }
    .tree-node summary { cursor:pointer; list-style:none; padding:3px 4px; border-radius:3px; }
    .tree-node summary::-webkit-details-marker { display:none; }
    .tree-node summary:hover { background:var(--accent-soft); }
    .tree-node[open] > summary::before { content:'▾ '; color:#546170; }
    .tree-node:not([open]) > summary::before { content:'▸ '; color:#546170; }
    .tree-icon { display:inline-block; width:20px; color:#2f5e87; }
    .tree-title { font-weight:600; color:#1f2933; }
    .tree-class,.tree-ref { margin-left:8px; color:#c36b00; }
    .tree-lines { margin-left:28px; padding-left:12px; border-left:1px dotted #aeb8c2; color:#26323d; }
    .tree-lines > div { padding:2px 0; }
    .content-list { display:grid; gap:4px; max-width:760px; }
    .content-item { padding:5px 8px; border-bottom:1px solid #eceff3; display:grid; grid-template-columns:1fr auto; gap:14px; }
    .content-item:nth-child(odd) { background:#fbfbfb; }
    .content-class { color:#c36b00; }
    .toggle-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid var(--b); border-radius:6px; padding:3px 8px; cursor:pointer; font-size:13px; color:var(--text); }
    .toggle-btn:hover { border-color:var(--accent); color:var(--accent); }
    .chev { transition:transform .2s ease; display:inline-block; }
    .chev.open { transform:rotate(90deg); }
    .pill { display:inline-block; padding:2px 6px; border-radius:999px; background:#e7f5ff; color:#0b74c9; font-size:12px; border:1px solid #b6dfff; }
    .props-block { display:none; margin-top:8px; }
    .props-wrap { padding:10px 4px; max-width:100%; overflow-x:auto; }
    .nested { width:100%; table-layout:fixed; border-collapse:collapse; margin:6px 0 0; }
    .nested th,.nested td { border:1px dashed var(--b); font-size:13px; background:#e5f2ff; white-space:normal; overflow-wrap:anywhere; word-break:break-word; line-break:anywhere; padding:5px 7px; }
    .nested th { background:#69beff; }
    .nested pre { margin:0; white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; }
    .tree-section { margin:8px 0 18px; border:1px solid var(--b); background:#fff; padding:8px; }
    .grid-after-tree details { margin-top:12px; }
    .grid-after-tree summary { cursor:pointer; font-weight:600; color:var(--accent); }
    .warn { border:1px solid var(--warn); background:#fff8f8; padding:8px 12px; margin:12px 0; }
    @media (max-width:900px){.package-grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}}
</style>
<script>
function showPanel(id){document.querySelectorAll('.main-tab').forEach(function(el){el.classList.toggle('active',el.dataset.panel===id);});document.querySelectorAll('.panel').forEach(function(el){el.classList.toggle('active',el.id===id);});}
function showSubPanel(id){document.querySelectorAll('.subtab').forEach(function(el){el.classList.toggle('active',el.dataset.subpanel===id);});document.querySelectorAll('.subpanel').forEach(function(el){el.classList.toggle('active',el.id===id);});}
function toggleProps(i){var row=document.getElementById('props-'+i);if(!row)return;var chev=document.getElementById('chev-'+i);var hidden=row.style.display===''||row.style.display==='none';row.style.display=hidden?'block':'none';if(chev){if(hidden)chev.classList.add('open');else chev.classList.remove('open');}}
function filterVisibleText(input){var term=(input.value||'').toLowerCase();document.querySelectorAll('[data-filter-row]').forEach(function(row){row.style.display=row.textContent.toLowerCase().indexOf(term)>=0?'':'none';});}
</script>
</head>
<body>
<div class="window">
    <div class="titlebar"><span class="title-dot"></span><span class="title">UE Explorer</span></div>
    <div class="menubar"><span>File</span><span>Tools</span><span>Options</span><span>Help</span></div>
    <div class="workspace">
        <div class="doc-tabs"><div class="doc-tab">Homepage</div><div class="doc-tab active"><?= h(basename($filePath)) ?></div></div>
        <div class="viewer">
            <div class="toolbar"><button class="toolbtn">Tools ▾</button><input class="searchbox" type="search" value="<?= h(pathinfo($filePath, PATHINFO_FILENAME)) ?>" oninput="filterVisibleText(this)" /><span class="package-name small"><?= h($filePath) ?> (<?= h($pkg->getFileSize()) ?>)</span></div>
            <div class="main-tabs"><button class="main-tab active" data-panel="package-panel" onclick="showPanel('package-panel')">▣ Package</button><button class="main-tab" data-panel="content-panel" onclick="showPanel('content-panel')">▤ Content</button><button class="main-tab" data-panel="externs-panel" onclick="showPanel('externs-panel')">⌘ Externs</button><button class="main-tab" data-panel="tables-panel" onclick="showPanel('tables-panel')">▦ Tables</button></div>

            <section id="package-panel" class="panel active">
                <div class="package-grid">
                    <div class="field-grid">
                        <div class="field-label">GUID</div><div class="field-value mono wrap"><?= h($hdr['guid'] ?? '') ?></div>
                        <div class="field-label">GUID Raw</div><div class="field-value mono wrap"><?= h($hdr['guidRaw'] ?? '') ?></div>
                        <div class="field-label">Version</div><div class="field-value mono"><?= h($hdr['version'] ?? '') ?></div>
                        <div class="field-label">Licensee Version</div><div class="field-value mono"><?= h($hdr['licensee'] ?? '') ?></div>
                        <div class="field-label">Signature</div><div class="field-value mono"><?= h(hx((int)($hdr['signature'] ?? 0))) ?></div>
                    </div>
                    <div class="field-grid">
                        <div class="field-label">Flags</div><div class="field-value mono"><?= h(hx((int)($hdr['pkgFlags'] ?? 0))) ?></div>
                        <div class="field-label">Build</div><div class="field-value">Unreal1</div>
                        <div class="field-label">Heritage</div><div class="field-value mono"><?= h(($hdr['heritageCount'] ?? '') . (($hdr['heritageOffset'] ?? '') !== '' ? ' / ' . ($hdr['heritageOffset'] ?? '') : '')) ?></div>
                        <div class="field-label">Counts</div><div class="field-value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div>
                    </div>
                </div>
                <table class="flag-table"><thead><tr><th>Flag</th><th>Condition</th></tr></thead><tbody><?php foreach ($pkgFlagsDecoded as $flag): ?><tr><td class="flag-true"><?= h(str_replace('PKG_', '', $flag)) ?></td><td>True</td></tr><?php endforeach; ?><?php if (!$pkgFlagsDecoded): ?><tr><td></td><td></td></tr><?php endif; ?></tbody></table>
                <?php if (!empty($issues)): ?><div class="warn"><strong>Validation</strong><ul><?php foreach ($issues as $w): ?><li class="mono wrap"><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            </section>

            <section id="content-panel" class="panel"><div class="content-list"><?php foreach ($exports as $i => $ex): ?><div class="content-item" data-filter-row><span class="mono"><?= h($pkg->exportObjectName((int)($ex['objectName'] ?? -1))) ?></span><span class="content-class mono"><?= h($pkg->exportClassName((int)($ex['classIndex'] ?? 0))) ?></span></div><?php endforeach; ?></div></section>

            <section id="externs-panel" class="panel"><div class="tree tree-section"><?php foreach (($importsByOuter[0] ?? []) as $rootIdx): ?><?php $renderImportNode((int)$rootIdx); ?><?php endforeach; ?><?php foreach (($exportsByOuter[0] ?? []) as $rootExportIdx): ?><?php $renderExportNode((int)$rootExportIdx); ?><?php endforeach; ?></div></section>

            <section id="tables-panel" class="panel">
                <div class="subtabs"><button class="subtab active" data-subpanel="names-subpanel" onclick="showSubPanel('names-subpanel')">☰ Names</button><button class="subtab" data-subpanel="exports-subpanel" onclick="showSubPanel('exports-subpanel')">▤ Exports</button><button class="subtab" data-subpanel="imports-subpanel" onclick="showSubPanel('imports-subpanel')">▧ Imports</button><button class="subtab" data-subpanel="generations-subpanel" onclick="showSubPanel('generations-subpanel')">☷ Generations</button></div>

                <div id="names-subpanel" class="subpanel active"><h2>Names (<?= h($hdr['nameCount'] ?? '') ?>:<?= h($hdr['nameOffset'] ?? '') ?>)</h2><table class="data names-table"><thead><tr><th>Name</th><th>Flags</th><th>Num.</th><th class="small">Raw (index / flags)</th></tr></thead><tbody><?php foreach ($names as $n): ?><?php $flags = (int)($n['flags'] ?? 0); ?><tr data-filter-row><td class="wrap"><?= h($n['name'] ?? '') ?></td><td class="mono wrap"><?= h(hx($flags)) ?><?= h(flags_text($pkg->decodeRF($flags))) ?></td><td class="mono"><?= h(($n['index'] ?? '') . ' (' . hx2((int)($n['index'] ?? 0)) . ')') ?></td><td class="small raw-cell"><?= h($n['name'] ?? '') ?> / <?= h($flags) ?></td></tr><?php endforeach; ?></tbody></table></div>

                <div id="exports-subpanel" class="subpanel">
                    <h2>Exports Tree (<?= h($hdr['exportCount'] ?? '') ?>:<?= h($hdr['exportOffset'] ?? '') ?>)</h2>
                    <div class="tree tree-section"><?php foreach (($exportsByOuter[0] ?? []) as $rootExportIdx): ?><?php $renderExportNode((int)$rootExportIdx, 0, true); ?><?php endforeach; ?></div>
                    <div class="grid-after-tree"><details><summary>Raw Exports Grid</summary><table class="data exports-table"><thead><tr><th>Class</th><th>Super</th><th>Package</th><th>Object</th><th>Object Flags</th><th>Size</th><th>Offset</th><th>Num.</th><th>Properties</th><th class="small">Raw</th></tr></thead><tbody><?php foreach ($exports as $i => $ex): ?><?php $props = $pkg->getExportProperties($i); $hasProps = is_array($props) && !empty($props); $objectFlags = (int)($ex['objectFlags'] ?? 0); ?><tr data-filter-row><td class="mono wrap"><?= h($pkg->exportClassName((int)($ex['classIndex'] ?? 0))) ?></td><td class="mono wrap"><?= h($pkg->exportSuperName((int)($ex['superIndex'] ?? 0))) ?></td><td class="mono wrap"><?= h($pkg->exportPackageName((int)($ex['packageIndex'] ?? 0))) ?></td><td class="mono wrap"><?= h($pkg->exportObjectName((int)($ex['objectName'] ?? -1))) ?></td><td class="mono wrap"><?= h(hx($objectFlags)) ?><?= h(flags_text($pkg->decodeRF($objectFlags))) ?></td><td class="mono"><?= h($ex['serialSize'] ?? '') ?></td><td class="mono"><?= h(hx((int)($ex['serialOffset'] ?? 0))) ?></td><td class="mono"><?= h($i . ' (' . hx2($i) . ')') ?></td><td><?php if ($hasProps): ?><span class="pill"><?= count($props) ?></span><?php endif; ?></td><td class="mono small raw-cell"><?= h(($ex['classIndex'] ?? '') . ' / ' . ($ex['superIndex'] ?? '') . ' / ' . ($ex['packageIndex'] ?? '') . ' / ' . ($ex['objectName'] ?? '') . ' / ' . ($ex['objectFlags'] ?? '') . ' / ' . ($ex['serialSize'] ?? '') . ' / ' . ($ex['serialOffset'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></details></div>
                </div>

                <div id="imports-subpanel" class="subpanel">
                    <h2>Imports Tree (<?= h($hdr['importCount'] ?? '') ?>:<?= h($hdr['importOffset'] ?? '') ?>)</h2>
                    <div class="tree tree-section"><?php foreach (($importsByOuter[0] ?? []) as $rootIdx): ?><?php $renderImportNode((int)$rootIdx); ?><?php endforeach; ?></div>
                    <div class="grid-after-tree"><details><summary>Raw Imports Grid</summary><table class="data imports-table"><thead><tr><th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th><th class="small">Raw</th></tr></thead><tbody><?php foreach ($imports as $i => $im): ?><tr data-filter-row><td class="wrap"><?= h($pkg->importClassPackageName((int)($im['classPackage'] ?? -1))) ?></td><td class="wrap"><?= h($pkg->importClassName((int)($im['className'] ?? -1))) ?></td><td class="wrap"><?= h($pkg->importPackageName((int)($im['outerIndex'] ?? 0))) ?></td><td class="wrap"><?= h($pkg->importObjectName((int)($im['objectName'] ?? -1))) ?></td><td class="mono"><?= h($i . ' (' . hx2($i) . ')') ?></td><td class="mono small raw-cell"><?= h(($im['classPackage'] ?? '') . ' / ' . ($im['className'] ?? '') . ' / ' . ($im['outerIndex'] ?? '') . ' / ' . ($im['objectName'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></details></div>
                </div>

                <div id="generations-subpanel" class="subpanel"><h2>Generations (<?= count($hdr['generations'] ?? []) ?>)</h2><table class="data"><thead><tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th class="small">Raw</th></tr></thead><tbody><?php foreach (($hdr['generations'] ?? []) as $i => $g): ?><tr><td><?= h($g['e'] ?? '') ?></td><td><?= h($g['n'] ?? '') ?></td><td><?= h($i) ?></td><td class="small raw-cell"><?= h(($g['e'] ?? '') . ' / ' . ($g['n'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div>
            </section>
        </div>
    </div>
</div>
</body>
</html>
