<?php
declare(strict_types=1);

require_once __DIR__ . '/UnrealPackageReader.php';

$uploadDir = __DIR__ . '/uploads';
$uploadRelDir = 'uploads';
$allowedExt = ['uasset', 'umap'];

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hx($v): string
{
    return sprintf('0x%08X', (int)$v);
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

    foreach (['test.uasset', 'test.umap'] as $defaultName) {
        $default = __DIR__ . DIRECTORY_SEPARATOR . $defaultName;
        if (is_file($default)) {
            return $default;
        }
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

function ref_label(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) {
        return '';
    }

    $name = $pkg->displayNameFromRef($ref);
    return $name !== '' ? $name . '(' . $ref . ')' : '(' . $ref . ')';
}

function ref_link(UnrealPackageReader4 $pkg, int $ref): string
{
    if ($ref === 0) {
        return '';
    }

    return '<a class="ref-link mono" href="#' . h(object_ref_target_id($ref)) . '">' . h(ref_label($pkg, $ref)) . '</a>';
}

function name_link(UnrealPackageReader4 $pkg, array $name): string
{
    $idx = (int)($name['index'] ?? -1);
    $num = (int)($name['number'] ?? 0);
    $text = (string)($name['text'] ?? ($idx >= 0 ? $pkg->nameByIndex($idx, $num) : ''));

    if ($idx < 0) {
        return h($text);
    }

    return '<a class="name-link mono" href="#' . h(name_ref_target_id($idx)) . '">' . h($text) . '</a>'
        . '<a class="name-tag name-link" href="#' . h(name_ref_target_id($idx)) . '">#' . h($idx) . '</a>';
}

function read_le_u16(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 2 > strlen($bytes)) {
        return 0;
    }

    return (int)unpack('v', substr($bytes, $offset, 2))[1];
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

function read_le_i64(string $bytes, int $offset): int
{
    if ($offset < 0 || $offset + 8 > strlen($bytes)) {
        return 0;
    }

    $parts = unpack('Vlo/Vhi', substr($bytes, $offset, 8));
    $lo = (int)$parts['lo'];
    $hi = (int)$parts['hi'];
    $value = ($hi * 4294967296) + $lo;
    if ($hi & 0x80000000) {
        $value -= 18446744073709551616;
    }

    return (int)$value;
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

    $parts = [
        read_le_u32($bytes, $offset),
        read_le_u32($bytes, $offset + 4),
        read_le_u32($bytes, $offset + 8),
        read_le_u32($bytes, $offset + 12),
    ];

    return sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
}

function add_guid_field(array &$fields, string $bytes, int &$pos, string $name): string
{
    $guid = read_guid_text($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 16, $name, 'FGuid', $guid);
    $pos += 16;
    return $guid;
}

function add_i32_field(array &$fields, string $bytes, int &$pos, string $name): int
{
    $value = read_le_i32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, $name, 'int32', $value);
    $pos += 4;
    return $value;
}

function add_u32_field(array &$fields, string $bytes, int &$pos, string $name): int
{
    $value = read_le_u32($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 4, $name, 'uint32', $value);
    $pos += 4;
    return $value;
}

function add_i64_field(array &$fields, string $bytes, int &$pos, string $name): int
{
    $value = read_le_i64($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, 8, $name, 'int64', $value);
    $pos += 8;
    return $value;
}

function read_engine_version_raw(string $bytes, int $offset): array
{
    $pos = $offset;
    $major = read_le_u16($bytes, $pos); $pos += 2;
    $minor = read_le_u16($bytes, $pos); $pos += 2;
    $patch = read_le_u16($bytes, $pos); $pos += 2;
    $changelist = read_le_u32($bytes, $pos); $pos += 4;
    [$branch, $branchSize] = read_fstring_raw($bytes, $pos);
    $pos += $branchSize;

    return [[
        'major' => $major,
        'minor' => $minor,
        'patch' => $patch,
        'changelist' => $changelist,
        'branch' => $branch,
    ], $pos - $offset];
}

function read_custom_versions_raw(string $bytes, int $offset): array
{
    $pos = $offset;
    $count = read_le_i32($bytes, $pos);
    $pos += 4;
    $items = [];

    if ($count < 0 || $count > 4096) {
        return [['count' => $count, 'items' => [], 'warning' => 'invalid custom version count'], 4];
    }

    for ($i = 0; $i < $count && $pos + 20 <= strlen($bytes); $i++) {
        $items[] = [
            'guid' => read_guid_text($bytes, $pos),
            'version' => read_le_i32($bytes, $pos + 16),
        ];
        $pos += 20;
    }

    return [['count' => $count, 'items' => $items], $pos - $offset];
}

function build_ue4_raw_header_fields(string $packagePath, array $hdr): array
{
    $bytes = is_file($packagePath) ? (string)file_get_contents($packagePath) : '';
    if (strlen($bytes) < 32) {
        return [];
    }

    $fields = [];
    $pos = 0;

    add_u32_field($fields, $bytes, $pos, 'signature');
    $legacyFileVersion = add_i32_field($fields, $bytes, $pos, 'legacyFileVersion');

    if ($legacyFileVersion !== -4) {
        add_i32_field($fields, $bytes, $pos, 'legacyUE3Version');
    }

    $rawVersionOffset = $pos;
    $rawVersion = add_i32_field($fields, $bytes, $pos, 'version');
    if (!empty($hdr['unversioned'])) {
        $fields[count($fields) - 1]['note'] = 'Raw file value; parser assumes UE4 version ' . (string)($hdr['version'] ?? '') . ' for unversioned package table parsing.';
    }

    add_i32_field($fields, $bytes, $pos, 'licenseeVersion');

    if ($legacyFileVersion <= -2) {
        [$customVersions, $customSize] = read_custom_versions_raw($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, $customSize, 'customVersions', 'TArray<FCustomVersion>', json_encode($customVersions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $pos += $customSize;
    }

    $totalHeaderSize = add_i32_field($fields, $bytes, $pos, 'totalHeaderSize');

    [$folderName, $folderSize, $folderNote] = read_fstring_raw($bytes, $pos);
    add_raw_header_field($fields, $bytes, $pos, $folderSize, 'folderName', 'FString', $folderName, $folderNote);
    $pos += $folderSize;

    add_u32_field($fields, $bytes, $pos, 'packageFlags');
    add_i32_field($fields, $bytes, $pos, 'nameCount');
    add_i32_field($fields, $bytes, $pos, 'nameOffset');

    if (array_key_exists('gatherableTextDataCount', $hdr)) {
        add_i32_field($fields, $bytes, $pos, 'gatherableTextDataCount');
        add_i32_field($fields, $bytes, $pos, 'gatherableTextDataOffset');
    }

    add_i32_field($fields, $bytes, $pos, 'exportCount');
    add_i32_field($fields, $bytes, $pos, 'exportOffset');
    add_i32_field($fields, $bytes, $pos, 'importCount');
    add_i32_field($fields, $bytes, $pos, 'importOffset');
    add_i32_field($fields, $bytes, $pos, 'dependsOffset');

    foreach (['stringAssetReferencesCount', 'stringAssetReferencesOffset', 'searchableNamesOffset', 'thumbnailTableOffset'] as $fieldName) {
        if (array_key_exists($fieldName, $hdr) && $pos + 4 <= strlen($bytes)) {
            add_i32_field($fields, $bytes, $pos, $fieldName);
        }
    }

    if ($pos + 16 <= strlen($bytes)) {
        add_guid_field($fields, $bytes, $pos, 'guid');
    }

    $generationCount = add_i32_field($fields, $bytes, $pos, 'generationCount');
    for ($i = 0; $i < $generationCount && $i < 4096 && $pos + 8 <= strlen($bytes); $i++) {
        $offset = $pos;
        $exportCount = read_le_i32($bytes, $pos);
        $nameCount = read_le_i32($bytes, $pos + 4);
        add_raw_header_field($fields, $bytes, $offset, 8, 'generation[' . $i . ']', 'FGenerationInfo', 'exportCount=' . $exportCount . ', nameCount=' . $nameCount);
        $pos += 8;
    }

    if (array_key_exists('savedByEngineVersion', $hdr) && is_array($hdr['savedByEngineVersion']) && array_key_exists('major', $hdr['savedByEngineVersion'])) {
        [$engine, $size] = read_engine_version_raw($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, $size, 'savedByEngineVersion', 'FEngineVersion', json_encode($engine, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $pos += $size;
    } elseif ($pos + 4 <= strlen($bytes)) {
        add_i32_field($fields, $bytes, $pos, 'savedByEngineVersion.changelist');
    }

    if (array_key_exists('compatibleWithEngineVersion', $hdr) && is_array($hdr['compatibleWithEngineVersion']) && array_key_exists('major', $hdr['compatibleWithEngineVersion'])) {
        [$engine, $size] = read_engine_version_raw($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, $size, 'compatibleWithEngineVersion', 'FEngineVersion', json_encode($engine, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $pos += $size;
    }

    add_u32_field($fields, $bytes, $pos, 'compressionFlags');

    $compressedChunkCount = 0;
    if (array_key_exists('compressedChunks', $hdr)) {
        $compressedChunkCount = read_le_i32($bytes, $pos);
        add_raw_header_field($fields, $bytes, $pos, 4, 'compressedChunkCount', 'int32', $compressedChunkCount);
        $pos += 4;

        for ($i = 0; $i < $compressedChunkCount && $i < 4096 && $pos + 16 <= strlen($bytes); $i++) {
            $uOff = read_le_i32($bytes, $pos);
            $uSize = read_le_i32($bytes, $pos + 4);
            $cOff = read_le_i32($bytes, $pos + 8);
            $cSize = read_le_i32($bytes, $pos + 12);
            add_raw_header_field($fields, $bytes, $pos, 16, 'compressedChunk[' . $i . ']', 'FCompressedChunk', 'uOff=' . $uOff . ', uSize=' . $uSize . ', cOff=' . $cOff . ', cSize=' . $cSize);
            $pos += 16;
        }
    }

    add_u32_field($fields, $bytes, $pos, 'packageSource');

    $additionalPackageCount = read_le_i32($bytes, $pos);
    $additionalSize = 4;
    $additional = ['count' => $additionalPackageCount, 'items' => []];
    $tmp = $pos + 4;
    if ($additionalPackageCount >= 0 && $additionalPackageCount <= 4096) {
        for ($i = 0; $i < $additionalPackageCount; $i++) {
            [$item, $itemSize] = read_fstring_raw($bytes, $tmp);
            if ($itemSize <= 0) {
                break;
            }
            $additional['items'][] = $item;
            $additionalSize += $itemSize;
            $tmp += $itemSize;
        }
    }
    add_raw_header_field($fields, $bytes, $pos, $additionalSize, 'additionalPackagesToCook', 'TArray<FString>', json_encode($additional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $pos += $additionalSize;

    if (array_key_exists('assetRegistryDataOffset', $hdr) && $pos + 4 <= strlen($bytes)) {
        add_i32_field($fields, $bytes, $pos, 'assetRegistryDataOffset');
    }

    if (array_key_exists('bulkDataStartOffset', $hdr) && $pos + 8 <= strlen($bytes)) {
        add_i64_field($fields, $bytes, $pos, 'bulkDataStartOffset');
    }

    if (array_key_exists('worldTileInfoDataOffset', $hdr) && $pos + 4 <= strlen($bytes)) {
        add_i32_field($fields, $bytes, $pos, 'worldTileInfoDataOffset');
    }

    if (array_key_exists('chunkIDs', $hdr) && $pos + 4 <= strlen($bytes)) {
        $chunkCount = read_le_i32($bytes, $pos);
        $chunkSize = 4 + max(0, min($chunkCount, 4096)) * 4;
        $chunks = ['count' => $chunkCount, 'items' => []];
        for ($i = 0; $i < $chunkCount && $i < 4096 && $pos + 4 + ($i * 4) + 4 <= strlen($bytes); $i++) {
            $chunks['items'][] = read_le_i32($bytes, $pos + 4 + ($i * 4));
        }
        add_raw_header_field($fields, $bytes, $pos, $chunkSize, 'chunkIDs', 'TArray<int32>', json_encode($chunks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $pos += $chunkSize;
    }

    if (array_key_exists('preloadDependencyCount', $hdr) && $pos + 8 <= strlen($bytes)) {
        add_i32_field($fields, $bytes, $pos, 'preloadDependencyCount');
        add_i32_field($fields, $bytes, $pos, 'preloadDependencyOffset');
    }

    if ($totalHeaderSize > 0) {
        add_unparsed_header_bytes($fields, $bytes, $pos, $totalHeaderSize, 'Bytes between decoded UE4 package summary fields and TotalHeaderSize.');
    }

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
                <tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw Hex</th><th>Note</th></tr>
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

function serial_preview(string $packagePath, array $hdr, array $ex): array
{
    $serialSize = (int)($ex['serialSize'] ?? 0);
    $serialOffset = (int)($ex['serialOffset'] ?? 0);
    $uassetSize = is_file($packagePath) ? (filesize($packagePath) ?: 0) : 0;
    $uexpPath = (string)($hdr['uexpPath'] ?? '');
    $totalHeaderSize = (int)($hdr['totalHeaderSize'] ?? 0);

    if ($serialSize <= 0) {
        return ['state' => 'none', 'source' => '', 'mode' => '', 'start' => 0, 'end' => 0, 'fileSize' => 0, 'hex' => '', 'warning' => ''];
    }

    $candidates = [['mode' => 'uasset:absolute', 'path' => $packagePath, 'offset' => $serialOffset]];
    if ($uexpPath !== '' && is_file($uexpPath)) {
        $candidates[] = ['mode' => 'uexp:absolute', 'path' => $uexpPath, 'offset' => $serialOffset];
        $candidates[] = ['mode' => 'uexp:offset-totalHeader', 'path' => $uexpPath, 'offset' => $serialOffset - $totalHeaderSize];
        $candidates[] = ['mode' => 'uexp:offset-uassetSize', 'path' => $uexpPath, 'offset' => $serialOffset - $uassetSize];
    }

    foreach ($candidates as $c) {
        $path = (string)$c['path'];
        $offset = (int)$c['offset'];
        $fileSize = is_file($path) ? (filesize($path) ?: 0) : 0;
        if ($fileSize <= 0 || $offset < 0 || $offset >= $fileSize) {
            continue;
        }

        $readLen = min(64, $serialSize, $fileSize - $offset);
        $hex = '';
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            @fseek($fh, $offset);
            $data = $readLen > 0 ? (string)@fread($fh, $readLen) : '';
            @fclose($fh);
            $hex = strtoupper(trim(chunk_split(bin2hex($data), 2, ' ')));
        }

        return [
            'state' => 'ok',
            'source' => basename($path),
            'mode' => (string)$c['mode'],
            'start' => $offset,
            'end' => $offset + $serialSize,
            'fileSize' => $fileSize,
            'hex' => $hex,
            'warning' => $offset + $serialSize > $fileSize ? 'range exceeds file' : '',
        ];
    }

    return [
        'state' => 'missing',
        'source' => '',
        'mode' => '',
        'start' => $serialOffset,
        'end' => $serialOffset + $serialSize,
        'fileSize' => $uassetSize,
        'hex' => '',
        'warning' => $uexpPath !== '' && is_file($uexpPath) ? 'range not found' : 'missing .uexp',
    ];
}

$uploadedFiles = upload_file_list($uploadDir, $uploadRelDir, $allowedExt);
$fileParam = isset($_GET['file']) ? (string)$_GET['file'] : '';
$filePath = resolve_package_path($fileParam, $uploadDir, $uploadedFiles);

if ($filePath === '' || !file_exists($filePath)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "UE4.php: no package file is available.\n";
    echo "Use UE4/upload.php, put a .uasset/.umap into UE4/uploads/, or keep test.uasset beside UE4.php.\n";
    exit;
}

$currentRel = str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR)
    ? $uploadRelDir . '/' . basename($filePath)
    : basename($filePath);

$pkg = new UnrealPackageReader4($filePath);
$hdr = $pkg->getHeader();
$names = $pkg->getNames();
$imports = $pkg->getImports();
$exports = $pkg->getExports();
$issues = $pkg->validatePackage();
$rawHeaderFields = build_ue4_raw_header_fields($filePath, $hdr);
$displayVersion = (string)($hdr['version'] ?? '');
if (!empty($hdr['unversioned'])) {
    $displayVersion .= ' (assumed; unversioned)';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>UE4 Explorer — <?= h(basename($filePath)) ?></title>
    <style>
        :root{--b:#cfd7df;--bg:#eef6f8;--panel:#fff;--muted:#f5f7f9;--text:#071629;--sub:#536471;--accent:#0969da}
        *{box-sizing:border-box}
        html,body{margin:0;background:var(--bg);color:var(--text);scroll-behavior:smooth}
        body{font-family:Segoe UI,Tahoma,Arial,sans-serif;font-size:14px}
        .mono{font-family:Consolas,Menlo,monospace}
        .raw{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
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
        .tabs{display:flex;border-bottom:1px solid var(--b);background:#f8fafb}
        .tab{border:0;border-right:1px solid var(--b);background:transparent;padding:10px 18px;font-weight:700;cursor:pointer}
        .tab.active{background:#fff;color:var(--accent);box-shadow:inset 0 -2px 0 var(--accent)}
        .panel{display:none;padding:16px}
        .panel.active{display:block}
        .grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:10px 18px;max-width:1180px}
        .label{font-weight:700}
        .value{border:1px solid var(--b);background:#fbfbfb;padding:6px 10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .data{border-collapse:collapse;width:100%;margin-top:12px}
        .data th,.data td{border:1px solid var(--b);padding:7px 9px;vertical-align:top}
        .data th{background:var(--muted);text-align:left}
        .data tr:nth-child(even){background:#fcfdff}
        .exports th:nth-child(1){width:55px}
        .exports th:nth-child(5){width:120px}
        .exports th:nth-child(6){width:90px}
        .exports th:nth-child(7){width:110px}
        .raw-header-table{font-size:13px}
        .raw-header-table th:nth-child(1),.raw-header-table th:nth-child(2){width:70px}
        .raw-header-table th:nth-child(3){width:230px}
        .raw-header-table th:nth-child(4){width:150px}
        .raw-header-table th:nth-child(6){width:260px}
        .grid-after-tree summary{cursor:pointer;font-weight:700;color:var(--accent);padding:4px 0}
        .ref-tag,.name-tag,.status{display:inline-block;margin-left:4px;border-radius:3px;padding:1px 5px;font-size:12px;border:1px solid #c7dff2;background:#edf6ff;color:#2f6f9f}
        .ok{background:#dafbe1;border-color:#aceebb;color:#116329}
        .bad{background:#fff1f0;border-color:#ffccc7;color:#8a1f11}
        .none{background:#f6f8fa;border-color:#d0d7de;color:#57606a}
        .warn{background:#fff8f8;border:1px solid #d1242f;padding:8px 12px;margin:12px 0}
        .ref-link,.name-link{text-decoration:none;color:#0969da}
        .ref-link:hover,.name-link:hover{text-decoration:underline}
        details{min-width:310px}
        summary{cursor:pointer;color:#0969da;font-weight:600}
        .kv{display:grid;grid-template-columns:115px minmax(0,1fr);gap:3px 8px;margin-top:6px}
        .hex{white-space:pre-wrap;word-break:break-word;background:#f6f8fa;border:1px solid #d0d7de;padding:6px;margin:6px 0 0;max-height:150px;overflow:auto}
        .is-target,.target{background:#fff3cd!important;outline:2px solid #f0c36d}
        .small{font-size:12px;color:#57606a}
        @media(max-width:1000px){.toolbar{grid-template-columns:1fr}.package-name{text-align:left}}
    </style>
    <script>
        function showPanel(id){document.querySelectorAll('.tab').forEach(e=>e.classList.toggle('active',e.dataset.panel===id));document.querySelectorAll('.panel').forEach(e=>e.classList.toggle('active',e.id===id));}
        function clearTargetState(){document.querySelectorAll('.target,.is-target').forEach(e=>e.classList.remove('target','is-target'));}
        function jumpToRef(id){if(!id)return;if(id.startsWith('ref-import-'))showPanel('imports-panel');else if(id.startsWith('ref-export-'))showPanel('exports-panel');else if(id.startsWith('ref-name-'))showPanel('names-panel');setTimeout(()=>{const el=document.getElementById(id);if(!el)return;clearTargetState();el.classList.add('target');el.scrollIntoView({behavior:'smooth',block:'center'});},60);}
        document.addEventListener('click',function(ev){const a=ev.target.closest&&ev.target.closest('a.ref-link,a.name-link');if(!a)return;const href=a.getAttribute('href')||'';if(href.charAt(0)!=='#')return;ev.preventDefault();jumpToRef(href.substring(1));});
    </script>
</head>
<body>
    <div class="workspace">
        <div class="doc-tabs"><div class="doc-tab"><?= h(basename($filePath)) ?></div></div>
        <div class="viewer">
            <div class="toolbar">
                <div class="file-open-bar">
                    <form method="get">
                        <select class="file-select" name="file">
                            <?php foreach ($uploadedFiles as $up): ?>
                                <option value="<?= h($up['rel']) ?>"<?= basename($filePath) === $up['name'] ? ' selected' : '' ?>><?= h($up['name']) ?> (<?= h(number_format((int)$up['size'])) ?> bytes)</option>
                            <?php endforeach; ?>
                            <?php foreach (['test.uasset', 'test.umap'] as $localDefault): ?>
                                <?php if (is_file(__DIR__ . '/' . $localDefault)): ?><option value="<?= h($localDefault) ?>"<?= $currentRel === $localDefault ? ' selected' : '' ?>><?= h($localDefault) ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Open</button>
                        <a class="btn" href="upload.php">Upload</a>
                    </form>
                </div>
                <span class="package-name"><?= h($currentRel) ?> (<?= h($pkg->getFileSize()) ?>)</span>
            </div>

            <div class="tabs">
                <button class="tab active" data-panel="summary-panel" onclick="showPanel('summary-panel')">▣ Summary</button>
                <button class="tab" data-panel="names-panel" onclick="showPanel('names-panel')">Names</button>
                <button class="tab" data-panel="imports-panel" onclick="showPanel('imports-panel')">Imports</button>
                <button class="tab" data-panel="exports-panel" onclick="showPanel('exports-panel')">Exports</button>
            </div>

            <section id="summary-panel" class="panel active">
                <h2>UE4 Package Summary</h2>
                <div class="grid">
                    <div class="label">GUID</div><div class="value mono"><?= h($hdr['guid'] ?? '') ?></div>
                    <div class="label">Legacy Version</div><div class="value mono"><?= h($hdr['legacyFileVersion'] ?? '') ?></div>
                    <div class="label">Legacy UE3 Version</div><div class="value mono"><?= h($hdr['legacyUE3Version'] ?? '') ?></div>
                    <div class="label">UE4 Version</div><div class="value mono"><?= h($displayVersion) ?></div>
                    <div class="label">Licensee Version</div><div class="value mono"><?= h($hdr['licenseeVersion'] ?? '') ?></div>
                    <div class="label">Package Flags</div><div class="value mono"><?= h(hx($hdr['packageFlags'] ?? 0)) ?></div>
                    <div class="label">Folder</div><div class="value mono"><?= h($hdr['folderName'] ?? '') ?></div>
                    <div class="label">Counts</div><div class="value mono">N <?= h($hdr['nameCount'] ?? '') ?> / I <?= h($hdr['importCount'] ?? '') ?> / E <?= h($hdr['exportCount'] ?? '') ?></div>
                    <div class="label">Offsets</div><div class="value mono">N <?= h($hdr['nameOffset'] ?? '') ?> / I <?= h($hdr['importOffset'] ?? '') ?> / E <?= h($hdr['exportOffset'] ?? '') ?></div>
                    <div class="label">Total Header Size</div><div class="value mono"><?= h($hdr['totalHeaderSize'] ?? '') ?></div>
                    <div class="label">Asset Registry Offset</div><div class="value mono"><?= h($hdr['assetRegistryDataOffset'] ?? '') ?></div>
                    <div class="label">Bulk Data Start</div><div class="value mono"><?= h($hdr['bulkDataStartOffset'] ?? '') ?></div>
                    <div class="label">Preload Dependencies</div><div class="value mono"><?= h($hdr['preloadDependencyCount'] ?? '') ?> @ <?= h($hdr['preloadDependencyOffset'] ?? '') ?></div>
                    <div class="label">UEXP Pair</div><div class="value mono"><?= !empty($hdr['hasUexp']) ? h(basename((string)$hdr['uexpPath'])) : 'not found' ?></div>
                </div>

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

            <section id="names-panel" class="panel">
                <h2>Name Map</h2>
                <table class="data">
                    <thead><tr><th>#</th><th>Name</th><th>Offset</th><th>Hashes</th></tr></thead>
                    <tbody>
                        <?php foreach ($names as $n): ?>
                            <tr id="<?= h(name_ref_target_id((int)$n['index'])) ?>">
                                <td class="mono"><?= h($n['index']) ?></td>
                                <td class="mono"><?= h($n['name']) ?></td>
                                <td class="mono"><?= h($n['offset']) ?></td>
                                <td class="mono"><?= h(($n['nonCaseHash'] ?? '') . ' / ' . ($n['caseHash'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section id="imports-panel" class="panel">
                <h2>Import Map</h2>
                <table class="data">
                    <thead><tr><th>Ref</th><th>Object</th><th>Class Package</th><th>Class</th><th>Outer</th><th>Offset</th></tr></thead>
                    <tbody>
                        <?php foreach ($imports as $im): ?>
                            <?php $ref = (int)$im['ref']; ?>
                            <tr id="<?= h(object_ref_target_id($ref)) ?>">
                                <td class="mono"><?= h($ref) ?></td>
                                <td><?= name_link($pkg, $im['objectName']) ?></td>
                                <td><?= name_link($pkg, $im['classPackage']) ?></td>
                                <td><?= name_link($pkg, $im['className']) ?></td>
                                <td><?= ref_link($pkg, (int)$im['outerIndex']) ?></td>
                                <td class="mono"><?= h($im['offset']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section id="exports-panel" class="panel">
                <h2>Export Map</h2>
                <table class="data exports">
                    <thead><tr><th>Ref</th><th>Object</th><th>Class</th><th>Outer</th><th>Serial</th><th>Flags</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                        <?php foreach ($exports as $ex): ?>
                            <?php
                            $ref = (int)$ex['ref'];
                            $p = serial_preview($filePath, $hdr, $ex);
                            $statusClass = $p['state'] === 'ok' && $p['warning'] === '' ? 'ok' : ($p['state'] === 'none' ? 'none' : 'bad');
                            ?>
                            <tr id="<?= h(object_ref_target_id($ref)) ?>">
                                <td class="mono"><?= h($ref) ?></td>
                                <td><?= name_link($pkg, $ex['objectName']) ?></td>
                                <td><?= ref_link($pkg, (int)$ex['classIndex']) ?></td>
                                <td><?= ref_link($pkg, (int)$ex['outerIndex']) ?></td>
                                <td class="mono">size <?= h($ex['serialSize']) ?><br>offset <?= h($ex['serialOffset']) ?></td>
                                <td class="mono"><?= h(hx($ex['objectFlags'] ?? 0)) ?></td>
                                <td><span class="status <?= h($statusClass) ?>"><?= h($p['warning'] ?: strtoupper($p['state'])) ?></span></td>
                                <td>
                                    <details>
                                        <summary>Details</summary>
                                        <div class="kv small">
                                            <div>Template</div><div><?= ref_link($pkg, (int)$ex['templateIndex']) ?: '<span class="status none">none</span>' ?></div>
                                            <div>Source</div><div class="mono"><?= h($p['source'] ?: '-') ?></div>
                                            <div>Mode</div><div class="mono"><?= h($p['mode'] ?: '-') ?></div>
                                            <div>Local range</div><div class="mono"><?= h($p['start']) ?>..<?= h($p['end']) ?></div>
                                            <div>File size</div><div class="mono"><?= h($p['fileSize']) ?></div>
                                            <div>Booleans</div><div>forced <?= !empty($ex['forcedExport']) ? 'Y' : 'N' ?>, client <?= !empty($ex['notForClient']) ? 'no' : 'yes' ?>, server <?= !empty($ex['notForServer']) ? 'no' : 'yes' ?>, asset <?= $ex['isAsset'] === null ? '?' : (!empty($ex['isAsset']) ? 'Y' : 'N') ?></div>
                                            <div>Package flags</div><div class="mono"><?= h(hx($ex['packageFlags'] ?? 0)) ?></div>
                                            <div>Package GUID</div><div class="mono"><?= h($ex['packageGuid'] ?? '') ?></div>
                                        </div>
                                        <?php if ($p['hex'] !== ''): ?><pre class="hex"><?= h($p['hex']) ?></pre><?php endif; ?>
                                        <?php if (!empty($ex['preload'])): ?><pre class="hex"><?= h(json_encode($ex['preload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</body>
</html>
