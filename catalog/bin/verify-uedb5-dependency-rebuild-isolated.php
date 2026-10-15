<?php
declare(strict_types=1);
/** Isolated real UEDB5 dependency rebuild with empty provider environment. */
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyRebuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyProjectionBuilder;
$app=catalog_bootstrap(false);
$production=new Uedb5MetadataReader((string)$app->config['storage_path']);
$storage=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-dependency-rebuild-'.bin2hex(random_bytes(8));
$writer=new Uedb5MetadataSnapshotWriter($storage);
$reader=new Uedb5MetadataReader($storage);
$rebuilder=new Uedb5DependencyRebuilder($reader,$writer);
$ids=[2=>111139,3=>113253,4=>148919,5=>111460,6=>112300,7=>113526,12=>110902];
$rows=[];$failed=[];
try{
 foreach($ids as $gameId=>$fileId){
  try{
   $snapshot=$production->snapshot($gameId,$fileId);
   // Preserve original full source-shaped consumer while rebuilding only its dependency section.
   $writer->write($snapshot);
   $result=$rebuilder->rebuild($gameId,$fileId,[]);
   $rebuilt=$reader->snapshot($gameId,$fileId);
   $projection=Uedb5DependencyProjectionBuilder::build($rebuilt);
   $count=count((array)($rebuilt['sections']['dependency_results']??[]));
   if($count!==(int)$result['dependency_count'] || $count!==count($projection['dependency_edges']))
    throw new RuntimeException('Rebuilt dependency and projection cardinality disagree');
   // Exercise the provider-selection hydration path with the same isolated package.
   // This is NOT a claim that this package satisfies any external imports.
   $selected=[['game_id'=>$gameId,'file_id'=>$fileId,
     'package_name'=>(string)($snapshot['file']['package_name']??'')]];
   $providerResult=$rebuilder->rebuild($gameId,$fileId,$selected);
   $providerSnapshot=$reader->snapshot($gameId,$fileId);
   $providerProjection=Uedb5DependencyProjectionBuilder::build($providerSnapshot);
   if((int)$providerResult['dependency_count']!==count($providerProjection['dependency_edges']))
    throw new RuntimeException('Provider-selected rebuild projection cardinality mismatch');
   $rows[]=['game_id'=>$gameId,'file_id'=>$fileId,'dependency_count'=>$count,
     'packages'=>count($projection['dependency_packages']),
     'selected_provider_hydrated'=>true,'ok'=>true];
  }catch(Throwable $e){$failed[]=['game_id'=>$gameId,'error'=>$e->getMessage()];}
  finally{$production->clearCache($gameId,$fileId);$reader->clearCache($gameId,$fileId);}
 }
}catch(Throwable $e){$failed[]=['setup'=>$e->getMessage()];}
finally{
 if(is_dir($storage)){
  $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($walk as $entry){$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());}
  @rmdir($storage);
 }
}
$ok=$failed===[] && count($rows)===count($ids);
echo json_encode(['ok'=>$ok,'disposable_v5_only'=>true,'provider_environment'=>'empty providers then isolated self-provider hydration; external provider parity not claimed',
 'results'=>$rows,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
