<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Supports the standalone `UE5` parser/viewer workflow for UE5.
 * Why: It exists for `UE5` package-format inspection, experiments, or parser development separate from the main
 *      catalog UI.
 * Role: Legacy/reference parser tooling unless another file explicitly requires it.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
declare(strict_types=1);

require_once __DIR__ . '/UnrealPackageReader.php';

$uploadDir = __DIR__ . '/uploads';
$uploadRelDir = 'uploads';
$allowedExt = ['uasset', 'umap'];

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hx($v): string { return sprintf('0x%08X', (int)$v); }

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
        $out[] = ['name' => $file, 'rel' => $uploadRelDir . '/' . rawurlencode($file), 'path' => $full, 'size' => filesize($full) ?: 0, 'mtime' => filemtime($full) ?: 0];
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

    foreach (['test.uasset', 'test.umap'] as $defaultName) {
        $default = __DIR__ . DIRECTORY_SEPARATOR . $defaultName;
        if (is_file($default)) {
            return $default;
        }
    }

    return $uploadedFiles[0]['path'] ?? '';
}

function object_ref_target_id(int $ref): string { return $ref < 0 ? 'ref-import-' . abs($ref) : 'ref-export-' . $ref; }
function name_ref_target_id(int $idx): string { return 'ref-name-' . $idx; }

function ref_label(UnrealPackageReader5 $pkg, int $ref): string
{
    if ($ref === 0) {
        return '';
    }
    $name = $pkg->displayNameFromRef($ref);
    return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
}

function ref_link(UnrealPackageReader5 $pkg, int $ref): string
{
    if ($ref === 0) {
        return '';
    }
    return '<a class="ref-link mono" href="#' . h(object_ref_target_id($ref)) . '">' . h(ref_label($pkg, $ref)) . '</a>';
}

function name_link(UnrealPackageReader5 $pkg, array $name): string
{
    $idx = (int)($name['index'] ?? -1);
    $num = (int)($name['number'] ?? 0);
    $text = (string)($name['text'] ?? ($idx >= 0 ? $pkg->nameByIndex($idx, $num) : ''));
    if ($idx < 0) {
        return h($text);
    }
    return '<a class="name-link mono" href="#' . h(name_ref_target_id($idx)) . '">' . h($text) . '</a><a class="name-tag name-link" href="#' . h(name_ref_target_id($idx)) . '">#' . h($idx) . '</a>';
}

function read_le_u16(string $bytes, int $offset): int { return ($offset < 0 || $offset + 2 > strlen($bytes)) ? 0 : (int)unpack('v', substr($bytes, $offset, 2))[1]; }
function read_le_u32(string $bytes, int $offset): int { return ($offset < 0 || $offset + 4 > strlen($bytes)) ? 0 : (int)unpack('V', substr($bytes, $offset, 4))[1]; }
function read_le_i32(string $bytes, int $offset): int { $v = read_le_u32($bytes, $offset); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
function raw_hex_at(string $bytes, int $offset, int $size): string { return ($size <= 0 || $offset < 0 || $offset >= strlen($bytes)) ? '' : strtoupper(trim(chunk_split(bin2hex(substr($bytes, $offset, $size)), 2, ' '))); }

function add_raw_header_field(array &$fields, string $bytes, int $offset, int $size, string $name, string $type, $value, string $note = ''): void
{
    if ($size <= 0) {
        return;
    }
    $fields[] = ['offset' => $offset, 'size' => $size, 'name' => $name, 'type' => $type, 'value' => $value, 'rawHex' => raw_hex_at($bytes, $offset, $size), 'note' => $note];
}

function read_fstring_raw(string $bytes, int $offset): array
{
    if ($offset < 0 || $offset + 4 > strlen($bytes)) {
        return ['', 0, 'invalid FString offset'];
    }

    $length = read_le_i32($bytes, $offset);
    if ($length === 0) {
        return ['', 4, 'length=0'];
    }

    if ($length > 0) {
        $size = 4 + $length;
        if ($offset + $size > strlen($bytes)) {
            return ['', 4, 'invalid FString length=' . $length];
        }
        $raw = substr($bytes, $offset + 4, $length);
        if ($raw !== '' && substr($raw, -1) === "\0") {
            $raw = substr($raw, 0, -1);
        }
        $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return [$text === false ? $raw : $text, $size, 'length=' . $length];
    }

    $chars = -$length;
    $size = 4 + ($chars * 2);
    if ($offset + $size > strlen($bytes)) {
        return ['', 4, 'invalid UTF-16 FString length=' . $length];
    }
    $raw = substr($bytes, $offset + 4, $chars * 2);
    if (substr($raw, -2) === "\0\0") {
        $raw = substr($raw, 0, -2);
    }
    $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    return [$text === false ? '' : $text, $size, 'utf16 length=' . $chars];
}

function read_guid_text(string $bytes, int $offset): string
{
    if ($offset + 16 > strlen($bytes)) {
        return '';
    }
    $parts = [read_le_u32($bytes, $offset), read_le_u32($bytes, $offset + 4), read_le_u32($bytes, $offset + 8), read_le_u32($bytes, $offset + 12)];
    return sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
}

function build_ue5_raw_header_fields(string $packagePath, array $hdr): array
{
    $bytes = is_file($packagePath) ? (string)file_get_contents($packagePath) : '';
    if (strlen($bytes) < 32) {
        return [];
    }

    $fields = [];
    $pos = 0;
    add_raw_header_field($fields, $bytes, $pos, 4, 'signature', 'uint32', read_le_u32($bytes, $pos)); $pos += 4;
    add_raw_header_field($fields, $bytes, $pos, 4, 'legacyFileVersion', 'int32', read_le_i32($bytes, $pos)); $legacy = read_le_i32($bytes, $pos); $pos += 4;
    if ($legacy !== -4) {
        add_raw_header_field($fields, $bytes, $pos, 4, 'legacyUE3Version', 'int32', read_le_i32($bytes, $pos)); $pos += 4;
    }
    add_raw_header_field($fields, $bytes, $pos, 4, 'fileVersionUE4', 'int32', read_le_i32($bytes, $pos), 'UE5 packages commonly store zero here when unversioned.'); $pos += 4;
    add_raw_header_field($fields, $bytes, $pos, 4, 'fileVersionLicenseeUE4', 'int32', read_le_i32($bytes, $pos)); $pos += 4;

    if ($legacy <= -2) {
        $customStart = $pos;
        $count = read_le_i32($bytes, $pos);
        $pos += 4;
        if ($count >= 0 && $count < 4096) {
            for ($i = 0; $i < $count && $pos + 20 <= strlen($bytes); $i++) {
                $pos += 20;
                if ($legacy >= -5) {
                    [$friendly, $friendlySize] = read_fstring_raw($bytes, $pos);
                    $pos += $friendlySize;
                }
            }
        }
        add_raw_header_field($fields, $bytes, $customStart, max(4, $pos - $customStart), 'customVersions', 'TArray<FCustomVersion>', 'count=' . $count);
    }

    foreach ([
        'totalHeaderSize' => 'int32',
    ] as $name => $type) {
        add_raw_header_field($fields, $bytes, $pos, 4, $name, $type, read_le_i32($bytes, $pos));
        $pos += 4;
    }

    [$folder, $folderSize, $folderNote] = read_fstring_raw($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, $folderSize, 'folderName', 'FString', $folder, $folderNote);
    $pos += $folderSize;

    foreach (['packageFlags', 'nameCount', 'nameOffset', 'exportCount', 'exportOffset', 'importCount', 'importOffset', 'dependsOffset'] as $name) {
        add_raw_header_field($fields, $bytes, $pos, 4, $name, str_contains($name, 'Flags') ? 'uint32' : 'int32', str_contains($name, 'Flags') ? read_le_u32($bytes, $pos) : read_le_i32($bytes, $pos));
        $pos += 4;
    }

    add_raw_header_field($fields, $bytes, $pos, 4, 'thumbnailTableOffset', 'int32', read_le_i32($bytes, $pos)); $pos += 4;
    add_raw_header_field($fields, $bytes, $pos, 16, 'guid', 'FGuid', read_guid_text($bytes, $pos)); $pos += 16;

    $genCount = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, 'generationCount', 'int32', $genCount); $pos += 4;
    for ($i = 0; $i < $genCount && $i < 1024 && $pos + 8 <= strlen($bytes); $i++) {
        add_raw_header_field($fields, $bytes, $pos, 8, 'generation[' . $i . ']', 'int32,int32', 'exportCount=' . read_le_i32($bytes, $pos) . ', nameCount=' . read_le_i32($bytes, $pos + 4));
        $pos += 8;
    }

    $firstTable = min(array_filter([(int)($hdr['nameOffset'] ?? 0), (int)($hdr['importOffset'] ?? 0), (int)($hdr['exportOffset'] ?? 0)], static fn($v) => $v > 0) ?: [0]);
    if ($firstTable > $pos) {
        add_raw_header_field($fields, $bytes, $pos, $firstTable - $pos, 'remainingSummaryBytes', 'bytes', ($firstTable - $pos) . ' bytes', 'UE5 package summary fields between generations and the first serialized table.');
    }

    return $fields;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pkg'])) {
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    $name = safe_package_name((string)($_FILES['pkg']['name'] ?? 'upload.uasset'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name !== '' && in_array($ext, $allowedExt, true) && is_uploaded_file((string)$_FILES['pkg']['tmp_name'])) {
        move_uploaded_file((string)$_FILES['pkg']['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $name);
        header('Location: UE5.php?file=' . rawurlencode($name));
        exit;
    }
}

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = (string)($_GET['file'] ?? '');
$packagePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);
$pkg = null;
$error = '';
$hdr = [];
$names = [];
$imports = [];
$exports = [];
$rawHeaderFields = [];

if ($packagePath !== '') {
    try {
        $pkg = new UnrealPackageReader5($packagePath);
        $hdr = $pkg->getHeader();
        $names = $pkg->getNames();
        $imports = $pkg->getImports();
        $exports = $pkg->getExports();
        $rawHeaderFields = method_exists($pkg, 'getRawHeaderFields') ? $pkg->getRawHeaderFields() : [];
        if (!$rawHeaderFields) {
            $rawHeaderFields = build_ue5_raw_header_fields($packagePath, $hdr);
        }
        $issues = $pkg->validatePackage();
        if ($issues) {
            $error = implode("\n", $issues);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>UE5 Package Reader</title>
<style>
body{font:14px/1.45 Arial,sans-serif;background:#eef3f6;color:#111;margin:0}header{display:flex;gap:10px;align-items:center;padding:12px 18px;background:#f8fbfd;border-bottom:1px solid #cbd5df}.wrap{padding:18px}.tabs a{display:inline-block;padding:10px 14px;border:1px solid #c8d4df;background:#f7fafc;text-decoration:none;color:#0366d6}.card{background:#fff;border:1px solid #c8d4df;margin:14px 0;padding:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}table{border-collapse:collapse;width:100%;background:white}th,td{border:1px solid #c8d4df;padding:7px;text-align:left;vertical-align:top}th{background:#edf2f7}.mono{font-family:Consolas,monospace}.muted{color:#5b6773}.err{background:#fff0f0;border-color:#e77;color:#900;white-space:pre-wrap}.pill{display:inline-block;border:1px solid #8aa4c2;border-radius:999px;padding:1px 7px;background:#f6fbff}.ref-link,.name-link{color:#0366d6}.name-tag{margin-left:4px;font-size:11px;color:#666}tr:target td,tr:target th{background:#fff4ce!important;box-shadow:inset 4px 0 #f0ad00}tr[id]{scroll-margin-top:80px}.scroll{overflow:auto}.raw td{font-family:Consolas,monospace;font-size:12px}.good{color:#087a2e}.bad{color:#a00000}@media(max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header><strong>UE5 Package Reader</strong><span class="muted">uasset / umap</span></header>
<div class="wrap">
<form method="get" style="display:inline-block">
<select name="file">
<?php foreach ($uploadedFiles as $f): ?>
<option value="<?= h($f['name']) ?>"<?= basename($packagePath) === $f['name'] ? ' selected' : '' ?>><?= h($f['name']) ?> (<?= h(number_format($f['size'])) ?> bytes)</option>
<?php endforeach; ?>
</select><button>Open</button>
</form>
<form method="post" enctype="multipart/form-data" style="display:inline-block;margin-left:10px"><input type="file" name="pkg" accept=".uasset,.umap"><button>Upload</button></form>
<?php if ($packagePath): ?><span style="float:right" class="mono"><?= h(str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $packagePath)) ?> (<?= h(number_format(filesize($packagePath) ?: 0)) ?> bytes)</span><?php endif; ?>

<div class="tabs"><a href="#package">Package</a><a href="#names">Names</a><a href="#imports">Imports</a><a href="#exports">Exports</a><a href="#raw">Raw header</a></div>

<?php if ($error !== ''): ?><div class="card err"><?= h($error) ?></div><?php endif; ?>

<div class="card" id="package"><h2>UE5 Package Summary</h2>
<div class="grid"><table>
<tr><th>Signature</th><td class="mono"><?= h(hx($hdr['signature'] ?? 0)) ?></td></tr>
<tr><th>Legacy file version</th><td class="mono"><?= h($hdr['legacyFileVersion'] ?? '') ?></td></tr>
<tr><th>Version</th><td class="mono"><?= h($hdr['version'] ?? '') ?><?= !empty($hdr['unversioned']) ? ' <span class="pill">unversioned assumed</span>' : '' ?></td></tr>
<tr><th>Licensee version</th><td class="mono"><?= h($hdr['licenseeVersion'] ?? '') ?></td></tr>
<tr><th>GUID</th><td class="mono"><?= h($hdr['guid'] ?? '') ?></td></tr>
<tr><th>Folder</th><td class="mono"><?= h($hdr['folderName'] ?? '') ?></td></tr>
<tr><th>Flags</th><td class="mono"><?= h(hx($hdr['packageFlags'] ?? 0)) ?></td></tr>
</table><table>
<tr><th>Names</th><td class="mono"><?= h($hdr['nameCount'] ?? 0) ?> @ <?= h($hdr['nameOffset'] ?? 0) ?></td></tr>
<tr><th>Imports</th><td class="mono"><?= h($hdr['importCount'] ?? 0) ?> @ <?= h($hdr['importOffset'] ?? 0) ?></td></tr>
<tr><th>Exports</th><td class="mono"><?= h($hdr['exportCount'] ?? 0) ?> @ <?= h($hdr['exportOffset'] ?? 0) ?></td></tr>
<tr><th>Depends offset</th><td class="mono"><?= h($hdr['dependsOffset'] ?? 0) ?></td></tr>
<tr><th>Asset registry offset</th><td class="mono"><?= h($hdr['assetRegistryDataOffset'] ?? 0) ?></td></tr>
<tr><th>Bulk data start</th><td class="mono"><?= h($hdr['bulkDataStartOffset'] ?? 0) ?></td></tr>
<tr><th>UEXP pair</th><td><?= !empty($hdr['hasUexp']) ? '<span class="good">found</span>' : '<span class="bad">not found</span>' ?></td></tr>
</table></div></div>

<div class="card scroll" id="raw"><h2>Raw Header Data</h2><table class="raw"><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw Hex</th><th>Note</th></tr>
<?php foreach ($rawHeaderFields as $row): ?>
<tr><td><?= h($row['offset'] ?? '') ?></td><td><?= h($row['size'] ?? '') ?></td><td><?= h($row['name'] ?? '') ?></td><td><?= h($row['type'] ?? '') ?></td><td><?= h(is_scalar($row['value'] ?? null) ? (string)$row['value'] : json_encode($row['value'])) ?></td><td><?= h($row['rawHex'] ?? '') ?></td><td><?= h($row['note'] ?? '') ?></td></tr>
<?php endforeach; ?>
</table></div>

<div class="card scroll" id="names"><h2>Names</h2><table><tr><th>Index</th><th>Name</th><th>Offset</th><th>Hashes</th></tr>
<?php foreach ($names as $n): $idx=(int)($n['index'] ?? 0); ?>
<tr id="<?= h(name_ref_target_id($idx)) ?>"><td class="mono"><?= h($idx) ?></td><td class="mono"><?= h($n['name'] ?? '') ?></td><td class="mono"><?= h($n['offset'] ?? '') ?></td><td class="mono"><?= h(($n['nonCaseHash'] ?? '') . ' / ' . ($n['caseHash'] ?? '')) ?></td></tr>
<?php endforeach; ?>
</table></div>

<div class="card scroll" id="imports"><h2>Imports</h2><table><tr><th>Ref</th><th>Index</th><th>Offset</th><th>Class package</th><th>Class</th><th>Outer</th><th>Object</th></tr>
<?php foreach ($imports as $im): $ref=(int)($im['ref'] ?? 0); ?>
<tr id="<?= h(object_ref_target_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td class="mono"><?= h($im['index'] ?? '') ?></td><td class="mono"><?= h($im['offset'] ?? '') ?></td><td><?= name_link($pkg, $im['classPackage'] ?? []) ?></td><td><?= name_link($pkg, $im['className'] ?? []) ?></td><td><?= ref_link($pkg, (int)($im['outerIndex'] ?? 0)) ?></td><td><?= name_link($pkg, $im['objectName'] ?? []) ?></td></tr>
<?php endforeach; ?>
</table></div>

<div class="card scroll" id="exports"><h2>Exports</h2><table><tr><th>Ref</th><th>Index</th><th>Offset</th><th>Class</th><th>Super</th><th>Template</th><th>Outer</th><th>Object</th><th>Flags</th><th>Serial</th><th>Package GUID</th><th>Asset</th></tr>
<?php foreach ($exports as $ex): $ref=(int)($ex['ref'] ?? 0); ?>
<tr id="<?= h(object_ref_target_id($ref)) ?>"><td class="mono"><?= h($ref) ?></td><td class="mono"><?= h($ex['index'] ?? '') ?></td><td class="mono"><?= h($ex['offset'] ?? '') ?></td><td><?= ref_link($pkg, (int)($ex['classIndex'] ?? 0)) ?></td><td><?= ref_link($pkg, (int)($ex['superIndex'] ?? 0)) ?></td><td><?= ref_link($pkg, (int)($ex['templateIndex'] ?? 0)) ?></td><td><?= ref_link($pkg, (int)($ex['outerIndex'] ?? 0)) ?></td><td><?= name_link($pkg, $ex['objectName'] ?? []) ?></td><td class="mono"><?= h(hx($ex['objectFlags'] ?? 0)) ?></td><td class="mono"><?= h(($ex['serialSize'] ?? '') . ' @ ' . ($ex['serialOffset'] ?? '')) ?></td><td class="mono"><?= h($ex['packageGuid'] ?? '') ?></td><td><?= !empty($ex['isAsset']) ? 'yes' : 'no' ?></td></tr>
<?php endforeach; ?>
</table></div>
</div>
</body>
</html>
