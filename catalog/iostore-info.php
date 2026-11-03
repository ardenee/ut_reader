<?php
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

catalog_start_session();
try {
    $config=catalog_config();
    $db=catalog_db($config);
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if (!is_int($id)||$id<1) throw new RuntimeException('Container ID is required.');
    if (!CatalogIoStoreContainerIndexer::schemaInstalled($db)) {
        throw new RuntimeException('IoStore container index is not installed.');
    }
    $q=$db->prepare('SELECT c.*,g.name game_name FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id WHERE c.id=?');
    $q->execute([$id]);$container=$q->fetch(PDO::FETCH_ASSOC);
    if (!$container)throw new RuntimeException('IoStore container not found.');
    $namesReady=(new UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector($db))
        ->columnExists('ue_iostore_packages','package_name');
    $filter=substr(trim((string)($_GET['q']??'')),0,80);
    $page=max(1,min(100000,(int)($_GET['page']??1)));
    $limit=100;$offset=($page-1)*$limit;
    $where='container_id=?';$args=[$id];
    if($filter!==''){
        if($namesReady){
            if(preg_match('/^[a-fA-F0-9]{1,16}$/',$filter)===1){
                $where.=' AND (HEX(package_id) LIKE ? OR INSTR(package_name,?)>0)';
                $args[]=strtoupper($filter).'%';$args[]=$filter;
            }else{
                $where.=' AND INSTR(package_name,?)>0';$args[]=$filter;
            }
        }else{
            if(preg_match('/^[a-fA-F0-9]{1,16}$/',$filter)!==1)throw new RuntimeException('Only FPackageId prefix search is available until the name index is installed.');
            $where.=' AND HEX(package_id) LIKE ?';$args[]=strtoupper($filter).'%';
        }
    }
    $count=$db->prepare('SELECT COUNT(*) FROM ue_iostore_packages WHERE '.$where);
    $count->execute($args);$total=(int)$count->fetchColumn();
    $extra=$namesReady?',package_name,metadata_status,import_count,export_count':',NULL AS package_name,NULL AS metadata_status,0 AS import_count,0 AS export_count';
    $rows=$db->prepare('SELECT toc_index,HEX(package_id) package_hex,chunk_index,logical_size,file_id,publication_status'.$extra.' FROM ue_iostore_packages WHERE '.$where.' ORDER BY toc_index LIMIT '.$limit.' OFFSET '.$offset);
    $rows->execute($args);
    catalog_head('Package Viewer: IoStore');
    echo CatalogUi::pageHeader('IoStore container: '.(string)$container['name'],
        'Indexed Zen packages; source retention and publication status are tracked separately.',
        ['Package Viewer'=>'game-paks.php?id='.(int)$container['game_id'],'Game files'=>'game-files.php?id='.(int)$container['game_id']]);
    echo '<div class="card"><p><strong>'.catalog_h((string)$container['game_name']).'</strong> · '
        .number_format((int)$container['package_count']).' indexed packages · status: '
        .catalog_h((string)$container['status']).'</p><p class="mono small">TOC SHA-256: '
        .catalog_h((string)$container['utoc_sha256']).'</p></div>';
    if ((int)$container['package_count'] === 0) {
        // Epic IoChunkId.h: ExportBundleData=1 is a package; ScriptObjects=5
        // is cooked global metadata and must never be published as a file.
        echo '<section class="ui-section"><div class="ui-section__header"><h2>Container contents</h2></div><div class="ui-section__body">';
        echo '<p><strong>No indexed Zen package files.</strong> The original .utoc/.ucas set is retained. The TOC chunk breakdown below explains whether this is a metadata-only container or an indexing problem.</p>';
        try {
            $storageRoot = realpath((string)($config['storage_path'] ?? ''));
            $relativeToc = str_replace('\\', '/', (string)$container['utoc_relative_path']);
            if ($storageRoot === false || !str_starts_with($relativeToc, 'games/')
                || str_contains($relativeToc, '..') || str_contains($relativeToc, ':')) {
                throw new RuntimeException('Retained IoStore source path is invalid.');
            }
            $tocPath = realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeToc));
            $rootPrefix = strtolower(rtrim(str_replace('\\', '/', $storageRoot), '/') . '/');
            if ($tocPath === false || !str_starts_with(strtolower(str_replace('\\', '/', $tocPath)), $rootPrefix)) {
                throw new RuntimeException('Retained IoStore TOC is unavailable inside catalogue storage.');
            }
            $tocReader = new Uedb5IoStoreTocReader($tocPath);
            $typeNames = [0=>'Invalid', 1=>'ExportBundleData', 2=>'BulkData', 3=>'OptionalBulkData',
                4=>'MemoryMappedBulkData', 5=>'ScriptObjects', 6=>'ContainerHeader', 7=>'ExternalFile',
                8=>'ShaderCodeLibrary', 9=>'ShaderCode', 10=>'PackageStoreEntry',
                11=>'DerivedData', 12=>'EditorDerivedData', 13=>'PackageResource'];
            $kinds = [];
            foreach ($tocReader->chunks() as $chunk) {
                $type = (int)$chunk['chunk_type'];
                $kinds[$type] ??= ['count'=>0, 'bytes'=>0];
                $kinds[$type]['count']++;
                $kinds[$type]['bytes'] += (int)$chunk['length'];
            }
            ksort($kinds);
            echo '<table><thead><tr><th>Actual TOC chunk type</th><th>Chunks</th><th>Logical size</th></tr></thead><tbody>';
            foreach ($kinds as $type => $stats) {
                echo '<tr><td>' . catalog_h(($typeNames[$type] ?? 'Unknown') . ' (' . $type . ')') . '</td>'
                    . '<td>' . number_format($stats['count']) . '</td>'
                    . '<td>' . catalog_h(catalog_bytes($stats['bytes'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            if (!empty($kinds[1]['count'])) {
                echo '<p><strong>Index discrepancy:</strong> the original TOC contains ExportBundleData chunks but the catalogue has no indexed Zen packages. This requires an indexing repair, not a new file upload.</p>';
            } elseif (isset($kinds[5])) {
                echo '<p class="muted">Metadata-only container: ScriptObjects holds cooked global script-object references, not independent package files. Nothing should appear in the game Files list from this chunk.</p>';
            } else {
                echo '<p class="muted">This TOC has no standalone package chunks to publish to the game Files list.</p>';
            }
        } catch (Throwable $contentsError) {
            echo '<p class="muted">The retained TOC chunk breakdown could not be displayed: '
                . catalog_h($contentsError->getMessage()) . '</p>';
        }
        echo '</div></section>';
    }
    echo '<section class="ui-section"><div class="ui-section__header"><h2>Contained packages</h2></div><div class="ui-section__body">';
    echo '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>Package name or FPackageId <input name="q" value="'.catalog_h($filter).'" maxlength="80"></label> <button type="submit">Filter</button></form>';
    echo '<p>'.number_format($total).' result(s)</p><div class="ui-table-region"><table><thead><tr><th>TOC entry</th><th>FPackageId</th><th>Package name</th><th>Chunk</th><th>Logical size</th><th>Publication</th></tr></thead><tbody>';
    foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $row){
        $link=(int)($row['file_id']??0);
        echo '<tr><td>'.number_format((int)$row['toc_index']).'</td><td class="mono">'
           .'<a href="iostore-package-info.php?id='.$id.'&entry='.(int)$row['toc_index'].'">'.catalog_h((string)$row['package_hex']).'</a></td><td>'.catalog_h((string)($row['package_name']??'')) . '</td><td>'.(int)$row['chunk_index'].'</td><td>'
           .catalog_h(catalog_bytes((int)$row['logical_size'])).'</td><td>';
        if($link>0)echo '<a href="file-info.php?id='.$link.'">View package</a>';
        else echo catalog_h((string)$row['publication_status']);
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    $max=(int)ceil($total/$limit);
    if($page>1)echo '<a class="button" href="iostore-info.php?'.http_build_query(['id'=>$id,'q'=>$filter,'page'=>$page-1]).'">Previous</a> ';
    if($page<$max)echo '<a class="button" href="iostore-info.php?'.http_build_query(['id'=>$id,'q'=>$filter,'page'=>$page+1]).'">Next</a>';
    echo '</div></section>';
    catalog_foot();
}catch(Throwable $e){
    if(!headers_sent())catalog_head('IoStore viewer error');
    echo CatalogUi::alert('danger',catalog_exception_display_message($e));
    catalog_foot();
}
