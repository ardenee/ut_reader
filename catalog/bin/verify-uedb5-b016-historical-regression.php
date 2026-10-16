<?php
declare(strict_types=1);
// Historical negative-before test: requires the original 4e4eaf9e commit to exist locally.
if (PHP_SAPI !== 'cli') exit(2);
$root=dirname(__DIR__,2);
require $root.'/catalog/bootstrap/autoload.php';
$command='git -c '.escapeshellarg('safe.directory='.$root)
 .' -C '.escapeshellarg($root)
 .' show '.escapeshellarg('4e4eaf9e:catalog/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php');
$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot launch git for historical replay');
$old=stream_get_contents($pipes[1]);fclose($pipes[1]);
$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
$exit=proc_close($process);
if ($exit!==0 || hash('sha256',$old)!=='9cd03b4322bbc56ed5f6998f143cd65403d55474dd66dde544d02837628cf990') {
 echo json_encode(['ok'=>false,'error'=>'Historical resolver unavailable or SHA mismatch; no evidence claimed','exit'=>$exit,'actual_hash'=>hash('sha256',$old),'stderr'=>$stderr],JSON_PRETTY_PRINT),PHP_EOL;
 exit(1);
}
$old=str_replace('final class Uedb5Ue5ZenDependencyResolver','final class Uedb5Ue5ZenDependencyResolverB016Before',$old);
$temp=sys_get_temp_dir().'/b016-before-'.bin2hex(random_bytes(5)).'.php';
file_put_contents($temp,$old);
try { require $temp; } finally { @unlink($temp); }
$before='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5Ue5ZenDependencyResolverB016Before';
$after='UnrealDb\\Catalog\\Infrastructure\\Metadata\\Uedb5Ue5ZenDependencyResolver';
$reader=UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader::class;
$snapshot=static fn(string $id,array $imports,array $exports,array $cellImports,array $cellExports):array=>[
 'package_family'=>$reader::PACKAGE_FAMILY,'source_policy'=>$reader::SOURCE_POLICY,
 'sections'=>['package_summary'=>[['package_id'=>$id]],'imports'=>$imports,'exports'=>$exports,
 'cell_imports'=>$cellImports,'cell_exports'=>$cellExports,
 'soft_package_references'=>[],'dependency_bundle_entries'=>[]],
];
$id='FEDCBA9876543210';$hash='8877665544332211';
$cell=['type'=>'PackageImport','dependency_class'=>'cell_verse','provider_package_id'=>$id,'provider_public_export_hash'=>$hash];
$import=['type'=>'ScriptImport','dependency_class'=>'script','script_import_hash'=>'0000000000001234'];
$consumer=$snapshot('0123456789ABCDEF',[$import],[],[$cell],[]);
$provider=$snapshot($id,[],[['public_export_hash'=>'1122334455667788','filter_flags'=>0]],[],[['public_export_hash'=>$hash]]);
$context=['package_identity_context_authoritative'=>true,'package_identity_rewrites'=>[],
 'package_store_context_authoritative'=>true,'package_redirects'=>[],'localized_packages'=>[],'with_editor'=>true];
$resolve=static function(string $cls,array $snapshot)use($consumer,$context):array{
 $rows=$cls::resolve($consumer,[['provider_id'=>91,'snapshot'=>$snapshot]],$context);
 foreach($rows as $row)if(($row['source_section']??'')==='cell_imports')return $row;
 throw new RuntimeException('Expected cell import row not returned');
};
$oldCell=$resolve($before,$provider);$newCell=$resolve($after,$provider);
$checks=[
 'before_export_offset_missing_after_present'=>!isset($oldCell['selected_provider_object']['local_export_index'])
   &&($newCell['selected_provider_object']['local_export_index']??null)===1,
 'before_import_offset_missing_after_present'=>!isset($oldCell['source_local_import_index'])
   &&($newCell['source_local_import_index']??null)===1,
];
$duplicate=$provider;$duplicate['sections']['cell_exports'][]=['public_export_hash'=>$hash];
$b=$resolve($before,$duplicate);$a=$resolve($after,$duplicate);
$checks['before_header_candidate_missing_after_preserved']=!isset($b['source_header_first_cell_export'])
 &&($a['source_header_first_cell_export']['local_export_index']??null)===1
 &&($a['source_header_first_cell_export']['runtime_lookup_authoritative']??null)===false
 &&($a['outcome']??null)==='unresolved';
$optional=$provider;$optional['sections']['cell_exports']=[];
$optional['sections']['optional_segment']=[['imports'=>[],'exports'=>[],'cell_imports'=>[],
 'cell_exports'=>[['public_export_hash'=>$hash]],'soft_package_references'=>[],'dependency_bundle_entries'=>[]]];
$b=$resolve($before,$optional);$a=$resolve($after,$optional);
$checks['before_optional_false_resolved_after_unresolved']=($b['outcome']??null)==='resolved'
 &&($a['outcome']??null)==='unresolved'
 &&($a['reason_code']??null)==='optional_segment_public_cell_runtime_registration_required';
echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,
 'baseline_revision'=>'4e4eaf9e','before_optional_outcome'=>$b['outcome']??null,
 'after_optional_outcome'=>$a['outcome']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
