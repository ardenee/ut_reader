<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenEffectivePackageStoreContextBuilder as Builder;
$id='AABBCCDDEEFF0011';
$mounts=[['container_id'=>'base','order'=>1,'sequence'=>0,
 'package_redirects'=>[['source_package_id'=>$id,'target_package_id'=>'1111111111111111']],
 'localized_packages'=>[]]];
$higher=[['backend_id'=>'runtime','priority'=>10,'entries'=>[],
 'redirects'=>[$id=>['source_package_id'=>$id,'target_package_id'=>'9999999999999999']]]];
$a=Builder::build($mounts,$higher,1);
$b=Builder::build($mounts,$higher,20);
$mountsWithEntries=[
 ['container_id'=>'base','order'=>1,'sequence'=>0,
  'package_redirects'=>[],'localized_packages'=>[],
  'package_entries'=>[['package_id'=>$id,'file_id'=>42]]],
 ['container_id'=>'patch','order'=>2,'sequence'=>1,
  'package_redirects'=>[],'localized_packages'=>[],
  'package_entries'=>[['package_id'=>strtolower($id),'file_id'=>43]]],
];
$evidenced=Builder::build($mountsWithEntries,$higher,20);
$fileEntries=array_values(array_filter($evidenced['package_store_backend_results'],
 static fn(array $row):bool=>($row['backend_id']??'')==='file'));
$file=$fileEntries[0]??[];
$sourceBackedFileEntry=($file['entries'][$id]['status']??'')==='Ok'
 &&($file['entries'][$id]['file_id']??null)===43;
$firstMountMissing=false;
try{
 Builder::build([['container_id'=>'bad','order'=>1,'sequence'=>0,
  'package_redirects'=>[],'localized_packages'=>[],
  'package_entries'=>[['package_id'=>'BAD','file_id'=>43]]]],[],1);
}catch(RuntimeException){$firstMountMissing=true;}
$ok=($a['package_redirects'][0]['target_package_id']??'')==='9999999999999999'
 &&($b['package_redirects'][0]['target_package_id']??'')==='1111111111111111'
 &&$a['package_store_context_authoritative']===false
 &&$a['package_identity_context_authoritative']===false
 &&$sourceBackedFileEntry &&$firstMountMissing
 &&$evidenced['package_store_context_authoritative']===false;
echo json_encode(['ok'=>$ok,'file_backend_entry_evidenced'=>$sourceBackedFileEntry,
 'malformed_mount_entry_rejected'=>$firstMountMissing,'runtime_backend_wins'=>$a['package_redirects'],
 'file_backend_wins'=>$b['package_redirects'],'backend_order'=>$a['package_store_backend_order']],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
