<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function examine_page_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function examine_ref_link(int $ref): string
{
    if ($ref === 0) {
        return '<span class="muted">none</span>';
    }

    if ($ref < 0) {
        $idx = abs($ref) - 1;
        return '<a class="xref mono" href="#import-' . $idx . '">' . $ref . '</a>';
    }

    $idx = $ref - 1;
    return '<a class="xref mono" href="#export-' . $idx . '">' . $ref . '</a>';
}

function examine_name_link(string $value, array $nameLookup): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $key = strtolower($text);
    if (isset($nameLookup[$key])) {
        return '<a class="xref mono path" href="#name-' . (int)$nameLookup[$key] . '">' . catalog_h($text) . '</a>';
    }

    return '<span class="mono path">' . catalog_h($text) . '</span>';
}

function examine_file_link(?int $fileId, string $label): string
{
    if (!$fileId) {
        return '<span class="muted">none</span>';
    }
    return '<a class="xref" href="file-examine.php?id=' . (int)$fileId . '">' . catalog_h($label) . '</a>';
}

function examine_hex_bytes(string $bytes): string
{
    return strtoupper(trim(chunk_split(bin2hex($bytes), 2, ' ')));
}

function examine_u32(string $bytes, int $offset): int
{
    return (int)unpack('V', substr($bytes, $offset, 4))[1];
}

function examine_i32(string $bytes, int $offset): int
{
    $v = examine_u32($bytes, $offset);
    return ($v & 0x80000000) ? $v - 0x100000000 : $v;
}

function examine_pkg_flags(int $flags): array
{
    $known = [
        0x00000001 => 'AllowDownload',
        0x00000002 => 'ClientOptional',
        0x00000004 => 'ServerSideOnly',
        0x00000008 => 'NoExportAllowed',
        0x00000010 => 'Cooked',
        0x00000020 => 'Encrypted',
        0x00008000 => 'Map',
        0x00020000 => 'Script',
        0x00040000 => 'ContainsMap',
        0x00080000 => 'DebugInfo',
        0x00100000 => 'Imports',
        0x00200000 => 'Compressed',
        0x00400000 => 'FullyCompressed',
    ];
    $out = [];
    foreach ($known as $bit => $name) {
        if (($flags & $bit) !== 0) {
            $out[] = $name;
        }
    }
    return $out;
}

function examine_build_label(int $version): string
{
    if ($version >= 500) {
        return 'UE3';
    }
    if ($version >= 100) {
        return 'UE2';
    }
    if ($version > 0) {
        return 'Unreal1';
    }
    return 'unknown';
}

function examine_header_field(array &$rows, string $bytes, int $offset, int $size, string $field, string $type, string $value, string $note = ''): void
{
    $rows[] = [
        'offset' => $offset,
        'size' => $size,
        'field' => $field,
        'type' => $type,
        'value' => $value,
        'hex' => examine_hex_bytes(substr($bytes, $offset, $size)),
        'note' => $note,
    ];
}

function examine_parse_package_header(?string $path): array
{
    if (!$path || !is_file($path)) {
        return ['ok' => false, 'error' => 'Stored package file is not available on disk.', 'summary' => [], 'rows' => []];
    }

    $bytes = @file_get_contents($path, false, null, 0, 4096);
    if ($bytes === false || strlen($bytes) < 40) {
        return ['ok' => false, 'error' => 'Stored package file is too small to parse header.', 'summary' => [], 'rows' => []];
    }

    $rows = [];
    $tag = examine_u32($bytes, 0);
    examine_header_field($rows, $bytes, 0, 4, 'signature', 'uint32', (string)$tag, sprintf('0x%08X', $tag));
    if ($tag !== 0x9E2A83C1) {
        return ['ok' => false, 'error' => sprintf('Bad package tag 0x%08X', $tag), 'summary' => [], 'rows' => $rows];
    }

    $packed = examine_u32($bytes, 4);
    $version = $packed & 0xFFFF;
    $licensee = ($packed >> 16) & 0xFFFF;
    examine_header_field($rows, $bytes, 4, 4, 'packedVersionLicensee', 'uint32', 'packed=' . $packed . ', version=' . $version . ', licensee=' . $licensee);

    $flags = examine_u32($bytes, 8);
    examine_header_field($rows, $bytes, 8, 4, 'pkgFlags', 'uint32', (string)$flags, sprintf('0x%08X', $flags));

    $nameCount = examine_i32($bytes, 12);
    $nameOffset = examine_i32($bytes, 16);
    $exportCount = examine_i32($bytes, 20);
    $exportOffset = examine_i32($bytes, 24);
    $importCount = examine_i32($bytes, 28);
    $importOffset = examine_i32($bytes, 32);
    examine_header_field($rows, $bytes, 12, 4, 'nameCount', 'int32', (string)$nameCount);
    examine_header_field($rows, $bytes, 16, 4, 'nameOffset', 'int32', (string)$nameOffset);
    examine_header_field($rows, $bytes, 20, 4, 'exportCount', 'int32', (string)$exportCount);
    examine_header_field($rows, $bytes, 24, 4, 'exportOffset', 'int32', (string)$exportOffset);
    examine_header_field($rows, $bytes, 28, 4, 'importCount', 'int32', (string)$importCount);
    examine_header_field($rows, $bytes, 32, 4, 'importOffset', 'int32', (string)$importOffset);

    $offset = 36;
    $heritageCount = null;
    $heritageOffset = null;
    $guid = '';
    $generationCount = null;

    if ($version < 68) {
        $heritageCount = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'heritageCount', 'int32', (string)$heritageCount);
        $offset += 4;
        $heritageOffset = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'heritageOffset', 'int32', (string)$heritageOffset);
        $offset += 4;
    }

    if (strlen($bytes) >= $offset + 16) {
        $dwords = [examine_u32($bytes, $offset), examine_u32($bytes, $offset + 4), examine_u32($bytes, $offset + 8), examine_u32($bytes, $offset + 12)];
        $guid = sprintf('%08X-%08X-%08X-%08X', $dwords[0], $dwords[1], $dwords[2], $dwords[3]);
        examine_header_field($rows, $bytes, $offset, 16, 'guid', 'FGuid', $guid);
        $offset += 16;
    }

    if ($version >= 68 && strlen($bytes) >= $offset + 4) {
        $generationCount = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'generationCount', 'int32', (string)$generationCount);
        $offset += 4;
        for ($i = 0; $i < $generationCount && strlen($bytes) >= $offset + 8; $i++) {
            $exportGen = examine_i32($bytes, $offset);
            $nameGen = examine_i32($bytes, $offset + 4);
            examine_header_field($rows, $bytes, $offset, 8, 'generation[' . $i . ']', 'int32,int32', 'exportCount=' . $exportGen . ', nameCount=' . $nameGen);
            $offset += 8;
        }
    }

    $flagsList = examine_pkg_flags($flags);
    $summary = [
        'GUID' => $guid,
        'Signature' => sprintf('0x%08X', $tag),
        'Version' => $version,
        'Licensee Version' => $licensee,
        'Flags' => sprintf('0x%08X', $flags) . ($flagsList ? ' / ' . implode(', ', $flagsList) : ''),
        'Build' => examine_build_label($version),
        'Heritage' => ($heritageCount !== null || $heritageOffset !== null) ? (($heritageCount ?? 0) . ' / ' . ($heritageOffset ?? 0)) : 'n/a',
        'Counts' => 'N ' . $nameCount . ' / I ' . $importCount . ' / E ' . $exportCount,
        'Name Offset' => $nameOffset,
        'Import Offset' => $importOffset,
        'Export Offset' => $exportOffset,
    ];
    if ($generationCount !== null) {
        $summary['Generations'] = $generationCount;
    }

    return ['ok' => true, 'error' => '', 'summary' => $summary, 'rows' => $rows];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = examine_page_int('id', 0, 1, PHP_INT_MAX);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name, g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if ($storageRoot && $storedPath && !str_starts_with($storedPath, $storageRoot)) {
        $storedPath = null;
    }
    $parsedHeader = examine_parse_package_header($storedPath);

    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$id]);
    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$id]);
    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$id]);
    $dependencies = catalog_all($db, 'SELECT d.*, rf.package_name resolved_package, rf.original_name resolved_file, re.full_path resolved_export_path FROM ue_dependencies d LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id LEFT JOIN ue_exports re ON re.id=d.resolved_export_id WHERE d.file_id=? ORDER BY d.id', [$id]);

    $nameLookup = [];
    foreach ($names as $name) {
        $text = strtolower(trim((string)$name['name_text']));
        if ($text !== '' && !isset($nameLookup[$text])) {
            $nameLookup[$text] = (int)$name['name_index'];
        }
    }

    $dependencyByImportId = [];
    foreach ($dependencies as $dep) {
        $dependencyByImportId[(int)$dep['import_id']] = $dep;
    }

    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<div class="card hero"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Database-backed package names, imports, exports and dependency links, with header data parsed from the stored package file.</p><p><a class="button" href="game-files.php?id=' . (int)$file['game_id'] . '">Back to files</a> <a class="button" href="file-info.php?id=' . $id . '">Details</a></p></div>';

    echo '<div class="card"><h2>Package header</h2>';
    if (!$parsedHeader['ok']) {
        echo '<p class="muted">' . catalog_h($parsedHeader['error']) . '</p>';
    }
    echo '<div class="two-col"><table>';
    foreach (['GUID', 'Version', 'Licensee Version', 'Signature', 'Name Offset', 'Import Offset', 'Export Offset'] as $label) {
        if (array_key_exists($label, $parsedHeader['summary'])) {
            echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$parsedHeader['summary'][$label]) . '</td></tr>';
        }
    }
    echo '</table><table>';
    foreach (['Flags', 'Build', 'Heritage', 'Counts', 'Generations'] as $label) {
        if (array_key_exists($label, $parsedHeader['summary'])) {
            echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$parsedHeader['summary'][$label]) . '</td></tr>';
        }
    }
    echo '</table></div></div>';

    if ($parsedHeader['rows']) {
        echo '<div class="card"><h2>Raw header data</h2><table><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw hex</th><th>Note</th></tr>';
        foreach ($parsedHeader['rows'] as $row) {
            echo '<tr><td class="mono">' . (int)$row['offset'] . '</td><td class="mono">' . (int)$row['size'] . '</td><td class="mono">' . catalog_h($row['field']) . '</td><td class="mono">' . catalog_h($row['type']) . '</td><td class="mono path">' . catalog_h($row['value']) . '</td><td class="mono path">' . catalog_h($row['hex']) . '</td><td>' . catalog_h($row['note']) . '</td></tr>';
        }
        echo '</table></div>';
    }

    if (!empty($file['detection_notes']) || !empty($file['scan_notes'])) {
        echo '<div class="card"><h2>Scanner notes</h2>';
        if (!empty($file['detection_notes'])) {
            echo '<h3>Detection</h3><pre class="mono path">' . catalog_h((string)$file['detection_notes']) . '</pre>';
        }
        if (!empty($file['scan_notes'])) {
            echo '<h3>Scan</h3><pre class="mono path">' . catalog_h((string)$file['scan_notes']) . '</pre>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Names</h2><table><tr><th>Index</th><th>Name</th><th>Flags</th></tr>';
    foreach ($names as $name) {
        $nameIndex = (int)$name['name_index'];
        echo '<tr id="name-' . $nameIndex . '"><td class="mono"><a class="xref" href="#name-' . $nameIndex . '">' . $nameIndex . '</a></td><td class="mono path">' . catalog_h($name['name_text']) . '</td><td class="mono">' . catalog_h((string)($name['flags'] ?? '')) . '</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Imports</h2><table><tr><th>Index</th><th>Package ref</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Full path</th><th>Root</th><th>Dependency</th><th>Common</th></tr>';
    foreach ($imports as $imp) {
        $importIndex = (int)$imp['import_index'];
        $packageRef = -($importIndex + 1);
        $dep = $dependencyByImportId[(int)$imp['id']] ?? null;
        $depHtml = '<span class="muted">not built</span>';
        if ($dep) {
            $status = (string)$dep['status'];
            $depHtml = '<span class="dep ' . catalog_h($status) . '">' . catalog_h($status) . '</span>';
            if (!empty($dep['resolved_file_id'])) {
                $label = (string)($dep['resolved_package'] ?: $dep['resolved_file'] ?: ('file #' . (int)$dep['resolved_file_id']));
                $depHtml .= ' ' . examine_file_link((int)$dep['resolved_file_id'], $label);
            }
            if (!empty($dep['resolved_export_id']) && !empty($dep['resolved_export_path'])) {
                $depHtml .= '<br><span class="mono small path">' . catalog_h($dep['resolved_export_path']) . '</span>';
            }
        }

        echo '<tr id="import-' . $importIndex . '">';
        echo '<td class="mono"><a class="xref" href="#import-' . $importIndex . '">' . $importIndex . '</a></td>';
        echo '<td class="mono"><a class="xref" href="#import-' . $importIndex . '">' . $packageRef . '</a></td>';
        echo '<td>' . examine_name_link((string)$imp['class_package'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$imp['class_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$imp['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$imp['outer_index']) . '</td>';
        echo '<td class="mono path">' . catalog_h($imp['full_path']) . '</td>';
        echo '<td class="mono path">' . catalog_h($imp['root_package']) . '</td>';
        echo '<td>' . $depHtml . '</td>';
        echo '<td>' . ((int)$imp['is_common'] ? 'yes' : 'no') . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Exports</h2><table><tr><th>Index</th><th>Package ref</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr>';
    foreach ($exports as $exp) {
        $exportIndex = (int)$exp['export_index'];
        $packageRef = $exportIndex + 1;
        echo '<tr id="export-' . $exportIndex . '">';
        echo '<td class="mono"><a class="xref" href="#export-' . $exportIndex . '">' . $exportIndex . '</a></td>';
        echo '<td class="mono"><a class="xref" href="#export-' . $exportIndex . '">' . $packageRef . '</a></td>';
        echo '<td class="mono path">' . catalog_h($exp['class_name']) . '</td>';
        echo '<td>' . examine_name_link((string)$exp['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$exp['outer_index']) . '</td>';
        echo '<td class="mono path">' . catalog_h($exp['local_path']) . '</td>';
        echo '<td class="mono path">' . catalog_h($exp['full_path']) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['object_flags'] ?? '')) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['serial_size'] ?? '')) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['serial_offset'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Examine error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
