<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
$ref=new ReflectionClass(Uedb5GameDependencyPassService::class);
$method=$ref->getMethod('resolverOptions');
$property=$ref->getProperty('config');
$run=static function(array $config,int $gameId=9001) use($ref,$method,$property):array {
    $service=$ref->newInstanceWithoutConstructor();
    $property->setValue($service,$config);
    return $method->invoke($service,$gameId);
};
$baseline=$run([]);
$other=$run(['zen_runtime_context_by_game'=>[9002=>['package_identity_context_authoritative'=>true,'evidence_source'=>'fixture']]]);
$known=$run(['zen_runtime_context_by_game'=>[9001=>[
 'package_identity_context_authoritative'=>true,
 'package_store_context_authoritative'=>false,
 'evidence_source'=>'isolated synthetic explicit fixture',
 'package_identity_rewrites'=>[],
]]]);
$reject=false;
try {$run(['zen_runtime_context_by_game'=>[9001=>['package_store_context_authoritative'=>true]]]);}
catch(RuntimeException $e){$reject=str_contains($e->getMessage(),'evidence_source');}
$mountContext=$run(['zen_mounted_containers_by_game'=>[9001=>[
 ['container_id'=>'base','order'=>1,'sequence'=>1,
  'package_redirects'=>[['source_package_id'=>'AABBCCDDEEFF0011','target_package_id'=>'1111111111111111']],
  'localized_packages'=>[]],
 ['container_id'=>'patch','order'=>2,'sequence'=>2,
  'package_redirects'=>[['source_package_id'=>'AABBCCDDEEFF0011','target_package_id'=>'2222222222222222']],
  'localized_packages'=>[]],
]]]);
$mountIsolated=$run(['zen_mounted_containers_by_game'=>[9002=>[
 ['container_id'=>'another','order'=>1,'sequence'=>1,'package_redirects'=>[],'localized_packages'=>[]],
]]]);
$combined=$run([
 'zen_mounted_containers_by_game'=>[9001=>[
  ['container_id'=>'file','order'=>1,'sequence'=>0,
   'package_redirects'=>[['source_package_id'=>'AABBCCDDEEFF0011','target_package_id'=>'1111111111111111']],
   'localized_packages'=>[]],
 ]],
 'zen_package_store_backends_by_game'=>[9001=>[
  ['backend_id'=>'higher','priority'=>5,'entries'=>[],
   'redirects'=>['AABBCCDDEEFF0011'=>['source_package_id'=>'AABBCCDDEEFF0011','target_package_id'=>'2222222222222222']]],
 ]],
 'zen_file_backend_priority_by_game'=>[9001=>1],
]);
$authorityWithoutEntriesRejected=false;
try {
 $run([
  'zen_runtime_context_by_game'=>[9001=>[
   'package_store_context_authoritative'=>true,'evidence_source'=>'captured runtime proof']],
  'zen_mounted_containers_by_game'=>[9001=>[
   ['container_id'=>'incomplete','order'=>1,'sequence'=>0,
    'package_redirects'=>[],'localized_packages'=>[]]]],
  'zen_package_store_backends_by_game'=>[9001=>[]],
  'zen_file_backend_priority_by_game'=>[9001=>1],
 ]);
}catch(RuntimeException $e){
 $authorityWithoutEntriesRejected=str_contains($e->getMessage(),'package_entries');
}
$authoritativeEntries=$run([
 'zen_runtime_context_by_game'=>[9001=>[
  'package_store_context_authoritative'=>true,'evidence_source'=>'captured runtime proof']],
 'zen_mounted_containers_by_game'=>[9001=>[
  ['container_id'=>'complete','order'=>1,'sequence'=>0,
   'package_redirects'=>[],'localized_packages'=>[],
   'package_entries'=>[['package_id'=>'AABBCCDDEEFF0011','file_id'=>87]]]]],
 'zen_package_store_backends_by_game'=>[9001=>[]],
 'zen_file_backend_priority_by_game'=>[9001=>1],
]);
$authBackendEntries=$authoritativeEntries['zen_package_store_backend_results']??[];
$authFileEntries=array_values(array_filter($authBackendEntries,
 static fn(array $row):bool=>($row['backend_id']??'')==='file'));
$authOk=($authFileEntries[0]['entries']['AABBCCDDEEFF0011']['file_id']??0)===87
 &&($authoritativeEntries['zen_runtime_context']['package_store_context_authoritative']??false)===true;
$evidencedMount=$run([
 'zen_runtime_context_by_game'=>[9001=>[
  'evidence_source'=>'complete verified runtime capture',
  'package_identity_context_authoritative'=>true,
  'package_store_context_authoritative'=>true,
  'package_identity_rewrites'=>[],
 ]],
 'zen_mounted_containers_by_game'=>[9001=>[
  ['container_id'=>'evidenced','order'=>1,'sequence'=>1,
   'package_redirects'=>[],'localized_packages'=>[]],
 ]],
]);
$partialOverride=$run(['zen_runtime_overrides_by_game'=>[9001=>['with_editor'=>false,'is_server'=>true]]]);
$otherOverride=$run(['zen_runtime_overrides_by_game'=>[9002=>['with_editor'=>true]]]);
$invalidOverrideRejected=false;
try{$run(['zen_runtime_overrides_by_game'=>[9001=>['is_editor'=>'yes']]]);}
catch(RuntimeException){$invalidOverrideRejected=true;}
$authorityOverrideRejected=false;
try{$run(['zen_runtime_overrides_by_game'=>[9001=>['package_store_context_authoritative'=>true]]]);}
catch(RuntimeException){$authorityOverrideRejected=true;}
$mergedOverride=$run([
 'zen_runtime_context_by_game'=>[9001=>['is_editor'=>true]],
 'zen_runtime_overrides_by_game'=>[9001=>['is_editor'=>false]],
]);
$ok=!isset($baseline['zen_runtime_context'])&&!isset($other['zen_runtime_context'])
    &&($known['zen_runtime_context']['package_identity_context_authoritative']??null)===true
    &&($known['zen_runtime_context']['package_store_context_authoritative']??null)===false
    &&$reject
    &&($mountContext['zen_runtime_context']['package_redirects'][0]['target_package_id']??'')==='2222222222222222'
    &&($mountContext['zen_runtime_context']['package_store_context_authoritative']??null)===false
    &&!isset($mountIsolated['zen_runtime_context'])
    &&($combined['zen_runtime_context']['package_redirects'][0]['target_package_id']??'')==='2222222222222222'
    &&($evidencedMount['zen_runtime_context']['package_store_context_authoritative']??false)===true
    &&($evidencedMount['zen_runtime_context']['package_identity_context_authoritative']??false)===true
    &&($partialOverride['zen_runtime_context']['with_editor']??null)===false
    &&($partialOverride['zen_runtime_context']['is_server']??null)===true
    &&!array_key_exists('is_client',$partialOverride['zen_runtime_context'])
    &&!isset($otherOverride['zen_runtime_context'])
    &&($mergedOverride['zen_runtime_context']['is_editor']??null)===false
    &&$authorityWithoutEntriesRejected &&$authOk
    &&$invalidOverrideRejected&&$authorityOverrideRejected;
echo json_encode(['ok'=>$ok,'baseline_unknown'=>$baseline,'other_game_isolation'=>$other,'known_context'=>$known,'unsupported_authority_rejected'=>$reject,'derived_mount_context'=>$mountContext,'mount_isolation'=>$mountIsolated,'backend_merged_context'=>$combined,'evidenced_mount_context'=>$evidencedMount,'partial_override'=>$partialOverride,'invalid_override_rejected'=>$invalidOverrideRejected,'authority_override_rejected'=>$authorityOverrideRejected],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
