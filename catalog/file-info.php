<?php
/** Catalog file information and dependency diagnostics. */
declare(strict_types=1);
// File details must not retain a pre-deployment HTML response in browser caches.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__.'/lib/CatalogSupport.php';
require_once __DIR__.'/lib/CatalogDependencySchema.php';
require_once __DIR__.'/lib/CatalogCompactDependencies.php';
require_once __DIR__.'/lib/CatalogFileFeedback.php';
require_once __DIR__.'/lib/CatalogDependencyDiagnostics.php';

function file_info_type_from_extension(string $ext):array{$ext=strtolower(trim($ext,'. '));return match($ext){'unr','un2','ut2','ut3','umap'=>['map','type-map'],'umx'=>['music','type-music'],'uax'=>['sound','type-sound'],'utx'=>['texture','type-texture'],'usx'=>['static mesh','type-static-mesh'],'ukx'=>['animation','type-animation'],'upx'=>['particle/effect','type-particle-effect'],'ugx'=>['gui','type-gui'],'con'=>['content','type-content'],'u','upk','uasset'=>['package','type-package'],default=>[$ext!==''?$ext:'unknown','type-unknown']};}
function file_info_imports_table(array $imports, array $dependencyByIndex, int $fileId, int $gameId, array $packageFiles): string {
    if ($imports === []) return '<p class="muted">No import entries are present in this portion of the file.</p>';
    $html = '<div class="file-imports-scroll"><table class="file-imports-table" data-sortable-table><thead><tr>'
          . '<th>Import</th><th>Package</th><th>Object / class</th><th>Outer</th><th>External file / export</th>'
          . '</tr></thead><tbody>';
    foreach ($imports as $row) {
        $index = (int)$row['import_index'];
        $link = 'file-examine.php?id='.$fileId.'&target=import-'.$index;
        $import = '<a href="'.$link.'">#'.$index.'</a>';
        $dep = $dependencyByIndex[$index] ?? [];
        $package = trim((string)($row['root_package'] ?? ''));
        if ($package === '') $package = trim((string)($dep['required_package'] ?? ''));
        $packageLink = \UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery::packageReferenceHtml($package, $gameId, $packageFiles[strtolower($package)] ?? []);
        $classPackage = trim((string)($row['class_package'] ?? ''));
        $className = trim((string)($row['class_name'] ?? ''));
        $class = implode('.', array_filter([$classPackage,$className],static fn($v)=>$v!==''));
        $object = trim((string)($row['full_path'] ?? ''));
        if ($object === '') $object = trim((string)($row['object_name'] ?? ''));
        $objectHtml = '<strong class="mono path">'.catalog_h($object !== '' ? $object : '—').'</strong>';
        if ($class !== '') $objectHtml .= ' <span class="muted">/</span> <span class="mono">'.catalog_h($class).'</span>';
        $outer = (int)($row['outer_index'] ?? 0);
        $outerHtml = '—';
        if ($outer !== 0) {
            $outerTarget = $outer < 0 ? 'import-'.(-$outer-1) : 'export-'.($outer-1);
            $outerHtml = '<a href="file-examine.php?id='.$fileId.'&target='.$outerTarget.'">'.$outer.'</a>';
        }
        $outcome = (string)($dep['status'] ?? '');
        $providerId = in_array($outcome,['resolved','package_only'],true) ? (int)($dep['resolved_id'] ?? 0) : 0;
        $providerHtml = '—';
        if ($providerId > 0 && $providerId !== $fileId) {
            $name = trim((string)($dep['resolved_package'] ?? ''));
            if ($name === '') $name = trim((string)($dep['resolved_file'] ?? ''));
            $providerHtml = '<a href="file-info.php?id='.$providerId.'">'.catalog_h($name).'</a>';
            if ($outcome === 'resolved' && ($dep['resolved_export_index'] ?? null) !== null) {
                $export = (int)$dep['resolved_export_index'];
                $providerHtml .= '<span class="dep-detail"><a href="file-examine.php?id='.$providerId.'&target=export-'.$export.'">Export #'.$export.'</a></span>';
            }
        }
        $html .= '<tr><td class="mono">'.$import.'</td><td class="mono path">'.catalog_h($package !== '' ? $package : '—').'</td>'
            . '<td>'.$objectHtml.'</td><td class="mono">'.$outerHtml.'</td><td>'.$providerHtml.'</td></tr>';
    }
    return $html.'</tbody></table></div>';
}
function file_info_related_files_table(array $rows, string $empty): string {
    if ($rows === []) return '<p class="muted">'.catalog_h($empty).'</p>';
    $html = '<table data-sortable-table><thead><tr><th>Package</th><th>File</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) continue;
        $url = 'file-info.php?id='.$id;
        $html .= '<tr><td class="mono"><a href="'.$url.'">'.catalog_h((string)($row['package_name'] ?? '')).'</a></td>'
            . '<td><a href="file-examine.php?id='.$id.'">'.catalog_h((string)($row['original_name'] ?? '')).'</a></td></tr>';
    }
    return $html.'</tbody></table>';
}


/**
 * Same visual hierarchy as file-examine.php: hero, canonical catalogue
 * metadata and bounded source-table navigation. Source identities are not
 * reinterpreted or manufactured by the summary page.
 *
 * @param array<string,mixed> $file
 * @param array<string,mixed> $zen
 */
function file_info_identity_card(array $file, array $zen = []): string
{
    $id=(int)$file['id'];
    $isZen=strtolower((string)($file['extension']??''))==='zenpkg';
    $gameHref='game-files.php?id='.(int)$file['game_id'];
    $examineHref='file-examine.php?id='.$id;
    $fields=[
        'Game (catalogue)' => '<a href="'.catalog_h($gameHref).'">'
            .catalog_h((string)$file['game_name']).'</a>',
        'Package path (decoded)' => '<a href="'.catalog_h($examineHref).'">'
            .catalog_h((string)$file['package_name']).'</a>',
    ];
    if ($isZen) {
        $containerId=(int)($zen['container_id']??0);
        $fields['Format (reader classification)']='UE5 Zen / IoStore';
        $fields['FPackageId (IoStore index)']=catalog_h((string)($zen['package_id']??'Not indexed'));
        $fields['Stored member filename (UnrealDB)']=catalog_h((string)$file['original_name']);
        $fields['Source IoStore file']=$containerId>0
            ? '<a href="iostore-info.php?id='.$containerId.'">'
                .catalog_h((string)($zen['container_name']??'')).'</a>'
            : '<span class="muted">Not recorded</span>';
    } else {
        $fields['File (catalogue)']=catalog_h((string)$file['original_name']);
        $fields['GUID (catalogue)']=catalog_h((string)($file['package_guid']?:'-'));
        $fields['Version (catalogue)']=catalog_h((string)($file['package_version']??'-'));
        $fields['Licensee version (catalogue)']=catalog_h((string)($file['licensee_version']??'-'));
    }
    $html='<div class="card file-info-identity"><h2>Catalogue metadata &amp; package identity</h2>'
        .'<p class="muted">The game, filename, size, status and table counts are catalogue records. '
        .($isZen
            ? 'FPackageId comes from the IoStore chunk index, not the Zen summary; .zenpkg is an internal storage filename.'
            : 'GUID and package version are indexed source-header metadata.')
        .'</p><div class="two-col"><table><tbody>';
    foreach ($fields as $label=>$value) {
        $html.='<tr><th>'.catalog_h((string)$label).'</th><td class="mono path">'.$value.'</td></tr>';
    }
    $html.='</tbody></table><table><tbody>';
    foreach([
        'Names' => (int)$file['name_count'],
        'Imports' => (int)$file['import_count'],
        'Exports' => (int)$file['export_count'],
        ($isZen ? 'Reconstructed member bytes' : 'File size')
            => catalog_bytes((int)$file['file_size']),
        'Scan status (catalogue)' => (string)$file['scan_status'],
    ] as $label=>$value){
        $html.='<tr><th>'.catalog_h((string)$label).'</th><td class="mono path">'
            .catalog_h((string)$value).'</td></tr>';
    }
    if (!$isZen) {
        $html.='<tr><th>MD5</th><td class="mono path">'
            .catalog_h((string)($file['md5']??'-')).'</td></tr>';
        $html.='<tr><th>SHA1</th><td class="mono path">'
            .catalog_h((string)($file['sha1']??'-')).'</td></tr>';
        $html.='<tr><th>Compression</th><td class="mono path">'
            .((int)($file['is_compressed']??0)===1?'compressed':'uncompressed')
            .' (flags 0x'
            .strtoupper(str_pad(dechex((int)($file['compression_flags']??0)),8,'0',STR_PAD_LEFT))
            .')</td></tr>';
    }
    return $html.'</tbody></table></div></div>';
}

try{
$config=catalog_config();
$db=catalog_db($config);
$id=(int)($_GET['id']??0);
$file=catalog_one($db,'SELECT f.*,g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',[$id]);
if(!$file)throw new RuntimeException('File not found');

// Failed/invalid packages have no trustworthy source tables or UEDB5
// snapshot to decode. Show the original catalogue scan diagnosis directly,
// without invoking the classic package-table reader or dependencies.
if ((string)($file['scan_status'] ?? '') === 'failed') {
    catalog_start_session();
    $failedIsAdmin = catalog_support_is_admin();
    $recheck = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$failedIsAdmin) {
            http_response_code(403);
            echo 'Administrator access required.';
            exit;
        }
        try {
            catalog_check_csrf('failed-file-recheck');
        } catch (Throwable $error) {
            http_response_code(403);
            echo 'Invalid request token.';
            exit;
        }
        if ((string)($_POST['operation'] ?? '') !== 'recheck_failed') {
            http_response_code(400);
            echo 'Unsupported failed-file action.';
            exit;
        }
        try {
            $db->exec('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            $recheck=(new \UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFailedFileRecheckService(
                $db,$config
            ))->recheck($id);
        } catch (Throwable $error) {
            $recheck=[
                'ok'=>false,'source_available'=>false,'identity_ok'=>false,
                'message'=>'Revalidation could not complete: '.$error->getMessage(),
                'details'=>[],
            ];
        } finally {
            if ($db->inTransaction()) $db->rollBack();
        }
    }
    $recheckCsrf = $failedIsAdmin ? catalog_csrf('failed-file-recheck') : '';
    $removeCsrf = $failedIsAdmin ? catalog_csrf('catalog-maintenance') : '';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    catalog_head('Failed file #' . $id);
    catalog_page_header(
        'Failed file #' . $id,
        'This catalogue record exists, but it failed format validation. The names, imports and exports of an invalid package must not be invented.',
        ['Dashboard failed files'=>'dashboard-detail.php?metric=failed',
         'Game files'=>'game-files.php?id=' . (int)$file['game_id']]
    );
    echo '<div class="card" id="scan-status"><h2>Original scan result: failed</h2>'
        . '<p>The record is valid catalogue data, but the source file was not accepted as a valid Unreal package. '
        . 'A normal package examination is unavailable.</p><table><tbody>';
    foreach ([
        'File ID' => '#' . $id,
        'Game' => (string)$file['game_name'],
        'Original filename' => (string)$file['original_name'],
        'Package name' => (string)$file['package_name'],
        'Extension' => (string)($file['extension'] ?? ''),
        'Scan status' => (string)$file['scan_status'],
        'Scan diagnostic' => (string)($file['scan_notes'] ?? ''),
        'Detection notes' => (string)($file['detection_notes'] ?? ''),
        'Metadata error' => (string)($file['metadata_error'] ?? ''),
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">'
            . catalog_h($value !== '' ? $value : 'Not recorded') . '</td></tr>';
    }
    echo '</tbody></table><p><a href="dashboard-detail.php?metric=failed">Back to failed files</a></p></div>';
    if ($failedIsAdmin) {
        echo '<div class="card" id="failed-maintenance"><h2>Failed-file maintenance</h2>'
            . '<p>Revalidate executes the current game-specific package reader against '
            . 'the original stored bytes, checking the recorded size, MD5 and SHA-1 first. '
            . 'It is read-only: the failed status and known-invalid byte fingerprint are preserved. '
            . 'Only remove if this invalid source should no longer be retained.</p>';
        if (is_array($recheck)) {
            echo '<div class="ui-alert" role="status"><strong>'
                . ($recheck['ok'] ? 'Revalidation accepted' : 'Revalidation not accepted')
                . '</strong><p>' . catalog_h((string)$recheck['message']) . '</p>';
            foreach ((array)($recheck['details']??[]) as $label=>$value) {
                echo '<p><strong>' . catalog_h((string)$label) . ':</strong> '
                    . catalog_h((string)$value) . '</p>';
            }
            echo '</div>';
        }
        echo '<div class="page-links">'
            . '<form method="post" action="file-info.php?id=' . $id . '#failed-maintenance">'
            . '<input type="hidden" name="csrf" value="' . catalog_h($recheckCsrf) . '">'
            . '<input type="hidden" name="operation" value="recheck_failed">'
            . '<button type="submit">Revalidate stored bytes</button></form>'
            . '<form method="post" action="file-maintenance.php">'
            . '<input type="hidden" name="csrf" value="' . catalog_h($removeCsrf) . '">'
            . '<input type="hidden" name="operation" value="remove_failed">'
            . '<input type="hidden" name="file_id" value="' . $id . '">'
            . '<label>Type <strong>DELETE</strong> to remove '
            . 'the stored source and catalogue record: '
            . '<input name="confirm_phrase" autocomplete="off" required pattern="DELETE"'
            . ' aria-label="Type DELETE to confirm permanent removal"></label> '
            . '<button class="danger" type="submit">Remove failed file + stored bytes</button>'
            . '</form></div><p class="muted">'
            . 'Removing a failed file does not make its original bytes valid. '
            . 'Re-uploading identical invalid bytes is still blocked by the known-invalid identity.'
            . '</p></div>';
    }
    catalog_foot();
    return;
}
catalog_dependency_schema_ensure($db);
$isZen=strtolower((string)($file['extension']??''))==='zenpkg';
$dependencyProjectionError=null;
$deps=[];$dependencyByIndex=[];$importPage=['rows'=>[]];$packageFiles=[];
if(!$isZen){
    // Classic packages retain the original source-backed, class/outer-aware
    // dependency renderer. Zen uses SHA-bound SQL source indexes instead:
    // browsing must not reopen tens of thousands of preserved .uedb5 files.
    try{
        $deps=catalog_dependency_rows($db,$config,$id);
    }catch(RuntimeException $error){
        if(!str_contains($error->getMessage(),'Dependency publication is stale'))throw $error;
        $dependencyProjectionError='Dependency links are not verified under the current Epic engine policy; regeneration is required.';
    }
    foreach($deps as $dep){
        $index=(int)($dep['import_index']??-1);
        if($index>=0)$dependencyByIndex[$index]=$dep;
    }
    $importPage=\UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery::fetchPage($db,$file,'imports',1,250);
    $rootNames=array_values(array_unique(array_filter(array_map(
        static fn(array $r):string=>trim((string)($r['root_package']??'')),$importPage['rows']
    ))));
    $packageFiles=\UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery::packageFiles($db,(int)$file['game_id'],$rootNames);
}
catalog_head('Package details — ' . (string)$file['package_name']);
echo <<<'CSS'
<style>
.file-imports-scroll{overflow-x:auto}
.file-imports-table{width:100%}
.file-imports-table th:nth-child(1){width:9%}
.file-imports-table th:nth-child(2){width:22%}
.file-imports-table th:nth-child(3){width:32%}
.file-imports-table th:nth-child(4){width:9%}
.file-imports-table th:nth-child(5){width:28%}
.file-relations-map{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}
.file-map-node{flex:1;min-width:160px;display:flex;flex-direction:column;gap:8px;border:1px solid var(--line2);padding:14px;border-radius:10px}
.file-map-node strong{overflow-wrap:anywhere}
.file-map-current{border-color:var(--blue)}
.file-map-arrow{font-size:24px}
.file-info-identity table th{text-align:left;white-space:nowrap}
.file-info-identity table td{overflow-wrap:anywhere}
.file-info-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}
.file-info-tabs a{display:inline-flex;gap:6px;align-items:center;min-height:34px;padding:6px 10px;border:1px solid var(--line2);border-radius:9px;color:var(--text);background:rgba(255,255,255,.035);font-weight:650;text-decoration:none}
.file-info-tabs a:hover{border-color:var(--blue);color:var(--blue)}
</style>
CSS;
$gameHref='game-files.php?id='.(int)$file['game_id'];
$examineHref='file-examine.php?id='.$id;
echo '<div class="card hero" id="top"><h1>Package details — '
    .catalog_h((string)$file['package_name']).'</h1>'
    .'<p class="muted">Catalogue identity, source package tables and dependency relationships for '
    .catalog_h((string)$file['original_name']).'.</p>'
    .'<p><a class="button" href="'.catalog_h($gameHref).'">Back to files</a> '
    .'<a class="button" href="'.catalog_h($examineHref).'">Examine tables</a> '
    .'<a class="button" href="download-info.php?id='.$id.'">Download options</a> '
    .'<a class="button" href="package-coverage.php?file_id='.$id.'">Send to Package Coverage</a></p></div>';
$isZen = strtolower((string)($file['extension'] ?? '')) === 'zenpkg';
echo catalog_file_feedback_form_html($id);
$zen=[];
if ($isZen) {
    $zen=catalog_one($db,
        'SELECT HEX(p.package_id) AS package_id,p.container_id,c.name container_name '
        . 'FROM ue_iostore_packages p JOIN ue_iostore_containers c ON c.id=p.container_id '
        . 'WHERE p.file_id=? AND p.chunk_index=0 ORDER BY p.id LIMIT 1', [$id]) ?: [];
}
echo file_info_identity_card($file,$zen);
echo '<div class="card"><h2>Package tables</h2><nav class="file-info-tabs" aria-label="Package tables">'
    . '<a href="file-examine.php?id='.$id.'&amp;tab=names">Names <span>'.(int)$file['name_count'].'</span></a>'
    . '<a href="file-examine.php?id='.$id.'&amp;tab=imports">Imports <span>'.(int)$file['import_count'].'</span></a>'
    . '<a href="file-examine.php?id='.$id.'&amp;tab=exports">Exports <span>'.(int)$file['export_count'].'</span></a>'
    . '<a href="file-examine.php?id='.$id.'&amp;tab=uses">Uses</a>'
    . '<a href="file-examine.php?id='.$id.'&amp;tab=used-by">Used By</a>'
    . '</nav><p class="muted">Select a table to examine the original indexed entries '
    . 'or the independently published package relationships.</p></div>';
if ($dependencyProjectionError !== null) {
    echo '<div class="card"><h2>Dependency publication unavailable</h2><p>'
        . catalog_h($dependencyProjectionError) . '</p></div>';
}
if ($isZen) {
    echo '<div class="card" id="dependencies"><h2>Import references</h2>'
        . '<p>There are ' . (int)$file['import_count'] . ' serialized Zen import references. '
        . 'The UE5 examiner displays their cooked script names, typed reference identities, '
        . 'outer references and recorded classes without treating them as classic signed import indexes.</p>'
        . '<p><a class="button secondary" href="file-examine.php?id=' . $id
        . '&amp;tab=imports">Examine ' . (int)$file['import_count'] . ' imports</a></p></div>';
} else {
    echo '<div class="card" id="dependencies"><h2>Imports and linked files</h2>'
        . '<p class="muted">Each row comes from the serialized Unreal import table. '
        . 'External file links are shown only for V5 package-level or object-level matches. '
        . 'An export link is shown only when V5 confirms a specific export identity.</p>'
        . '<p class="muted">Showing ' . count($importPage['rows']) . ' of '
        . (int)$file['import_count'] . ' import entries. <a href="file-examine.php?id=' . $id
        . '&amp;tab=imports">View every import</a>.</p>'
        . file_info_imports_table($importPage['rows'], $dependencyByIndex, $id, (int)$file['game_id'], $packageFiles)
        . '</div>';
}
$usesById=[];foreach($deps as$d){$target=(int)($d['resolved_id']??0);if($target<1||$target===$id||!in_array((string)($d['status']??''),['resolved','package_only'],true))continue;$usesById[$target]=['id'=>$target,'package_name'=>(string)($d['resolved_package']??''),'original_name'=>(string)($d['resolved_file']??''),'package_guid'=>(string)($d['resolved_guid']??''),'md5'=>(string)($d['resolved_md5']??''),'sha1'=>(string)($d['resolved_sha1']??''),'file_size'=>(int)($d['resolved_size']??0)];}
$uses=array_values($usesById);usort($uses,static fn($a,$b)=>strnatcasecmp((string)$a['package_name'],(string)$b['package_name'])?:strnatcasecmp((string)$a['original_name'],(string)$b['original_name']));
$reverseCounts=catalog_one($db,'SELECT COUNT(DISTINCT e.file_id) AS c FROM ue_uedb5_dependency_edges e '. \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::joinCurrentEdges('e') . ' WHERE e.resolved_file_id=? AND e.outcome IN (1,2)',[$id]);
$reverseCount=(int)($reverseCounts['c']??0);
$usedBy=[];
$zenUses=null;$zenReverse=null;
if($isZen){
    $zenUses=(new \UnrealDb\Catalog\Infrastructure\Persistence\PdoZenPackageUsesQuery($db))
        ->forFile((int)$file['game_id'],$id,200);
    $zenReverse=(new \UnrealDb\Catalog\Infrastructure\Persistence\PdoZenPackageUsedByQuery($db))
        ->forFile((int)$file['game_id'],$id,1,200,true);
    $uses=[];
    foreach($zenUses['rows'] as $row){
        $row['original_name']=(string)$row['original_name'];
        $uses[]=$row;
    }
    $usedBy=[];
    foreach($zenReverse['rows'] as $row){
        $row['id']=(int)$row['file_id'];
        $usedBy[]=$row;
    }
    $reverseCount=(int)$zenReverse['total'];
}else{
    $usedBy=catalog_dependency_used_by_rows($db,$id,200);
}
// Link counts and serialized reference counts answer different questions.
// Show both, so a source-unresolved UE2/UE3 import never looks like no dependency.
$classicSourceCounts=null;
if(!$isZen && $dependencyProjectionError===null){
    $classicSourceCounts=catalog_one($db,
        'SELECT COALESCE(SUM(p.unresolved_count+p.common_count),0) unresolved_count '
        .'FROM ue_uedb5_dependency_packages p '
        .'WHERE p.file_id=? AND '
        .\UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope::currentOwnerPredicate('p'),
        [$id]);
}
echo '<div class="card"><h2>File connections</h2>';
if($classicSourceCounts!==null){
    $sourceUnresolved=(int)($classicSourceCounts['unresolved_count']??0);
    if($sourceUnresolved>0){
        echo '<p class="muted">The file links below are established catalogue provider links, '
            .'not a count of every serialized import. Source-published references additionally include '
            .number_format($sourceUnresolved).' unresolved. '
            .'<a href="file-examine.php?id='.$id.'&amp;tab=imports">Examine Imports</a>.</p>';
    }
}
echo '<div class="file-relations-map">'
    . '<div class="file-map-node"><span class="muted">Linked provider files</span><strong>'.count($uses).'</strong><a href="file-examine.php?id='.$id.'&tab=uses">View linked files</a></div>'
    . '<span class="file-map-arrow" aria-hidden="true">←</span>'
    . '<div class="file-map-node file-map-current"><span class="muted">Current package</span><strong>'.catalog_h((string)$file['package_name']).'</strong></div>'
    . '<span class="file-map-arrow" aria-hidden="true">←</span>'
    . '<div class="file-map-node"><span class="muted">Current files using this package</span><strong>'.$reverseCount.'</strong><a href="file-examine.php?id='.$id.'&tab=used-by">View referring files</a></div>'
    . '</div></div>';
echo '<div class="card"><h2>Uses ('.count($uses).')</h2>'
    .file_info_related_files_table($uses,$dependencyProjectionError??'No linked external files recorded.');
if($zenUses!==null&&$zenUses['truncated']){
    echo '<p><a href="file-examine.php?id='.$id.'&tab=uses">See all serialized imported package references</a></p>';
}
echo '</div>';
echo '<div class="card"><h2>Used by ('.($isZen?$reverseCount:count($usedBy)).')</h2>'
    .file_info_related_files_table($usedBy,'No files reference this package in the current indexed catalogue.');
if($zenReverse!==null&&$reverseCount>count($usedBy)){
    echo '<p><a href="file-examine.php?id='.$id.'&tab=used-by">See all '
        .$reverseCount.' indexed source references</a></p>';
}
echo '</div>';

echo <<<'HTML'
<script>(function(){'use strict';var tabs=Array.from(document.querySelectorAll('[data-dependency-tab]')),panels=Array.from(document.querySelectorAll('[data-dependency-panel]')),nav=document.querySelector('[data-dependency-default]');function name(){var h=decodeURIComponent(location.hash.replace(/^#/,''));return h.indexOf('dependency-')===0?h.slice(11):(nav?nav.dataset.dependencyDefault:'all')}function activate(n){if(!panels.some(p=>p.dataset.dependencyPanel===n))n=nav?nav.dataset.dependencyDefault:'all';panels.forEach(p=>p.hidden=p.dataset.dependencyPanel!==n);tabs.forEach(t=>t.classList.toggle('is-active',t.dataset.dependencyTab===n))}tabs.forEach(t=>t.addEventListener('click',e=>{e.preventDefault();history.pushState(null,'','#dependency-'+t.dataset.dependencyTab);activate(t.dataset.dependencyTab)}));addEventListener('hashchange',()=>activate(name()));activate(name());document.querySelectorAll('table[data-sortable-table]').forEach(function(table){var head=table.tHead&&table.tHead.rows[0],body=table.tBodies[0];if(!head||!body)return;var active=-1,asc=true;Array.from(head.cells).forEach(function(th,i){th.addEventListener('click',function(){if(active===i)asc=!asc;else{active=i;asc=true}Array.from(body.rows).sort(function(a,b){var x=(a.cells[i]?.dataset.sortValue||a.cells[i]?.textContent||'').trim(),y=(b.cells[i]?.dataset.sortValue||b.cells[i]?.textContent||'').trim();return(asc?1:-1)*x.localeCompare(y,undefined,{numeric:true,sensitivity:'base'})}).forEach(r=>body.appendChild(r))})})})})();</script>
HTML;
catalog_foot();}catch(Throwable$e){catalog_head('Error');echo'<div class="card"><h1>Error</h1><p>'.catalog_h($e->getMessage()).'</p></div>';catalog_foot();}
