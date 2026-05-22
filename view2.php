<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);

require_once __DIR__ . '/TUnrealPackage.php';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_hex($v): string {
    return sprintf('0x%08X', (int)$v);
}

function arr_get(array $row, string $key, $default = '') {
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function text_get(array $row, string $key, $default = ''): string {
    $text = $row['text'] ?? [];
    if (is_array($text) && array_key_exists($key, $text)) {
        return (string)$text[$key];
    }
    return (string)$default;
}

function int_get(array $row, string $key, int $default = 0): int {
    $v = arr_get($row, $key, $default);
    return is_numeric($v) ? (int)$v : $default;
}

$err = null;
$pkg = null;
$hdr = [];
$names = [];
$imports = [];
$exports = [];

$path = __DIR__ . '/test.ut3';

try {
    if (!is_file($path)) {
        throw new RuntimeException('File not found: ' . $path);
    }

    $pkg = TPackageReader::open($path);

    if (method_exists($pkg, 'annotateTablesWithText')) {
        $pkg->annotateTablesWithText();
    }

    $hdr     = $pkg->getHeader();
    $names   = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();
} catch (Throwable $t) {
    $err = $t->getMessage();
}

$childFieldOrder = [
    'class','super','outer','outerIndex','nameIndex','nameNumber','objectName','archetype',
    'objectFlags','objectFlagsLo','objectFlagsHi','exportFlags',
    'serialSize','serialOffset','componentCount','pad0','pad1','unknown0',
];
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
    --error-bg: #fff1f2;
    --error-text: #9f1239;
  }
  body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color: var(--text); margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 12px; }
  .summary { margin: 0 0 16px; color: var(--muted); }
  .error { background: var(--error-bg); color: var(--error-text); border: 1px solid #fecdd3; border-radius: 10px; padding: 12px; margin: 12px 0; }
  .table-wrap { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  thead th { text-align: left; font-weight: 600; font-size: 13px; color: var(--muted); background: #fcfcfd; border-bottom: 1px solid var(--border); padding: 10px 12px; }
  tbody td { border-bottom: 1px solid var(--border); padding: 10px 12px; }
  .parent-row { background: var(--parent-bg); cursor: pointer; }
  .parent-row:hover { background: var(--parent-hover); }
  .parent-row .name { font-weight: 600; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; }
  .muted { color: var(--muted); }
  .chev { display: inline-block; width: 10px; height: 10px; margin-right: 8px; border-right: 2px solid var(--accent); border-bottom: 2px solid var(--accent); transform: rotate(-45deg); transition: transform 150ms ease; }
  .expanded .chev { transform: rotate(45deg); }
  .child-row { background: var(--child-bg); display: none; }
  .child-cell { padding-left: 42px; }
  .kv { display: grid; grid-template-columns: 180px 1fr; gap: 8px 16px; }
  .kv .k { color: var(--muted); }
  .tag { display: inline-block; font-size: 11px; padding: 2px 6px; border-radius: 999px; background: #eef2ff; color: #3730a3; border: 1px solid #e0e7ff; }
  .nowrap { white-space: nowrap; }
</style>
</head>
<body>
  <h1>UE3 Export Table</h1>

  <?php if ($err): ?>
    <div class="error"><strong>Error:</strong> <?= h($err) ?></div>
    <p class="summary">Expected file: <span class="mono"><?= h($path) ?></span></p>
  <?php elseif (!$pkg): ?>
    <div class="error">Package did not load.</div>
  <?php else: ?>
    <p class="summary mono">
      File: <?= h(basename($path)) ?> |
      Version: <?= h($hdr['version'] ?? '') ?> |
      Names: <?= count($names) ?> |
      Imports: <?= count($imports) ?> |
      Exports: <?= count($exports) ?>
    </p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:48px;">#</th>
            <th>Name</th>
            <th>Class</th>
            <th>Size</th>
            <th>Offset</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($exports as $i => $itm):
            $itm = (array)$itm;
            $nameText = text_get($itm, 'name', '');
            if ($nameText === '') {
                $nameText = text_get($itm, 'object', '');
            }
            if ($nameText === '') {
                $nameText = 'Export #' . $i;
            }

            $classText = text_get($itm, 'class', '');
            $nameIndex = int_get($itm, 'nameIndex', int_get($itm, 'objectName', -1));
            $serialSize = int_get($itm, 'serialSize', 0);
            $serialOffset = int_get($itm, 'serialOffset', 0);
            $objectFlags = int_get($itm, 'objectFlags', 0);
            $exportFlags = int_get($itm, 'exportFlags', 0);
        ?>
          <tr class="parent-row" data-row="<?= (int)$i ?>">
            <td class="mono"><span class="chev" aria-hidden="true"></span><?= (int)$i ?></td>
            <td class="name"><?= h($nameText . ($nameIndex >= 0 ? " ($nameIndex)" : '')) ?></td>
            <td><?= h($classText) ?></td>
            <td class="mono"><?= number_format($serialSize) ?></td>
            <td class="mono"><?= fmt_hex($serialOffset) ?></td>
          </tr>

          <tr class="child-row" data-child-of="<?= (int)$i ?>">
            <td></td>
            <td class="child-cell" colspan="4">
              <div class="kv">
                <div class="k">ObjectFlags</div><div class="v mono"><?= h(fmt_hex($objectFlags) . ' (' . $objectFlags . ')') ?></div>
                <div class="k">ExportFlags</div><div class="v mono"><?= h(fmt_hex($exportFlags) . ' (' . $exportFlags . ')') ?></div>
                <div class="k">Object</div><div class="v"><?= h($nameText . ($nameIndex >= 0 ? " ($nameIndex)" : '')) ?></div>
                <div class="k">Class</div><div class="v"><?= h($classText . ' (' . int_get($itm, 'class', 0) . ')') ?></div>
                <div class="k">Super</div><div class="v"><?= h(text_get($itm, 'super', '') . ' (' . int_get($itm, 'super', 0) . ')') ?></div>
                <div class="k">Outer</div><div class="v"><?= h(text_get($itm, 'outer', '') . ' (' . int_get($itm, 'outer', int_get($itm, 'outerIndex', 0)) . ')') ?></div>
                <div class="k">Object Size</div><div class="v mono"><?= h((string)$serialSize) ?></div>
                <div class="k">Object Offset</div><div class="v mono"><?= h((string)$serialOffset) ?></div>
                <?php foreach ($childFieldOrder as $field): ?>
                  <?php if (array_key_exists($field, $itm) && !is_array($itm[$field])): ?>
                    <div class="k"><?= h($field) ?></div><div class="v mono"><?= h((string)$itm[$field]) ?></div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<script>
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
