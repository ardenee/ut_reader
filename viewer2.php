<?php
// viewer2.php — Unreal package loader & explorer using TUnrealPackage.php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
ini_set('memory_limit', '768M');
ini_set('max_execution_time', '300');

require_once __DIR__ . '/TUnrealPackage.php';
require_once __DIR__ . '/UE_LZO1X_register.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx($v): string { return sprintf('0x%08X', (int)$v); }
function raw_row(array $row): array { return isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row; }
function view_row(array $row): array { return isset($row['view']) && is_array($row['view']) ? $row['view'] : []; }
function row_int(array $row, array $keys, int $default = 0): int {
    $raw = raw_row($row);
    foreach ($keys as $k) {
        if (isset($row[$k]) && is_numeric($row[$k])) return (int)$row[$k];
        if (isset($raw[$k]) && is_numeric($raw[$k])) return (int)$raw[$k];
    }
    return $default;
}
function row_text(array $row, array $keys, string $default = ''): string {
    $view = view_row($row);
    $raw = raw_row($row);
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
    $raw = raw_row($row);
    return (string)($row['name'] ?? $raw['name'] ?? '');
}
function ref_name(int $ref, array $imports, array $exports, array $names): string {
    if ($ref === 0) return '';
    if ($ref > 0) {
        $i = $ref - 1;
        if (!isset($exports[$i])) return "__BADEXPORT[$i]__";
        $ex = (array)$exports[$i];
        $txt = row_text($ex, ['objectNameText','ObjectNameText','name','Name'], '');
        if ($txt !== '' && !is_numeric($txt)) return $txt;
        return name_at($names, row_int($ex, ['objectName','ObjectName','nameIndex','NameIndex'], -1));
    }
    $i = -$ref - 1;
    if (!isset($imports[$i])) return "__BADIMPORT[$i]__";
    $im = (array)$imports[$i];
    $txt = row_text($im, ['objectNameText','ObjectNameText','name','Name'], '');
    if ($txt !== '' && !is_numeric($txt)) return $txt;
    return name_at($names, row_int($im, ['objectName','ObjectName','nameIndex','NameIndex'], -1));
}
function ref_path(int $ref, array $imports, array $exports, array $names): string {
    $parts = [];
    $seen = [];
    for ($depth = 0; $ref !== 0 && $depth < 64; $depth++) {
        if (isset($seen[$ref])) { $parts[] = '__CYCLE__'; break; }
        $seen[$ref] = true;
        $name = ref_name($ref, $imports, $exports, $names);
        if ($name !== '') $parts[] = $name;
        if ($ref > 0) {
            $i = $ref - 1;
            if (!isset($exports[$i])) break;
            $ref = row_int((array)$exports[$i], ['outerIndex','OuterIndex','packageIndex','PackageIndex','outer'], 0);
        } else {
            $i = -$ref - 1;
            if (!isset($imports[$i])) break;
            $ref = row_int((array)$imports[$i], ['outerIndex','OuterIndex','outer'], 0);
        }
    }
    return implode('.', array_reverse(array_filter($parts, fn($s) => $s !== '')));
}

$uploadDir = __DIR__ . '/uploads';
$file = null;
$err = null;

if (!empty($_FILES['file']['tmp_name'])) {
    try {
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) throw new RuntimeException('Upload folder could not be created: ' . $uploadDir);
        if (!is_writable($uploadDir)) throw new RuntimeException('Upload folder is not writable by PHP/Web Station: ' . $uploadDir);
        if (!is_uploaded_file($_FILES['file']['tmp_name'])) throw new RuntimeException('Upload failed: invalid temporary upload file.');
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($_FILES['file']['name']));
        $file = $uploadDir . '/' . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $file)) throw new RuntimeException('Unable to move uploaded file to: ' . $file);
    } catch (Throwable $t) {
        $err = $t->getMessage();
        $file = null;
    }
}

if (!$file) {
    foreach ([__DIR__ . '/test.ut3', __DIR__ . '/test.utx', __DIR__ . '/uploads/test.ut3', __DIR__ . '/uploads/test.utx'] as $candidate) {
        if (is_file($candidate)) { $file = $candidate; break; }
    }
}

$pkg = null;
$hdr = $names = $imports = $exports = [];

if ($file && !$err) {
    try {
        $pkg = TPackageReader::open($file);
        if (method_exists($pkg, 'annotateTablesWithText')) $pkg->annotateTablesWithText();
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
<title>Unreal Package Viewer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#0f1116;--panel:#161a22;--muted:#8892a6;--outline:#95b8ff;--border:#252b36;--hi:#dfe7ff;--err:#ff9f9f}*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:#e8eefc}header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;gap:1rem;align-items:center;flex-wrap:wrap}h1{margin:0;font-weight:700;color:var(--outline)}form{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}input[type=file]{color:#e8eefc}input[type=submit],select{background:var(--panel);color:#e8eefc;border:1px solid var(--border);padding:.5rem .75rem;border-radius:.5rem;cursor:pointer}main{padding:1rem;display:grid;grid-template-columns:320px 1fr;gap:1rem}.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden}.card h2{margin:0;padding:.85rem 1rem;border-bottom:1px solid var(--border);color:var(--outline)}.body{padding:.85rem 1rem}.wide{grid-column:1/-1}.table-wrap{overflow:auto;max-height:65vh}table{width:100%;border-collapse:collapse;font-size:.95rem}th,td{padding:.5rem .6rem;border-bottom:1px solid var(--border);vertical-align:top;text-align:left;white-space:nowrap}tr:hover td{background:#141923}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}.wrap{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}.dim{opacity:.65;font-size:.9em}.err{color:var(--err);background:#2a1116;border:1px solid #55303a;border-radius:8px;padding:.75rem;margin:.5rem 0}.controls{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;padding:.5rem 0}.controls input{flex:1 1 360px;min-width:220px;background:#0f131b;border:1px solid var(--border);padding:.6rem .75rem;border-radius:.75rem;color:#e8eefc}.row-click{cursor:pointer}.props-row{display:none}.badge{display:inline-block;padding:.15rem .45rem;border:1px solid var(--border);border-radius:.5rem;color:var(--hi);background:#121620;margin-right:.35rem}.ok{color:#9fdf9f}</style>
</head>
<body>
<header>
  <h1>Unreal Package Viewer</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="file" accept=".utx,.u,.umx,.uax,.unr,.ukx,.usx,.ut2,.upkg,.upk,.ut3,.xxx">
    <input type="submit" value="Open">
    <?php if ($file): ?><span class="mono">Loaded: <?=h(basename($file))?></span><?php endif; ?>
  </form>
</header>
<main>
  <div class="card"><h2>Package Info</h2><div class="body">
    <?php if ($err): ?><div class="err"><strong>Error:</strong> <?=h($err)?></div><?php endif; ?>
    <?php if (!$pkg): ?><p>Upload a package to inspect.</p><?php else: ?>
      <div class="mono">
        <div>File: <?=h($file)?></div>
        <div>Version: <?=h($hdr['version'] ?? '')?></div>
        <div>Name count / offset: <?= (int)($hdr['nameCount']??0) ?> @ <?= hx((int)($hdr['nameOffset']??0)) ?></div>
        <div>Import count / offset: <?= (int)($hdr['importCount']??0) ?> @ <?= hx((int)($hdr['importOffset']??0)) ?></div>
        <div>Export count / offset: <?= (int)($hdr['exportCount']??0) ?> @ <?= hx((int)($hdr['exportOffset']??0)) ?></div>
        <div>Package flags: <?= hx((int)($hdr['packageFlags'] ?? $hdr['pkgFlags'] ?? 0)) ?></div>
        <div>Compression flags: <?= hx((int)($hdr['compressionFlags'] ?? 0)) ?><?= !empty($hdr['compressed']) ? ' <span class="ok">compressed</span>' : '' ?></div>
      </div>
    <?php endif; ?>
  </div></div>

  <div class="card"><h2>Names</h2><div class="body">
    <?php if ($names): ?><div class="table-wrap"><table class="mono"><thead><tr><th>#</th><th>Name</th><th>Flags</th></tr></thead><tbody>
    <?php foreach ($names as $i => $row): $row=(array)$row; $raw=raw_row($row); $idx=(int)($row['index'] ?? $i); ?>
      <tr><td><?= $idx ?></td><td><?= h($row['name'] ?? $raw['name'] ?? '') ?></td><td><?= h(hx((int)($row['flags'] ?? $raw['flags'] ?? 0))) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php else: ?><small>No names found.</small><?php endif; ?>
  </div></div>

  <div class="card wide"><h2>Exports</h2><div class="body">
    <div class="controls"><input type="search" id="q" placeholder="Filter by name / class / package…"><select id="classFilter"><option value="">All classes</option></select><select id="sort"><option value="idx">Sort: Index</option><option value="name">Sort: Name</option><option value="class">Sort: Class</option><option value="size">Sort: Size</option></select></div>
    <?php if ($exports): ?><div class="table-wrap"><table id="exportsTable"><thead><tr><th>Group</th><th>Name</th><th>Class</th><th>#</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th><th>Raw refs</th></tr></thead><tbody>
    <?php foreach ($exports as $i => $e):
        $e=(array)$e;
        $classRef=row_int($e,['classIndex','ClassIndex','class'],0);
        $superRef=row_int($e,['superIndex','SuperIndex','super'],0);
        $pkgRef=row_int($e,['packageIndex','PackageIndex','outerIndex','OuterIndex','outer'],0);
        $nameIdx=row_int($e,['objectName','ObjectName','nameIndex','NameIndex'],-1);
        $flags=row_int($e,['objectFlags','ObjectFlags','flags'],0);
        $size=row_int($e,['serialSize','SerialSize','size'],0);
        $offset=row_int($e,['serialOffset','SerialOffset','offset'],0);
        $view=view_row($e);
        $name=row_text($e,['objectNameText','ObjectNameText','name','Name'],'');
        if ($name === '' || is_numeric($name)) $name = name_at($names,$nameIdx);
        if ($name === '') $name = 'Export #'.$i;
        $class=row_text($e,['classNameText','ClassNameText','className','ClassName'],'');
        if ($class === '') $class = ref_name($classRef,$imports,$exports,$names);
        $super=ref_name($superRef,$imports,$exports,$names);
        $group='';
        if (!empty($view['outerChain']) && is_array($view['outerChain'])) $group=implode('.',array_filter($view['outerChain']));
        if ($group === '') $group=ref_path($pkgRef,$imports,$exports,$names);
        $raw=sprintf('%d / %d / %d / %d',$classRef,$superRef,$pkgRef,$nameIdx);
    ?>
      <tr class="data row-click" data-idx="<?=$i?>" data-size="<?=$size?>" data-name="<?=h($name)?>" data-class="<?=h($class)?>" data-pkg="<?=h($group)?>"><td><?=h($group)?></td><td><span class="badge">#<?=$i?></span><?=h($name)?></td><td><?=h($class)?></td><td class="mono"><?=$i?></td><td><?=h($super)?></td><td class="mono"><?=number_format($size)?></td><td class="mono"><?=hx($offset)?></td><td class="mono"><?=hx($flags)?></td><td class="mono dim"><?=h($raw)?></td></tr>
      <tr class="props-row"><td colspan="9"><pre class="wrap mono"><?=h(json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))?></pre></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php else: ?><small>No exports.</small><?php endif; ?>
  </div></div>

  <div class="card wide"><h2>Imports</h2><div class="body">
    <?php if ($imports): ?><div class="table-wrap"><table><thead><tr><th>Name</th><th>Class</th><th>Class Package</th><th>Outer</th><th>#</th><th>Raw refs</th></tr></thead><tbody>
    <?php foreach ($imports as $i => $im):
        $im=(array)$im;
        $classPkgIdx=row_int($im,['classPackage','ClassPackage'],-1);
        $classIdx=row_int($im,['className','ClassName'],-1);
        $outerRef=row_int($im,['outerIndex','OuterIndex','outer'],0);
        $objIdx=row_int($im,['objectName','ObjectName'],-1);
        $name=row_text($im,['objectNameText','ObjectNameText','objectName','ObjectName','name'],'');
        if ($name === '' || is_numeric($name)) $name=name_at($names,$objIdx);
        if ($name === '') $name='Import #'.$i;
        $class=row_text($im,['classNameText','ClassNameText'],'');
        if ($class === '') $class=name_at($names,$classIdx);
        $classPkg=row_text($im,['classPackageText','classPackageName','ClassPackageName'],'');
        if ($classPkg === '') $classPkg=name_at($names,$classPkgIdx);
        $outer=ref_path($outerRef,$imports,$exports,$names);
        $raw=sprintf('%d / %d / %d / %d',$classPkgIdx,$classIdx,$outerRef,$objIdx);
    ?>
      <tr><td><?=h($name)?></td><td><?=h($class)?></td><td><?=h($classPkg)?></td><td><?=h($outer)?></td><td class="mono"><?=$i?></td><td class="mono dim"><?=h($raw)?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php else: ?><small>No imports.</small><?php endif; ?>
  </div></div>
</main>
<script>
const q=document.getElementById('q'),classFilter=document.getElementById('classFilter'),sortSel=document.getElementById('sort'),table=document.getElementById('exportsTable'),tbody=table?table.querySelector('tbody'):null;
function dataRows(){return tbody?[...tbody.querySelectorAll('tr.data')]:[]}function propsRowFor(r){return r?.nextElementSibling&&r.nextElementSibling.classList.contains('props-row')?r.nextElementSibling:null}
function populateClassFilter(){if(!classFilter)return;[...new Set(dataRows().map(r=>r.dataset.class||'').filter(Boolean))].sort().forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=v;classFilter.appendChild(o)})}
function applyFilterSort(){const qv=(q?.value||'').toLowerCase().trim(),cf=(classFilter?.value||'').toLowerCase(),metric=(sortSel?.value||'idx'),rows=dataRows();rows.forEach(r=>{const n=(r.dataset.name||'').toLowerCase(),c=(r.dataset.class||'').toLowerCase(),p=(r.dataset.pkg||'').toLowerCase(),show=(!qv||n.includes(qv)||c.includes(qv)||p.includes(qv))&&(!cf||c===cf);r.style.display=show?'':'none';const pr=propsRowFor(r);if(pr)pr.style.display=(show&&pr.dataset.open==='1')?'':'none'});rows.slice().sort((a,b)=>metric==='idx'?(+a.dataset.idx)-(+b.dataset.idx):metric==='size'?(+b.dataset.size)-(+a.dataset.size):metric==='name'?a.dataset.name.localeCompare(b.dataset.name):metric==='class'?a.dataset.class.localeCompare(b.dataset.class):0).forEach(r=>{const pr=propsRowFor(r);tbody.appendChild(r);if(pr)tbody.appendChild(pr)})}
if(tbody){tbody.addEventListener('click',e=>{const tr=e.target.closest('tr.data');if(!tr)return;const pr=propsRowFor(tr);if(!pr)return;const open=pr.style.display===''||pr.dataset.open==='1';pr.style.display=open?'none':'';pr.dataset.open=open?'0':'1'})}populateClassFilter();[q,classFilter,sortSel].forEach(el=>el&&el.addEventListener('input',applyFilterSort));applyFilterSort();
</script>
</body>
</html>
