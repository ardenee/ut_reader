<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader.php';

// Accept ?file=... (or set a default)
$filePath = isset($_GET['file']) ? (string)$_GET['file'] : (isset($filePath) ? (string)$filePath : 'test.utx');
if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE1.php: missing or invalid ?file= parameter.\n";
    echo "Example: UE2.php?file=test.utx\n";
    exit;
}

// Build package
$pkg = new UnrealPackageReader($filePath);

// Pull data using the NEW getters
$hdr             = $pkg->getHeader();
$names           = $pkg->getNames();
$imports         = $pkg->getImports();
$exports         = $pkg->getExports();
$pkgFlagsDecoded = $pkg->decodePKG(intval($hdr['pkgFlags'] ?? 0));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

echo "<pre>";
print_r($hdr);
echo "</pre>";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Unreal Package Viewer — <?= h(basename($filePath)) ?></title>
<style>
    :root {
        --b: #d0d7de;
        --bg: #fff;
        --muted: #f6f8fa;
        --text: #24292f;
        --sub: #57606a;
        --accent: #0969da;
    }
    html, body { background: var(--bg); color: var(--text); }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; line-height: 1.35; padding: 16px; }
    h1, h2 { margin: 0.4em 0; }
    .small { font-size: 12px; color: var(--sub); }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

    table { border-collapse: collapse; width: 100%; margin: 12px 0 24px; }
    th, td { border: 1px solid var(--b); padding: 6px 8px; vertical-align: top; font-size: 14px; }
    th { background: var(--muted); text-align: left; }

    /* Dropdown toggle button */
    .toggle-btn {display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid var(--b); border-radius: 6px; padding: 3px 8px; cursor: pointer; font-size: 13px; color: var(--text); }
    .toggle-btn:hover { border-color: var(--accent); color: var(--accent); }
    .chev { transition: transform 0.2s ease; display: inline-block; }
    .chev.open { transform: rotate(90deg); }

    /* Hidden row that contains the properties table */
    .props-row { display: none; background: #fcfcfd; }
    .props-wrap { padding: 10px 4px; }

    /* Nested table style */
    .nested { width: 100%; border-collapse: collapse; margin: 6px 0 0; }
    .nested th, .nested td { border: 1px dashed var(--b); font-size: 13px; background: #E5F2FF;}
    .nested th { background: #69BEFF; }
    .pill { display: inline-block; padding: 2px 6px; border-radius: 999px; background: #e7f5ff; color: #0b74c9; font-size: 12px; border: 1px solid #b6dfff; }	
</style>


<style id="hl-css-ref">
  .hl-row{ background: rgba(9,105,218,.08)!important }
  .hl-cell{ outline: 2px solid rgba(9,105,218,.55); position: relative }
  .hl-strong{ background: rgba(9,105,218,.16)!important }
  td[data-ref-type]{ cursor:pointer }
</style>

<style id="hl-css-clean">
  .hl-row{ background: rgba(9,105,218,.08)!important }
  .hl-cell{ outline: 2px solid rgba(9,105,218,.55); position: relative }
  .hl-strong{ background: rgba(9,105,218,.16)!important }
  td{ user-select: none; }
  td[data-ref-type]{ cursor:pointer }
</style>
</head>
<body>

<h1>Unreal Package Viewer</h1>
<div class="small"><?= h($filePath) ?> (<?=h($pkg->getFileSize())?>)</div>

<h2>Header</h2>
<table>
<tr><th>Name</th><th>Value</th><th class="small">Raw</th></tr>

    <tbody>
        <tr><th>Signature</th><td class="mono">            <?= h(sprintf("0x%08X",$hdr['signature']) ?? '') ?></td><td class="small"><?= h($hdr['signature']) ?></td></tr>
        <tr><th>Version</th><td class="mono">              <?= h($hdr['version'] ?? '') ?></td><td class="small"><?= h($hdr['version'] ?? '') ?></td></tr>
        <tr><th>Licensee</th><td class="mono">             <?= h($hdr['licensee'] ?? '') ?></td><td class="small"><?= h($hdr['licensee'] ?? '') ?></td></tr>
        <tr><th>Package Flags</th><td class="mono">        <?= h(sprintf("0x%08X",$hdr['pkgFlags']) ?? '')." (".implode(', ', $pkgFlagsDecoded).")" ?></td><td class="small"><?= h($hdr['pkgFlags']) ?></td></tr>
		
        <tr><th>Name Count / Offset</th><td class="mono">  <?= h($hdr['nameCount'])." / ".h($hdr['nameOffset']) ?></td><td class="small"><?=h($hdr['nameCount'])." / ".h($hdr['nameOffset']) ?></td></tr> 
        <tr><th>Export Count / Offset</th><td class="mono"><?= h($hdr['exportCount'])." / ".h($hdr['exportOffset'])?></td><td class="small"><?=h($hdr['exportCount'])." / ".h($hdr['exportOffset']) ?></td></tr>
        <tr><th>Import Count / Offset</th><td class="mono"><?= h($hdr['importCount'])." / ".h($hdr['importOffset'])?></td><td class="small"><?=h($hdr['importCount'])." / ".h($hdr['importOffset']) ?></td></tr>
		
		
        <?php if (!empty($hdr['guid'])): ?>
        <tr><th>GUID</th><td class="mono"><?= h($hdr['guid']) ?></td><td class="small"><?= h($hdr['guid']) ?></td></tr>
        <?php endif; ?>		
		<?php if (($hdr['version']??0) < 68): ?>
		<tr><th>Heritage count </th><td class="mono"><?=$hdr['heritageCount']?></td><td class="small"> <?=h($hdr['heritageCount']) ?></td></tr>
		<tr><th>Heritage offset</th><td class="mono"><?=$hdr['heritageOffset']?></td><td class="small"><?=h($hdr['heritageOffset']) ?></td></tr>
		<?php endif; ?>		
    </tbody>
</table>
	<?php
		// Compression summary
		$comp   = $pkg->getCompressionInfo();
		$issues = $pkg->validatePackage();
	?>

	<?php if (!empty($comp['isCompressed']) || !empty($comp['chunks'])): ?>
	<h2>Compression</h2>
	<table>
	  <tr><th>Compressed?</th><td class="mono"><?= $comp['isCompressed'] ? 'Yes' : 'No' ?></td></tr>
	  <tr><th>Total (compressed → uncompressed)</th>
		  <td class="mono"><?= h($comp['totalCompressed']) ?> → <?= h($comp['totalUncompressed']) ?> bytes</td></tr>
	</table>

	<?php if (!empty($comp['chunks'])): ?>
	<h3>Chunks (<?= count($comp['chunks']) ?>)</h3>
	<table>
	  <thead><tr><th>#</th><th>Comp Off/Len</th><th>Uncomp Off/Len</th></tr></thead>
	  <tbody>
		<?php foreach ($comp['chunks'] as $ci => $c): ?>
		<tr>
		  <td class="mono"><?= (int)$ci ?></td>
		  <td class="mono"><?= h($c['cOff']) ?> / <?= h($c['cLen']) ?></td>
		  <td class="mono"><?= h($c['uOff']) ?> / <?= h($c['uLen']) ?></td>
		</tr>
		<?php endforeach; ?>
	  </tbody>
	</table>
	<?php endif; ?>
	<?php endif; ?>

	<?php if (!empty($issues)): ?>
	<h2>Validation</h2>
	<div class="warn">
	  <ul>
		<?php foreach ($issues as $w): ?>
		  <li class="mono"><?= h($w) ?></li>
		<?php endforeach; ?>
	  </ul>
	</div>
	<?php endif; ?>


<?php if (!empty($hdr['generations'])): ?>
<h2>Generations (<?=count($hdr['generations'])?>)</h2>
<table><tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th class="small">Raw (ExportCount / NameCount)</th></tr>
<?php foreach ($hdr['generations'] as $i=>$g): ?>
<tr><td><?=$g['e']?></td><td><?=$g['n']?></td><td><?=$i?></td><td class="small"><?=$g['e'] ?> / <?= $g['n']?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>



<h2>Names (<?=$hdr['nameCount']?>:<?=$hdr['nameOffset']?>)</h2></h2>

<table>
    <thead>
        <tr><th>Name</th><th>Flags</th><th>Num.</th><th class="small">Raw (index / flags)</th></tr>
    </thead>
    <tbody>
    <?php foreach ($names as $n): ?>
	<?php $pkgFlagsDecoded = $pkg->decodePKG(intval($n['objectFlags'] ?? 0)); ?>
        <tr>
            <td><?= h($n['name']) ?></td>
            <td class="mono"><?=  h(sprintf("0x%08X",$n['flags']))." (".implode(', ',$pkg->decodeRF($n['flags']))?>)</td>			
			<td class="mono"><?=  h($n['index']." (".sprintf("0x%02X",$n['index'])) ?>)</td>	
			<td class="small"><?= h($n['name'])?> / <?= $n['flags']?></td>					
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>


<h2>Imports (<?=$hdr['importCount']?>:<?=$hdr['importOffset']?>)</h2>
<table>
    <thead>
        <tr>            
            <th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th>
            <th class="small">Raw (classPackage / className / outerIndex / objectName)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($imports as $i => $im): ?>
        <tr>  
		    <td><?= h($pkg->importClassPackageName($im['classPackage'])) ?></td>
		    <td><?= h($pkg->importClassName($im['className'])) ?></td>
		    <td><?= h($pkg->importPackageName($im['outerIndex'])) ?></td>
		    <td><?= h($pkg->importObjectName($im['objectName'])) ?></td>
            <td class="mono"><?= h($i." (".sprintf("0x%02X",$i)) ?>)</td>
            <td class="mono small"><?= h(($im['classPackage'] ?? '') . ' / ' . ($im['className'] ?? '') . ' / ' . ($im['outerIndex'] ?? '') . ' / ' . ($im['objectName'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>



<h2>Exports (<?=$hdr['exportCount']?>:<?=$hdr['exportOffset']?>)</h2></h2>
<table>
    <thead>
        <tr>
		    <th>Class Index</th><th>Super Index</th><th>Package Index</th><th>Object Name</th><th>Object Flags</th><th>Serial Size</th><th>Serial Offset</th><th>Num.</th><th>Properties</th><th class="small">Raw (cla / sup / pac / obj / flg / si / off)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($exports as $i => $ex): ?>
        <?php
            $props           = $pkg->getExportProperties($i);// getExportProps($pkg, $i);
            $hasProps        = is_array($props) && !empty($props);
			$pkgFlagsDecoded = $pkg->decodePKG(intval($i['objectFlags'] ?? 0));
        ?>
        <tr>		
			<td class="mono"><?= h($pkg->exportClassName($ex['classIndex']))   ?></td>
			<td class="mono"><?= h($pkg->exportSuperName($ex['superIndex'])) ?></td>
			<td class="mono"><?= h($pkg->exportPackageName($ex['packageIndex']))   ?></td>
		    <td class="mono"><?= h($pkg->exportObjectName($ex['objectName'])) ?></td> 
		    <td class="mono"><?= h(sprintf("0x%08X",$ex['objectFlags']))." (".implode(', ',$pkg->decodeRF($ex['objectFlags']))?>)</td>
			<td class="mono"><?= h($ex['serialSize'] ?? '') ?></td>
			<td class="mono"><?= h(sprintf("0x%08X",$ex['serialOffset'])) ?></td>					
            <td class="mono"><?= h($i." (".sprintf("0x%02X",$i)) ?>)</td>   
            <td>
          <?php if ($hasProps): ?>
              <button class="toggle-btn" onclick="toggleProps(<?= (int)$i ?>)"><span id="chev-<?= (int)$i ?>" class="chev">▶</span> Properties <span class="pill"><?= count($props) ?></span></button>
          <?php else: ?>
              <span class="small mono">—</span>
          <?php endif; ?>
            </td>
            <td class="mono small"><?= h(($ex['classIndex'] ?? '') . ' / ' . ($ex['superIndex'] ?? '') . ' / ' . ($ex['packageIndex'] ?? '') . ' / ' . ($ex['objectName'] ?? '') . ' / ' . ($ex['objectFlags'] ?? '') . ' / ' . ($ex['serialSize'] ?? '') . ' / ' . ($ex['serialOffset'] ?? '')) ?></td>
        </tr>



        <?php if ($hasProps): 
		//echo "<pre>";
		//print_r($props);
		//echo "</pre>";		?>
        <tr id="props-<?= (int)$i ?>" class="props-row">
            <td colspan="10">
                <div class="props-wrap">
                    <table class="nested">
                        <thead>
                            <tr><th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Struct</th><th>isArray</th><th>idx</th><th>idxFromFile</th><th>Value</th><th>Num.</th><th class="small">Raw</th></tr>
                        </thead>
						
						
						
						
						
						
                        <tbody>
                            <?php foreach ($props as $pi => $p): ?>
                                <?php   
								
		//echo "<HR><pre>";
		//print_r($pi);
		//print_r($p);
		//echo "<HR></pre>";	
								
								
                                    $val   = $p['value'] ?? ($p['val'] ?? ($p['data'] ?? ''));
                                    $raw   = json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                                ?>
                                <tr>
                                    <td class="mono"><?= h(sprintf("0x%08X", $p['offset'])) ?></td>
									<td class="mono"><?= h($p['length']) ?></td>
									<td class="mono"><?= h($p['name']) ?></td>
                                    <td class="mono"><?= h($p['type']) ?></td>
									<td class="mono"><?= h($p['struct']) ?></td>
									<td class="mono"><?= h($p['isArray']) ?></td>
									<td class="mono"><?= h($p['idx']) ?></td>
									<td class="mono"><?= h($p['idxFromFile']) ?></td>
                                    <td><?= is_scalar($val) ? h((string)$val) : '<pre class="mono small">'.h(print_r($val, true)).'</pre>' ?></td>
									<td class="mono small"><?= h($pi) ?></td>
                                    <td class="mono small" style="white-space: pre-wrap;  overflow-wrap: anywhere;   word-break: break-word; line-break: anywhere; max-width: 40rem;"><?= h($raw) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
						
						
						
						
						
						
                    </table>
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
<?php
  // Type-specific summaries based on export class
  $clsName = strtolower((string)$pkg->exportClassName($ex['classIndex']));
?>

<!-- Type-specific summaries -->
<?php if ($clsName === 'texture'): ?>
  <?php if ($sum = $pkg->peekTextureSummary($i)): ?>
    <h4>Texture Summary</h4>
    <table class="nested">
      <tr><th>MipMaps</th><td class="mono"><?= h($sum['mipmaps']) ?></td></tr>
      <tr><th>Width</th><td class="mono"><?= h($sum['width']) ?></td></tr>
      <tr><th>Height</th><td class="mono"><?= h($sum['height']) ?></td></tr>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($clsName === 'sound'): ?>
  <?php if ($sum = $pkg->peekSoundSummary($i)): ?>
    <h4>Sound Summary</h4>
    <table class="nested">
      <tr><th>Format</th><td class="mono"><?= h($sum['format'] ?? '') ?></td></tr>
      <tr><th>Size (bytes)</th><td class="mono"><?= h($sum['bytes'] ?? '') ?></td></tr>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($clsName === 'music'): ?>
  <?php if ($sum = $pkg->peekMusicSummary($i)): ?>
    <h4>Music Summary</h4>
    <table class="nested">
      <tr><th>Text Size</th><td class="mono"><?= h($sum['bytes'] ?? '') ?></td></tr>
      <tr><th>Preview</th><td class="mono small"><?= h($sum['preview'] ?? '') ?></td></tr>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($clsName === 'function'): ?>
  <?php if ($sum = $pkg->peekFunction($i)): ?>
    <h4>Function Header</h4>
    <table class="nested">
      <tr><th>iNative</th><td class="mono"><?= h($sum['iNative'] ?? '') ?></td></tr>
      <tr><th>Flags</th>
          <td class="mono"><?= h(sprintf("0x%08X", intval($sum['FunctionFlagsRaw'] ?? 0))) ?>
              (<?= h(implode(', ', $sum['FunctionFlags'] ?? [])) ?>)
          </td></tr>
      <?php if (!empty($sum['ScriptPreview'])): ?>
      <tr><th>Script Preview</th>
          <td><pre class="mono small"><?php foreach ($sum['ScriptPreview'] as $ln) echo h($ln)."\n"; ?></pre></td></tr>
      <?php endif; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($clsName === 'state'): ?>
  <?php if ($sum = $pkg->peekState($i)): ?>
    <h4>State Header</h4>
    <table class="nested">
      <tr><th>State Flags</th>
          <td class="mono"><?= h(sprintf("0x%08X", intval($sum['StateFlagsRaw'] ?? 0))) ?>
              (<?= h(implode(', ', $sum['StateFlags'] ?? [])) ?>)
          </td></tr>
      <?php if (!empty($sum['ScriptPreview'])): ?>
      <tr><th>Script Preview</th>
          <td><pre class="mono small"><?php foreach ($sum['ScriptPreview'] as $ln) echo h($ln)."\n"; ?></pre></td></tr>
      <?php endif; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($clsName === 'class'): ?>
  <?php if ($sum = $pkg->peekClass($i)): ?>
    <h4>Class Header</h4>
    <table class="nested">
      <?php if (isset($sum['Dependencies'])): ?>
      <tr><th>Dependencies</th><td class="mono"><?= h($sum['Dependencies']) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($sum['DepsDetail'])): ?>
      <tr><th>Deps Detail</th>
          <td>
            <table class="nested">
              <thead><tr><th>Class</th><th>Deep</th><th>ScriptTextCRC</th></tr></thead>
              <tbody>
                <?php foreach ($sum['DepsDetail'] as $d): ?>
                  <tr><td class="mono"><?= h($d['class'] ?? '') ?></td>
                      <td class="mono"><?= h($d['deep'] ?? '') ?></td>
                      <td class="mono"><?= h($d['crc'] ?? '') ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </td>
      </tr>
      <?php endif; ?>
      <?php if (!empty($sum['PackageImports'])): ?>
      <tr><th>Package Imports</th><td class="mono"><?= h($sum['PackageImports']) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($sum['ImportsDetail'])): ?>
      <tr><th>Imports Detail</th>
          <td class="mono small"><?= h(implode(', ', $sum['ImportsDetail'])) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($sum['ClassWithin'])): ?>
      <tr><th>Within</th><td class="mono"><?= h($sum['ClassWithin']) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($sum['ClassConfigName'])): ?>
      <tr><th>ConfigName</th><td class="mono"><?= h($sum['ClassConfigName']) ?></td></tr>
      <?php endif; ?>
    </table>
  <?php endif; ?>
<?php endif; ?>
				
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
                </div>
            </td>
        </tr>
        <?php endif; ?>
		
		
		
		
		
		

    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
<script>
  // --- Document-level capture: ensure Properties buttons always toggle ---
  (function(){
    function getIndexFromButton(btn){
      if (!btn) return NaN;
      var ixAttr = btn.getAttribute && btn.getAttribute('data-ix');
      if (ixAttr){
        var n = parseInt(ixAttr,10); if (!isNaN(n)) return n;
      }
      var chev = btn.querySelector && btn.querySelector('.chev[id^="chev-"]');
      if (chev && chev.id){
        var m = chev.id.match(/^chev-(\d+)$/);
        if (m){ var n2 = parseInt(m[1],10); if (!isNaN(n2)) return n2; }
      }
      var tr = btn.closest && btn.closest('tr');
      if (tr && tr.nextElementSibling && tr.nextElementSibling.id){
        var m2 = tr.nextElementSibling.id.match(/^props-(\d+)$/);
        if (m2){ var n3 = parseInt(m2[1],10); if (!isNaN(n3)) return n3; }
      }
      return NaN;
    }
    function onDocCapture(ev){
      var t = ev.target;
      if (!t || !t.closest) return;
      var btn = t.closest('.toggle-btn');
      if (!btn) return;
      var idx = getIndexFromButton(btn);
      if (isNaN(idx)) return;
      if (typeof toggleProps === 'function') toggleProps(idx);
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
      if (ev.stopPropagation) ev.stopPropagation();
      if (ev.preventDefault) ev.preventDefault();
    }
    if (document && document.addEventListener){
      // only attach once
      if (!document.__ue_props_doc_capture){
        document.__ue_props_doc_capture = true;
        document.addEventListener('click', onDocCapture, true);
      }
    }
  })();

  // --- Minimal toggle for Properties rows (id=props-<i>) ---
  if (typeof toggleProps !== 'function'){
    function toggleProps(i){
      var row = document.getElementById('props-' + i);
      if (!row) return;
      var chev = document.getElementById('chev-' + i);
      var hidden = (window.getComputedStyle ? window.getComputedStyle(row).display === 'none'
                                            : (row.style.display === '' || row.style.display === 'none'));
      if (hidden){
        row.style.display = 'table-row';
        if (chev) chev.classList.add('open');
      } else {
        row.style.display = 'none';
        if (chev) chev.classList.remove('open');
      }
    }
  }

document.addEventListener('DOMContentLoaded', function(){
  const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
  const txt = el => (el && el.textContent || '').trim();
  const num = s => { const m=(s||'').match(/-?\d+/); return m ? parseInt(m[0],10) : NaN; };

  function clearHL(){
    $$('.hl-row').forEach(el => el.classList.remove('hl-row'));
    $$('.hl-cell').forEach(el => el.classList.remove('hl-cell','hl-strong'));
  }
  function rowFor(table, idx){
    return document.querySelector(`tr[data-table="${table}"][data-${table.slice(0,-1)}-index="${idx}"]`);
  }
  function markRow(r){ if (r) r.classList.add('hl-row'); }
  function markCell(td, strong=true){ if (!td) return; td.classList.add('hl-cell'); if (strong) td.classList.add('hl-strong'); }

  function tableAfterH2(prefix){
    const hs = Array.from(document.querySelectorAll('h2'));
    for (const h of hs){
      if (txt(h).toLowerCase().startsWith(prefix.toLowerCase())){
        let t = h.nextElementSibling;
        while (t && t.tagName !== 'TABLE') t = t.nextElementSibling;
        return t || null;
      }
    }
    return null;
  }

  const namesT   = tableAfterH2('names');
  const importsT = tableAfterH2('imports');
  const exportsT = tableAfterH2('exports');

  // Helper: parse Raw column (last cell) -> array of ints split by '/'
  function rawParts(tr){
    const tds = tr.querySelectorAll('td');
    const raw = tds[tds.length-1];
    return txt(raw).split('/').map(s=>s.trim()).map(num);
  }

  // Names: detect "Num" and "Name" columns by header; only mark Name as ref
  if (namesT){
    const head = namesT.querySelector('thead tr');
    let idxCol = 0, nameCol = 1;
    if (head){
      const ths = Array.from(head.querySelectorAll('th')).map(th => txt(th).toLowerCase());
      const numPos  = ths.findIndex(t => t.startsWith('num'));
      const namePos = ths.findIndex(t => t.startsWith('name'));
      if (numPos  >= 0) idxCol  = numPos;
      if (namePos >= 0) nameCol = namePos;
    }
    namesT.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td'); if (tds.length === 0) return;
      const idxCell  = tds[idxCol]  || tds[0];
      const nameCell = tds[nameCol] || tds[1] || tds[0];
      const ix = num(txt(idxCell));
      tr.dataset.table = 'names';
      tr.dataset.nameIndex = String(ix);
      // ONLY the Name cell is a reference; flags/others are not
      if (nameCell){
        nameCell.dataset.field = 'name';
        nameCell.dataset.refType = 'name';
        nameCell.dataset.refValue = String(ix);
      }
    });
  }

  // Imports: use header names to find columns
  if (importsT){
    const head = importsT.querySelector('thead tr');
    let colClassPkg=-1, colClassName=-1, colPackage=-1, colObjectName=-1, colNum=-1;
    if (head){
      const ths = Array.from(head.querySelectorAll('th')).map(th => txt(th).toLowerCase());
      colClassPkg   = ths.findIndex(t => t.includes('class package'));
      colClassName  = ths.findIndex(t => t.includes('class name'));
      colPackage    = ths.findIndex(t => t === 'package' || t.startsWith('package '));
      colObjectName = ths.findIndex(t => t.includes('object name'));
      colNum        = ths.findIndex(t => t.startsWith('num'));
    }
    importsT.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td'); if (tds.length === 0) return;
      tr.dataset.table = 'imports';
      const rowIdx = (colNum>=0 && tds[colNum]) ? num(txt(tds[colNum])) : num(txt(tds[tds.length-2] || tds[0]));
      tr.dataset.importIndex = String(rowIdx);
      const parts = rawParts(tr);
      if (parts.length >= 4){
        const [classPkgIx, classNameIx, packageRef, objectNameIx] = parts;
        const tdClassPkg   = (colClassPkg  >=0 ? tds[colClassPkg]   : tds[0]);
        const tdClassName  = (colClassName >=0 ? tds[colClassName]  : tds[1]);
        const tdPackage    = (colPackage   >=0 ? tds[colPackage]    : tds[2]);
        const tdObjectName = (colObjectName>=0 ? tds[colObjectName] : tds[3]);
        if (tdClassPkg)   { tdClassPkg.dataset.refType='name';   tdClassPkg.dataset.refValue=String(classPkgIx);  tdClassPkg.dataset.field='classPackage'; }
        if (tdClassName)  { tdClassName.dataset.refType='name';  tdClassName.dataset.refValue=String(classNameIx); tdClassName.dataset.field='className'; }
        if (tdPackage)    { tdPackage.dataset.refType='object';  tdPackage.dataset.refValue=String(packageRef);   tdPackage.dataset.field='package'; }
        if (tdObjectName) { tdObjectName.dataset.refType='name'; tdObjectName.dataset.refValue=String(objectNameIx); tdObjectName.dataset.field='objectName'; }
      }
    });
  }

  // Exports: use header names to find columns
  if (exportsT){
    const head = exportsT.querySelector('thead tr');
    let colClass=-1, colSuper=-1, colPackage=-1, colObjectName=-1, colNum=-1;
    if (head){
      const ths = Array.from(head.querySelectorAll('th')).map(th => txt(th).toLowerCase());
      colClass      = ths.findIndex(t => t === 'class' || t.startsWith('class '));
      colSuper      = ths.findIndex(t => t.startsWith('super'));
      colPackage    = ths.findIndex(t => t === 'package' || t.startsWith('package '));
      colObjectName = ths.findIndex(t => t.includes('object name'));
      colNum        = ths.findIndex(t => t.startsWith('num'));
    }
    exportsT.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td'); if (tds.length === 0) return;
      tr.dataset.table = 'exports';
      const rowIdx = (colNum>=0 && tds[colNum]) ? num(txt(tds[colNum])) : num(txt(tds[tds.length-2] || tds[0]));
      tr.dataset.exportIndex = String(rowIdx);
      const parts = rawParts(tr);
      if (parts.length >= 4){
        const [classRef, superRef, packageRef, objectNameIx] = parts;
        const tdClass      = (colClass     >=0 ? tds[colClass]      : tds[0]);
        const tdSuper      = (colSuper     >=0 ? tds[colSuper]      : tds[1]);
        const tdPackage    = (colPackage   >=0 ? tds[colPackage]    : tds[2]);
        const tdObjectName = (colObjectName>=0 ? tds[colObjectName] : tds[3]);
        if (tdClass)      { tdClass.dataset.refType='object';  tdClass.dataset.refValue=String(classRef);     tdClass.dataset.field='class'; }
        if (tdSuper)      { tdSuper.dataset.refType='object';  tdSuper.dataset.refValue=String(superRef);     tdSuper.dataset.field='super'; }
        if (tdPackage)    { tdPackage.dataset.refType='object'; tdPackage.dataset.refValue=String(packageRef); tdPackage.dataset.field='package'; }
        if (tdObjectName) { tdObjectName.dataset.refType='name'; tdObjectName.dataset.refValue=String(objectNameIx); tdObjectName.dataset.field='objectName'; }
      }
    });
  }

  // Highlight helpers
  function highlightNamesRowOnly(ix){
    const r = rowFor('names', ix);
    if (r){ markRow(r); const c=r.querySelector('[data-field="name"]'); markCell(c, true); }
  }
  function highlightObjectRef(ref){
    const v = parseInt(ref,10);
    if (!v) return; // 0=None
    if (v > 0){
      const r = rowFor('exports', v-1); markRow(r);
      if (r){ const c=r.querySelector('[data-field="objectName"]'); markCell(c, true); }
    } else {
      const r = rowFor('imports', (-v)-1); markRow(r);
      if (r){ const c=r.querySelector('[data-field="objectName"]'); markCell(c, true); }
    }
  }

  // Click behavior
  function onCellClick(ev){
    const td = ev.target.closest('td[data-ref-type]'); if (!td) return false;
    ev.stopImmediatePropagation(); ev.stopPropagation(); ev.preventDefault();
    clearHL();
    const row = td.closest('tr[data-table]'); markRow(row); markCell(td, true);
    const kind = td.getAttribute('data-ref-type');
    const val  = td.getAttribute('data-ref-value');
    const table= row ? row.getAttribute('data-table') : '';

    if (kind === 'name'){
      if (table !== 'names'){ // clicking a name field in imports/exports
        highlightNamesRowOnly(val);
      } else {
        // clicking the Name cell in the Names table itself: also bring up all referencing fields outside Names
        document.querySelectorAll(`[data-ref-type="name"][data-ref-value="${val}"]`).forEach(cell => {
          const tr = cell.closest('tr[data-table]');
          if (tr && tr.getAttribute('data-table') !== 'names'){ markCell(cell, true); markRow(tr); }
        });
      }
    } else if (kind === 'object'){
      highlightObjectRef(val);
    }
    return true;
  }

  function onRowClick(ev){
    if (ev.target.closest('td[data-ref-type]')) return false;
    const row = ev.target.closest('tr[data-table]'); if (!row) return false;
    ev.stopImmediatePropagation(); ev.stopPropagation(); ev.preventDefault();
    clearHL(); markRow(row);
    /* NAMES ROW CLICK MIRROR */
    try {
      const table = row.getAttribute('data-table');
      if (table === 'names'){
        // Get the Name cell and its index
        const nameCell = row.querySelector('td[data-ref-type="name"]');
        if (nameCell){
          const val = nameCell.getAttribute('data-ref-value');
          if (val !== null && val !== ''){
            // Darken the Names cell itself (user expectation of "darker" on Names col)
            markCell(nameCell, true);
            // Highlight referencing fields outside Names exactly like the Name-cell branch does
            document.querySelectorAll('[data-ref-type="name"][data-ref-value="' + val + '"]').forEach(function(cell){
              const tr = cell.closest('tr[data-table]');
              if (tr && tr.getAttribute('data-table') !== 'names'){ 
                markCell(cell, true); 
                markRow(tr);
              }
            });
          }
        }
        return true;
      }
    } catch(e) { /* no-op */ }

    // Darken all referenced fields IN THE ROW
    row.querySelectorAll('td[data-ref-type]').forEach(td => markCell(td, true));

    // Highlight targets
    const names = Array.from(row.querySelectorAll('td[data-ref-type="name"]')).map(td => td.getAttribute('data-ref-value'));
    const objs  = Array.from(row.querySelectorAll('td[data-ref-type="object"]')).map(td => td.getAttribute('data-ref-value'));
    names.forEach(ix => highlightNamesRowOnly(ix));
    objs.forEach(ref => highlightObjectRef(ref));
    return true;
  }

  if (namesT)   namesT.addEventListener('click', function(ev){ if (!onCellClick(ev)) onRowClick(ev); }, true);
  if (importsT) importsT.addEventListener('click', function(ev){ if (!onCellClick(ev)) onRowClick(ev); }, true);
  if (exportsT) exportsT.addEventListener('click', function(ev){ if (!onCellClick(ev)) onRowClick(ev); }, true);

  // Global clear
  document.addEventListener('click', function(ev){
    if (!ev.target.closest('table')) clearHL();
  }, true);
});
</script>
