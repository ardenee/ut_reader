<?php
declare(strict_types=1);
require_once __DIR__.'/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreIndexedPackageReader;

catalog_start_session();
if(!catalog_require_admin_page('Zen package extraction'))exit;

try{
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    $entry=filter_input(INPUT_GET,'entry',FILTER_VALIDATE_INT);
    if(!is_int($id)||$id<1||!is_int($entry)||$entry<0)throw new RuntimeException('Select a container and Zen package.');
    $config=catalog_config();
    $library=getenv('UNREALDB_OODLE_LIBRARY');
    $reader=new CatalogIoStoreIndexedPackageReader(
        catalog_db($config),
        (string)$config['storage_path'],
        $library!==false&&$library!==''?$library:null
    );
    $out=$reader->extract($id,$entry);
    $body=$out['bytes'];
    $name=$out['package_id'].'.zenpkg';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Content-Length: '.strlen($body));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $body;
}catch(Throwable $e){
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Could not extract the selected Zen package: '.catalog_exception_display_message($e);
}
