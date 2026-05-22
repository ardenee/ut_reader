<?php
declare(strict_types=1);
require_once __DIR__ . '/UnrealPackageReader.php';

$pkgPath = $_GET['file'] ?? 'test.utx';
if ($pkgPath === '') { echo '<p>Pass ?file=path/to/file.u</p>'; exit; }

try {
    $pkg = new UnrealPackageReader($pkgPath);
    $pkg->setDebug(true);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\n\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES) . "</pre>";
    exit;
}

$hdr             = $pkg->getHeader();
$names           = $pkg->getNames();
$imports         = $pkg->getImports();
$exports         = $pkg->getExports();
$debugWarnings   = $pkg->getDebugWarnings();
$filesize        = @filesize($pkg->getFilePath()) ?: 0;
$pkgFlagsDecoded = $pkg->decodePKG(intval($hdr['flags'] ?? 0));
?>

<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Unreal Package Viewer</title>
<style>
body{font-family:system-ui,Arial,Helvetica,sans-serif;margin:24px;max-width:1200px;}
table{border-collapse:collapse;width:100%;margin:12px 0;}
th,td{border:1px solid #ddd;padding:6px 8px;font-size:14px;}
th{background:#f6f6f6;text-align:left;}
code{background:#f2f2f2;padding:1px 4px;border-radius:3px;}
h2{margin-top:2rem;}
small.mono{font-family:ui-monospace,Consolas,Menlo,monospace;}
</style>

<style>
/* Expand/collapse for Export rows */
tr.exp-row { cursor: pointer; }
tr.exp-row:hover { background: #fafafa; }
tr.prop-row { display: none; }
td.prop-cell { padding: 0 !important; background: #fbfbfb; }
td.prop-cell > .prop-wrap { padding: 10px 12px; }
td .badge { display:inline-block; font-size:12px; padding:2px 6px; border:1px solid #ddd; border-radius:10px; margin-left:6px; background:#fff; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('tr.exp-row').forEach(function(row){
    row.addEventListener('click', function(e){
      // Don't toggle when clicking links or selection inside
      if (e.target && (e.target.tagName === 'A' || e.target.closest('a'))) return;
      var id = row.getAttribute('data-exp');
      var detail = document.querySelector('tr.prop-row[data-exp="'+id+'"]');
      if (!detail) return;
      var isOpen = detail.style.display !== 'none' && detail.style.display !== '';
      // Close any open row if you want accordion behavior; comment out to allow multiple open
      // document.querySelectorAll('tr.prop-row').forEach(function(r){ r.style.display = 'none'; });
      detail.style.display = isOpen ? 'none' : 'table-row';
      row.classList.toggle('open', !isOpen);
    });
  });
});
</script>

<style>
/* visual distinction for properties section */
tr.prop-row { background: #f9fbff; } /* light bluish */
td.prop-cell > .prop-wrap { border-left: 4px solid #b3d4ff; background: #f9fbff; }
td.prop-cell table { background: #ffffff; border: 1px solid #e3eeff; }
td.prop-cell th { background: #eef5ff; }
</style>
</head>
<body>
<h1>Unreal Package Viewer</h1>
<p>Unreal File found. (<?=number_format($filesize/1024,0)?>) KB</p>

<h2>File Header</h2>
<table>
<tr><th>Var</th><th>Value</th><th>Additional</th></tr>
<tr><td>Version</td><td><?=$hdr['version']?></td><td></td></tr>
<tr><td>License mode</td><td><?=$hdr['licensee']?></td><td></td></tr>
<tr><td>Package flags</td><td><?=sprintf("0x%08X",$hdr['flags'])?></td><td>(<?=implode(', ',$pkgFlagsDecoded)?>)</td></tr>
<tr><td>Name count</td><td><?=$hdr['nameCount']?></td><td></td></tr>
<tr><td>Name offset</td><td><?=$hdr['nameOffset']?></td><td></td></tr>
<tr><td>Export count</td><td><?=$hdr['exportCount']?></td><td></td></tr>
<tr><td>Export offset</td><td><?=$hdr['exportOffset']?></td><td></td></tr>
<tr><td>Import count</td><td><?=$hdr['importCount']?></td><td></td></tr>
<tr><td>Import offset</td><td><?=$hdr['importOffset']?></td><td></td></tr>
<tr><td>GUID</td><td colspan="2"><?=htmlspecialchars($hdr['guid'] ?: '(none)')?></td></tr>
<?php if (!empty($hdr['generations'])): ?>
<tr><td>Generation count</td><td colspan="2"><?=count($hdr['generations'])?></td></tr>
<?php endif; ?>
<?php if (($hdr['version']??0) < 68): ?>
<tr><td>Heritage count</td><td><?=$hdr['heritageCount']?></td><td></td></tr>
<tr><td>Heritage offset</td><td><?=$hdr['heritageOffset']?></td><td></td></tr>
<?php endif; ?>
</table>

<h2>Debug warnings</h2>
<?php if (!empty($debugWarnings)): ?>
<ul><?php foreach ($debugWarnings as $w): ?><li><code><?=htmlspecialchars($w)?></code></li><?php endforeach; ?></ul>
<?php else: ?><p>(none)</p><?php endif; ?>

<h2>Generations</h2>
<?php if (!empty($hdr['generations'])): ?>
<table><tr><th>#</th><th>ExportCount</th><th>NameCount</th></tr>
<?php foreach ($hdr['generations'] as $i=>$g): ?>
<tr><td><?=$i?></td><td><?=$g['exportCount']?></td><td><?=$g['nameCount']?></td></tr>
<?php endforeach; ?>
</table>
<?php else: ?>
<p>(none)</p>
<?php endif; ?>

<h2>Name Table (<?=$hdr['nameCount']?>:<?=$hdr['nameOffset']?>)</h2>
<table>
<tr><th>Num.</th><th>Name</th><th>Len</th><th>Flags (hex)</th><th>Flags (decoded)</th></tr>
<?php foreach ($names as $i=>$n): $fname=$n['name']; ?>
<tr>
<td><?=$i?> (<?=sprintf("0x%02X",$i)?>)</td>
<td><?=htmlspecialchars($fname)?></td>
<td><?=strlen($fname)?></td>
<td><?=sprintf("0x%08X",$n['flags'])?></td>
<td><?=implode(', ',$pkg->decodeRF($n['flags']))?></td>
</tr>
<?php endforeach; ?>
</table>

<?php $expRows = $pkg->getExportDisplayRows(); ?>


<h2>Export Table (<?=$hdr['exportCount']?>:<?=$hdr['exportOffset']?>)</h2>
<table>
  <tr><th>Group</th><th>Name</th><th>Class</th><th>Num.</th><th>Super</th><th>Size</th><th>Offset</th><th>Flags</th></tr>
  <?php for ($i = 0; $i < count($exports); $i++): 
        $ex = $exports[$i];
        $r  = $expRows[$i] ?? null;
        $props = $pkg->getExportProperties($i);
        $hasProps = !empty($props);
  ?>
  <tr class="exp-row" data-exp="<?=$i?>">
    <td><?=htmlspecialchars($r['group'] ?? '')?></td>
    <td><?=htmlspecialchars($r['name'] ?? '')?><?php if ($hasProps): ?><span class="badge"><?=count($props)?> props</span><?php endif; ?></td>
    <td><?=htmlspecialchars($r['class'] ?? '')?></td>
    <td><?=$r['num'] ?? ''?><?php if(isset($r['num'])):?> (<?=sprintf("0x%02X",$r['num'])?>)<?php endif;?></td>
    <td><?=htmlspecialchars($r['super'] ?? '')?></td>
    <td><?=$r['serialSize'] ?? ''?></td>
    <td><?php if(isset($r['serialOffset'])): ?><?=sprintf("0x%08X",$r['serialOffset'])?><?php endif; ?></td>
    <td><?=isset($r['flagsDec']) ? implode(',', $r['flagsDec']) : ''?></td>
  </tr>
  <tr class="prop-row" data-exp="<?=$i?>">
    <td class="prop-cell" colspan="8">
      <div class="prop-wrap">
      <?php if (empty($props)): ?>
        <em>(no properties)</em>
      <?php else: ?>
        <table>
          <tr>
            <th>Offset</th><th>Length</th><th>Name</th><th>Type</th><th>Struct</th><th>Array?</th><th>Idx</th><th>Value</th>
          </tr>
          <?php foreach ($props as $p): ?>
          <tr>
            <td><?=sprintf("0x%08X", (int)($p['offset'] ?? 0))?></td>
            <td><?=($p['length'] ?? '')?></td>
            <td><?=htmlspecialchars(($p['name'] ?? ''))?></td>
            <td><?=htmlspecialchars(($p['type'] ?? ''))?></td>
            <td><?=htmlspecialchars(($p['struct'] ?? ''))?></td>
            <td><?=($p['isArray'] ?? '')?></td>
            <td>
              <?php if ((!empty($p['isEnum']))): ?>
                <span class="mono"><?=htmlspecialchars(($p['enumType'] ?? ''))?></span>
              <?php elseif ((!empty($p['isText']))): ?>
                <span class="mono">text</span>
              <?php elseif ((!empty($p['isNameIdx']))): ?>
                name[<?=($p['nameIdx'] ?? '')?>]
              <?php else: ?>
                <?=($p['idx'] ?? '')?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((!empty($p['isEnum']))): ?>
                <code><?=htmlspecialchars(($p['valueEnum'] ?? '') ?? '')?></code>
              <?php elseif ((!empty($p['isText']))): ?>
                <code><?=htmlspecialchars(($p['valueText'] ?? '') ?? '')?></code>
              <?php elseif ((!empty($p['isNameIdx']))): ?>
                <code><?=htmlspecialchars(($p['valueName'] ?? '') ?? '')?></code>
              <?php else: ?>
                <code><?=htmlspecialchars(($p['value'] ?? '') ?? '')?></code>
              <?php endif; ?>
              
              <?php if ((!empty($p['hasRaw']))): ?>
                <div class="small mono" style="margin-top:6px;">
                  raw_typeIdx=<?=($p['raw_typeIdx'] ?? '')?>,
                  raw_nameIdx=<?=($p['raw_nameIdx'] ?? '')?>,
                  raw_size=<?=($p['raw_size'] ?? '')?>,
                  raw_arrayIdx=<?=($p['raw_arrayIdx'] ?? '')?>,
                  raw_payloadLen=<?=strlen(($p['raw_payload'] ?? ''))?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endfor; ?>
</table>

<?php $impRows = $pkg->getImportDisplayRows(); ?>
<h2>Import Table (<?=$hdr['importCount']?>:<?=$hdr['importOffset']?>)</h2>
<table>
  <tr><th>Package &amp; Group</th><th>Name</th><th>Class</th><th>Class Package</th><th>Num.</th></tr>
  <?php foreach ($impRows as $r): ?>
  <tr>
    <td><?=htmlspecialchars($r['pkgGroup'])?></td>
    <td><?=htmlspecialchars($r['name'])?></td>
    <td><?=htmlspecialchars($r['class'])?></td>
    <td><?=htmlspecialchars($r['classPkg'])?></td>
    <td><?=$r['num']?> (<?=sprintf("0x%02X",$r['num'])?>)</td>
  </tr>
  <?php endforeach; ?>
</table>

<!-- Properties by Export moved under Export Table rows. -->




</body>
</html>
