<?php
declare(strict_types=1);
/**
 * Original B009/B010 source-to-application callsite accounting, no DB writes.
 * Scan all production PHP (not tests) rather than only names from the ledger.
 * Fail closed when new direct Zen parser/builder callers appear unregistered.
 */
if(PHP_SAPI!=='cli'){exit(2);}
$root=dirname(__DIR__);
$sourceRoot=$root.'/src';
$checks=[];$failures=[];
$check=static function(string $key,bool $good)use(&$checks,&$failures):void {
    $checks[$key]=$good;if(!$good){$failures[]=$key;}
};
$files=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $sourceRoot,FilesystemIterator::SKIP_DOTS
));
foreach($iterator as $entry){
    if($entry->isFile()&&strtolower($entry->getExtension())==='php'){
        $relative=str_replace(chr(92),'/',substr($entry->getPathname(),strlen($root)+1));
        $files[$relative]=(string)file_get_contents($entry->getPathname());
    }
}
ksort($files);
$strip=static function(string $source):string {
    $out='';
    foreach(token_get_all($source) as $token){
        if(is_array($token)){
            if($token[0]===T_COMMENT||$token[0]===T_DOC_COMMENT){$out.=' ';}
            else{$out.=$token[1];}
        } else {$out.=$token;}
    }
    return $out;
};
$active=array_map($strip,$files);
$scan=static function(string $expression)use($active):array {
    $matches=[];
    foreach($active as $path=>$text){
        if(preg_match_all($expression,$text,$m)){
            $matches[$path]=count($m[0]);
        }
    }
    return $matches;
};
$expectedDirect=[
    'src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php'=>2
];
$expectedBuilder=[
    'src/Infrastructure/Import/CatalogZenIoStorePackagePublisher.php'=>1,
    'src/Infrastructure/Maintenance/CatalogIoStoreMemberReimportService.php'=>1,
    'src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php'=>1,
    'src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php'=>1,
];
ksort($expectedDirect);ksort($expectedBuilder);
$parser=$scan('/\bUedb5ZenPackageReader\s*::\s*parse\s*\(/');
$builder=$scan('/\bUedb5Ue5ZenIoStoreSnapshotBuilder\s*::\s*build\s*\(/');
$check('only_shared_snapshot_builder_invokes_zen_header_parser',$parser===$expectedDirect);
$check('all_four_production_snapshot_builder_consumers_accounted',$builder===$expectedBuilder);
// Negative controls: injecting an additional production invocation must
// invalidate the exact caller inventory, even when it is in a known file.
$injectedBuilder=$builder;
$injectedBuilder['src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php']++;
$check('unregistered_builder_call_fails_closed',$injectedBuilder!==$expectedBuilder);
$injectedParser=$parser;
$injectedParser['src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php']=1;
$check('unregistered_direct_parser_call_fails_closed',$injectedParser!==$expectedDirect);
$need=[
 'src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php'=>[
  '/CHUNK_TYPE_EXPORT_BUNDLE_DATA/',
  '/Uedb5ZenPackageReader\s*::\s*parse\s*\(/',
  '/if\s*\(is_array\(\$optionalStoreEntry\)\)/',
  '/versioning_info/',
  '/cell_offsets/',
  '/optionalZen/',
 ],
 'src/Infrastructure/Import/CatalogZenIoStorePackagePublisher.php'=>[
  '/Uedb5Ue5ZenIoStoreSnapshotBuilder\s*::\s*build\s*\(/',
  '/Uedb5MetadataSnapshotWriter/',
  '/Uedb5MigrationValidator/',
  '/->validate\(\$fileId,\s*\$snapshot\)/',
  '/catch\s*\(Throwable\s*\$e\)/',
  '/->rollBack\(\)/',
 ],
 'src/Infrastructure/Maintenance/CatalogIoStoreMemberReimportService.php'=>[
  '/Uedb5Ue5ZenIoStoreSnapshotBuilder\s*::\s*build\s*\(/',
  '/Uedb5MetadataSnapshotWriter/',
  '/Uedb5MigrationValidator/',
  '/->validate\(\$fileId,\s*\$snapshot\)/',
  '/->rollBack\(\)/',
  '/\.reimport-backup-/',
 ],
 'src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php'=>[
  '/Uedb5Ue5ZenIoStoreSnapshotBuilder\s*::\s*build\s*\(/',
  '/public\s+function\s+inspect\s*\(/',
  '/public\s+function\s+extract\s*\(/',
 ],
 'src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php'=>[
  '/Uedb5Ue5ZenIoStoreSnapshotBuilder\s*::\s*build\s*\(/',
  '/catch\s*\(/',
  '/metadata_status="failed"/',
 ],
 'src/Infrastructure/Import/CatalogIoStoreMemberCatalogPublisher.php'=>[
  '/CatalogZenIoStorePackagePublisher/',
  '/->publish\(\$toc,\s*\$packageId,\s*\$gameId,\s*\$fileId\)/',
 ],
 'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php'=>[
  '/CatalogIoStoreMemberReimportService/',
  "/'zenpkg'/",
 ],
 'src/Infrastructure/Metadata/Uedb5MigrationValidator.php'=>[
  '/private\s+function\s+validateZenFields/',
  '/private\s+function\s+validateZenVersionLayout/',
  '/->validateZenFields\(\$snapshot\)/',
  '/->validateZenVersionLayout\(/',
 ],
 'src/Infrastructure/Jobs/CatalogIoStoreNameIndexJobHandler.php'=>[
  '/CatalogIoStorePackageNameIndexer/',
 ],
];
foreach($need as $path=>$patterns){
    $code=$active[$path]??'';
    $check('production_caller_exists_'.basename($path),$code!=='');
    foreach($patterns as $n=>$regex){
        $check('production_path_'.basename($path).'_'.($n+1),
            @preg_match($regex,$code)===1);
    }
}
$exclude=[
 'classic_source_snapshot_factory'=>'Uedb5SourceSnapshotFactory::buildForGameId is for classic package readers and does not parse Zen package chunks',
 'metadata_read_only'=>'Uedb5MetadataReader reads V5 snapshot sections; it does not reinterpret serialized Zen header bytes',
 'dependency_rebuild'=>'Uedb5DependencyRebuilder consumes stored import/export indices; serialized Zen version thresholds were already decided by the header reader',
 'epic_uobject_payload'=>'Engine UObject export archive versioning is separate from UnrealDB header metadata extraction; no claim that UnrealDB instantiates Epic linker',
];
echo json_encode([
 'ok'=>$failures===[],
 'read_only'=>true,
 'production_php_scanned'=>count($files),
 'direct_parser_calls'=>$parser,
 'direct_builder_calls'=>$builder,
 'expected_paths'=>$need===[]?[]:array_keys($need),
 'scope_exclusions'=>$exclude,
 'checks'=>$checks,
 'failures'=>$failures,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
