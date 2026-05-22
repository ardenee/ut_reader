<?php
require_once __DIR__ . '/TUnrealPackage.php';

$path = "test.ut3";

$pkg     = TPackageReader::open($path); // returns TUE1/TUE2/TUE3/TUE4
$pkg->annotateTablesWithText();
$pkg->annotateTablesWithText();

$hdr     = $pkg->getHeader();
$names   = $pkg->getNames();
$chunks  = $pkg->chunkMeta;
$imports = $pkg->getImports();
$exports = $pkg->getExports();

//$pkg->debugExport0U32s();
// Helper: safe HTML
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Helper: show hex for 32/64-bit-ish numbers
function fmt_hex($v) {
	//sprintf('0x%08X', $v);
	sprintf("0x%08X", $v);
}

// Optional: choose which fields to show in child rows (order matters)
$childFieldOrder = [
    'class','super','outer','nameIndex','nameNumber','archetype',
    'objectFlags','objectFlagsLo','objectFlagsHi','exportFlags',
    'serialSize','serialOffset','componentCount','pad0','pad1','unknown0',
    // any other fields you have will be appended after these
];

// Build a normalized set of keys seen across items (for fallback display)
$allKeys = [];
foreach ($exports as $it) {
    foreach (array_keys((array)$it) as $k) {
        if ($k === 'text') continue;
        $allKeys[$k] = true;
    }
}
$allKeys = array_keys($allKeys);


?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE3 Exports</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {
    --parent-bg: #f5faff;
    --parent-hover: #e8f1ff;
    --child-bg: #fafafa;
    --border: #e5e7eb;
    --accent: #2563eb;
    --text: #111827;
    --muted: #6b7280;
  }
  body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color: var(--text); margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 12px; }
  .table-wrap { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  thead th {
    text-align: left; font-weight: 600; font-size: 13px; color: var(--muted);
    background: #fcfcfd; border-bottom: 1px solid var(--border); padding: 10px 12px;
  }
  tbody td { border-bottom: 1px solid var(--border); padding: 10px 12px; }

  /* Parent rows */
  .parent-row { background: var(--parent-bg); cursor: pointer; }
  .parent-row:hover { background: var(--parent-hover); }
  .parent-row .name { font-weight: 600; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; }
  .muted { color: var(--muted); }

  /* Expand icon */
  .chev {
    display: inline-block; width: 10px; height: 10px; margin-right: 8px;
    border-right: 2px solid var(--accent); border-bottom: 2px solid var(--accent);
    transform: rotate(-45deg); transition: transform 150ms ease;
  }
  .expanded .chev { transform: rotate(45deg); }

  /* Child rows */
  .child-row { background: var(--child-bg); display: none; }
  .child-cell { padding-left: 42px; }
  .kv { display: grid; grid-template-columns: 160px 1fr; gap: 8px 16px; }
  .kv .k { color: var(--muted); }
  .tag {
    display: inline-block; font-size: 11px; padding: 2px 6px; border-radius: 999px;
    background: #eef2ff; color: #3730a3; border: 1px solid #e0e7ff;
  }

  /* Small utility */
  .nowrap { white-space: nowrap; }
</style>
</head>
<body>
  <h1>UE3 Export Table</h1>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:48px;">#</th>
          <th>Name</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($exports as $i => $itm): ?>
        <!-- Parent row -->
        <tr class="parent-row" data-row="<?php echo $i; ?>">
          <td class="mono"><span class="chev" aria-hidden="true"></span><?php echo (int)$i; ?></td>
          <td class="name"><?php echo h($itm['text']['name']." (".$itm['nameIndex'].")"); ?></td>
        </tr>

        <tr class="child-row" data-child-of="<?php echo $i; ?>">
          <td></td>
          <td class="child-cell" colspan="5">
		    <div class="k"><?php echo h("ObjectFlags:".sprintf("0x%08X", $itm['objectFlags'])." (".$itm['objectFlags'].")"); ?></div>
            <div class="v"> </div>				
			<div class="k"><?php echo h("ExportFlags:".sprintf("0x%08X", $itm['exportFlags'])." (".$itm['exportFlags'].")"); ?></div>
            <div class="v"> </div>				
			<div class="k"><?php echo h("Object:".$itm['text']['name']."(".$itm['nameIndex'].")"); ?></div>
            <div class="v"> </div>				
			<div class="k"><?php echo h("Class:".$itm['text']['class']."(".$itm['class'].")"); ?></div>
            <div class="v"> </div>	 
			
			
			<div class="k"><?php echo h("Super:".$itm['text']['super']."(".$itm['super'].")"); ?></div>
            <div class="v"> </div>
			
            <div class="k"><?php echo h("Outer:".$itm['text']['outer']."(".$itm['outer'].")"); ?></div> 
			<div class="v"> </div>	
            <div class="k"><?php echo h("Object Size:".$itm['serialSize']); ?></div> 
			<div class="v"> </div>	
            <div class="k"><?php echo h("Object Offset:".$itm['serialOffset']); ?></div> 
			<div class="v"> </div>					
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<script>
// Expand / collapse
document.addEventListener('DOMContentLoaded', function () {
  const parents = document.querySelectorAll('.parent-row');
  parents.forEach(function(row) {
    row.addEventListener('click', function () {
      const id = row.getAttribute('data-row');
      const child = document.querySelector('tr.child-row[data-child-of="'+id+'"]');
      if (!child) return;
      const isOpen = child.style.display === 'table-row';
      child.style.display = isOpen ? 'none' : 'table-row';
      row.classList.toggle('expanded', !isOpen);
    });
  });
});
</script>
</body>
</html>
