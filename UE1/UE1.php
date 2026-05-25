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
$pkgFlagsDecoded = $pkg->decodePKG((int)($hdr['pkgFlags'] ?? 0));
$issues = $pkg->validatePackage();

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_hex(int $v): string
{
    return sprintf('0x%08X', $v);
}
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
        --prop-bg: #e5f2ff;
        --prop-head: #69beff;
    }

    html, body { background: var(--bg); color: var(--text); }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; line-height: 1.35; padding: 16px; }
    h1, h2 { margin: 0.4em 0; }
    .small { font-size: 12px; color: var(--sub); }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

    table { border-collapse: collapse; width: 100%; margin: 12px 0 24px; }
    th, td { border: 1px solid var(--b); padding: 6px 8px; vertical-align: top; font-size: 14px; }
    th { background: var(--muted); text-align: left; }

    .wrap, .wrap * {
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
        line-break: anywhere;
    }

    .raw-cell {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        word-break: break-word;
        line-break: anywhere;
        max-width: 28rem;
    }

    .exports-table {
        table-layout: fixed;
    }

    .exports-table th:nth-child(1), .exports-table td:nth-child(1) { width: 8%; }
    .exports-table th:nth-child(2), .exports-table td:nth-child(2) { width: 7%; }
    .exports-table th:nth-child(3), .exports-table td:nth-child(3) { width: 7%; }
    .exports-table th:nth-child(4), .exports-table td:nth-child(4) { width: 9%; }
    .exports-table th:nth-child(5), .exports-table td:nth-child(5) { width: 20%; }
    .exports-table th:nth-child(6), .exports-table td:nth-child(6) { width: 6%; }
    .exports-table th:nth-child(7), .exports-table td:nth-child(7) { width: 8%; }
    .exports-table th:nth-child(8), .exports-table td:nth-child(8) { width: 6%; }
    .exports-table th:nth-child(9), .exports-table td:nth-child(9) { width: 8%; }
    .exports-table th:nth-child(10), .exports-table td:nth-child(10) { width: 21%; }

    .toggle-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid var(--b); border-radius: 6px; padding: 3px 8px; cursor: pointer; font-size: 13px; color: var(--text); }
    .toggle-btn:hover { border-color: var(--accent); color: var(--accent); }
    .chev { transition: transform 0.2s ease; display: inline-block; }
    .chev.open { transform: rotate(90deg); }
    .pill { display: inline-block; padding: 2px 6px; border-radius: 999px; background: #e7f5ff; color: #0b74c9; font-size: 12px; border: 1px solid #b6dfff; }

    .props-row { display: none; background: #fcfcfd; }
    .props-wrap { padding: 10px 4px; max-width: 100%; overflow-x: auto; }

    .nested {
        width: 100%;
        max-width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        margin: 6px 0 0;
    }

    .nested th, .nested td {
        border: 1px dashed var(--b);
        font-size: 13px;
        background: var(--prop-bg);
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
        line-break: anywhere;
    }

    .nested th { background: var(--prop-head); }
    .nested pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }

    .nested th:nth-child(1), .nested td:nth-child(1) { width: 6%; }
    .nested th:nth-child(2), .nested td:nth-child(2) { width: 6%; }
    .nested th:nth-child(3), .nested td:nth-child(3) { width: 10%; }
    .nested th:nth-child(4), .nested td:nth-child(4) { width: 10%; }
    .nested th:nth-child(5), .nested td:nth-child(5) { width: 8%; }
    .nested th:nth-child(6), .nested td:nth-child(6) { width: 5%; }
    .nested th:nth-child(7), .nested td:nth-child(7) { width: 5%; }
    .nested th:nth-child(8), .nested td:nth-child(8) { width: 7%; }
    .nested th:nth-child(9), .nested td:nth-child(9) { width: 15%; }
    .nested th:nth-child(10), .nested td:nth-child(10) { width: 5%; }
    .nested th:nth-child(11), .nested td:nth-child(11) { width: 23%; }

    .warn { border: 1px solid #d1242f; background: #fff8f8; padding: 8px 12px; }
</style>
<script>
function toggleProps(i) {
    var row = document.getElementById('props-' + i);
    if (!row) return;

    var chev = document.getElementById('chev-' + i);
    var hidden = row.style.display === '' || row.style.display === 'none';

    row.style.display = hidden ? 'table-row' : 'none';
    if (chev) {
        if (hidden) chev.classList.add('open');
        else chev.classList.remove('open');
    }
}
</script>
</head>
<body>

<h1>Unreal Package Viewer</h1>
<div class="small"><?= h($filePath) ?> (<?= h($pkg->getFileSize()) ?>)</div>

<h2>Header</h2>
<table>
    <thead><tr><th>Name</th><th>Value</th><th class="small">Raw</th></tr></thead>
    <tbody>
        <tr><th>Signature</th><td class="mono"><?= h(fmt_hex((int)($hdr['signature'] ?? 0))) ?></td><td class="small"><?= h($hdr['signature'] ?? '') ?></td></tr>
        <tr><th>Version</th><td class="mono"><?= h($hdr['version'] ?? '') ?></td><td class="small"><?= h($hdr['version'] ?? '') ?></td></tr>
        <tr><th>Licensee</th><td class="mono"><?= h($hdr['licensee'] ?? '') ?></td><td class="small"><?= h($hdr['licensee'] ?? '') ?></td></tr>
        <tr><th>Package Flags</th><td class="mono"><?= h(fmt_hex((int)($hdr['pkgFlags'] ?? 0))) ?><?= $pkgFlagsDecoded ? ' (' . h(implode(', ', $pkgFlagsDecoded)) . ')' : '' ?></td><td class="small"><?= h($hdr['pkgFlags'] ?? '') ?></td></tr>
        <tr><th>Name Count / Offset</th><td class="mono"><?= h($hdr['nameCount'] ?? '') ?> / <?= h($hdr['nameOffset'] ?? '') ?></td><td class="small"><?= h($hdr['nameCount'] ?? '') ?> / <?= h($hdr['nameOffset'] ?? '') ?></td></tr>
        <tr><th>Export Count / Offset</th><td class="mono"><?= h($hdr['exportCount'] ?? '') ?> / <?= h($hdr['exportOffset'] ?? '') ?></td><td class="small"><?= h($hdr['exportCount'] ?? '') ?> / <?= h($hdr['exportOffset'] ?? '') ?></td></tr>
        <tr><th>Import Count / Offset</th><td class="mono"><?= h($hdr['importCount'] ?? '') ?> / <?= h($hdr['importOffset'] ?? '') ?></td><td class="small"><?= h($hdr['importCount'] ?? '') ?> / <?= h($hdr['importOffset'] ?? '') ?></td></tr>
        <?php if (!empty($hdr['guid'])): ?>
        <tr><th>GUID</th><td class="mono wrap"><?= h($hdr['guid']) ?></td><td class="small wrap"><?= h($hdr['guid']) ?></td></tr>
        <?php endif; ?>
        <?php if (($hdr['version'] ?? 0) < 68): ?>
        <tr><th>Heritage count</th><td class="mono"><?= h($hdr['heritageCount'] ?? '') ?></td><td class="small"><?= h($hdr['heritageCount'] ?? '') ?></td></tr>
        <tr><th>Heritage offset</th><td class="mono"><?= h($hdr['heritageOffset'] ?? '') ?></td><td class="small"><?= h($hdr['heritageOffset'] ?? '') ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if (!empty($issues)): ?>
<h2>Validation</h2>
<div class="warn">
    <ul>
        <?php foreach ($issues as $w): ?>
        <li class="mono wrap"><?= h($w) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($hdr['generations'])): ?>
<h2>Generations (<?= count($hdr['generations']) ?>)</h2>
<table>
    <thead><tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th class="small">Raw (ExportCount / NameCount)</th></tr></thead>
    <tbody>
        <?php foreach ($hdr['generations'] as $i => $g): ?>
        <tr><td><?= h($g['e'] ?? '') ?></td><td><?= h($g['n'] ?? '') ?></td><td><?= h($i) ?></td><td class="small"><?= h($g['e'] ?? '') ?> / <?= h($g['n'] ?? '') ?></td></tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Names (<?= h($hdr['nameCount'] ?? '') ?>:<?= h($hdr['nameOffset'] ?? '') ?>)</h2>
<table>
    <thead><tr><th>Name</th><th>Flags</th><th>Num.</th><th class="small">Raw (index / flags)</th></tr></thead>
    <tbody>
    <?php foreach ($names as $n): ?>
        <?php $flags = (int)($n['flags'] ?? 0); ?>
        <tr>
            <td class="wrap"><?= h($n['name'] ?? '') ?></td>
            <td class="mono wrap"><?= h(fmt_hex($flags)) ?><?= $pkg->decodeRF($flags) ? ' (' . h(implode(', ', $pkg->decodeRF($flags))) . ')' : '' ?></td>
            <td class="mono"><?= h(($n['index'] ?? '') . ' (' . sprintf('0x%02X', (int)($n['index'] ?? 0)) . ')') ?></td>
            <td class="small wrap"><?= h($n['name'] ?? '') ?> / <?= h($flags) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Imports (<?= h($hdr['importCount'] ?? '') ?>:<?= h($hdr['importOffset'] ?? '') ?>)</h2>
<table>
    <thead>
        <tr><th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th><th class="small">Raw (classPackage / className / outerIndex / objectName)</th></tr>
    </thead>
    <tbody>
    <?php foreach ($imports as $i => $im): ?>
        <tr>
            <td class="wrap"><?= h($pkg->importClassPackageName((int)($im['classPackage'] ?? -1))) ?></td>
            <td class="wrap"><?= h($pkg->importClassName((int)($im['className'] ?? -1))) ?></td>
            <td class="wrap"><?= h($pkg->importPackageName((int)($im['outerIndex'] ?? 0))) ?></td>
            <td class="wrap"><?= h($pkg->importObjectName((int)($im['objectName'] ?? -1))) ?></td>
            <td class="mono"><?= h($i . ' (' . sprintf('0x%02X', $i) . ')') ?></td>
            <td class="mono small raw-cell"><?= h(($im['classPackage'] ?? '') . ' / ' . ($im['className'] ?? '') . ' / ' . ($im['outerIndex'] ?? '') . ' / ' . ($im['objectName'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Exports (<?= h($hdr['exportCount'] ?? '') ?>:<?= h($hdr['exportOffset'] ?? '') ?>)</h2>
<table class="exports-table">
    <thead>
        <tr><th>Class Index</th><th>Super Index</th><th>Package Index</th><th>Object Name</th><th>Object Flags</th><th>Serial Size</th><th>Serial Offset</th><th>Num.</th><th>Properties</th><th class="small">Raw (cla / sup / pac / obj / flg / si / off)</th></tr>
    </thead>
    <tbody>
    <?php foreach ($exports as $i => $ex): ?>
        <?php
            $props = $pkg->getExportProperties($i);
            $hasProps = is_array($props) && !empty($props);
            $objectFlags = (int)($ex['objectFlags'] ?? 0);
        ?>
        <tr>
            <td class="mono wrap"><?= h($pkg->exportClassName((int)($ex['classIndex'] ?? 0))) ?></td>
            <td class="mono wrap"><?= h($pkg->exportSuperName((int)($ex['superIndex'] ?? 0))) ?></td>
            <td class="mono wrap"><?= h($pkg->exportPackageName((int)($ex['packageIndex'] ?? 0))) ?></td>
            <td class="mono wrap"><?= h($pkg->exportObjectName((int)($ex['objectName'] ?? -1))) ?></td>
            <td class="mono wrap"><?= h(fmt_hex($objectFlags)) ?><?= $pkg->decodeRF($objectFlags) ? ' (' . h(implode(', ', $pkg->decodeRF($objectFlags))) . ')' : '' ?></td>
            <td class="mono"><?= h($ex['serialSize'] ?? '') ?></td>
            <td class="mono"><?= h(fmt_hex((int)($ex['serialOffset'] ?? 0))) ?></td>
            <td class="mono"><?= h($i . ' (' . sprintf('0x%02X', $i) . ')') ?></td>
            <td>
                <?php if ($hasProps): ?>
                <button class="toggle-btn" onclick="toggleProps(<?= (int)$i ?>)"><span id="chev-<?= (int)$i ?>" class="chev">▶</span> Properties <span class="pill"><?= count($props) ?></span></button>
                <?php else: ?>
                <span class="small mono">-</span>
                <?php endif; ?>
            </td>
            <td class="mono small raw-cell"><?= h(($ex['classIndex'] ?? '') . ' / ' . ($ex['superIndex'] ?? '') . ' / ' . ($ex['packageIndex'] ?? '') . ' / ' . ($ex['objectName'] ?? '') . ' / ' . ($ex['objectFlags'] ?? '') . ' / ' . ($ex['serialSize'] ?? '') . ' / ' . ($ex['serialOffset'] ?? '')) ?></td>
        </tr>

        <?php if ($hasProps): ?>
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
                                $val = $p['value'] ?? ($p['val'] ?? ($p['data'] ?? ''));
                                $raw = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            ?>
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
                </div>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
