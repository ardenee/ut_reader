<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE5/UnrealPackageReader.php';
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$root='H:/UE5-583-runtime-probe/Engine/Content';
if(!is_file($exe)||!is_dir($root))throw new RuntimeException('Pinned native UE5 runtime unavailable');
$mapping=[
'LegacyUE3Version'=>'legacyUE3Version',
'FileVersionUE4'=>'serializedUE4Version','FileVersionUE5'=>'serializedUE5Version',
'FileVersionLicenseeUE4'=>'serializedLicenseeVersion','TotalHeaderSize'=>'totalHeaderSize',
'PackageName'=>'packageName','PackageFlags'=>'packageFlags','NameCount'=>'nameCount',
'NameOffset'=>'nameOffset','LocalizationId'=>'localizationId','GatherableTextDataCount'=>'gatherableTextDataCount',
'GatherableTextDataOffset'=>'gatherableTextDataOffset','ExportCount'=>'exportCount',
'ExportOffset'=>'exportOffset','ImportCount'=>'importCount','ImportOffset'=>'importOffset',
'DependsOffset'=>'dependsOffset','SoftPackageReferencesCount'=>'stringAssetReferencesCount',
'SoftPackageReferencesOffset'=>'stringAssetReferencesOffset','SearchableNamesOffset'=>'searchableNamesOffset',
'ThumbnailTableOffset'=>'thumbnailTableOffset','Guid'=>'guid','PersistentGuid'=>'persistentGuid',
'GenerationCount'=>'generations','CompressionFlags'=>'compressionFlags','CompressedChunks'=>'compressedChunks',
'PackageSource'=>'packageSource','AdditionalPackagesToCook'=>'additionalPackagesToCook',
'AssetRegistryDataOffset'=>'assetRegistryDataOffset','BulkDataStartOffset'=>'bulkDataStartOffset',
'WorldTileInfoDataOffset'=>'worldTileInfoDataOffset','ChunkIDs'=>'chunkIDs',
'PreloadDependencyCount'=>'preloadDependencyCount','PreloadDependencyOffset'=>'preloadDependencyOffset',
'NamesReferencedFromExportDataCount'=>'namesReferencedFromExportDataCount','PayloadTocOffset'=>'payloadTocOffset',
'DataResourceOffset'=>'dataResourceOffset','SavedHash'=>'savedHash',
'SoftObjectPathsCount'=>'softObjectPathsCount','SoftObjectPathsOffset'=>'softObjectPathsOffset',
'CellExportCount'=>'cellExportCount','CellExportOffset'=>'cellExportOffset',
'CellImportCount'=>'cellImportCount','CellImportOffset'=>'cellImportOffset',
'MetaDataOffset'=>'metaDataOffset','ImportTypeHierarchiesCount'=>'importTypeHierarchiesCount',
'ImportTypeHierarchiesOffset'=>'importTypeHierarchiesOffset'
];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$checked=0;$fields=0;$errors=[];$versions=[];
foreach($it as $file){
 if(!in_array(strtolower($file->getExtension()),['uasset','umap'],true)||$file->getSize()>2000000)continue;
 $reader=new UnrealPackageReader5($file->getPathname());
 $issues=$reader->validatePackage();
 if($issues!==[])continue;
 $out=[];$exit=0;
 exec(escapeshellarg($exe).' -j PackageInfo -p '.escapeshellarg($file->getPathname()).' --summary 2>&1',$out,$exit);
 $raw=implode("\n",$out);$start=strpos($raw,'{');
 $decoded=$start===false?null:json_decode(substr($raw,$start),true);
 $native=$decoded['PackageFileSummary']??null;
 if($exit!==0||!is_array($native)){ $errors[]=['asset'=>$file->getFilename(),'error'=>'native command failure','exit'=>$exit];if(count($errors)>=12)break;continue;}
 $ours=$reader->getHeader();
 foreach($mapping as $epic=>$local){
  if(!array_key_exists($epic,$native))continue;
  $left=$native[$epic];$right=$ours[$local]??null;
  // Epic's tool reports the normalized in-memory Tag/LegacyFileVersion, not always raw wire values.
  if($epic==='GenerationCount')$right=count($ours['generations']??[]);
  if($epic==='SavedHash'&&is_array($left)){$encoded=(string)($left['Data']??'');$bytes=str_starts_with($encoded,'Base64:')?base64_decode(substr($encoded,7),true):false;$left=is_string($bytes)?strtoupper(bin2hex($bytes)):$encoded;}
  if($epic==='Guid'||$epic==='PersistentGuid'||$epic==='SavedHash'){$left=strtoupper((string)$left);$right=strtoupper(str_replace('-','',(string)$right));}
  if($epic==='PackageName'&&$left==='None'&&$right==='')continue;
  if(in_array($epic,['CompressedChunks','AdditionalPackagesToCook','ChunkIDs'],true)){$left=json_encode($left);$right=json_encode($right);}
  if($left!==$right){$errors[]=['asset'=>$file->getFilename(),'field'=>$epic,'epic'=>$left,'unrealdb'=>$right];if(count($errors)>=15)break 2;}
  $fields++;
 }
 $checked++;$v=(string)($ours['ue5Version']??'');$versions[$v]=($versions[$v]??0)+1;
 if($checked>=140)break;
}
echo json_encode(['ok'=>$checked>=10&&$errors===[],'native_packages'=>$checked,'field_comparisons'=>$fields,'versions'=>$versions,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE),PHP_EOL;
exit($checked>=10&&$errors===[]?0:2);
