<?php
declare(strict_types=1);

require_once __DIR__ . '/UnrealPackageReader.php';

$uploadDir = __DIR__ . '/uploads';
$uploadRelDir = 'uploads';
$allowedExt = ['u', 'utx', 'umx', 'uax', 'unr', 'ut2', 'ut3', 'upk'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hx(int $v): string
{
    return sprintf('0x%08X', $v);
}

function hx2(int $v): string
{
    return sprintf('0x%02X', $v);
}

function flag_names(array $flags): string
{
    return $flags ? ' (' . implode(', ', $flags) . ')' : '';
}

function safe_package_name(string $name): string
{
    $base = basename(str_replace('\\', '/', rawurldecode($name)));
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base) ?? '';
    return trim($base, " .\t\n\r\0\x0B");
}

function upload_file_list(string $uploadDir, string $uploadRelDir, array $allowedExt): array
{
    if (!is_dir($uploadDir)) {
        return [];
    }

    $out = [];

    foreach (scandir($uploadDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $full = $uploadDir . DIRECTORY_SEPARATOR . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!is_file($full) || !in_array($ext, $allowedExt, true)) {
            continue;
        }

        $out[] = [
            'name' => $file,
            'rel' => $uploadRelDir . '/' . rawurlencode($file),
            'path' => $full,
            'size' => filesize($full) ?: 0,
            'mtime' => filemtime($full) ?: 0,
        ];
    }

    usort($out, static fn(array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

function resolve_package_path(string $fileParam, string $uploadDir, array $uploadedFiles): string
{
    $root = realpath(__DIR__);
    if ($root === false) {
        return '';
    }

    if ($fileParam !== '') {
        $decoded = rawurldecode($fileParam);
        $base = safe_package_name($decoded);

        if ($base !== '') {
            $uploadCandidate = $uploadDir . DIRECTORY_SEPARATOR . $base;
            if (is_file($uploadCandidate)) {
                return $uploadCandidate;
            }
        }

        $localCandidate = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $decoded), DIRECTORY_SEPARATOR);
        $localReal = realpath($localCandidate);

        if ($localReal !== false && is_file($localReal) && str_starts_with($localReal, $root . DIRECTORY_SEPARATOR)) {
            return $localReal;
        }
    }

    $default = __DIR__ . DIRECTORY_SEPARATOR . 'oldtest.utx';
    if (is_file($default)) {
        return $default;
    }

    return $uploadedFiles[0]['path'] ?? '';
}

function object_ref_target_id(int $ref): string
{
    return $ref < 0 ? 'ref-import-' . abs($ref) : 'ref-export-' . $ref;
}

function name_ref_target_id(int $idx): string
{
    return 'ref-name-' . $idx;
}

function object_ref_label(UnrealPackageReader $pkg, int $ref): string
{
    if ($ref === 0) {
        return '';
    }

    $name = $pkg->displayNameFromRef($ref);
    return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
}

function split_ref_for_display(string $label): array
{
    if (preg_match('/^(.*?)(\(-?\d+\))$/', $label, $m)) {
        return [$m[1], $m[2], (int)trim($m[2], '()')];
    }

    return [$label, '', 0];
}

function ref_value_html(string $label): string
{
    [$name, $refText, $ref] = split_ref_for_display($label);
    $html = '<span class="mono">' . h($name) . '</span>';

    if ($refText !== '' && $ref !== 0) {
        $html .= '<a class="ref-tag ref-link" href="#' . h(object_ref_target_id($ref)) . '" data-ref="' . h($ref) . '">' . h($refText) . '</a>';
    }

    return $html;
}

function name_link_html(UnrealPackageReader $pkg, int $idx, string $label = ''): string
{
    if ($idx < 0) {
        return h($label);
    }

    $text = $label !== '' ? $label : $pkg->nameByIndex($idx);

    return '<a class="name-link mono" href="#' . h(name_ref_target_id($idx)) . '" data-name-index="' . h($idx) . '">' . h($text) . '</a>'
        . '<a class="name-tag name-link" href="#' . h(name_ref_target_id($idx)) . '" data-name-index="' . h($idx) . '">#' . h($idx) . '</a>';
}

function name_index_link(int $idx): string
{
    if ($idx < 0) {
        return '';
    }

    return '<a class="name-tag name-link" href="#' . h(name_ref_target_id($idx)) . '" data-name-index="' . h($idx) . '">#' . h($idx) . '</a>';
}

function prop_value_text($value): string
{
    return is_scalar($value) ? (string)$value : trim(print_r($value, true));
}

function read_le_u32(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($bytes)) {
        return 0;
    }

    return (int)unpack('V', substr($bytes, $offset, 4))[1];
}

function read_le_i32(string $bytes, int $offset): int
{
    $v = read_le_u32($bytes, $offset);
    return ($v & 0x80000000) ? $v - 0x100000000 : $v;
}

function raw_hex_at(string $bytes, int $offset, int $size): string
{
    if ($size <= 0 || $offset < 0 || $offset >= strlen($bytes)) {
        return '';
    }

    return strtoupper(trim(chunk_split(bin2hex(substr($bytes, $offset, $size)), 2, ' ')));
}

function add_raw_header_field(array &$fields, string $bytes, int $offset, int $size, string $name, string $type, $value, string $note = ''): void
{
    if ($size <= 0) {
        return;
    }

    $fields[] = [
        'offset' => $offset,
        'size' => $size,
        'name' => $name,
        'type' => $type,
        'value' => $value,
        'rawHex' => raw_hex_at($bytes, $offset, $size),
        'note' => $note,
    ];
}

function add_unparsed_header_bytes(array &$fields, string $bytes, int $start, int $end, string $note): void
{
    if ($end <= $start) {
        return;
    }

    add_raw_header_field($fields, $bytes, $start, $end - $start, 'unparsedHeaderBytes', 'bytes', ($end - $start) . ' bytes', $note);
}

function first_positive_offset(array $values): int
{
    $positive = array_values(array_filter(array_map('intval', $values), static fn(int $v): bool => $v > 0));
    return $positive ? min($positive) : 0;
}

function build_ue1_raw_header_fields(string $packagePath): array
{
    $bytes = is_file($packagePath) ? (string)file_get_contents($packagePath) : '';
    if (strlen($bytes) < 36) {
        return [];
    }

    $fields = [];
    $pos = 0;

    $tag = read_le_u32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'signature', 'uint32', $tag);
    $pos += 4;

    $packed = read_le_u32($bytes, $pos);
    $version = $packed & 0xFFFF;
    $licensee = ($packed >> 16) & 0xFFFF;
    add_raw_header_field($fields, $bytes, $pos, 4, 'packedVersionLicensee', 'uint32', 'packed=' . $packed . ', version=' . $version . ', licensee=' . $licensee);
    $pos += 4;

    $pkgFlags = read_le_u32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'pkgFlags', 'uint32', $pkgFlags);
    $pos += 4;

    $nameCount = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'nameCount', 'int32', $nameCount);
    $pos += 4;

    $nameOffset = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'nameOffset', 'int32', $nameOffset);
    $pos += 4;

    $exportCount = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'exportCount', 'int32', $exportCount);
    $pos += 4;

    $exportOffset = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'exportOffset', 'int32', $exportOffset);
    $pos += 4;

    $importCount = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'importCount', 'int32', $importCount);
    $pos += 4;

    $importOffset = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'importOffset', 'int32', $importOffset);
    $pos += 4;

    if ($version < 68) {
        $heritageCount = read_le_i32($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, 4, 'heritageCount', 'int32', $heritageCount);
        $pos += 4;

        $heritageOffset = read_le_i32($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, 4, 'heritageOffset', 'int32', $heritageOffset);
        $pos += 4;

        if ($nameOffset - $pos >= 16 && $pos + 16 <= strlen($bytes)) {
            $guidParts = [read_le_u32($bytes, $pos), read_le_u32($bytes, $pos + 4), read_le_u32($bytes, $pos + 8), read_le_u32($bytes, $pos + 12)];
            $guid = sprintf('%08X-%08X-%08X-%08X', $guidParts[0], $guidParts[1], $guidParts[2], $guidParts[3]);
            add_raw_header_field($fields, $bytes, $pos, 16, 'guid', 'FGuid', $guid);
            $pos += 16;
        }
    } else {
        $guidParts = [read_le_u32($bytes, $pos), read_le_u32($bytes, $pos + 4), read_le_u32($bytes, $pos + 8), read_le_u32($bytes, $pos + 12)];
        $guid = sprintf('%08X-%08X-%08X-%08X', $guidParts[0], $guidParts[1], $guidParts[2], $guidParts[3]);
        add_raw_header_field($fields, $bytes, $pos, 16, 'guid', 'FGuid', $guid);
        $pos += 16;

        $generationCount = read_le_i32($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, 4, 'generationCount', 'int32', $generationCount);
        $pos += 4;

        for ($i = 0; $i < $generationCount && $i < 1024 && $pos + 8 <= strlen($bytes); $i++) {
            $e = read_le_i32($bytes, $pos);
            $n = read_le_i32($bytes, $pos + 4);
            add_raw_header_field($fields, $bytes, $pos, 8, 'generation[' . $i . ']', 'FGenerationInfo', 'exportCount=' . $e . ', nameCount=' . $n);
            $pos += 8;
        }
    }

    $headerEnd = first_positive_offset([$nameOffset, $importOffset, $exportOffset]);
    add_unparsed_header_bytes($fields, $bytes, $pos, $headerEnd, 'Bytes between decoded UE1 header fields and the first package table.');

    return $fields;
}

function raw_header_value_text($value): string
{
    if (is_scalar($value) || $value === null) {
        return (string)$value;
    }

    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function renderRawHeaderFields(array $fields): void
{
    if (!$fields) {
        return;
    }

    ?>
    <details class="grid-after-tree raw-header-details">
        <summary>Raw Header Data</summary>
        <table class="data raw-header-table">
            <thead>
                <tr>
                    <th>Offset</th>
                    <th>Size</th>
                    <th>Field</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Raw Hex</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fields as $f): ?>
                    <tr>
                        <td class="mono"><?= h((string)($f['offset'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($f['size'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($f['name'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($f['type'] ?? '')) ?></td>
                        <td class="mono raw"><?= h(raw_header_value_text($f['value'] ?? '')) ?></td>
                        <td class="mono raw"><?= h((string)($f['rawHex'] ?? '')) ?></td>
                        <td class="raw"><?= h((string)($f['note'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php
}

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : '';
$filePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);

if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE1.php: no package file is available.\n";
    echo "Use UE1/upload.php, put a supported package file into UE1/uploads/, or keep oldtest.utx beside UE1.php.\n";
    exit;
}

$currentRel = str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR)
    ? $uploadRelDir . '/' . basename($filePath)
    : basename($filePath);

$pkg = new UnrealPackageReader($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
$rawHeaderFields = build_ue1_raw_header_fields($filePath);
$pkgFlagsDecoded = $pkg->decodePKG((int)($hdr['pkgFlags'] ?? 0));
$displayGuid = strtoupper((string)($hdr['guid'] ?? ''));

$importsByOuter = [];
foreach ($imports as $idx => $im) {
    $importsByOuter[(int)($im['outerIndex'] ?? 0)][] = (int)$idx;
}

$exportsByOuter = [];
foreach ($exports as $idx => $ex) {
    $exportsByOuter[(int)($ex['packageIndex'] ?? $ex['outerIndex'] ?? 0)][] = (int)$idx;
}

function renderPropsTree(array $props): void
{
    ?>
    <div class="props-tree">
        <?php foreach ($props as $p): ?>
            <?php
            $name = (string)($p['name'] ?? '');
            $type = (string)($p['type'] ?? '');
            $valueText = prop_value_text($p['value'] ?? '');
            $raw = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $extras = $p;
            unset($extras['name'], $extras['type'], $extras['value'], $extras['rawHex']);
            ?>
            <details class="prop-node">
                <summary>
                    <span class="ico prop">▤</span>
                    <span class="prop-name"><?= h($name) ?></span>
                    <?php if ($type !== ''): ?><span class="prop-type"><?= h($type) ?></span><?php endif; ?>
                    <?php if ($valueText !== ''): ?><span class="prop-value"><?= h($valueText) ?></span><?php endif; ?>
                </summary>
                <div class="prop-lines">
                    <?php if ($name !== ''): ?><div>Name:<span class="mono"><?= h($name) ?></span></div><?php endif; ?>
                    <?php if ($type !== ''): ?><div>Type:<span class="mono"><?= h($type) ?></span></div><?php endif; ?>
                    <?php if ($valueText !== ''): ?><div>Value:<span class="mono raw-inline"><?= h($valueText) ?></span></div><?php endif; ?>

                    <?php foreach ($extras as $k => $v): ?>
                        <?php if ($v === '' || $v === null || $v === []) continue; ?>
                        <div class="extra-line">
                            <?= h($k) ?>:<span class="mono raw-inline"><?= h(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($p['rawHex'])): ?>
                        <div class="extra-line">rawHex:<span class="mono raw-inline"><?= h($p['rawHex']) ?></span></div>
                    <?php endif; ?>

                    <details class="raw-prop">
                        <summary>Raw property record</summary>
                        <pre><?= h((string)$raw) ?></pre>
                    </details>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderImportNode(UnrealPackageReader $pkg, array $imports, array $importsByOuter, int $idx, bool $includeChildren, string $idPrefix = ''): void
{
    $im = $imports[$idx] ?? null;
    if (!is_array($im)) {
        return;
    }

    $ref = -($idx + 1);
    $objectIdx = (int)($im['objectName'] ?? -1);
    $classIdx = (int)($im['className'] ?? -1);
    $classPackageIdx = (int)($im['classPackage'] ?? -1);
    $object = $pkg->importObjectName($objectIdx);
    $class = $pkg->importClassName($classIdx);
    $classPackage = $pkg->importClassPackageName($classPackageIdx);
    $outerLabel = object_ref_label($pkg, (int)($im['outerIndex'] ?? 0));
    $children = $includeChildren ? ($importsByOuter[$ref] ?? []) : [];
    $anchor = $idPrefix === 'tables-' ? object_ref_target_id($ref) : $idPrefix . object_ref_target_id($ref);
    ?>
    <details id="<?= h($anchor) ?>" class="tree-node" data-filter-row>
        <summary>
            <span class="ico package">▣</span>
            <span class="name"><?= name_link_html($pkg, $objectIdx, $object) ?></span>
        </summary>
        <div class="tree-lines">
            <div>
                Object:<?= name_link_html($pkg, $objectIdx, $object) ?>
                <a class="ref-tag ref-link" href="#<?= h(object_ref_target_id($ref)) ?>" data-ref="<?= h($ref) ?>">(<?= h($ref) ?>)</a>
            </div>
            <div>Class:<?= name_link_html($pkg, $classIdx, $class) ?></div>
            <div>Package:<?= name_link_html($pkg, $classPackageIdx, $classPackage) ?></div>
            <?php if ($outerLabel !== ''): ?><div class="extra-line">Outer:<?= ref_value_html($outerLabel) ?></div><?php endif; ?>
            <?php foreach ($children as $childIdx): ?>
                <?php renderImportNode($pkg, $imports, $importsByOuter, (int)$childIdx, true, $idPrefix); ?>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}

function renderExportNode(UnrealPackageReader $pkg, array $exports, array $exportsByOuter, int $idx, bool $withProps = false, string $idPrefix = '', bool $includeChildren = true): void
{
    $ex = $exports[$idx] ?? null;
    if (!is_array($ex)) {
        return;
    }

    $ref = $idx + 1;
    $objectIdx = (int)($ex['objectName'] ?? -1);
    $object = $pkg->exportObjectName($objectIdx);
    $class = object_ref_label($pkg, (int)($ex['classIndex'] ?? 0));
    $super = object_ref_label($pkg, (int)($ex['superIndex'] ?? 0));
    $outer = object_ref_label($pkg, (int)($ex['packageIndex'] ?? 0));
    $flags = (int)($ex['objectFlags'] ?? 0);
    $props = $withProps ? ($pkg->getExportProperties($idx) ?? []) : [];
    $children = $includeChildren ? ($exportsByOuter[$ref] ?? []) : [];
    $anchor = $idPrefix === 'tables-' ? object_ref_target_id($ref) : $idPrefix . object_ref_target_id($ref);
    $propsId = 'props-' . (int)$idx;
    ?>
    <details id="<?= h($anchor) ?>" class="tree-node" open data-filter-row>
        <summary>
            <span class="ico export">≡</span>
            <span class="name"><?= name_link_html($pkg, $objectIdx, $object) ?></span>
            <?php if ($class !== ''): ?><span class="class-name"><?= ref_value_html($class) ?></span><?php endif; ?>
        </summary>
        <div class="tree-lines">
            <div>ObjectFlags:<span class="mono"><?= h(sprintf('%08X', $flags)) ?></span><?= h(flag_names($pkg->decodeRF($flags))) ?></div>
            <div>
                Object:<?= name_link_html($pkg, $objectIdx, $object) ?>
                <a class="ref-tag ref-link" href="#<?= h(object_ref_target_id($ref)) ?>" data-ref="<?= h($ref) ?>">(<?= h($ref) ?>)</a>
            </div>
            <?php if ($class !== ''): ?><div>Class:<?= ref_value_html($class) ?></div><?php endif; ?>
            <?php if ($super !== ''): ?><div class="extra-line">Super:<?= ref_value_html($super) ?></div><?php endif; ?>
            <?php if ($outer !== ''): ?><div class="extra-line">Package:<?= ref_value_html($outer) ?></div><?php endif; ?>
            <div class="extra-line">Object Size:<span class="mono"><?= h($ex['serialSize'] ?? '') ?></span></div>
            <div class="extra-line">Object Offset:<span class="mono"><?= h($ex['serialOffset'] ?? '') ?></span></div>

            <?php if ($withProps && $props): ?>
                <div class="prop-tools">
                    <button class="prop-btn" type="button" onclick="toggleProps('<?= h($propsId) ?>')">Properties <span><?= count($props) ?></span></button>
                    <button class="prop-btn small" type="button" onclick="setPropsOpen('<?= h($propsId) ?>', true)">Expand all</button>
                    <button class="prop-btn small" type="button" onclick="setPropsOpen('<?= h($propsId) ?>', false)">Collapse all</button>
                    <button class="prop-btn small" type="button" onclick="copyPropsText('<?= h($propsId) ?>')">Copy text</button>
                </div>
                <div id="<?= h($propsId) ?>" class="props-block">
                    <?php renderPropsTree($props); ?>
                </div>
            <?php endif; ?>

            <?php foreach ($children as $childIdx): ?>
                <?php renderExportNode($pkg, $exports, $exportsByOuter, (int)$childIdx, $withProps, $idPrefix, true); ?>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>UE1 Explorer — <?= h(basename($filePath)) ?></title>
    <style>
        :root{--b:#cfd7df;--bg:#eef6f8;--panel:#fff;--muted:#f5f7f9;--text:#071629;--sub:#536471;--accent:#0969da;--soft:#eaf4ff;--orange:#c26700;--extra:#537895;--extra-bg:#f4f9ff}
        *{box-sizing:border-box}
        html,body{margin:0;background:var(--bg);color:var(--text);scroll-behavior:smooth}
        body{font-family:Segoe UI,Tahoma,Arial,sans-serif;font-size:14px}
        .mono{font-family:Consolas,Menlo,monospace}
        .raw,.raw-inline{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
        .workspace{padding:12px}
        .viewer{width:100%;background:var(--panel);border:1px solid var(--b);min-height:650px}
        .doc-tabs{display:flex;margin-left:12px}
        .doc-tab{padding:9px 28px;border:1px solid var(--b);border-bottom:0;border-radius:6px 6px 0 0;background:#fff;font-weight:600}
        .toolbar{display:grid;grid-template-columns:minmax(420px,1fr) minmax(260px,420px);gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--b);background:#fbfbfb}
        .file-open-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .file-open-bar form{display:flex;gap:6px;align-items:center;margin:0}
        .file-select{min-width:320px;padding:6px 8px;border:1px solid #9aa7b1;background:#fff}
        .btn{border:1px solid var(--b);background:#fff;border-radius:5px;padding:5px 9px;cursor:pointer;text-decoration:none;color:var(--text)}
        .btn:hover{background:#eef6ff}
        .package-name{text-align:right;color:#475569}
        .tabs,.subtabs{display:flex;border-bottom:1px solid var(--b);background:#f8fafb}
        .tab,.subtab{border:0;border-right:1px solid var(--b);background:transparent;padding:10px 18px;font-weight:700;cursor:pointer}
        .tab.active,.subtab.active{background:#fff;color:var(--accent);box-shadow:inset 0 -2px 0 var(--accent)}
        .panel,.subpanel{display:none;padding:16px}
        .panel.active,.subpanel.active{display:block}
        .package-grid{display:grid;grid-template-columns:minmax(560px,760px) minmax(320px,520px);gap:24px;align-items:start}
        .field-grid{display:grid;grid-template-columns:170px minmax(0,1fr);gap:10px 18px;align-items:center}
        .field-label{font-weight:600}
        .field-value{min-height:30px;border:1px solid var(--b);background:#fbfbfb;padding:6px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .flag-table,.data{border-collapse:collapse;width:100%;margin-top:20px}
        .flag-table th,.flag-table td,.data th,.data td{border:1px solid var(--b);padding:7px 9px;vertical-align:top}
        .flag-table th,.data th{background:var(--muted);text-align:left}
        .flag-true{background:#0078d7;color:#fff}
        .raw-header-table{font-size:13px}
        .raw-header-table th:nth-child(1),.raw-header-table th:nth-child(2){width:70px}
        .raw-header-table th:nth-child(3){width:190px}
        .raw-header-table th:nth-child(4){width:120px}
        .raw-header-table th:nth-child(6){width:260px}
        .tree-box{border:1px solid var(--b);background:#fff;padding:12px;margin:12px 0 18px;width:100%}
        .tree-node{margin:3px 0;scroll-margin-top:20px}
        .tree-node.is-target>summary,.data tr.is-target td{background:#fff3cd!important;outline:2px solid #f0c36d}
        .tree-node summary{cursor:pointer;list-style:none;padding:3px 4px;border-radius:3px}
        .tree-node summary::-webkit-details-marker,.prop-node summary::-webkit-details-marker{display:none}
        .tree-node[open]>summary:before,.prop-node[open]>summary:before{content:'▾ ';color:#546170}
        .tree-node:not([open])>summary:before,.prop-node:not([open])>summary:before{content:'▸ ';color:#546170}
        .ico{display:inline-block;width:22px;color:#2f5e87}
        .ico.export{color:#111}
        .ico.prop{color:#6f42c1}
        .name{font-weight:650}
        .class-name{margin-left:10px;color:var(--orange)}
        .tree-lines{margin-left:30px;padding-left:12px;border-left:1px dotted #aeb8c2}
        .tree-lines>div{padding:2px 0}
        .tree-lines span.mono{margin-left:5px}
        .extra-line{color:var(--extra);background:var(--extra-bg);border-left:2px solid #bdd7ee;margin:2px 0 2px -6px;padding-left:6px!important}
        .ref-tag,.name-tag{display:inline-block;margin-left:4px;color:#2f6f9f;background:#edf6ff;border:1px solid #c7dff2;border-radius:3px;padding:0 3px;font-family:Consolas,Menlo,monospace;font-size:.92em}
        .ref-link,.name-link{text-decoration:none}
        .ref-link:hover,.name-link:hover{text-decoration:underline;background:#dff0ff}
        .content-item{display:grid;grid-template-columns:1fr auto;border-bottom:1px solid #e5e7eb;padding:6px 8px}
        .content-class{color:var(--orange)}
        .grid-after-tree summary{cursor:pointer;font-weight:700;color:var(--accent);padding:4px 0}
        .props-block{display:none;margin-top:8px;max-width:100%;overflow-x:auto}
        .prop-tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
        .prop-btn{border:1px solid var(--b);background:#fff;border-radius:5px;padding:4px 8px;cursor:pointer}
        .prop-btn.small{font-size:12px;color:#0958b8}
        .prop-btn span{display:inline-block;margin-left:5px;background:#e7f5ff;border:1px solid #b6dfff;border-radius:999px;padding:1px 6px}
        .props-tree{border:1px solid #c8d9ea;background:#fbfdff;margin-top:8px;padding:8px}
        .prop-node{border-bottom:1px solid #e2edf7;padding:4px 0}
        .prop-node summary{cursor:pointer;list-style:none;padding:4px 6px}
        .prop-name{font-weight:650}
        .prop-type{margin-left:10px;color:#6f42c1}
        .prop-value{margin-left:10px;color:#116329;font-family:Consolas,Menlo,monospace}
        .prop-lines{margin-left:30px;padding-left:12px;border-left:1px dotted #b7c8d9}
        .prop-lines>div{padding:2px 0}
        .raw-prop summary{font-weight:600;color:#0969da}
        .raw-prop pre{margin:6px 0 0;background:#f6f8fa;border:1px solid #d0d7de;padding:8px;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
        .names-table{table-layout:fixed}
        .warn{border:1px solid #d1242f;background:#fff8f8;padding:8px 12px;margin-top:14px}
        @media(max-width:1000px){.package-grid,.toolbar{grid-template-columns:1fr}.package-name{text-align:left}}
    </style>
    <script>
        function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
        function showSub(id){document.querySelectorAll('.subtab').forEach(e=>e.classList.toggle('active',e.dataset.sub===id));document.querySelectorAll('.subpanel').forEach(e=>e.classList.toggle('active',e.id===id));}
        function toggleProps(id){const e=document.getElementById(id);if(e)e.style.display=(e.style.display==='block')?'none':'block';}
        function setPropsOpen(id,open){const e=document.getElementById(id);if(!e)return;e.style.display='block';e.querySelectorAll('details').forEach(d=>d.open=open);}
        function copyPropsText(id){const e=document.getElementById(id);if(!e)return;setPropsOpen(id,true);const text=e.innerText.replace(/\n{3,}/g,'\n\n');navigator.clipboard&&navigator.clipboard.writeText(text);}
        function openContainingDetails(el){let p=el;while(p){if(p.tagName&&p.tagName.toLowerCase()==='details')p.open=true;p=p.parentElement;}}
        function clearTargetState(){document.querySelectorAll('.tree-node.is-target,.data tr.is-target').forEach(e=>e.classList.remove('is-target'));}
        function jumpToRef(targetId){if(!targetId)return;if(targetId.indexOf('ref-import-')===0){showPanel('tables-panel');showSub('imports-sub');}else if(targetId.indexOf('ref-export-')===0){showPanel('tables-panel');showSub('exports-sub');}else if(targetId.indexOf('ref-name-')===0){showPanel('tables-panel');showSub('names-sub');}window.setTimeout(()=>{const target=document.getElementById(targetId);if(!target)return;clearTargetState();openContainingDetails(target);target.classList.add('is-target');target.scrollIntoView({behavior:'smooth',block:'center'});},60);}
        document.addEventListener('click',function(ev){const a=ev.target.closest&&ev.target.closest('a.ref-link,a.name-link');if(!a)return;const href=a.getAttribute('href')||'';if(href.charAt(0)!=='#')return;ev.preventDefault();history.replaceState(null,'',window.location.pathname+window.location.search);jumpToRef(href.substring(1));});
        window.addEventListener('load',function(){if(location.hash){const targetId=location.hash.substring(1);history.replaceState(null,'',window.location.pathname+window.location.search);jumpToRef(targetId);}});
    </script>
</head>
<body>
    <div class="workspace">
        <div class="doc-tabs">
            <div class="doc-tab"><?= h(basename($filePath)) ?></div>
        </div>

        <div class="viewer">
            <div class="toolbar">
                <div class="file-open-bar">
                    <form method="get">
                        <select class="file-select" name="file">
                            <?php if (is_file(__DIR__ . '/oldtest.utx')): ?>
                                <option value="oldtest.utx"<?= $currentRel === 'oldtest.utx' ? ' selected' : '' ?>>oldtest.utx</option>
                            <?php endif; ?>
                            <?php foreach ($uploadedFiles as $up): ?>
                                <option value="<?= h($up['rel']) ?>"<?= basename($filePath) === $up['name'] ? ' selected' : '' ?>>
                                    <?= h($up['name']) ?> (<?= h(number_format((int)$up['size'])) ?> bytes)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Open</button>
                        <a class="btn" href="upload.php">Upload</a>
                    </form>
                </div>
                <span class="package-name"><?= h($currentRel) ?> (<?= h($pkg->getFileSize()) ?>)</span>
            </div>

            <div class="tabs">
                <button class="tab active" data-panel="package-panel" onclick="showPanel('package-panel')">▣ Package</button>
                <button class="tab" data-panel="content-panel" onclick="showPanel('content-panel')">▤ Content</button>
                <button class="tab" data-panel="externs-panel" onclick="showPanel('externs-panel')">⌘ Externs</button>
                <button class="tab" data-panel="tables-panel" onclick="showPanel('tables-panel')">▦ Tables</button>
            </div>

            <section id="package-panel" class="panel active">
                <div class="package-grid">
                    <div class="field-grid">
                        <div class="field-label">GUID</div><div class="field-value mono"><?= h($displayGuid) ?></div>
                        <div class="field-label">Version</div><div class="field-value mono"><?= h($hdr['version'] ?? '') ?></div>
                        <div class="field-label">Licensee Version</div><div class="field-value mono"><?= h($hdr['licensee'] ?? '') ?></div>
                        <div class="field-label">Signature</div><div class="field-value mono"><?= h(hx((int)($hdr['signature'] ?? 0))) ?></div>
                    </div>
                    <div class="field-grid">
                        <div class="field-label">Flags</div><div class="field-value mono"><?= h(hx((int)($hdr['pkgFlags'] ?? 0))) ?></div>
                        <div class="field-label">Build</div><div class="field-value">Unreal1</div>
                        <div class="field-label">Heritage</div><div class="field-value mono"><?= h(($hdr['heritageCount'] ?? '') . (($hdr['heritageOffset'] ?? '') !== '' ? ' / ' . ($hdr['heritageOffset'] ?? '') : '')) ?></div>
                        <div class="field-label">Counts</div><div class="field-value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div>
                    </div>
                </div>

                <table class="flag-table">
                    <thead>
                        <tr><th>Flag</th><th>Condition</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pkgFlagsDecoded as $flag): ?>
                            <tr><td class="flag-true"><?= h(str_replace('PKG_', '', $flag)) ?></td><td>True</td></tr>
                        <?php endforeach; ?>
                        <?php if (!$pkgFlagsDecoded): ?>
                            <tr><td></td><td></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php renderRawHeaderFields($rawHeaderFields); ?>

                <?php if ($issues): ?>
                    <div class="warn">
                        <strong>Validation / Notes</strong>
                        <ul>
                            <?php foreach ($issues as $w): ?>
                                <li class="mono raw"><?= h($w) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>

            <section id="content-panel" class="panel">
                <div class="content-list">
                    <?php foreach ($exports as $i => $ex): ?>
                        <div class="content-item" data-filter-row>
                            <span><?= name_link_html($pkg, (int)($ex['objectName'] ?? -1), $pkg->exportObjectName((int)($ex['objectName'] ?? -1))) ?></span>
                            <span class="content-class"><?= ref_value_html(object_ref_label($pkg, (int)($ex['classIndex'] ?? 0))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="externs-panel" class="panel">
                <div class="tree-box">
                    <?php foreach (($importsByOuter[0] ?? []) as $rootIdx): ?>
                        <?php renderImportNode($pkg, $imports, $importsByOuter, (int)$rootIdx, true, 'externs-'); ?>
                    <?php endforeach; ?>
                    <?php foreach (($exportsByOuter[0] ?? []) as $rootIdx): ?>
                        <?php renderExportNode($pkg, $exports, $exportsByOuter, (int)$rootIdx, false, 'externs-', true); ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="tables-panel" class="panel">
                <div class="subtabs">
                    <button class="subtab active" data-sub="names-sub" onclick="showSub('names-sub')">☰ Names</button>
                    <button class="subtab" data-sub="exports-sub" onclick="showSub('exports-sub')">▤ Exports</button>
                    <button class="subtab" data-sub="imports-sub" onclick="showSub('imports-sub')">▧ Imports</button>
                    <button class="subtab" data-sub="gens-sub" onclick="showSub('gens-sub')">☷ Generations</button>
                </div>

                <div id="names-sub" class="subpanel active">
                    <h2>Names (<?= h($hdr['nameCount'] ?? '') ?>:<?= h($hdr['nameOffset'] ?? '') ?>)</h2>
                    <table class="data names-table">
                        <thead>
                            <tr><th>Name</th><th>Flags</th><th>Num.</th><th>Raw</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($names as $n): ?>
                                <?php $flags = (int)($n['flags'] ?? 0); $ni = (int)($n['index'] ?? 0); ?>
                                <tr id="<?= h(name_ref_target_id($ni)) ?>" data-filter-row>
                                    <td><?= h($n['name'] ?? '') ?></td>
                                    <td class="mono raw"><?= h(hx($flags)) ?><?= h(flag_names($pkg->decodeRF($flags))) ?></td>
                                    <td class="mono"><?= h(($n['index'] ?? '') . ' (' . hx2($ni) . ')') ?></td>
                                    <td class="mono raw"><?= h(($n['name'] ?? '') . ' / ' . $flags) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="exports-sub" class="subpanel">
                    <h2>Exports Tree (<?= h($hdr['exportCount'] ?? '') ?>:<?= h($hdr['exportOffset'] ?? '') ?>)</h2>
                    <div class="tree-box">
                        <?php foreach ($exports as $i => $_ex): ?>
                            <?php renderExportNode($pkg, $exports, $exportsByOuter, (int)$i, true, 'tables-', false); ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid-after-tree">
                        <details>
                            <summary>Raw Exports Grid</summary>
                            <table class="data">
                                <thead>
                                    <tr><th>Class</th><th>Super</th><th>Package</th><th>Object</th><th>Flags</th><th>Size</th><th>Offset</th><th>Num.</th><th>Raw</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exports as $i => $ex): ?>
                                        <?php $objIdx = (int)($ex['objectName'] ?? -1); ?>
                                        <tr data-filter-row>
                                            <td><?= ref_value_html(object_ref_label($pkg, (int)($ex['classIndex'] ?? 0))) ?></td>
                                            <td><?= ref_value_html(object_ref_label($pkg, (int)($ex['superIndex'] ?? 0))) ?></td>
                                            <td><?= ref_value_html(object_ref_label($pkg, (int)($ex['packageIndex'] ?? 0))) ?></td>
                                            <td><?= name_link_html($pkg, $objIdx, $pkg->exportObjectName($objIdx)) ?></td>
                                            <td class="mono raw"><?= h(hx((int)($ex['objectFlags'] ?? 0))) ?></td>
                                            <td><?= h($ex['serialSize'] ?? '') ?></td>
                                            <td><?= h($ex['serialOffset'] ?? '') ?></td>
                                            <td><?= h($i . ' (' . hx2($i) . ')') ?></td>
                                            <td class="mono raw">
                                                <?= h(($ex['classIndex'] ?? '') . ' / ' . ($ex['superIndex'] ?? '') . ' / ' . ($ex['packageIndex'] ?? '') . ' / ') ?>
                                                <?= name_index_link($objIdx) ?>
                                                <?= h(' / ' . ($ex['objectFlags'] ?? '') . ' / ' . ($ex['serialSize'] ?? '') . ' / ' . ($ex['serialOffset'] ?? '')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </div>
                </div>

                <div id="imports-sub" class="subpanel">
                    <h2>Imports Tree (<?= h($hdr['importCount'] ?? '') ?>:<?= h($hdr['importOffset'] ?? '') ?>)</h2>
                    <div class="tree-box">
                        <?php foreach ($imports as $i => $_im): ?>
                            <?php renderImportNode($pkg, $imports, $importsByOuter, (int)$i, false, 'tables-'); ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid-after-tree">
                        <details>
                            <summary>Raw Imports Grid</summary>
                            <table class="data">
                                <thead>
                                    <tr><th>Class Package</th><th>Class Name</th><th>Package Name</th><th>Object Name</th><th>Num.</th><th>Raw</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($imports as $i => $im): ?>
                                        <?php $cp = (int)($im['classPackage'] ?? -1); $cn = (int)($im['className'] ?? -1); $on = (int)($im['objectName'] ?? -1); ?>
                                        <tr data-filter-row>
                                            <td><?= name_link_html($pkg, $cp, $pkg->importClassPackageName($cp)) ?></td>
                                            <td><?= name_link_html($pkg, $cn, $pkg->importClassName($cn)) ?></td>
                                            <td><?= ref_value_html(object_ref_label($pkg, (int)($im['outerIndex'] ?? 0))) ?></td>
                                            <td><?= name_link_html($pkg, $on, $pkg->importObjectName($on)) ?></td>
                                            <td><?= h($i . ' (' . hx2($i) . ')') ?></td>
                                            <td class="mono raw"><?= name_index_link($cp) ?><?= h(' / ') ?><?= name_index_link($cn) ?><?= h(' / ' . ($im['outerIndex'] ?? '') . ' / ') ?><?= name_index_link($on) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    </div>
                </div>

                <div id="gens-sub" class="subpanel">
                    <h2>Generations (<?= count($hdr['generations'] ?? []) ?>)</h2>
                    <table class="data">
                        <thead>
                            <tr><th>ExportCount</th><th>NameCount</th><th>Num.</th><th>Raw</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (($hdr['generations'] ?? []) as $i => $g): ?>
                                <tr>
                                    <td><?= h($g['e'] ?? '') ?></td>
                                    <td><?= h($g['n'] ?? '') ?></td>
                                    <td><?= h($i) ?></td>
                                    <td><?= h(($g['e'] ?? '') . ' / ' . ($g['n'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
