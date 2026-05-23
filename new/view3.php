<?php
require_once __DIR__ . '/TUnrealPackage2.php';

// ---- config ----
$path = $argv[1] ?? 'test.ut3'; // CLI: php view2.php myfile.uxx  OR set directly
// For web: $path = $_GET['file'] ?? 'test.ut3';

try {
    $pkg     = TPackageReader::open($path);
    $hdr     = $pkg->getHeader();
    $names   = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();
    $error   = null;
} catch (\Throwable $e) {
    $error = $e->getMessage();
    $hdr = $names = $imports = $exports = [];
    $pkg = null;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function hex32(int $v): string { return sprintf('0x%08X', $v & 0xFFFFFFFF); }
function hex64(int $v): string { return sprintf('0x%016X', $v); }
function fmtGuid(array $g): string {
    return sprintf('%08X-%08X-%08X-%08X', $g[0]??0, $g[1]??0, $g[2]??0, $g[3]??0);
}
function flagBadge(string $name): string {
    return '<span class="badge">' . h($name) . '</span>';
}
function renderFlags(int $flags, array $map): string {
    $bits = decodeFlagBits($flags, $map);
    if (!$bits) return '<span class="muted">—</span>';
    return implode(' ', array_map('flagBadge', $bits));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Unreal Package Inspector</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root {
  --bg: #f8fafc; --card: #fff; --border: #e2e8f0;
  --accent: #2563eb; --accent2: #7c3aed;
  --text: #0f172a; --muted: #64748b; --sub: #94a3b8;
  --green: #16a34a; --amber: #d97706; --red: #dc2626;
  --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
* { box-sizing: border-box; }
body { font: 14px/1.5 ui-sans-serif,system-ui,sans-serif; background: var(--bg); color: var(--text); margin: 0; }
a { color: var(--accent); }

/* ---- layout ---- */
.page { max-width: 1400px; margin: 0 auto; padding: 20px 16px; }
h1 { font-size: 22px; margin: 0 0 4px; }
.filepath { color: var(--muted); font: 13px var(--mono); margin-bottom: 20px; }
.error { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 14px 18px; color: var(--red); margin-bottom: 20px; }

/* ---- section ---- */
.section { margin-bottom: 24px; }
.section-hdr {
  display: flex; align-items: center; gap: 10px;
  font-weight: 700; font-size: 13px; letter-spacing: .04em; text-transform: uppercase;
  color: var(--muted); margin-bottom: 8px; cursor: pointer; user-select: none;
}
.section-hdr .chev { display: inline-block; width: 8px; height: 8px; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(45deg); transition: transform 120ms; }
.section-hdr.closed .chev { transform: rotate(-45deg); }
.section-body { display: block; }
.section-body.hidden { display: none; }

/* ---- card / table ---- */
.card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.kv-grid { display: grid; grid-template-columns: 200px 1fr; gap: 1px; background: var(--border); }
.kv-row { display: contents; }
.kv-row > * { background: var(--card); padding: 7px 14px; }
.kv-row .k { color: var(--muted); font-size: 12px; }
.kv-row .v { font: 13px var(--mono); word-break: break-all; }

table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th { background: #f1f5f9; padding: 9px 12px; text-align: left; font-weight: 600; color: var(--muted); font-size: 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
tbody td { padding: 7px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }
.mono { font-family: var(--mono); font-size: 12px; }
.muted { color: var(--muted); }
.nowrap { white-space: nowrap; }

/* ---- badges ---- */
.badge { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; margin: 1px; white-space: nowrap; }
.badge.red { background: #fef2f2; color: var(--red); border-color: #fca5a5; }
.badge.green { background: #f0fdf4; color: var(--green); border-color: #bbf7d0; }
.badge.purple { background: #f5f3ff; color: var(--accent2); border-color: #ddd6fe; }

/* ---- expand rows ---- */
.parent-row { cursor: pointer; }
.parent-row:hover td { background: #f8fafc; }
.parent-row .row-chev { display: inline-block; width: 8px; height: 8px; border-right: 2px solid var(--accent); border-bottom: 2px solid var(--accent); transform: rotate(-45deg); transition: transform 120ms; margin-right: 6px; }
.parent-row.open .row-chev { transform: rotate(45deg); }
.detail-row { display: none; background: #f8fafc; }
.detail-row.open { display: table-row; }
.detail-cell { padding: 12px 16px 12px 40px !important; }
.detail-grid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 16px; }
.detail-grid .dk { color: var(--muted); font-size: 12px; }
.detail-grid .dv { font: 12px var(--mono); word-break: break-all; }

/* ---- search ---- */
.search-row { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
.search-row input { flex: 1; padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.search-row .count { color: var(--muted); font-size: 12px; white-space: nowrap; }

/* ---- tabs ---- */
.tabs { display: flex; gap: 2px; margin-bottom: 12px; border-bottom: 1px solid var(--border); }
.tab { padding: 6px 16px; font-size: 13px; font-weight: 500; cursor: pointer; color: var(--muted); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tab.active { color: var(--accent); border-color: var(--accent); }

/* version pill */
.ver-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #eff6ff; color: var(--accent); border: 1px solid #bfdbfe; }
</style>
</head>
<body>
<div class="page">

<h1>Unreal Package Inspector</h1>
<div class="filepath"><?= h($path) ?></div>

<?php if ($error): ?>
<div class="error"><strong>Error:</strong> <?= h($error) ?></div>
<?php else: ?>

<?php
  $ver = (int)($hdr['version'] ?? 0);
  $ue  = $ver >= 500 ? 'UE4' : ($ver >= 300 ? 'UE3' : ($ver >= 100 ? 'UE2' : 'UE1'));
?>

<!-- ============================================================ HEADER -->
<div class="section">
  <div class="section-hdr" onclick="toggleSection(this)">
    <span class="chev"></span> Package Header
    <span class="ver-pill"><?= h($ue) ?> v<?= h($ver) ?></span>
    <?php if (!empty($hdr['compressed'])): ?>
      <span class="badge purple">Compressed</span>
    <?php endif; ?>
  </div>
  <div class="section-body">
  <div class="card">
  <div class="kv-grid">

    <?php
    $rows = [
      'Tag'               => sprintf('0x%08X', $hdr['tag'] ?? 0),
      'Version'           => ($hdr['version'] ?? '?') . ' (' . $ue . ')',
      'Licensee Version'  => $hdr['licenseeVersion'] ?? 0,
      'Engine Version'    => $hdr['engineVersion']   ?? '—',
      'Cooker Version'    => $hdr['cookerVersion']   ?? '—',
      'Folder'            => $hdr['folderName']      ?? '—',
      'GUID'              => fmtGuid((array)($hdr['guid'] ?? [])),
      'Names'             => count($names) . ' entries @ ' . hex32($hdr['nameOffset'] ?? 0),
      'Exports'           => count($exports) . ' entries @ ' . hex32($hdr['exportOffset'] ?? 0),
      'Imports'           => count($imports) . ' entries @ ' . hex32($hdr['importOffset'] ?? 0),
      'Depends Offset'    => isset($hdr['dependsOffset']) ? hex32($hdr['dependsOffset']) : '—',
      'Generations'       => count($hdr['generations'] ?? []),
      'Compressed'        => ($hdr['compressed'] ?? false) ? 'Yes' : 'No',
      'Compression Flags' => ($hdr['compressionFlags'] ?? 0) ?
          sprintf('0x%08X', $hdr['compressionFlags']) .
          (isset($pkg) && method_exists($pkg,'describeCompressionFlags') ?
            ' (' . $pkg->describeCompressionFlags((int)$hdr['compressionFlags']) . ')' : '')
          : 'None',
    ];
    foreach ($rows as $k => $v): ?>
    <div class="kv-row"><div class="k"><?= h($k) ?></div><div class="v"><?= h($v) ?></div></div>
    <?php endforeach; ?>

    <!-- Package Flags -->
    <div class="kv-row">
      <div class="k">Package Flags</div>
      <div class="v" style="font-size:13px">
        <?= h(hex32($hdr['packageFlags'] ?? 0)) ?> &nbsp;
        <?= renderFlags((int)($hdr['packageFlags'] ?? 0), PKG_FLAGS) ?>
      </div>
    </div>

  </div><!-- kv-grid -->

  <?php if (!empty($hdr['generations'])): ?>
  <div style="padding:10px 14px;border-top:1px solid var(--border)">
    <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px">GENERATIONS</div>
    <table><thead><tr><th>#</th><th>Exports</th><th>Names</th><th>Net Objects</th></tr></thead><tbody>
    <?php foreach ($hdr['generations'] as $gi => $g): ?>
    <tr>
      <td class="mono muted"><?= $gi ?></td>
      <td class="mono"><?= h($g['exportCount'] ?? 0) ?></td>
      <td class="mono"><?= h($g['nameCount']   ?? 0) ?></td>
      <td class="mono"><?= h($g['netObjectCount'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>

  <?php if (!empty($hdr['chunks'])): ?>
  <div style="padding:10px 14px;border-top:1px solid var(--border)">
    <div style="font-size:12px;color:var(--muted);font-weight:600;margin-bottom:6px">COMPRESSION CHUNKS</div>
    <table><thead><tr><th>#</th><th>Logical Offset</th><th>Logical Size</th><th>Compressed Offset</th><th>Compressed Size</th><th>Ratio</th></tr></thead><tbody>
    <?php foreach ($hdr['chunks'] as $ci => $ch): ?>
    <tr>
      <td class="mono muted"><?= $ci ?></td>
      <td class="mono"><?= hex32((int)$ch['uOff'])  ?></td>
      <td class="mono"><?= number_format((int)$ch['uSize']) ?></td>
      <td class="mono"><?= hex32((int)$ch['cOff'])  ?></td>
      <td class="mono"><?= number_format((int)$ch['cSize']) ?></td>
      <td class="mono"><?= $ch['uSize'] > 0 ? round($ch['cSize']/$ch['uSize']*100,1).'%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>

  </div><!-- card -->
  </div><!-- section-body -->
</div><!-- section -->

<!-- ============================================================ NAMES -->
<div class="section">
  <div class="section-hdr" onclick="toggleSection(this)">
    <span class="chev"></span> Name Table
    <span class="muted" style="font-weight:400;font-size:12px"><?= count($names) ?> entries</span>
  </div>
  <div class="section-body">
  <div class="search-row">
    <input type="search" id="name-search" placeholder="Filter names…" oninput="filterTable('name-table','name-search','name-count')">
    <span class="count" id="name-count"><?= count($names) ?> shown</span>
  </div>
  <div class="card">
  <table id="name-table">
    <thead><tr><th style="width:60px">#</th><th>Name</th><th style="width:120px">Flags</th></tr></thead>
    <tbody>
    <?php foreach ($names as $ni => $n): $nm = is_array($n)?($n['name']??''):$n; $fl = is_array($n)?($n['flags']??0):0; ?>
    <tr data-search="<?= h(strtolower($nm)) ?>">
      <td class="mono muted"><?= $ni ?></td>
      <td><?= h($nm) ?></td>
      <td class="mono muted"><?= hex32((int)$fl) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- card -->
  </div>
</div>

<!-- ============================================================ IMPORTS -->
<div class="section">
  <div class="section-hdr" onclick="toggleSection(this)">
    <span class="chev"></span> Import Table
    <span class="muted" style="font-weight:400;font-size:12px"><?= count($imports) ?> entries</span>
  </div>
  <div class="section-body">
  <div class="search-row">
    <input type="search" id="imp-search" placeholder="Filter imports…" oninput="filterExpandTable('imp-table','imp-search','imp-count')">
    <span class="count" id="imp-count"><?= count($imports) ?> shown</span>
  </div>
  <div class="card">
  <table id="imp-table">
    <thead><tr>
      <th style="width:60px">#</th>
      <th>Object Name</th>
      <th>Class</th>
      <th>Package</th>
      <th>Outer</th>
    </tr></thead>
    <tbody>
    <?php foreach ($imports as $ii => $im):
        $oName  = h($im['text']['objectName']   ?? '');
        $cls    = h($im['text']['className']    ?? '');
        $cpkg   = h($im['text']['classPackage'] ?? '');
        $outer  = h($im['text']['outer']        ?? '');
        $search = strtolower(strip_tags($oName.' '.$cls.' '.$cpkg.' '.$outer));
    ?>
    <tr class="parent-row" onclick="toggleRow(this)" data-search="<?= h($search) ?>">
      <td><span class="row-chev"></span><span class="mono muted"><?= $ii ?></span></td>
      <td><strong><?= $oName ?></strong></td>
      <td class="muted"><?= $cls ?></td>
      <td class="muted"><?= $cpkg ?></td>
      <td class="muted"><?= $outer ?></td>
    </tr>
    <tr class="detail-row">
      <td></td>
      <td class="detail-cell" colspan="4">
        <div class="detail-grid">
          <span class="dk">objectNameIndex</span><span class="dv"><?= h($im['objectNameIndex'] ?? 0) ?></span>
          <span class="dk">classNameIndex</span> <span class="dv"><?= h($im['classNameIndex']  ?? 0) ?></span>
          <span class="dk">classPkgNameIndex</span><span class="dv"><?= h($im['classPackageNameIndex'] ?? 0) ?></span>
          <span class="dk">outer (ref)</span>    <span class="dv"><?= h($im['outer'] ?? 0) ?></span>
          <span class="dk">nameNumber</span>     <span class="dv"><?= h($im['objectNameNumber'] ?? 0) ?></span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<!-- ============================================================ EXPORTS -->
<div class="section">
  <div class="section-hdr" onclick="toggleSection(this)">
    <span class="chev"></span> Export Table
    <span class="muted" style="font-weight:400;font-size:12px"><?= count($exports) ?> entries</span>
  </div>
  <div class="section-body">
  <div class="search-row">
    <input type="search" id="exp-search" placeholder="Filter exports…" oninput="filterExpandTable('exp-table','exp-search','exp-count')">
    <span class="count" id="exp-count"><?= count($exports) ?> shown</span>
  </div>
  <div class="card">
  <table id="exp-table">
    <thead><tr>
      <th style="width:60px">#</th>
      <th>Object Name</th>
      <th>Class</th>
      <th>Outer</th>
      <th style="width:90px">Size</th>
      <th style="width:100px">Offset</th>
    </tr></thead>
    <tbody>
    <?php foreach ($exports as $ei => $ex):
        $eName  = h($ex['text']['name']  ?? '');
        $eClass = h($ex['text']['class'] ?? '');
        $eOuter = h($ex['text']['outer'] ?? '');
        $search = strtolower(strip_tags($eName.' '.$eClass.' '.$eOuter));
        $flags  = (int)($ex['objectFlags'] ?? 0);
        $xflags = (int)($ex['exportFlags'] ?? 0);
    ?>
    <tr class="parent-row" onclick="toggleRow(this)" data-search="<?= h($search) ?>">
      <td><span class="row-chev"></span><span class="mono muted"><?= $ei ?></span></td>
      <td><strong><?= $eName ?></strong></td>
      <td class="muted"><?= $eClass ?></td>
      <td class="muted"><?= $eOuter ?></td>
      <td class="mono"><?= number_format((int)($ex['serialSize']   ?? 0)) ?></td>
      <td class="mono"><?= hex32((int)($ex['serialOffset'] ?? 0)) ?></td>
    </tr>
    <tr class="detail-row">
      <td></td>
      <td class="detail-cell" colspan="5">
        <div class="detail-grid">
          <span class="dk">nameIndex</span>    <span class="dv"><?= h($ex['nameIndex']   ?? 0) ?> / <?= h($ex['nameNumber'] ?? 0) ?></span>
          <span class="dk">class (ref)</span>  <span class="dv"><?= h($ex['class']  ?? 0) ?></span>
          <span class="dk">super (ref)</span>  <span class="dv"><?= h($ex['super']  ?? 0) ?></span>
          <span class="dk">outer (ref)</span>  <span class="dv"><?= h($ex['outer']  ?? 0) ?></span>
          <span class="dk">archetype</span>    <span class="dv"><?= h($ex['archetype'] ?? 0) ?></span>
          <span class="dk">serialSize</span>   <span class="dv"><?= h($ex['serialSize']   ?? 0) ?></span>
          <span class="dk">serialOffset</span> <span class="dv"><?= hex32((int)($ex['serialOffset'] ?? 0)) ?></span>
          <span class="dk">objectFlags</span>  <span class="dv"><?= hex32($flags) ?> (<?= h($flags) ?>)<br>
            <?= renderFlags($flags, RF_FLAGS) ?></span>
          <?php if ($xflags): ?>
          <span class="dk">exportFlags</span> <span class="dv"><?= hex32($xflags) ?><br>
            <?= renderFlags($xflags, EF_FLAGS) ?></span>
          <?php endif; ?>
          <?php if (($ex['componentCount'] ?? 0) > 0): ?>
          <span class="dk">componentCount</span><span class="dv"><?= h($ex['componentCount']) ?></span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<?php endif; // no error ?>
</div><!-- page -->

<script>
// Section collapse
function toggleSection(hdr) {
  hdr.classList.toggle('closed');
  hdr.nextElementSibling.classList.toggle('hidden');
}

// Row expand/collapse
function toggleRow(row) {
  row.classList.toggle('open');
  const detail = row.nextElementSibling;
  if (detail && detail.classList.contains('detail-row'))
    detail.classList.toggle('open');
}

// Filter flat table rows
function filterTable(tableId, inputId, countId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
  let shown = 0;
  rows.forEach(r => {
    const match = !q || (r.dataset.search || '').includes(q);
    r.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  const c = document.getElementById(countId);
  if (c) c.textContent = shown + ' shown';
}

// Filter expand-table (pairs of parent+detail rows)
function filterExpandTable(tableId, inputId, countId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  const parents = document.querySelectorAll('#' + tableId + ' tbody .parent-row');
  let shown = 0;
  parents.forEach(p => {
    const match = !q || (p.dataset.search || '').includes(q);
    p.style.display = match ? '' : 'none';
    const d = p.nextElementSibling;
    if (d && d.classList.contains('detail-row'))
      d.style.display = (!match || !d.classList.contains('open')) ? 'none' : 'table-row';
    if (match) shown++;
  });
  const c = document.getElementById(countId);
  if (c) c.textContent = shown + ' shown';
}
</script>
</body>
</html>
