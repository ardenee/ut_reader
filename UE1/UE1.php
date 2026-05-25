<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader.php';

$filePath = isset($_GET['file']) ? (string)$_GET['file'] : 'oldtest.utx';

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
function flag_names(array $flags): string { return $flags ? ' (' . implode(', ', $flags) . ')' : ''; }

$displayGuid = strtoupper(str_replace('-', '', (string)($hdr['guid'] ?? '')));
$searchValue = pathinfo($filePath, PATHINFO_FILENAME);

$importsByOuter = [];
foreach ($imports as $idx => $im) {
    $importsByOuter[(int)($im['outerIndex'] ?? 0)][] = (int)$idx;
}

$exportsByOuter = [];
foreach ($exports as $idx => $ex) {
    $exportsByOuter[(int)($ex['packageIndex'] ?? $ex['outerIndex'] ?? 0)][] = (int)$idx;
}

function renderImportNode(UnrealPackageReader $pkg, array $imports, array $importsByOuter, int $idx): void
{
    $im = $imports[$idx] ?? null;
    if (!is_array($im)) {
        return;
    }

    $ref = -($idx + 1);
    $object = $pkg->importObjectName((int)($im['objectName'] ?? -1));
    $class = $pkg->importClassName((int)($im['className'] ?? -1));
    $classPackage = $pkg->importClassPackageName((int)($im['classPackage'] ?? -1));
    $outer = $pkg->importPackageName((int)($im['outerIndex'] ?? 0));
    $children = $importsByOuter[$ref] ?? [];
    ?>
    <details class="tree-node" data-filter-row>
        <summary><span class="ico package">▣</span><span class="name"><?= h($object) ?></span></summary>
        <div class="tree-lines">
            <div>Object:<span class="mono"><?= h($object) ?></span><span class="muted">(<?= h($ref) ?>)</span></div>
            <div>Class:<span class="mono"><?= h($class) ?></span></div>
            <div>Package:<span class="mono"><?= h($classPackage) ?></span></div>
            <?php if ($outer !== ''): ?><div>Outer:<span class="mono"><?= h($outer) ?></span></div><?php endif; ?>
            <?php foreach ($children as $childIdx): renderImportNode($pkg, $imports, $importsByOuter, (int)$childIdx); endforeach; ?>
        </div>
    </details>
    <?php
}

function renderExportNode(UnrealPackageReader $pkg, array $exports, array $exportsByOuter, int $idx, bool $withProps = false): void
{
    $ex = $exports[$idx] ?? null;
    if (!is_array($ex)) {
        return;
    }

    $ref = $idx + 1;
    $object = $pkg->exportObjectName((int)($ex['objectName'] ?? -1));
    $class = $pkg->exportClassName((int)($ex['classIndex'] ?? 0));
    $super = $pkg->exportSuperName((int)($ex['superIndex'] ?? 0));
    $outer = $pkg->exportPackageName((int)($ex['packageIndex'] ?? 0));
    $children = $exportsByOuter[$ref] ?? [];
    $flags = (int)($ex['objectFlags'] ?? 0);
    $props = $withProps ? ($pkg->getExportProperties($idx) ?? []) : [];
    ?>
    <details class="tree-node" open data-filter-row>
        <summary><span class="ico export">≡</span><span class="name"><?= h($object) ?></span><?php if ($class !== ''): ?><span class="class-name"><?= h($class) ?></span><?php endif; ?></summary>
        <div class="tree-lines">
            <div>ObjectFlags:<span class="mono"><?= h(sprintf('%08X', $flags)) ?></span><?= h(flag_names($pkg->decodeRF($flags))) ?></div>
            <div>Object:<span class="mono"><?= h($object) ?></span><span class="muted">(<?= h($ref) ?>)</span></div>
            <div>Class:<span class="mono"><?= h($class) ?></span><?php if ((int)($ex['classIndex'] ?? 0) !== 0): ?><span class="muted">(<?= h($ex['classIndex']) ?>)</span><?php endif; ?></div>
            <?php if ($super !== ''): ?><div>Super:<span class="mono"><?= h($super) ?></span></div><?php endif; ?>
            <?php if ($outer !== ''): ?><div>Package:<span class="mono"><?= h($outer) ?></span></div><?php endif; ?>
            <div>Object Size:<span class="mono"><?= h($ex['serialSize'] ?? '') ?></span></div>
            <div>Object Offset:<span class="mono"><?= h($ex['serialOffset'] ?? '') ?></span></div>
            <?php if ($withProps && $props): ?>
                <button class="prop-btn" type="button" onclick="toggleProps(<?= (int)$idx ?>)">Properties <span><?= count($props) ?></span></button>
                <div id="props-<?= (int)$idx ?>" class="props-block"><?php renderPropsTable($props); ?></div>
            <?php endif; ?>
            <?php foreach ($children as $childIdx): renderExportNode($pkg, $exports, $exportsByOuter, (int)$childIdx, $withProps); endforeach; ?>
        </div>
    </details>
    <?php
}

function renderPropsTable(array $props): void
{
    ?>
    <table class="props-table">
        <thead><tr><th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Value</th><th>Raw</th></tr></thead>
        <tbody>
        <?php foreach ($props as $p): ?>
            <?php $val = $p['value'] ?? ''; $raw = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
            <tr><td><?= h($p['offset'] ?? '') ?></td><td><?= h($p['length'] ?? '') ?></td><td><?= h($p['name'] ?? '') ?></td><td><?= h($p['type'] ?? '') ?></td><td><?= h(is_scalar($val) ? (string)$val : print_r($val, true)) ?></td><td class="raw"><?= h($raw) ?></td></tr>
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
:root{--b:#cfd7df;--bg:#eef6f8;--panel:#fff;--muted:#f5f7f9;--text:#071629;--sub:#536471;--accent:#0969da;--soft:#eaf4ff;--orange:#c26700;}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text)}body{font-family:Segoe UI,Tahoma,Arial,sans-serif;font-size:14px}.mono{font-family:Consolas,Menlo,monospace}.muted{color:var(--sub);margin-left:3px}.raw{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}.workspace{padding:12px}.viewer{background:var(--panel);border:1px solid var(--b);min-height:650px}.doc-tabs{display:flex;margin-left:12px}.doc-tab{padding:9px 28px;border:1px solid var(--b);border-bottom:0;border-radius:6px 6px 0 0;background:#fff;font-weight:600}.toolbar{display:grid;grid-template-columns:minmax(260px,520px) 1fr;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--b);background:#fbfbfb}.searchbox{width:100%;padding:7px 9px;border:1px solid #9aa7b1;font-size:15px}.package-name{text-align:right;color:#475569}.tabs,.subtabs{display:flex;border-bottom:1px solid var(--b);background:#f8fafb}.tab,.subtab{border:0;border-right:1px solid var(--b);background:transparent;padding:10px 18px;font-weight:700;cursor:pointer}.tab.active,.subtab.active{background:#fff;color:var(--accent);box-shadow:inset 0 -2px 0 var(--accent)}.panel,.subpanel{display:none;padding:16px}.panel.active,.subpanel.active{display:block}.package-grid{display:grid;grid-template-columns:minmax(560px,760px) minmax(320px,520px);gap:24px;align-items:start;max-width:1320px}.field-grid{display:grid;grid-template-columns:170px minmax(0,1fr);gap:10px 18px;align-items:center}.field-label{font-weight:600}.field-value{min-height:30px;border:1px solid var(--b);background:#fbfbfb;padding:6px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.field-value.guid{font-size:15px;letter-spacing:.2px}.flag-table,.data{border-collapse:collapse;width:100%;margin-top:20px}.flag-table th,.flag-table td,.data th,.data td{border:1px solid var(--b);padding:7px 9px;vertical-align:top}.flag-table th,.data th{background:var(--muted);text-align:left}.flag-true{background:#0078d7;color:#fff}.tree-box{border:1px solid var(--b);background:#fff;padding:12px;margin:12px 0 18px;max-width:980px}.tree-node{margin:3px 0}.tree-node summary{cursor:pointer;list-style:none;padding:3px 4px;border-radius:3px}.tree-node summary::-webkit-details-marker{display:none}.tree-node summary:hover{background:var(--soft)}.tree-node[open]>summary:before{content:'▾ ';color:#546170}.tree-node:not([open])>summary:before{content:'▸ ';color:#546170}.ico{display:inline-block;width:22px;color:#2f5e87}.ico.export{color:#111}.name{font-weight:650}.class-name{margin-left:10px;color:var(--orange)}.tree-lines{margin-left:30px;padding-left:12px;border-left:1px dotted #aeb8c2}.tree-lines>div{padding:2px 0}.tree-lines span.mono{margin-left:5px}.content-list{max-width:780px}.content-item{display:grid;grid-template-columns:1fr auto;border-bottom:1px solid #e5e7eb;padding:6px 8px}.content-class{color:var(--orange)}.grid-after-tree details{margin-top:10px}.grid-after-tree summary{cursor:pointer;font-weight:700;color:var(--accent);padding:4px 0}.props-block{display:none;margin-top:8px;max-width:100%;overflow-x:auto}.prop-btn{margin-top:6px;border:1px solid var(--b);background:#fff;border-radius:5px;padding:4px 8px;cursor:pointer}.prop-btn span{display:inline-block;margin-left:5px;background:#e7f5ff;border:1px solid #b6dfff;border-radius:999px;padding:1px 6px}.props-table{border-collapse:collapse;width:100%;table-layout:fixed;margin-top:7px}.props-table th,.props-table td{border:1px dashed var(--b);background:#e5f2ff;padding:5px 7px;overflow-wrap:anywhere;word-break:break-word}.props-table th{background:#69beff}.names-table{table-layout:fixed}.names-table th:nth-child(1){width:24%}.names-table th:nth-child(2){width:44%}.names-table th:nth-child(3){width:10%}.names-table th:nth-child(4){width:22%}.warn{border:1px solid #d1242f;background:#fff8f8;padding:8px 12px;margin-top:14px}@media(max-width:1000px){.package-grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}.package-name{text-align:left}}
</style>
<script>
function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
function showSub(id){document.querySelectorAll('.subtab').forEach(e=>e.classList.toggle('active',e.dataset.sub===id));document.querySelectorAll('.subpanel').forEach(e=>e.classList.toggle('active',e.id===id));}
function toggleProps(i){const e=document.getElementById('props-'+i);if(e)e.style.display=(e.style.display==='block')?'none':'block';}
function filterVisibleText(input){const term=(input.value||'').toLowerCase();document.querySelectorAll('[data-filter-row]').forEach(e=>{e.style.display=e.textContent.toLowerCase().includes(term)?'':'none';});}
</script>
</head>
<body>
<div class="workspace">
    <div class="doc-tabs"><div class="doc-tab"><?= h(basename($filePath)) ?></div></div>
    <div class="viewer">
        <div class="toolbar"><input class="searchbox" type="search" value="<?= h($searchValue) ?>" oninput="filterVisibleText(this)"><span class="package-name"><?= h($filePath) ?> (<?= h($pkg->getFileSize()) ?>)</span></div>
        <div class="tabs"><button class="tab active" data-panel="package-panel" onclick="showPanel('package-panel')">▣ Package</button><button class="tab" data-panel="content-panel" onclick="showPanel('content-panel')">▤ Content</button><button class="tab" data-panel="externs-panel" onclick="showPanel('externs-panel')">⌘ Externs</button><button class="tab" data-panel="tables-panel" onclick="showPanel('tables-panel')">▦ Tables</button></div>

        <section id="package-panel" class="panel active">
            <div class="package-grid">
                <div class="field-grid"><div class="field-label">GUID</div><div class="field-value guid mono"><?= h($displayGuid) ?></div><div class="field-label">Version</div><div class="field-value mono"><?= h($hdr['version'] ?? '') ?></div><div class="field-label">Licensee Version</div><div class="field-value mono"><?= h($hdr['licensee'] ?? '') ?></div><div class="field-label">Signature</div><div class="field-value mono"><?= h(hx((int)($hdr['signature'] ?? 0))) ?></div></div>
                <div class="field-grid"><div class="field-label">Flags</div><div class="field-value mono"><?= h(hx((int)($hdr['pkgFlags'] ?? 0))) ?></div><div class="field-label">Build</div><div class="field-value">Unreal1</div><div class="field-label">Heritage</div><div class="field-value mono"><?= h(($hdr['heritageCount'] ?? '') . (($hdr['heritageOffset'] ?? '') !== '' ? ' / ' . ($hdr['heritageOffset'] ?? '') : '')) ?></div><div class="field-label">Counts</div><div class="field-value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div></div>
            </div>
            <table class="flag-table"><thead><tr><th>Flag</th><th>Condition</th></tr></thead><tbody><?php foreach ($pkgFlagsDecoded as $flag): ?><tr><td class="flag-true"><?= h(str_replace('PKG_', '', $flag)) ?></td><td>True</td></tr><?php endforeach; ?><?php if (!$pkgFlagsDecoded): ?><tr><td></td><td></td></tr><?php endif; ?></tbody></table>
            <?php if ($issues): ?><div class="warn"><strong>Validation</strong><ul><?php foreach ($issues as $w): ?><li class="mono raw"><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        </section>

        <section id="content-panel" class="panel"><div class="content-list"><?php foreach ($exports as $i=>$ex): ?><div class="content-item" data-filter-row><span class="mono"><?= h($pkg->exportObjectName((int)($ex['objectName'] ?? -1))) ?></span><span class="content-class mono"><?= h($pkg->exportClassName((int)($ex['classIndex'] ?? 0))) ?></span></div><?php endforeach; ?></div></section>

        <section id="externs-panel" class="panel"><div class="tree-box"><?php foreach (($importsByOuter[0] ?? []) as $rootIdx): renderImportNode($pkg,$imports,$importsByOuter,(int)$rootIdx); endforeach; ?><?php foreach (($exportsByOuter[0] ?? []) as $rootIdx): renderExportNode($pkg,$exports,$exportsByOuter,(int)$rootIdx,false); endforeach; ?></div></section>

        <section id="tables-panel" class="panel">
            <div class="subtabs"><button class="subtab active" data-sub="names-sub" onclick="showSub('names-sub')">☰ Names</button><button class="subtab" data-sub="exports-sub" onclick="showSub('exports-sub')">▤ Exports</button><button class="subtab" data-sub="imports-sub" onclick="showSub('imports-sub')">▧ Imports</button><button class="subtab" data-sub="gens-sub" onclick="showSub('gens-sub')">☷ Generations</button></div>
            <div id="names-sub" class="subpanel active"><h2>Names (<?= h($hdr['nameCount'] ?? '') ?>:<?= h($hdr['nameOffset'] ?? '') ?>)</h2><table class="data names-table"><thead><tr><th>Name</th><th>Flags</th><th>Num.</th><th>Raw</th></tr></thead><tbody><?php foreach ($names as $n): ?><?php $flags=(int)($n['flags']??0); ?><tr data-filter-row><td><?= h($n['name']??'') ?></td><td class="mono raw"><?= h(hx($flags)) ?><?= h(flag_names($pkg->decodeRF($flags))) ?></td><td class="mono"><?= h(($n['index']??'').' ('.hx2((int)($n['index']??0)).')') ?></td><td class="mono raw"><?= h(($n['name']??'').' / '.$flags) ?></td></tr><?php endforeach; ?></tbody></table></div>
            <div id="exports-sub" class="subpanel"><h2>Exports Tree (<?= h($hdr['exportCount'] ?? '') ?>:<?= h($hdr['exportOffset'] ?? '') ?>)</h2><div class="tree-box"><?php foreach (($exportsByOuter[0] ?? []) as $rootIdx): renderExportNode($pkg,$exports,$exportsByOuter,(int)$rootIdx,true); endforeach; ?></div><div class="grid-after-tree"><details><summary>Raw Exports Grid</summary><table class="data"><thead><tr><th>Class</th><th>Super</th><th>Package</th><th>Object</th><th>Flags</th><th>Size</th><th>Offset</th><th>Num.</th><th>Raw</th></tr></thead><tbody><?php foreach ($exports as $i=>$ex): ?><tr data-filter-row><td><?= h($pkg->exportClassName((int)($ex['classIndex']??0))) ?></td><td><?= h($pkg->exportSuperName((int)($ex['superIndex']??0))) ?></td><td><?= h($pkg->exportPackageName((int)($ex['packageIndex']??0))) ?></td><td><?= h($pkg->exportObjectName((int)($ex['objectName']??-1))) ?></td><td class="mono raw"><?= h(hx((int)($ex['objectFlags']??0))) ?></td><td><?= h($ex['serialSize']??'') ?></td><td><?= h($ex['serialOffset']??'') ?></td><td><?= h($i.' ('.hx2($i).')') ?></td><td class="mono raw"><?= h(($ex['classIndex']??'').' / '.($ex['superIndex']??'').' / '.($ex['packageIndex']??'').' / '.($ex['objectName']??'').' / '.($ex['objectFlags']??'').' / '.($ex['serialSize']??'').' / '.($ex['serialOffset']??'')) ?></td></tr><?php endforeach; ?></tbody></table></details></div></div>
            <div id="imports-sub" class="subpanel"><h2>Imports Tree (<?= h($hdr['importCount'] ?? '') ?>:<?= h($hdr['importOffset'] ?? '') ?>)</h2><div class="tree-box"><?php foreach ($imports as $i=>$im): renderImportNode($pkg,$imports,$importsByOuter,(int)$i); endforeach; ?></div><div class="grid-after-tree"><details><summary>Raw Imports Grid</summary><table class="data"><thead><tr><th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th><th>Raw</th></tr></thead><tbody><?php foreach ($imports as $i=>$im): ?><tr data-filter-row><td><?= h($pkg->importClassPackageName((int)($im['classPackage']??-1))) ?></td><td><?= h($pkg->importClassName((int)($im['className']??-1))) ?></td><td><?= h($pkg->importPackageName((int)($im['outerIndex']??0))) ?></td><td><?= h($pkg->importObjectName((int)($im['objectName']??-1))) ?></td><td><?= h($i.' ('.hx2($i).')') ?></td><td class="mono raw"><?= h(($im['classPackage']??'').' / '.($im['className']??'').' / '.($im['outerIndex']??'').' / '.($im['objectName']??'')) ?></td></tr><?php endforeach; ?></tbody></table></details></div></div>
            <div id="gens-sub" class="subpanel"><h2>Generations (<?= count($hdr['generations'] ?? []) ?>)</h2><table class="data"><thead><tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th>Raw</th></tr></thead><tbody><?php foreach (($hdr['generations']??[]) as $i=>$g): ?><tr><td><?= h($g['e']??'') ?></td><td><?= h($g['n']??'') ?></td><td><?= h($i) ?></td><td><?= h(($g['e']??'').' / '.($g['n']??'')) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
    </div>
</div>
</body>
</html>
