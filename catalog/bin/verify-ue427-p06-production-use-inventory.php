<?php
/**
 * P06 static caller inventory only. Reports observed production call sites;
 * a passing inventory does not certify semantic AssetRegistry publication.
 */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
$methods=[
 'getThumbnailIndex','getThumbnailPayload','getAssetRegistryObjects',
 'getAssetRegistryDependencyDataOffset','getAssetRegistryDependencyBits','getWorldTileInfo'
];
$areas=['catalog','UE4'];
$skip=['bin'=>true,'compliance'=>true,'storage'=>true,'vendor'=>true,'.git'=>true,'tests'=>true];
$hits=array_fill_keys($methods,[]);
$total=0;
foreach($areas as $area){
 $base=$repo.'/'.$area;
 if(!is_dir($base))throw new RuntimeException("Missing source area $base");
 $iterator=new RecursiveIteratorIterator(
  new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),
   static function(SplFileInfo $file)use($skip):bool {
    return !$file->isDir()||!isset($skip[$file->getFilename()]);
   })
 );
 foreach($iterator as $file){
  if(!$file instanceof SplFileInfo||$file->getExtension()!=='php')continue;
  $name=str_replace('\\','/',substr($file->getPathname(),strlen($repo)+1));
  if($name==='UE4/UnrealPackageReader.php')continue;
  $content=file_get_contents($file->getPathname());
  if($content===false)throw new RuntimeException("Cannot read $name");
  $total++;
  foreach($methods as $method){
   if(preg_match('~(?:->|::)\s*'.preg_quote($method,'~').'\s*\(~',$content)===1)
    $hits[$method][]=$name;
  }
 }
}
$source=$repo.'/catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php';
$snapshot=file_get_contents($source);
if($snapshot===false)throw new RuntimeException('Cannot read UT4 snapshot builder');
$sections=[];
foreach(['summary','names','imports','exports','string_asset_references','preload_dependencies','depends_map',
 'asset_registry','thumbnails','world_tile_info'] as $section){
 $sections[$section]=(bool)preg_match("~['\"]".preg_quote($section,'~')."['\"]\s*=>~",$snapshot);
}
$hasCalls=array_sum(array_map('count',$hits));
$ok=$total>=100&&$hasCalls===0&&$sections['summary']
 && !$sections['asset_registry']&&!$sections['thumbnails']&&!$sections['world_tile_info'];
echo json_encode([
 'ok'=>$ok,'scope'=>'Source-static P06 production caller inventory; negative coverage finding only',
 'production_php_files_scanned'=>$total,'p06_getter_calls'=>$hits,
 'p06_getter_call_count'=>$hasCalls,
 'ut4_v511_snapshot_section_names'=>$sections,
 'finding'=>'No static production call site consumes new P06 raw getters; Epic FAssetData synthesis is not implemented here. UT4 v511 retains some summary fields but must not be confused with stock UE4.27 asset-data publication.',
 'limitation'=>'Static literal method-call search excludes reflection/dynamic invocation and external apps.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
