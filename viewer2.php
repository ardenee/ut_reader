<?php
// viewer2.php — Unreal package loader & explorer
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

require_once __DIR__ . '/UnrealPackageReader.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dump_pre($x){ return '<pre>'.h(print_r($x,true)).'</pre>'; }

$uploaded = null;
$uploadError = null;
$uploadDir = __DIR__ . '/uploads';

if (!empty($_FILES['file']['tmp_name'])) {
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        $uploadError = 'Upload folder does not exist and could not be created: ' . $uploadDir;
    } elseif (!is_writable($uploadDir)) {
        $uploadError = 'Upload folder is not writable by PHP/Web Station: ' . $uploadDir;
    } elseif (!is_uploaded_file($_FILES['file']['tmp_name'])) {
        $uploadError = 'Upload failed: invalid temporary upload file.';
    } else {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($_FILES['file']['name']));
        $uploaded = $uploadDir . '/' . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploaded)) {
            $uploadError = 'Unable to move uploaded file to: ' . $uploaded;
            $uploaded = null;
        }
    }
}

$default = __DIR__ . '/test.utx';
$file = $uploaded ?: (is_file($default) ? $default : null);
$pkg = null;
$err = $uploadError;
if ($file && !$err) {
    try {
        $pkg = new UnrealPackageReader($file);
    } catch (Throwable $t) {
        $err = $t->getMessage();
    }
}
$names   = $pkg ? $pkg->getNames()   : [];
$imports = $pkg ? $pkg->getImports() : [];
$exports = $pkg ? $pkg->getExports() : [];
$hdr     = $pkg ? $pkg->getHeader()  : [];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Unreal Package Viewer</title>
<style>
:root {
  --bg:#0f1116; --panel:#161a22; --muted:#8892a6; --outline:#95b8ff; --accent:#66aaff; --border:#252b36; --hi:#dfe7ff;
}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:#e8eefc}
a{color:var(--accent);text-decoration:none}
header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
h1{margin:0;font-weight:700;color:var(--outline)}
form.uploader{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
input[type=file]{color:#e8eefc}
input[type=submit],button,select{
  background:var(--panel); color:#e8eefc; border:1px solid var(--border);
  padding:.5rem .75rem; border-radius:.5rem; cursor:pointer;
}
main{padding:1rem; display:grid; grid-template-columns: 320px 1fr; gap:1rem}
.card{background:var(--panel); border:1px solid var(--border); border-radius:12px; overflow:hidden}
.card h2{margin:0; padding:.85rem 1rem; border-bottom:1px solid var(--border); color:var(--outline)}
.card .body{padding: .85rem 1rem}
.card.exports{grid-column:1 / -1}
.table-wrap{overflow:auto; max-height:65vh}
table{width:100%; border-collapse:collapse; font-size:.95rem}
th,td{padding:.5rem .6rem; border-bottom:1px solid var(--border); vertical-align:top}
tr:hover td{background:#141923}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace}
.wrap{white-space:pre-wrap; overflow-wrap:anywhere; word-break:break-word; line-break:anywhere;}
/* Keep most cols compact; let Flags expand */
.table td, .table th { white-space: nowrap; }
.table .col-flags { white-space: normal; min-width: 18rem; }
.dim{opacity:.65; font-size:.9em}

.controls{display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; padding: .5rem 0}
.controls input[type=search]{flex:1 1 360px; min-width:220px; background:#0f131b; border:1px solid var(--border); padding:.6rem .75rem; border-radius:.75rem; color:#e8eefc}
.controls select.sort{min-width:160px}

.row-click{cursor:pointer}
.props-row{display:none}
.badge{display:inline-block; padding:.15rem .45rem; border:1px solid var(--border); border-radius:.5rem; color:var(--hi); background:#121620; margin-right:.35rem}
</style>
</head>
<body>
<header>
  <h1>Unreal Package Viewer</h1>
  <form class="uploader" method="post" enctype="multipart/form-data">
    <input type="file" name="file" accept=".utx,.u,.umx,.uax,.unr,.ukx,.usx,.ut2,.upkg,.upk,.ut3">
    <input type="submit" value="Open">
    <?php if ($file): ?><span class="mono">Loaded: <?=h(basename($file))?></span><?php endif; ?>
  </form>
</header>

<main>

  <!-- Left column: Package info -->
  <div class="card">
    <h2>Package Info</h2>
    <div class="body">
      <?php if (!$pkg): ?>
        <?php if ($err): ?><div style="color:#ff9f9f"><?=h($err)?></div><?php endif; ?>
        <p>Upload a package to inspect.</p>
      <?php else: ?>
        <div class="mono">
          <div>File: <?=h($file)?></div>
          <div>Version: <?=h($hdr['version'] ?? '')?></div>
          <div>Name count / offset: <?= (int)($hdr['nameCount']??0) ?> @ <?= sprintf('0x%08X', (int)($hdr['nameOffset']??0)) ?></div>
          <div>Import count / offset: <?= (int)($hdr['importCount']??0) ?> @ <?= sprintf('0x%08X', (int)($hdr['importOffset']??0)) ?></div>
          <div>Export count / offset: <?= (int)($hdr['exportCount']??0) ?> @ <?= sprintf('0x%08X', (int)($hdr['exportOffset']??0)) ?></div>
          <div>Package flags:
            <?php $pf = (int)($hdr['pkgFlags'] ?? 0);
              $arr = $pkg->decodePKG($pf);
              $hex = ($pf > 0xFFFFFFFF) ? sprintf('0x%016X',$pf) : sprintf('0x%08X',$pf);
              echo ' ' . h($hex . (empty($arr)?'':(' (' . implode(', ', $arr) . ')')));
            ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right column: Names table -->
  <div class="card">
    <h2>Names</h2>
    <div class="body">
      <?php if (!empty($names)): ?>
      <div class="table-wrap">
        <table class="table mono">
          <thead>
            <tr><th style="width:7rem">Num.</th><th>Name</th><th class="col-flags">Flags</th></tr>
          </thead>
          <tbody>
            <?php foreach ($names as $row):
              $idx = (int)($row['index'] ?? 0);
              $nm  = (string)($row['name']  ?? '');
              $fl  = (int)($row['flags'] ?? 0);
              $namesArr = $pkg->decodeRF($fl);
              $hex      = ($fl > 0xFFFFFFFF) ? sprintf("0x%016X",$fl) : sprintf("0x%08X",$fl);
              $flagsDisplay = $hex . (empty($namesArr) ? '' : (' (' . implode(', ', $namesArr) . ')'));
            ?>
              <tr data-idx="<?= $idx ?>">
                <td><?= $idx ?> (<?= sprintf("0x%02X",$idx) ?>)</td>
                <td><?= h($nm) ?></td>
                <td class="col-flags"><?= h($flagsDisplay) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <small>No names found.</small>
      <?php endif; ?>
    </div>
  </div>

  <!-- Exports single table (clickable rows) -->
  <div class="card exports">
    <h2>Exports</h2>
    <div class="body">
      <div class="controls">
        <input type="search" id="q" placeholder="Filter by name / class / package…">
        <select id="classFilter">
          <option value="">All classes</option>
          <option>Texture</option><option>Palette</option><option>TextBuffer</option>
          <option>Sound</option><option>Music</option>
          <option>Mesh</option><option>LodMesh</option><option>SkeletalMesh</option><option>Animation</option>
          <option>Function</option><option>State</option><option>Class</option>
        </select>
        <select id="sort" class="sort">
          <option value="idx">Sort: Index</option>
          <option value="name">Sort: Name</option>
          <option value="class">Sort: Class</option>
          <option value="size">Sort: Size</option>
        </select>
      </div>

      <?php if (!empty($exports)): ?>
      <div class="table-wrap">
        <table id="exportsTable" class="table">
          <thead>
            <tr>
              <th>Group</th>
              <th>Name</th>
              <th>Class</th>
              <th>Num.</th>
              <th>Super</th>
              <th>Size</th>
              <th>Offset</th>
              <th class="col-flags">Flags</th>
              <th>Raw (class / super / pkg / name)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($exports as $i => $e):
            $classRef  = (int)($e['classIndex'] ?? 0);
            $superRef  = (int)($e['superIndex'] ?? 0);
            $pkgRef    = (int)($e['packageIndex'] ?? 0);
            $nameIdx   = (int)($e['objectName'] ?? 0);
            $flags     = (int)($e['objectFlags'] ?? 0);
            $size      = (int)($e['serialSize'] ?? 0);
            $offset    = (int)($e['serialOffset'] ?? 0);

            $group     = (string)$pkg->exportGroupPath($i);
            $name      = (string)($pkg->nameByIndex($nameIdx) ?? '');
            $className = (string)$pkg->exportClassName($i);
            $superName = (string)$pkg->displayNameFromRef($superRef);

            $flagsArr  = (array)$pkg->decodeRF($flags);
            $flagsHex  = ($flags > 0xFFFFFFFF) ? sprintf('0x%016X',$flags) : sprintf('0x%08X',$flags);
            $flagsDisp = $flagsHex . (empty($flagsArr) ? '' : (' (' . implode(', ', $flagsArr) . ')'));

            $raw       = sprintf('%d / %d / %d / %d', $classRef, $superRef, $pkgRef, $nameIdx);
          ?>
            <tr id="exp-<?= $i ?>" class="data row-click"
                data-idx="<?= $i ?>" data-size="<?= $size ?>"
                data-name="<?= h($name) ?>" data-class="<?= h($className) ?>" data-pkg="<?= h($group) ?>">
              <td><?= h($group) ?></td>
              <td><span class="badge">#<?= $i ?></span> <?= h($name) ?></td>
              <td><?= h($className) ?></td>
              <td class="mono"><?= $i ?> (<?= sprintf('0x%02X',$i) ?>)</td>
              <td><?= h($superName) ?></td>
              <td class="mono"><?= number_format($size) ?></td>
              <td class="mono"><?= sprintf('0x%08X',$offset) ?></td>
              <td class="mono col-flags"><?= h($flagsDisp) ?></td>
              <td class="mono dim"><?= h($raw) ?></td>
            </tr>
            <tr class="props-row"><td colspan="9">
              <?php $props = $pkg->getExportProperties($i) ?? []; ?>
              <?php if ($props): ?>
                <table class="table mono">
                  <thead><tr><th>Property</th><th>Value</th></tr></thead>
                  <tbody>
                    <?php foreach ($props as $k => $v): ?>
                      <tr><td><?= h($k) ?></td><td class="wrap"><?= h(is_scalar($v) ? (string)$v : json_encode($v, JSON_PRETTY_PRINT)) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <small>No properties.</small>
              <?php endif; ?>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <small>No exports.</small>
      <?php endif; ?>

    </div>
  </div>

  <!-- Imports table -->
  <div class="card">
    <h2>Imports</h2>
    <div class="body">
      <?php if (!empty($imports)): ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Package / Group</th>
              <th>Name</th>
              <th>Class</th>
              <th>Class Package</th>
              <th>Num.</th>
              <th>Raw (classPkg / className / outer / objName)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($imports as $i => $im):
            $classPkgIdx = (int)($im['classPackage'] ?? 0);
            $classIdx    = (int)($im['className'] ?? 0);
            $outerRef    = (int)($im['outerIndex'] ?? 0);
            $objIdx      = (int)($im['objectName'] ?? 0);

            $pkgGroup = (string)$pkg->importGroupPath($i);
            $name     = (string)($pkg->nameByIndex($objIdx)      ?? '');
            $class    = (string)($pkg->nameByIndex($classIdx)    ?? '');
            $classPkg = (string)($pkg->nameByIndex($classPkgIdx) ?? '');

            $raw = sprintf('%d / %d / %d / %d', $classPkgIdx, $classIdx, $outerRef, $objIdx);
          ?>
            <tr>
              <td><?= h($pkgGroup) ?></td>
              <td><?= h($name) ?></td>
              <td><?= h($class) ?></td>
              <td><?= h($classPkg) ?></td>
              <td class="mono"><?= $i ?> (<?= sprintf('0x%02X', $i) ?>)</td>
              <td class="mono dim"><?= h($raw) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <small>No imports.</small>
      <?php endif; ?>
    </div>
  </div>

</main>

<script>
// ------- Exports filter + sort for the table (row pairs) -------
const q = document.getElementById('q');
const classFilter = document.getElementById('classFilter');
const sortSel = document.getElementById('sort');
const table = document.getElementById('exportsTable');
const tbody = table ? table.querySelector('tbody') : null;

function dataRows(){
  return tbody ? [...tbody.querySelectorAll('tr.data')] : [];
}
function propsRowFor(dataRow){
  return dataRow?.nextElementSibling && dataRow.nextElementSibling.classList.contains('props-row')
    ? dataRow.nextElementSibling : null;
}

function applyFilterSort(){
  const qv = (q?.value || '').toLowerCase().trim();
  const cf = (classFilter?.value || '').toLowerCase();
  const metric = (sortSel?.value || 'idx');

  const rows = dataRows();
  // filter
  rows.forEach(r=>{
    const n = (r.dataset.name || '').toLowerCase();
    const c = (r.dataset.class || '').toLowerCase();
    const p = (r.dataset.pkg || '').toLowerCase();
    const okQ = !qv || n.includes(qv) || c.includes(qv) || p.includes(qv);
    const okC = !cf || c === cf;
    const show = okQ && okC;
    r.style.display = show ? '' : 'none';
    const pr = propsRowFor(r);
    if (pr) pr.style.display = (show && pr.dataset.open==='1') ? '' : 'none';
  });

  // sort (move pairs together)
  const sorted = rows.slice().sort((a,b)=>{
    if (metric === 'idx')  return (+a.dataset.idx) - (+b.dataset.idx);
    if (metric === 'size') return (+b.dataset.size) - (+a.dataset.size);
    if (metric === 'name') return a.dataset.name.localeCompare(b.dataset.name);
    if (metric === 'class')return a.dataset.class.localeCompare(b.dataset.class);
    return 0;
  });
  sorted.forEach(dr=>{
    const pr = propsRowFor(dr);
    tbody.appendChild(dr);
    if (pr) tbody.appendChild(pr);
  });
}

// click to toggle properties
if (tbody){
  tbody.addEventListener('click', (e)=>{
    let tr = e.target.closest('tr.data');
    if (!tr) return;
    const pr = propsRowFor(tr);
    if (!pr) return;
    const open = pr.style.display === '' || pr.dataset.open==='1';
    if (open){
      pr.style.display = 'none';
      pr.dataset.open = '0';
    } else {
      pr.style.display = '';
      pr.dataset.open = '1';
    }
  });
}

[q, classFilter, sortSel].forEach(el=> el && el.addEventListener('input', applyFilterSort));
applyFilterSort();
</script>
</body>
</html>
