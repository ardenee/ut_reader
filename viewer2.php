<?php
// viewer2.php — Unreal package loader & explorer using TUnrealPackage.php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');

require_once __DIR__ . '/TUnrealPackage.php';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_hex($v): string {
    return sprintf('0x%08X', (int)$v);
}

function name_text($pkg, int $idx): string {
    if (!$pkg || !method_exists($pkg, 'nameText')) return '';
    try { return (string)$pkg->nameText($idx); } catch (Throwable $t) { return ''; }
}

function ref_text($pkg, int $ref): string {
    if (!$pkg) return '';
    try {
        if ($ref > 0 && method_exists($pkg, 'exportName')) return (string)$pkg->exportName($ref);
        if ($ref < 0 && method_exists($pkg, 'importName')) return (string)$pkg->importName($ref);
    } catch (Throwable $t) { }
    return '';
}

function int_get(array $row, array $keys, int $default = 0): int {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && is_numeric($row[$k])) return (int)$row[$k];
    }
    return $default;
}

function text_get(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) continue;
        $v = $row[$k];
        if (is_string($v) || is_numeric($v)) return (string)$v;
        if (is_array($v)) {
            foreach (['text','base','name'] as $tk) {
                if (isset($v[$tk]) && $v[$tk] !== '') return (string)$v[$tk];
            }
        }
    }
    $view = $row['view'] ?? [];
    if (is_array($view)) {
        foreach ($keys as $k) {
            if (isset($view[$k]) && (is_string($view[$k]) || is_numeric($view[$k]))) return (string)$view[$k];
        }
    }
    return $default;
}

function export_name($pkg, array $row, int $fallbackIndex): string {
    $view = $row['view'] ?? [];
    if (is_array($view) && !empty($view['objectNameText'])) return (string)$view['objectNameText'];

    $direct = text_get($row, ['objectNameText','ObjectNameText','name','Name'], '');
    if ($direct !== '') return $direct;

    $nameIndex = int_get($row, ['objectName','ObjectName','nameIndex','NameIndex'], -1);
    if ($nameIndex >= 0) {
        $name = name_text($pkg, $nameIndex);
        if ($name !== '') return $name;
    }

    return 'Export #' . $fallbackIndex;
}

function export_class($pkg, array $row): string {
    $view = $row['view'] ?? [];
    if (is_array($view) && !empty($view['classNameText'])) return (string)$view['classNameText'];

    $direct = text_get($row, ['classNameText','ClassNameText','className','ClassName'], '');
    if ($direct !== '') return $direct;

    $ref = int_get($row, ['classIndex','ClassIndex','class'], 0);
    return ref_text($pkg, $ref);
}

function export_group($pkg, array $row): string {
    $view = $row['view'] ?? [];
    if (is_array($view)) {
        if (!empty($view['outerChain']) && is_array($view['outerChain'])) return implode('.', array_filter($view['outerChain']));
        if (!empty($view['packageNameText'])) return (string)$view['packageNameText'];
    }

    $direct = text_get($row, ['packageNameText','group','package','outerName'], '');
    if ($direct !== '') return $direct;

    $ref = int_get($row, ['packageIndex','PackageIndex','outerIndex','OuterIndex','outer'], 0);
    return ref_text($pkg, $ref);
}

function import_name($pkg, array $row, int $fallbackIndex): string {
    $view = $row['view'] ?? [];
    if (is_array($view) && !empty($view['objectNameText'])) return (string)$view['objectNameText'];

    $direct = text_get($row, ['objectNameText','ObjectNameText','objectName','ObjectName','name'], '');
    if ($direct !== '' && !is_numeric($direct)) return $direct;

    $idx = int_get($row, ['objectName','ObjectName'], -1);
    if ($idx >= 0) {
        $name = name_text($pkg, $idx);
        if ($name !== '') return $name;
    }

    return 'Import #' . $fallbackIndex;
}

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

$defaultCandidates = [__DIR__ . '/test.ut3', __DIR__ . '/test.utx'];
$default = null;
foreach ($defaultCandidates as $candidate) {
    if (is_file($candidate)) { $default = $candidate; break; }
}

$file = $uploaded ?: $default;
$pkg = null;
$err = $uploadError;

if ($file && !$err) {
    try {
        $pkg = TPackageReader::open($file);
        if (method_exists($pkg, 'annotateTablesWithText')) {
            $pkg->annotateTablesWithText();
        }
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
<html lang="en">
<head>
<meta charset="utf-8">
<title>Unreal Package Viewer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
  --bg:#0f1116; --panel:#161a22; --muted:#8892a6; --outline:#95b8ff; --accent:#66aaff; --border:#252b36; --hi:#dfe7ff; --err:#ff9f9f;
}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:#e8eefc}
a{color:var(--accent);text-decoration:none}
header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
h1{margin:0;font-weight:700;color:var(--outline)}
form.uploader{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
input[type=file]{color:#e8eefc}
input[type=submit],button,select{background:var(--panel);color:#e8eefc;border:1px solid var(--border);padding:.5rem .75rem;border-radius:.5rem;cursor:pointer}
main{padding:1rem;display:grid;grid-template-columns:320px 1fr;gap:1rem}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.card h2{margin:0;padding:.85rem 1rem;border-bottom:1px solid var(--border);color:var(--outline)}
.card .body{padding:.85rem 1rem}
.card.exports{grid-column:1 / -1}
.card.imports{grid-column:1 / -1}
.table-wrap{overflow:auto;max-height:65vh}
table{width:100%;border-collapse:collapse;font-size:.95rem}
th,td{padding:.5rem .6rem;border-bottom:1px solid var(--border);vertical-align:top;text-align:left}
tr:hover td{background:#141923}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}
.wrap{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;line-break:anywhere}
.table td,.table th{white-space:nowrap}
.table .col-flags{white-space:normal;min-width:18rem}
.dim{opacity:.65;font-size:.9em}
.err{color:var(--err);background:#2a1116;border:1px solid #55303a;border-radius:8px;padding:.75rem;margin:.5rem 0}
.controls{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;padding:.5rem 0}
.controls input[type=search]{flex:1 1 360px;min-width:220px;background:#0f131b;border:1px solid var(--border);padding:.6rem .75rem;border-radius:.75rem;color:#e8eefc}
.controls select.sort{min-width:160px}
.row-click{cursor:pointer}
.props-row{display:none}
.badge{display:inline-block;padding:.15rem .45rem;border:1px solid var(--border);border-radius:.5rem;color:var(--hi);background:#121620;margin-right:.35rem}
</style>
</head>
<body>
<header>
  <h1>Unreal Package Viewer</h1>
  <form class="uploader" method="post" enctype="multipart/form-data">
    <input type="file" name="file" accept=".utx,.u,.umx,.uax,.unr,.ukx,.usx,.ut2,.upkg,.upk,.ut3,.xxx">
    <input type="submit" value="Open">
    <?php if ($file): ?><span class="mono">Loaded: <?=h(basename($file))?></span><?php endif; ?>
  </form>
</header>

<main>
  <div class="card">
    <h2>Package Info</h2>
    <div class="body">
      <?php if ($err): ?>
        <div class="err"><strong>Error:</strong> <?=h($err)?></div>
      <?php endif; ?>
      <?php if (!$pkg): ?>
        <p>Upload a package to inspect.</p>
      <?php else: ?>
        <div class="mono">
          <div>File: <?=h($file)?></div>
          <div>Version: <?=h($hdr['version'] ?? '')?></div>
          <div>Name count / offset: <?= (int)($hdr['nameCount']??0) ?> @ <?= fmt_hex((int)($hdr['nameOffset']??0)) ?></div>
          <div>Import count / offset: <?= (int)($hdr['importCount']??0) ?> @ <?= fmt_hex((int)($hdr['importOffset']??0)) ?></div>
          <div>Export count / offset: <?= (int)($hdr['exportCount']??0) ?> @ <?= fmt_hex((int)($hdr['exportOffset']??0)) ?></div>
          <div>Package flags: <?= fmt_hex((int)($hdr['packageFlags'] ?? $hdr['pkgFlags'] ?? 0)) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Names</h2>
    <div class="body">
      <?php if (!empty($names)): ?>
      <div class="table-wrap">
        <table class="table mono">
          <thead><tr><th style="width:7rem">Num.</th><th>Name</th><th class="col-flags">Flags</th></tr></thead>
          <tbody>
            <?php foreach ($names as $idx => $row):
              $row = (array)$row;
              $nidx = (int)($row['index'] ?? $idx);
              $nm = (string)($row['name'] ?? ($row['raw']['name'] ?? ''));
              $fl = (int)($row['flags'] ?? ($row['raw']['flags'] ?? 0));
            ?>
              <tr><td><?= $nidx ?> (<?= fmt_hex($nidx) ?>)</td><td><?= h($nm) ?></td><td class="col-flags"><?= h(fmt_hex($fl)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <small>No names found.</small>
      <?php endif; ?>
    </div>
  </div>

  <div class="card exports">
    <h2>Exports</h2>
    <div class="body">
      <div class="controls">
        <input type="search" id="q" placeholder="Filter by name / class / package…">
        <select id="classFilter"><option value="">All classes</option></select>
        <select id="sort" class="sort"><option value="idx">Sort: Index</option><option value="name">Sort: Name</option><option value="class">Sort: Class</option><option value="size">Sort: Size</option></select>
      </div>

      <?php if (!empty($exports)): ?>
      <div class="table-wrap">
        <table id="exportsTable" class="table">
          <thead>
            <tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th class="col-flags">Flags</th><th>Raw refs</th></tr>
          </thead>
          <tbody>
          <?php foreach ($exports as $i => $e):
            $e = (array)$e;
            $classRef = int_get($e, ['classIndex','ClassIndex','class'], 0);
            $superRef = int_get($e, ['superIndex','SuperIndex','super'], 0);
            $pkgRef = int_get($e, ['packageIndex','PackageIndex','outerIndex','OuterIndex','outer'], 0);
            $nameIdx = int_get($e, ['objectName','ObjectName','nameIndex','NameIndex'], -1);
            $flags = int_get($e, ['objectFlags','ObjectFlags','flags'], 0);
            $size = int_get($e, ['serialSize','SerialSize','size'], 0);
            $offset = int_get($e, ['serialOffset','SerialOffset','offset'], 0);

            $group = export_group($pkg, $e);
            $name = export_name($pkg, $e, $i);
            $className = export_class($pkg, $e);
            $superName = ref_text($pkg, $superRef);
            $raw = sprintf('%d / %d / %d / %d', $classRef, $superRef, $pkgRef, $nameIdx);
          ?>
            <tr id="exp-<?= $i ?>" class="data row-click" data-idx="<?= $i ?>" data-size="<?= $size ?>" data-name="<?= h($name) ?>" data-class="<?= h($className) ?>" data-pkg="<?= h($group) ?>">
              <td><?= h($group) ?></td>
              <td><span class="badge">#<?= $i ?></span> <?= h($name) ?></td>
              <td><?= h($className) ?></td>
              <td class="mono"><?= $i ?> (<?= fmt_hex($i) ?>)</td>
              <td><?= h($superName) ?></td>
              <td class="mono"><?= number_format($size) ?></td>
              <td class="mono"><?= fmt_hex($offset) ?></td>
              <td class="mono col-flags"><?= h(fmt_hex($flags)) ?></td>
              <td class="mono dim"><?= h($raw) ?></td>
            </tr>
            <tr class="props-row"><td colspan="9">
              <table class="table mono"><tbody>
              <?php foreach ($e as $k => $v): ?>
                <tr><td><?= h($k) ?></td><td class="wrap"><?= h(is_scalar($v) ? (string)$v : json_encode($v, JSON_PRETTY_PRINT)) ?></td></tr>
              <?php endforeach; ?>
              </tbody></table>
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

  <div class="card imports">
    <h2>Imports</h2>
    <div class="body">
      <?php if (!empty($imports)): ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Name</th><th>Class</th><th>Class Package</th><th>Outer</th><th>Num.</th><th>Raw refs</th></tr></thead>
          <tbody>
          <?php foreach ($imports as $i => $im):
            $im = (array)$im;
            $classPkgIdx = int_get($im, ['classPackage','ClassPackage'], -1);
            $classIdx = int_get($im, ['className','ClassName'], -1);
            $outerRef = int_get($im, ['outerIndex','OuterIndex','outer'], 0);
            $objIdx = int_get($im, ['objectName','ObjectName'], -1);
            $name = import_name($pkg, $im, $i);
            $class = ($classIdx >= 0) ? name_text($pkg, $classIdx) : text_get($im, ['classNameText'], '');
            $classPkg = ($classPkgIdx >= 0) ? name_text($pkg, $classPkgIdx) : text_get($im, ['classPackageName'], '');
            $outer = ref_text($pkg, $outerRef);
            $raw = sprintf('%d / %d / %d / %d', $classPkgIdx, $classIdx, $outerRef, $objIdx);
          ?>
            <tr><td><?= h($name) ?></td><td><?= h($class) ?></td><td><?= h($classPkg) ?></td><td><?= h($outer) ?></td><td class="mono"><?= $i ?> (<?= fmt_hex($i) ?>)</td><td class="mono dim"><?= h($raw) ?></td></tr>
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
const q = document.getElementById('q');
const classFilter = document.getElementById('classFilter');
const sortSel = document.getElementById('sort');
const table = document.getElementById('exportsTable');
const tbody = table ? table.querySelector('tbody') : null;
function dataRows(){ return tbody ? [...tbody.querySelectorAll('tr.data')] : []; }
function propsRowFor(dataRow){ return dataRow?.nextElementSibling && dataRow.nextElementSibling.classList.contains('props-row') ? dataRow.nextElementSibling : null; }
function populateClassFilter(){
  if (!classFilter) return;
  const vals = [...new Set(dataRows().map(r => r.dataset.class || '').filter(Boolean))].sort();
  vals.forEach(v => { const o = document.createElement('option'); o.value = v; o.textContent = v; classFilter.appendChild(o); });
}
function applyFilterSort(){
  const qv = (q?.value || '').toLowerCase().trim();
  const cf = (classFilter?.value || '').toLowerCase();
  const metric = (sortSel?.value || 'idx');
  const rows = dataRows();
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
  const sorted = rows.slice().sort((a,b)=>{
    if (metric === 'idx') return (+a.dataset.idx) - (+b.dataset.idx);
    if (metric === 'size') return (+b.dataset.size) - (+a.dataset.size);
    if (metric === 'name') return a.dataset.name.localeCompare(b.dataset.name);
    if (metric === 'class') return a.dataset.class.localeCompare(b.dataset.class);
    return 0;
  });
  sorted.forEach(dr=>{ const pr = propsRowFor(dr); tbody.appendChild(dr); if (pr) tbody.appendChild(pr); });
}
if (tbody){
  tbody.addEventListener('click', (e)=>{
    let tr = e.target.closest('tr.data');
    if (!tr) return;
    const pr = propsRowFor(tr);
    if (!pr) return;
    const open = pr.style.display === '' || pr.dataset.open==='1';
    pr.style.display = open ? 'none' : '';
    pr.dataset.open = open ? '0' : '1';
  });
}
populateClassFilter();
[q, classFilter, sortSel].forEach(el=> el && el.addEventListener('input', applyFilterSort));
applyFilterSort();
</script>
</body>
</html>
