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
function file_info_imports_table(array $imports, array $dependencyByIndex, int $fileId): string {
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

try{
$config=catalog_config();$db=catalog_db($config);catalog_dependency_schema_ensure($db);$id=(int)($_GET['id']??0);$file=catalog_one($db,'SELECT f.*,g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',[$id]);if(!$file)throw new RuntimeException('File not found');$deps=catalog_dependency_rows($db,$config,$id);
$dependencyByIndex=[];foreach($deps as $dep){$index=(int)($dep['import_index']??-1);if($index>=0)$dependencyByIndex[$index]=$dep;}
$importPage=\UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery::fetchPage($db,$file,'imports',1,250);
catalog_head('File info');echo <<<'CSS'
<style>
.file-info-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.file-info-title h1{margin:0}.file-info-context{margin:8px 0}.file-imports-scroll{overflow-x:auto}.file-imports-table{width:100%}.file-imports-table th:nth-child(1){width:9%}.file-imports-table th:nth-child(2){width:22%}.file-imports-table th:nth-child(3){width:32%}.file-imports-table th:nth-child(4){width:9%}.file-imports-table th:nth-child(5){width:28%}.file-map-nav{display:flex;gap:10px;flex-wrap:wrap}.file-map-nav a{display:flex;gap:12px;align-items:center;border:1px solid var(--border,#2b3950);border-radius:8px;padding:12px;text-decoration:none}.file-relations-map{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}.file-map-node{flex:1;min-width:160px;display:flex;flex-direction:column;gap:8px;border:1px solid var(--border,#2b3950);padding:14px;border-radius:10px}.file-map-node strong{overflow-wrap:anywhere}.file-map-current{border-color:var(--blue,#76a9ff)}.file-map-arrow{font-size:24px}[data-sortable-table] th{cursor:pointer;user-select:none}[data-sortable-table] th::after{content:'↕';display:inline-block;margin-left:7px;color:var(--muted);font-size:11px;opacity:.7}[data-sortable-table] th.is-sort-ascending::after{content:'▲';color:var(--blue);opacity:1}[data-sortable-table] th.is-sort-descending::after{content:'▼';color:var(--blue);opacity:1}
</style>
CSS;
$compressed=(int)($file['is_compressed']??0)===1;[$fileType,$fileTypeClass]=file_info_type_from_extension((string)($file['extension']??''));$packageHref='file-examine.php?id='.$id;$gameHref='game-files.php?id='.(int)$file['game_id'];
echo'<div class="card"><div class="file-info-title"><h1>'.catalog_h($file['package_name']).'</h1><span class="dep file-type-pill '.catalog_h($fileTypeClass).'">'.catalog_h($fileType).'</span></div><p class="file-info-context"><a href="'.$packageHref.'">'.catalog_h($file['original_name']).'</a> / <a href="'.$gameHref.'">'.catalog_h($file['game_name']).'</a></p><p><a class="button" href="download-info.php?id='.$id.'">Download options</a> <a class="button secondary" href="'.$packageHref.'">Examine file</a></p><p><span class="dep '.($compressed?'compressed':'uncompressed').'">'.($compressed?'compressed':'uncompressed').'</span> <span class="mono small">flags 0x'.strtoupper(str_pad(dechex((int)($file['compression_flags']??0)),8,'0',STR_PAD_LEFT)).'</span></p><table><tr><th>MD5</th><td class="mono">'.catalog_h($file['md5']).'</td></tr><tr><th>SHA1</th><td class="mono">'.catalog_h($file['sha1']).'</td></tr><tr><th>GUID</th><td class="mono">'.catalog_h($file['package_guid']).'</td></tr><tr><th>Tables</th><td><a href="'.$packageHref.'">'.(int)$file['name_count'].' names / '.(int)$file['import_count'].' imports / '.(int)$file['export_count'].' exports</a></td></tr></table></div>';
echo '<div class="card"><h2>Inside this file</h2><nav class="file-map-nav">'
    . '<a href="file-examine.php?id='.$id.'&tab=names">Names <strong>'.(int)$file['name_count'].'</strong></a>'
    . '<a href="file-examine.php?id='.$id.'&tab=imports">Imports <strong>'.(int)$file['import_count'].'</strong></a>'
    . '<a href="file-examine.php?id='.$id.'&tab=exports">Exports <strong>'.(int)$file['export_count'].'</strong></a>'
    . '</nav><p class="muted">Names are stored strings. Imports refer to objects outside or within the package hierarchy. Exports are objects defined by this package. Select a section to examine the original indexed entries and their references.</p></div>';
echo '<div class="card" id="dependencies"><h2>Imports and linked files</h2>'
    . '<p class="muted">Each row comes from the serialized Unreal import table. External file links are shown only for V5 package-level or object-level matches. An export link is shown only when V5 confirms a specific export identity. A dash does not mean the import is invalid or that Unreal Engine cannot load it.</p>'
    . '<p class="muted">Showing '.count($importPage['rows']).' of '.(int)$file['import_count'].' import entries. <a href="file-examine.php?id='.$id.'&tab=imports">View every import</a>.</p>'
    . file_info_imports_table($importPage['rows'], $dependencyByIndex, $id) . '</div>';
$usesById=[];foreach($deps as$d){$target=(int)($d['resolved_id']??0);if($target<1||$target===$id||!in_array((string)($d['status']??''),['resolved','package_only'],true))continue;$usesById[$target]=['id'=>$target,'package_name'=>(string)($d['resolved_package']??''),'original_name'=>(string)($d['resolved_file']??''),'package_guid'=>(string)($d['resolved_guid']??''),'md5'=>(string)($d['resolved_md5']??''),'sha1'=>(string)($d['resolved_sha1']??''),'file_size'=>(int)($d['resolved_size']??0)];}$uses=array_values($usesById);usort($uses,static fn($a,$b)=>strnatcasecmp((string)$a['package_name'],(string)$b['package_name'])?:strnatcasecmp((string)$a['original_name'],(string)$b['original_name']));
$reverseCounts=catalog_one($db,'SELECT COUNT(DISTINCT file_id) AS c FROM ue_uedb5_dependency_edges WHERE resolved_file_id=? AND outcome IN (1,2)',[$id]);$reverseCount=(int)($reverseCounts['c']??0);
echo '<div class="card"><h2>File connections</h2><div class="file-relations-map">'
    . '<div class="file-map-node"><span class="muted">Files this package uses</span><strong>'.count($uses).'</strong><a href="file-examine.php?id='.$id.'&tab=uses">View linked files</a></div>'
    . '<span class="file-map-arrow" aria-hidden="true">←</span>'
    . '<div class="file-map-node file-map-current"><span class="muted">Current package</span><strong>'.catalog_h((string)$file['package_name']).'</strong></div>'
    . '<span class="file-map-arrow" aria-hidden="true">←</span>'
    . '<div class="file-map-node"><span class="muted">Files using this package</span><strong>'.$reverseCount.'</strong><a href="file-examine.php?id='.$id.'&tab=used-by">View referring files</a></div>'
    . '</div></div>';
echo '<div class="card"><h2>Uses ('.count($uses).')</h2>'.file_info_related_files_table($uses,'No linked external files recorded.').'</div>';$usedBy=catalog_dependency_used_by_rows($db,$id,200);echo'<div class="card"><h2>Used by ('.count($usedBy).')</h2>'.file_info_related_files_table($usedBy,'No files reference this package in the V5 provider links.').'</div>';
echo catalog_file_feedback_form_html($id);
echo <<<'HTML'
<script>(function(){'use strict';var tabs=Array.from(document.querySelectorAll('[data-dependency-tab]')),panels=Array.from(document.querySelectorAll('[data-dependency-panel]')),nav=document.querySelector('[data-dependency-default]');function name(){var h=decodeURIComponent(location.hash.replace(/^#/,''));return h.indexOf('dependency-')===0?h.slice(11):(nav?nav.dataset.dependencyDefault:'all')}function activate(n){if(!panels.some(p=>p.dataset.dependencyPanel===n))n=nav?nav.dataset.dependencyDefault:'all';panels.forEach(p=>p.hidden=p.dataset.dependencyPanel!==n);tabs.forEach(t=>t.classList.toggle('is-active',t.dataset.dependencyTab===n))}tabs.forEach(t=>t.addEventListener('click',e=>{e.preventDefault();history.pushState(null,'','#dependency-'+t.dataset.dependencyTab);activate(t.dataset.dependencyTab)}));addEventListener('hashchange',()=>activate(name()));activate(name());document.querySelectorAll('table[data-sortable-table]').forEach(function(table){var head=table.tHead&&table.tHead.rows[0],body=table.tBodies[0];if(!head||!body)return;var active=-1,asc=true;Array.from(head.cells).forEach(function(th,i){th.addEventListener('click',function(){if(active===i)asc=!asc;else{active=i;asc=true}Array.from(body.rows).sort(function(a,b){var x=(a.cells[i]?.dataset.sortValue||a.cells[i]?.textContent||'').trim(),y=(b.cells[i]?.dataset.sortValue||b.cells[i]?.textContent||'').trim();return(asc?1:-1)*x.localeCompare(y,undefined,{numeric:true,sensitivity:'base'})}).forEach(r=>body.appendChild(r))})})})})();</script>
HTML;
catalog_foot();}catch(Throwable$e){catalog_head('Error');echo'<div class="card"><h1>Error</h1><p>'.catalog_h($e->getMessage()).'</p></div>';catalog_foot();}
