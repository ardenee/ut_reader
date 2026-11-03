<?php
declare(strict_types=1);

/**
 * Existing actual website path guard: one combined retained-archive page
 * replacing the two retired root entries. No SQL or filesystem writes.
 */
$root=dirname(__DIR__,2);
$page=$root.'/catalog/pakages.php';
if (!is_file($page) || is_file($root.'/catalog/paks.php') || is_file($root.'/catalog/upks.php')) {
    throw new RuntimeException('Unified archive route was not deployed or retired pages remain.');
}
$code=file_get_contents($page);
if (!is_string($code)) throw new RuntimeException('Cannot read combined archive source.');
$expected=[
 'ue_pak_archives'=>'pak',
 'LOWER(f.extension)="upk"'=>'upk',
 'ue_iostore_containers'=>'utoc',
 'ue_iostore_container_parts'=>'ucas',
 'pak-info.php?id='=>'PAK details',
 'upk-info.php?id='=>'UPK details',
 'iostore-info.php?id='=>'IoStore TOC/UCAS details',
 'pakages.php?'=>'retained archive filtering',
 'CatalogIoStoreContainerIndexer::schemaInstalled'=>'migration guard',
 'CatalogPakArchiveStore::schemaInstalled'=>'PAK migration guard',
];
foreach($expected as $needle=>$purpose) {
    if (!str_contains($code,$needle)) {
        throw new RuntimeException('Combined archive page lost '.$purpose.' ('.$needle.').');
    }
}
$requiredFiles=[
 'catalog/lib/CatalogNavigation.php',
 'catalog/game-paks.php',
 'catalog/iostore-import.php',
 'catalog/iostore-backup.php',
 'catalog/iostore-package-info.php',
 'catalog/pak-import.php',
];
foreach($requiredFiles as $file) {
    $text=file_get_contents($root.'/'.$file);
    if (!is_string($text) || !str_contains($text,'pakages.php')) {
        throw new RuntimeException('Existing archive navigation still points to old route: '.$file);
    }
}
$nav=file_get_contents($root.'/catalog/lib/CatalogNavigation.php');
if (!is_string($nav) || str_contains($nav,"'UPK Packages' =>") || str_contains($nav,"'upks.php'")) {
    throw new RuntimeException('Removed UPK root navigation still exists.');
}
$validLinks=0;
// Only inspect source folders. PRD catalog/storage contains hundreds of
// thousands of original archives; walking it adds no route evidence.
$sourceTree = new RecursiveDirectoryIterator(
    $root.'/catalog', FilesystemIterator::SKIP_DOTS
);
$sourceOnly = new RecursiveCallbackFilterIterator(
    $sourceTree,
    static fn(SplFileInfo $item): bool => !$item->isDir()
        || !in_array(strtolower($item->getFilename()), [
            'storage', 'vendor', 'node_modules', '.git', 'archive', 'cache',
        ], true)
);
foreach(new RecursiveIteratorIterator($sourceOnly) as $item) {
    if (!$item->isFile() || !in_array(strtolower($item->getExtension()),['php','js'],true)) continue;
    $content=file_get_contents((string)$item);
    if (!is_string($content)) continue;
    if (preg_match('/(?<![A-Za-z0-9_-])(paks|upks)\.php/',$content)===1) {
        throw new RuntimeException('Retired root URL remains in source '.$item->getPathname());
    }
    $validLinks+=substr_count($content,'pakages.php');
}
if ($validLinks < 8) throw new RuntimeException('New route is not linked from active pages.');
echo json_encode([
  'result'=>'PASS',
  'displayed_physical_formats'=>['pak','upk','utoc','ucas'],
  'retired_root_files_removed'=>true,
  'per_game_PAK_and_UPK_detail_routes_retained'=>true,
  'root_navigation_retargeted'=>true,
  'new_route_source_references'=>$validLinks,
  'PRD_SQL_writes'=>0,
  'new_acceptance_contract_ids'=>0,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
