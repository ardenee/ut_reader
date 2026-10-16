<?php
declare(strict_types=1);
/** B005 complete bounded production callsite inventory + fail-closed controls. */
$root=dirname(__DIR__);
$source=$root.'/src';
$active=[];
foreach(new RecursiveIteratorIterator(
 new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||$file->getExtension()!=='php')continue;
 $relative=str_replace(chr(92),'/',substr($file->getPathname(),strlen($root)+1));
 $code=(string)file_get_contents($file->getPathname());$clean='';
 foreach(token_get_all($code) as $token){
  $clean.=is_array($token)
   &&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)?' ':(
     is_array($token)?$token[1]:$token);
 }
 $active[$relative]=$clean;
}
$checks=[];$fail=[];
$check=static function(string $key,bool $ok)use(&$checks,&$fail){
 $checks[$key]=$ok;if(!$ok)$fail[]=$key;
};
$expected=[
 'PdoUedb5PhysicalProviderSelector.php'=>['evidencedBackends'=>1,'entry'=>1],
 'Uedb5DependencyRebuilder.php'=>['evidencedBackends'=>1],
 'Uedb5Ue5ZenDependencyResolver.php'=>['entry'=>1],
 'Uedb5ZenEffectivePackageStoreContextBuilder.php'=>['ordered'=>1],
];
$actual=[];
foreach($active as $path=>$code){
 foreach(['evidencedBackends','entry','ordered','redirect'] as $method){
  $matches=preg_match_all(
   '/\bUedb5ZenPackageStoreBackendArbitrator\s*::\s*'.$method.'\s*\(/',
   $code);
  if($matches){$actual[basename($path)][$method]=$matches;}
 }
}
ksort($actual);
ksort($expected);
$check('b005_all_direct_production_arbitrator_calls_mapped',$actual===$expected);
$mutated=$actual;
$mutated['UnknownNewProvider.php']['entry']=1;
$check('b005_unmapped_future_caller_fails_inventory',$mutated!==$expected);
$mutated=$actual;
$mutated['PdoUedb5PhysicalProviderSelector.php']['entry']++;
$check('b005_extra_existing_caller_fails_inventory',$mutated!==$expected);
$needed=[
 'PdoUedb5PhysicalProviderSelector.php'=>[
  'package_store_context_authoritative',
  'Uedb5ZenPackageStoreBackendArbitrator::evidencedBackends',
  'Uedb5ZenPackageStoreBackendArbitrator::entry',
  'evidenced_package_store_backend',
  'candidateRows(',
 ],
 'Uedb5DependencyRebuilder.php'=>[
  'Uedb5ZenPackageStoreBackendArbitrator::evidencedBackends',
  'package_store_backend_results',
  'rebuildZen(',
 ],
 'Uedb5Ue5ZenDependencyResolver.php'=>[
  'Uedb5ZenPackageStoreBackendArbitrator::entry',
  'package_store_entry_pending',
  'package_store_entry_not_installed',
  'package_store_evidenced_provider_unavailable',
 ],
 'Uedb5GameDependencyPassService.php'=>[
  'evidence_source','zen_package_store_backends_by_game',
  'package_store_context_authoritative','zen_file_backend_priority_by_game',
  'Uedb5ZenEffectivePackageStoreContextBuilder::build',
  'zen_package_store_backend_results',
 ],
 'Uedb5ZenEffectivePackageStoreContextBuilder.php'=>[
  'Uedb5ZenPackageStoreBackendArbitrator::ordered',
  'package_store_backend_results','package_store_backend_order',
 ],
 'Uedb5ZenMountedStoreContextBuilder.php'=>[
  'package_store_context_authoritative',
  'file_backend_entries',
 ],
];
foreach($needed as $file=>$tokens){
 $path='src/Infrastructure/Metadata/'.$file;
 $body=$active[$path]??'';
 $check('b005_mapped_file_'.pathinfo($file,PATHINFO_FILENAME),$body!=='');
 foreach($tokens as $n=>$token){
  $check('b005_caller_'.pathinfo($file,PATHINFO_FILENAME).'_'.($n+1),
   str_contains($body,$token));
 }
}
echo json_encode([
 'ok'=>$fail===[],'production_php_scanned'=>count($active),
 'actual_calls'=>$actual,'checks'=>$checks,'failures'=>$fail,
 'scope'=>'UE5 package store status/priority including hybrid output as evidenced backend; actual editor asset state is never inferred'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
