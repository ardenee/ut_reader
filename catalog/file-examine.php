<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function ex_i(string $key, int $default = 0): int
{
    $v = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $v === false || $v === null ? $default : max(0, (int)$v);
}

function ex_u32(string $b, int $o): int { return (int)unpack('V', substr($b, $o, 4))[1]; }
function ex_i32(string $b, int $o): int { $v = ex_u32($b, $o); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
function ex_hex(string $b): string { return strtoupper(trim(chunk_split(bin2hex($b), 2, ' '))); }
function ex_row(array &$rows, string $b, int $o, int $s, string $field, string $type, string $value, string $note = ''): void
{
    $rows[] = ['offset' => $o, 'size' => $s, 'field' => $field, 'type' => $type, 'value' => $value, 'hex' => ex_hex(substr($b, $o, max(0, $s))), 'note' => $note];
}
function ex_i32f(array &$rows, string $b, int &$o, string $field, string $note = ''): int { $s = $o; $v = ex_i32($b, $o); ex_row($rows, $b, $s, 4, $field, 'int32', (string)$v, $note); $o += 4; return $v; }
function ex_u32f(array &$rows, string $b, int &$o, string $field, string $note = ''): int { $s = $o; $v = ex_u32($b, $o); ex_row($rows, $b, $s, 4, $field, 'uint32', (string)$v, $note !== '' ? $note : sprintf('0x%08X', $v)); $o += 4; return $v; }
function ex_guidf(array &$rows, string $b, int &$o, string $field = 'guid'): string
{
    $s = $o; $g = sprintf('%08X-%08X-%08X-%08X', ex_u32($b, $o), ex_u32($b, $o + 4), ex_u32($b, $o + 8), ex_u32($b, $o + 12));
    ex_row($rows, $b, $s, 16, $field, 'FGuid', $g); $o += 16; return $g;
}
function ex_fstringf(array &$rows, string $b, int &$o, string $field): string
{
    $s = $o; $len = ex_i32($b, $o); $o += 4;
    if ($len === 0) { ex_row($rows, $b, $s, 4, $field, 'FString', '', 'length=0'); return ''; }
    if ($len > 0) {
        $raw = substr($b, $o, max(0, min($len, strlen($b) - $o))); $o += $len;
        if ($raw !== '' && substr($raw, -1) === "\0") $raw = substr($raw, 0, -1);
        $v = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252'); $v = $v === false ? $raw : $v;
        ex_row($rows, $b, $s, 4 + strlen($raw) + 1, $field, 'FString', $v, 'length=' . $len); return $v;
    }
    $bytes = (-$len) * 2; $raw = substr($b, $o, max(0, min($bytes, strlen($b) - $o))); $o += $bytes;
    if (substr($raw, -2) === "\0\0") $raw = substr($raw, 0, -2);
    $v = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'); $v = $v === false ? '' : $v;
    ex_row($rows, $b, $s, 4 + strlen($raw) + 2, $field, 'FString', $v, 'wide length=' . $len); return $v;
}

function ex_flags(int $flags): string
{
    $known = [0x1=>'AllowDownload',0x2=>'ClientOptional',0x4=>'ServerSideOnly',0x8=>'NoExportAllowed',0x10=>'Cooked',0x20=>'Encrypted',0x8000=>'Map',0x20000=>'Script',0x40000=>'ContainsMap',0x80000=>'DebugInfo',0x100000=>'Imports',0x200000=>'Compressed',0x400000=>'FullyCompressed'];
    $out = []; foreach ($known as $bit => $name) if (($flags & $bit) !== 0) $out[] = $name;
    return sprintf('0x%08X', $flags) . ($out ? ' / ' . implode(', ', $out) : '');
}

function ex_parse_header(?string $path, array $file): array
{
    if (!$path || !is_file($path)) return ['ok'=>false,'error'=>'Stored package file is not available on disk.','summary'=>[],'rows'=>[]];
    $b = @file_get_contents($path, false, null, 0, 1048576);
    if ($b === false || strlen($b) < 40) return ['ok'=>false,'error'=>'Stored package file is too small to parse header.','summary'=>[],'rows'=>[]];
    $rows = []; $tag = ex_u32($b, 0); ex_row($rows, $b, 0, 4, 'signature', 'uint32', (string)$tag, sprintf('0x%08X', $tag));
    if ($tag !== 0x9E2A83C1) return ['ok'=>false,'error'=>sprintf('Bad package tag 0x%08X', $tag),'summary'=>[],'rows'=>$rows];
    $signed = ex_i32($b, 4); $ext = strtolower((string)($file['extension'] ?? ''));
    if ($signed < 0 || in_array($ext, ['uasset','umap'], true)) return ex_parse_ue4_header($b, $file);
    return ex_parse_legacy_header($b);
}

function ex_parse_legacy_header(string $b): array
{
    $rows = []; $o = 0; $tag = ex_u32f($rows, $b, $o, 'signature');
    $packed = ex_u32($b, 4); $version = $packed & 0xFFFF; $licensee = ($packed >> 16) & 0xFFFF;
    ex_row($rows, $b, 4, 4, 'packedVersionLicensee', 'uint32', 'packed=' . $packed . ', version=' . $version . ', licensee=' . $licensee); $o = 8;
    $flags = ex_u32f($rows, $b, $o, 'pkgFlags');
    $nameCount = ex_i32f($rows, $b, $o, 'nameCount'); $nameOffset = ex_i32f($rows, $b, $o, 'nameOffset');
    $exportCount = ex_i32f($rows, $b, $o, 'exportCount'); $exportOffset = ex_i32f($rows, $b, $o, 'exportOffset');
    $importCount = ex_i32f($rows, $b, $o, 'importCount'); $importOffset = ex_i32f($rows, $b, $o, 'importOffset');
    $heritage = 'n/a'; if ($version < 68) { $hc = ex_i32f($rows, $b, $o, 'heritageCount'); $ho = ex_i32f($rows, $b, $o, 'heritageOffset'); $heritage = $hc . ' / ' . $ho; }
    $guid = strlen($b) >= $o + 16 ? ex_guidf($rows, $b, $o) : '';
    $gens = null; if ($version >= 68 && strlen($b) >= $o + 4) { $gens = ex_i32f($rows, $b, $o, 'generationCount'); }
    $build = $version >= 500 ? 'UE3' : ($version >= 100 ? 'UE2' : ($version > 0 ? 'Unreal1' : 'unknown'));
    return ['ok'=>true,'error'=>'','summary'=>['GUID'=>$guid,'Version'=>$version,'Licensee Version'=>$licensee,'Signature'=>sprintf('0x%08X',$tag),'Name Offset'=>$nameOffset,'Import Offset'=>$importOffset,'Export Offset'=>$exportOffset,'Flags'=>ex_flags($flags),'Build'=>$build,'Heritage'=>$heritage,'Counts'=>'N '.$nameCount.' / I '.$importCount.' / E '.$exportCount,'Generations'=>$gens ?? 'n/a'],'rows'=>$rows];
}

function ex_parse_ue4_header(string $b, array $file): array
{
    $rows = []; $o = 0; $tag = ex_u32f($rows, $b, $o, 'signature');
    $legacy = ex_i32f($rows, $b, $o, 'legacyFileVersion', 'signed 32-bit UE4 marker');
    $legacyUE3 = null; if ($legacy !== -4) $legacyUE3 = ex_i32f($rows, $b, $o, 'legacyUE3Version');
    $rawVersion = ex_i32f($rows, $b, $o, 'fileVersionUE4'); $licensee = ex_i32f($rows, $b, $o, 'fileVersionLicenseeUE4');
    if ($legacy <= -2) {
        $cc = ex_i32f($rows, $b, $o, 'customVersionCount'); $cc = max(0, min($cc, 4096));
        for ($i = 0; $i < $cc; $i++) {
            if ($legacy === -2) { ex_i32f($rows, $b, $o, 'customVersion['.$i.'].key'); ex_i32f($rows, $b, $o, 'customVersion['.$i.'].version'); }
            elseif ($legacy >= -5) { ex_guidf($rows, $b, $o, 'customVersion['.$i.'].guid'); ex_i32f($rows, $b, $o, 'customVersion['.$i.'].version'); ex_fstringf($rows, $b, $o, 'customVersion['.$i.'].friendlyName'); }
            else { ex_guidf($rows, $b, $o, 'customVersion['.$i.'].guid'); ex_i32f($rows, $b, $o, 'customVersion['.$i.'].version'); }
        }
    }
    $unversioned = $rawVersion === 0 && $licensee === 0; $v = $unversioned ? 511 : $rawVersion;
    $totalHeader = ex_i32f($rows, $b, $o, 'totalHeaderSize'); $folder = ex_fstringf($rows, $b, $o, 'folderName'); $flags = ex_u32f($rows, $b, $o, 'packageFlags');
    $nameCount = ex_i32f($rows, $b, $o, 'nameCount'); $nameOffset = ex_i32f($rows, $b, $o, 'nameOffset');
    if ($v >= 459) { ex_i32f($rows, $b, $o, 'gatherableTextDataCount'); ex_i32f($rows, $b, $o, 'gatherableTextDataOffset'); }
    $exportCount = ex_i32f($rows, $b, $o, 'exportCount'); $exportOffset = ex_i32f($rows, $b, $o, 'exportOffset');
    $importCount = ex_i32f($rows, $b, $o, 'importCount'); $importOffset = ex_i32f($rows, $b, $o, 'importOffset'); ex_i32f($rows, $b, $o, 'dependsOffset');
    if ($v >= 384) { ex_i32f($rows, $b, $o, 'stringAssetReferencesCount'); ex_i32f($rows, $b, $o, 'stringAssetReferencesOffset'); }
    if ($v >= 510) ex_i32f($rows, $b, $o, 'searchableNamesOffset');
    ex_i32f($rows, $b, $o, 'thumbnailTableOffset'); $guid = ex_guidf($rows, $b, $o); $gens = ex_i32f($rows, $b, $o, 'generationCount');
    $counts = 'N '.$nameCount.' / I '.$importCount.' / E '.$exportCount; $dbCounts = 'N '.(int)($file['name_count'] ?? 0).' / I '.(int)($file['import_count'] ?? 0).' / E '.(int)($file['export_count'] ?? 0);
    $summary = ['GUID'=>$guid,'Version'=>$unversioned ? '511 assumed (unversioned UE4; raw 0)' : $rawVersion,'Licensee Version'=>$licensee,'Signature'=>sprintf('0x%08X',$tag),'Name Offset'=>$nameOffset,'Import Offset'=>$importOffset,'Export Offset'=>$exportOffset,'Flags'=>ex_flags($flags),'Build'=>'UE4','Heritage'=>$legacyUE3 === null ? 'n/a' : ('legacyUE3Version='.$legacyUE3),'Counts'=>$counts,'Generations'=>$gens,'Total Header Size'=>$totalHeader,'Folder Name'=>$folder !== '' ? $folder : 'n/a'];
    if ($counts !== $dbCounts) $summary['Catalog Counts'] = $dbCounts;
    return ['ok'=>true,'error'=>'','summary'=>$summary,'rows'=>$rows];
}

function ex_back(int $gameId): string
{
    $candidate = trim((string)($_GET['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    $parts = $candidate !== '' ? parse_url($candidate) : false;
    if (is_array($parts) && basename((string)($parts['path'] ?? '')) === 'game-files.php') {
        parse_str((string)($parts['query'] ?? ''), $p);
        if ((int)($p['id'] ?? 0) === $gameId) return 'game-files.php?' . http_build_query(array_intersect_key($p, array_flip(['id','file_filter','dep_filter','type_filter','compression_filter','sort','dir','file_page'])));
    }
    return 'game-files.php?id=' . $gameId;
}
function ex_ref(int $ref): string { if ($ref === 0) return '<span class="muted">none</span>'; $id = $ref < 0 ? 'import-' . ((-$ref) - 1) : 'export-' . ($ref - 1); return '<a class="xref mono" href="#'.$id.'">'.$ref.'</a>'; }
function ex_key(string $v): string { return strtolower(trim($v)); }
function ex_add_target(array &$targets, string $value, string $id): void { $k = ex_key($value); if ($k !== '') $targets[$k][] = $id; }
function ex_link(string $v, array $lookup, array $targets): string
{
    $t = trim($v); if ($t === '') return '<span class="muted">none</span>';
    $k = ex_key($t);
    if (isset($lookup[$k])) return '<a class="xref mono path" href="#name-'.(int)$lookup[$k].'" title="Open name table entry">'.catalog_h($t).'</a>';
    if (isset($targets[$k])) { $target = $targets[$k][0]; return '<a class="xref mono path" href="#'.catalog_h($target).'" title="Open referenced import/export row">'.catalog_h($t).'</a>'; }
    return '<span class="mono path">'.catalog_h($t).'</span>';
}
function ex_usage_links(array $usage): string
{
    $links = [];
    if (!empty($usage['imports'])) {
        $targets = array_values(array_unique($usage['imports']));
        $links[] = '<a class="xref" href="#'.catalog_h($targets[0]).'">Imports: '.count($targets).'</a>';
    }
    if (!empty($usage['exports'])) {
        $targets = array_values(array_unique($usage['exports']));
        $links[] = '<a class="xref" href="#'.catalog_h($targets[0]).'">Exports: '.count($targets).'</a>';
    }
    return $links ? implode(' <span class="muted">·</span> ', $links) : '<span class="muted">none</span>';
}
function ex_dep(?array $d, string $back): string { if (!$d) return '<span class="muted">not built</span>'; $s = (string)$d['status']; return '<span class="dep '.catalog_h($s).'">'.catalog_h($s).'</span>'; }
function ex_name_flags($value): string
{
    if ($value === null || $value === '') return '<span class="muted">n/a</span>';
    $int = (int)$value;
    if ($int > 65535) return sprintf('0x%04X / 0x%04X', ($int >> 16) & 0xFFFF, $int & 0xFFFF);
    return (string)$int;
}

try {
    $config = catalog_config(); $db = catalog_db($config); $id = ex_i('id');
    $file = catalog_one($db, 'SELECT f.*, g.name game_name, g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) throw new RuntimeException('File not found');
    $back = ex_back((int)$file['game_id']);
    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR)); $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if ($storageRoot && $storedPath && !str_starts_with($storedPath, $storageRoot)) $storedPath = null;
    $header = ex_parse_header($storedPath, $file);
    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$id]);
    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$id]);
    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$id]);
    $deps = catalog_all($db, 'SELECT d.* FROM ue_dependencies d WHERE d.file_id=? ORDER BY d.id', [$id]); $depByImport = []; foreach ($deps as $d) $depByImport[(int)$d['import_id']] = $d;
    $lookup = []; $usage = []; $targets = [];
    foreach ($names as $n) { $txt = (string)$n['name_text']; if ($txt !== '') $lookup[ex_key($txt)] = (int)$n['name_index']; }
    foreach ($imports as $im) {
        $importId = 'import-'.(int)$im['import_index'];
        foreach (['full_path','root_package'] as $f) ex_add_target($targets, (string)$im[$f], $importId);
        foreach (['class_package','class_name','object_name'] as $f) if ((string)$im[$f] !== '') $usage[ex_key((string)$im[$f])]['imports'][] = $importId;
    }
    foreach ($exports as $ex) {
        $exportId = 'export-'.(int)$ex['export_index'];
        foreach (['full_path','local_path','class_name','object_name'] as $f) ex_add_target($targets, (string)$ex[$f], $exportId);
        foreach (['class_name','object_name'] as $f) if ((string)$ex[$f] !== '') $usage[ex_key((string)$ex[$f])]['exports'][] = $exportId;
    }

    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<style>.examine-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}.examine-tab{display:inline-flex;gap:6px;min-height:34px;padding:6px 10px;border:1px solid var(--line2);border-radius:9px;color:var(--text);background:rgba(255,255,255,.035);font-weight:650;text-decoration:none}.examine-tab.is-active{color:#07111f;background:linear-gradient(180deg,#9dc2ff,#76a9ff);border-color:#a9c9ff}.examine-tab-panel[hidden]{display:none}.examine-table-region{overflow-x:auto;border:1px solid var(--line);border-radius:12px}.examine-imports-table{min-width:1420px}.examine-exports-table{min-width:1320px}.is-reference-target td{background:rgba(246,196,83,.18)!important}.path{white-space:normal}.to-top{position:fixed;right:20px;bottom:20px;width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:rgba(16,24,39,.94);border:1px solid var(--line2);font-size:22px}</style>';
    echo '<div class="card hero" id="top"><h1>Examine '.catalog_h($file['package_name']).'</h1><p class="muted">Database-backed package names, imports, exports and dependency links, with header data parsed from the stored package file.</p><p><a class="button" href="'.catalog_h($back).'">Back to files</a> <a class="button" href="file-info.php?id='.$id.'">Details</a></p></div>';
    echo '<div class="card"><h2>Package header</h2>'; if (!$header['ok']) echo '<p class="muted">'.catalog_h($header['error']).'</p>'; echo '<div class="two-col"><table>';
    foreach (['GUID','Version','Licensee Version','Signature','Name Offset','Import Offset','Export Offset','Total Header Size'] as $l) if (array_key_exists($l,$header['summary'])) echo '<tr><th>'.catalog_h($l).'</th><td class="mono path">'.catalog_h((string)$header['summary'][$l]).'</td></tr>';
    echo '</table><table>'; foreach (['Flags','Build','Heritage','Counts','Catalog Counts','Generations','Folder Name'] as $l) if (array_key_exists($l,$header['summary'])) echo '<tr><th>'.catalog_h($l).'</th><td class="mono path">'.catalog_h((string)$header['summary'][$l]).'</td></tr>'; echo '</table></div></div>';
    if ($header['rows']) { echo '<div class="card"><h2>Raw header data</h2><div class="examine-table-region"><table><thead><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw hex</th><th>Note</th></tr></thead><tbody>'; foreach ($header['rows'] as $r) echo '<tr><td class="mono">'.(int)$r['offset'].'</td><td class="mono">'.(int)$r['size'].'</td><td class="mono">'.catalog_h($r['field']).'</td><td class="mono">'.catalog_h($r['type']).'</td><td class="mono path">'.catalog_h($r['value']).'</td><td class="mono path">'.catalog_h($r['hex']).'</td><td>'.catalog_h($r['note']).'</td></tr>'; echo '</tbody></table></div></div>'; }
    if (!empty($file['detection_notes']) || !empty($file['scan_notes'])) { echo '<div class="card"><h2>Scanner notes</h2>'; if (!empty($file['detection_notes'])) echo '<h3>Detection</h3><pre class="mono path">'.catalog_h((string)$file['detection_notes']).'</pre>'; if (!empty($file['scan_notes'])) echo '<h3>Scan</h3><pre class="mono path">'.catalog_h((string)$file['scan_notes']).'</pre>'; echo '</div>'; }
    echo '<div class="card" id="package-tables"><nav class="examine-tabs"><a class="examine-tab is-active" href="#tab-names" data-tab="names">Names <span>'.count($names).'</span></a><a class="examine-tab" href="#tab-imports" data-tab="imports">Imports <span>'.count($imports).'</span></a><a class="examine-tab" href="#tab-exports" data-tab="exports">Exports <span>'.count($exports).'</span></a></nav>';
    echo '<section id="tab-names" data-panel="names" class="examine-tab-panel"><h2>Names</h2><div class="examine-table-region"><table><thead><tr><th>Index</th><th>Name</th><th>Used by</th><th>Flags / hashes</th></tr></thead><tbody>';
    foreach ($names as $n) { $idx=(int)$n['name_index']; $txt=(string)$n['name_text']; $u=$usage[ex_key($txt)]??[]; echo '<tr id="name-'.$idx.'"><td class="mono"><a class="xref" href="#name-'.$idx.'">'.$idx.'</a></td><td><span class="mono path">'.catalog_h($txt).'</span></td><td>'.ex_usage_links($u).'</td><td class="mono">'.ex_name_flags($n['flags'] ?? null).'</td></tr>'; }
    echo '</tbody></table></div></section>';
    echo '<section id="tab-imports" data-panel="imports" class="examine-tab-panel" hidden><h2>Imports</h2><p class="muted">Object references: 0 = null; &lt; 0 = import; &gt; 0 = export.</p><div class="examine-table-region"><table class="examine-imports-table"><thead><tr><th>Index</th><th>Package ref</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Full path</th><th>Root</th><th>Dependency</th></tr></thead><tbody>';
    foreach ($imports as $im) { $idx=(int)$im['import_index']; echo '<tr id="import-'.$idx.'"><td class="mono"><a class="xref" href="#import-'.$idx.'">'.$idx.'</a></td><td class="mono">'.(-($idx+1)).'</td><td>'.ex_link((string)$im['class_package'],$lookup,$targets).'</td><td>'.ex_link((string)$im['class_name'],$lookup,$targets).'</td><td>'.ex_link((string)$im['object_name'],$lookup,$targets).'</td><td>'.ex_ref((int)$im['outer_index']).'</td><td>'.ex_link((string)$im['full_path'],$lookup,$targets).'</td><td>'.ex_link((string)$im['root_package'],$lookup,$targets).'</td><td>'.ex_dep($depByImport[(int)$im['id']]??null,$back).'</td></tr>'; }
    echo '</tbody></table></div></section>';
    echo '<section id="tab-exports" data-panel="exports" class="examine-tab-panel" hidden><h2>Exports</h2><p class="muted">Object references: 0 = null; &lt; 0 = import; &gt; 0 = export.</p><div class="examine-table-region"><table class="examine-exports-table"><thead><tr><th>Index</th><th>Package ref</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr></thead><tbody>';
    foreach ($exports as $ex) { $idx=(int)$ex['export_index']; echo '<tr id="export-'.$idx.'"><td class="mono"><a class="xref" href="#export-'.$idx.'">'.$idx.'</a></td><td class="mono">'.($idx+1).'</td><td>'.ex_link((string)$ex['class_name'],$lookup,$targets).'</td><td>'.ex_link((string)$ex['object_name'],$lookup,$targets).'</td><td>'.ex_ref((int)$ex['outer_index']).'</td><td>'.ex_link((string)$ex['local_path'],$lookup,$targets).'</td><td>'.ex_link((string)$ex['full_path'],$lookup,$targets).'</td><td class="mono">'.catalog_h((string)($ex['object_flags']??'')).'</td><td class="mono">'.catalog_h((string)($ex['serial_size']??'')).'</td><td class="mono">'.catalog_h((string)($ex['serial_offset']??'')).'</td></tr>'; }
    echo '</tbody></table></div></section></div><a class="to-top" href="#top">↑</a>';
    echo '<script>(()=>{const tabs=[...document.querySelectorAll("[data-tab]")],panels=[...document.querySelectorAll("[data-panel]")];function show(t){panels.forEach(p=>p.hidden=p.dataset.panel!==t);tabs.forEach(a=>a.classList.toggle("is-active",a.dataset.tab===t));}tabs.forEach(a=>a.onclick=e=>{e.preventDefault();location.hash="tab-"+a.dataset.tab;show(a.dataset.tab);});function hash(){document.querySelectorAll(".is-reference-target").forEach(e=>e.classList.remove("is-reference-target"));let h=decodeURIComponent(location.hash.slice(1));if(h.startsWith("tab-")){show(h.slice(4));return}let el=h&&document.getElementById(h);if(el){let p=el.closest("[data-panel]");if(p)show(p.dataset.panel);el.classList.add("is-reference-target");el.scrollIntoView({block:"center"});}else show("names");}addEventListener("hashchange",hash);hash();})();</script>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) catalog_head('Examine error');
    echo '<div class="card"><h1>Error</h1><p>'.catalog_h($e->getMessage()).'</p></div>';
    catalog_foot();
}
