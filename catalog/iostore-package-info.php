<?php
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreIndexedPackageReader;

catalog_start_session();
try {
    $container=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    $entry=filter_input(INPUT_GET,'entry',FILTER_VALIDATE_INT);
    if(!is_int($container)||$container<1||!is_int($entry)||$entry<0)throw new RuntimeException('Container and TOC entry are required.');
    $config=catalog_config();$db=catalog_db($config);
    $inspector=new CatalogIoStoreIndexedPackageReader($db,(string)$config['storage_path'],
        is_string(getenv('UNREALDB_OODLE_LIBRARY'))?getenv('UNREALDB_OODLE_LIBRARY'):null);
    $details=$inspector->inspect($container,$entry);
    catalog_head('Package Viewer: Zen package');
    echo CatalogUi::pageHeader('Zen package details',
        (string)$details['package_name'],
        ['Container'=>'iostore-info.php?id='.$container,'Package Viewer'=>'paks.php']);
    echo '<section class="ui-section"><div class="ui-section__header"><h2>IoStore package</h2></div><div class="ui-section__body"><table><tbody>';
    foreach([
        'Package name'=>$details['package_name'],
        'FPackageId'=>$details['package_id'],
        'TOC entry'=>$details['toc_index'],
        'Name-map entries'=>$details['name_count'],
        'Imports'=>$details['imports'],
        'Exports'=>$details['exports'],
        'Dependency-bundle entries'=>$details['dependency_bundles'],
        'Logical chunk size'=>catalog_bytes((int)$details['logical_size'])
    ] as $label=>$value)echo '<tr><th>'.catalog_h((string)$label).'</th><td>'.catalog_h((string)$value).'</td></tr>';
    echo '</tbody></table>';
    if(catalog_support_is_admin()){
        echo '<p><a class="button" href="iostore-package-download.php?id='.$container.'&entry='.$entry.'">Extract original Zen package (.zenpkg)</a></p>';
    }
    if((int)($details['file_id']??0)>0){
        echo '<p><a class="button primary" href="file-info.php?id='.(int)$details['file_id'].'">Open catalog file details</a></p>';
    }else{
        echo '<p class="muted">This package is indexed in its IoStore container but has not yet been published as an UnrealDB file.</p>';
    }
    echo '</div></section>';
    catalog_foot();
}catch(Throwable $e){
    if(!headers_sent())catalog_head('Zen package inspection error');
    echo CatalogUi::alert('danger',catalog_exception_display_message($e));
    catalog_foot();
}
