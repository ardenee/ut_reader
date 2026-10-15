<?php
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;

catalog_start_session();
try {
    $db=catalog_db(catalog_config());
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if (!is_int($id)||$id<1) throw new RuntimeException('Container ID is required.');
    if (!CatalogIoStoreContainerIndexer::schemaInstalled($db)) {
        throw new RuntimeException('IoStore container index is not installed.');
    }
    $q=$db->prepare('SELECT c.*,g.name game_name FROM ue_iostore_containers c JOIN ue_games g ON g.id=c.game_id WHERE c.id=?');
    $q->execute([$id]);$container=$q->fetch(PDO::FETCH_ASSOC);
    if (!$container)throw new RuntimeException('IoStore container not found.');
    $filter=substr(trim((string)($_GET['q']??'')),0,80);
    $page=max(1,min(100000,(int)($_GET['page']??1)));
    $limit=100;$offset=($page-1)*$limit;
    $where='container_id=?';$args=[$id];
    if($filter!==''){
        if(preg_match('/^[a-fA-F0-9]{1,16}$/',$filter)!==1)throw new RuntimeException('Filter must be a hexadecimal FPackageId prefix.');
        $where.=' AND HEX(package_id) LIKE ?';
        $args[]=strtoupper($filter).'%';
    }
    $count=$db->prepare('SELECT COUNT(*) FROM ue_iostore_packages WHERE '.$where);
    $count->execute($args);$total=(int)$count->fetchColumn();
    $rows=$db->prepare('SELECT toc_index,HEX(package_id) package_hex,chunk_index,logical_size,file_id,publication_status FROM ue_iostore_packages WHERE '.$where.' ORDER BY toc_index LIMIT '.$limit.' OFFSET '.$offset);
    $rows->execute($args);
    catalog_head('Package Viewer: IoStore');
    echo CatalogUi::pageHeader('IoStore container: '.(string)$container['name'],
        'Indexed Zen packages; source retention and publication status are tracked separately.',
        ['Package Viewer'=>'game-paks.php?id='.(int)$container['game_id'],'Game files'=>'game-files.php?id='.(int)$container['game_id']]);
    echo '<div class="card"><p><strong>'.catalog_h((string)$container['game_name']).'</strong> · '
        .number_format((int)$container['package_count']).' indexed packages · status: '
        .catalog_h((string)$container['status']).'</p><p class="mono small">TOC SHA-256: '
        .catalog_h((string)$container['utoc_sha256']).'</p></div>';
    echo '<section class="ui-section"><div class="ui-section__header"><h2>Contained packages</h2></div><div class="ui-section__body">';
    echo '<form method="get"><input type="hidden" name="id" value="'.$id.'"><label>FPackageId prefix <input name="q" value="'.catalog_h($filter).'" maxlength="16"></label> <button type="submit">Filter</button></form>';
    echo '<p>'.number_format($total).' result(s)</p><div class="ui-table-region"><table><thead><tr><th>TOC entry</th><th>FPackageId</th><th>Chunk</th><th>Logical size</th><th>Publication</th></tr></thead><tbody>';
    foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $row){
        $link=(int)($row['file_id']??0);
        echo '<tr><td>'.number_format((int)$row['toc_index']).'</td><td class="mono">'
           .'<a href="iostore-package-info.php?id='.$id.'&entry='.(int)$row['toc_index'].'">'.catalog_h((string)$row['package_hex']).'</a></td><td>'.(int)$row['chunk_index'].'</td><td>'
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
