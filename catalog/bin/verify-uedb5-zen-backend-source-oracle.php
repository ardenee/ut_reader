<?php
declare(strict_types=1);
/**
 * Original B005: independent ordinary FPackageStore backend/status oracle.
 * Pinned Epic source behavior, not an executed editor or hybrid mount emulator.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageStoreBackendArbitrator as Arb;
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
 $checks[$name]=$ok;
 if(!$ok){$failures[]=$name;}
};
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$file=$root.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/PackageStore.cpp';
$source=is_file($file)?file_get_contents($file):false;
$check('b005_pinned_package_store_source_hash',is_string($source)
 &&hash('sha256',$source)==='fb5c1930f5b8e6611392c3009dde16922661c7942f2eaa8d875492c8981f67b9');
$check('b005_epic_none_missing_only_continue',
 is_string($source)&&str_contains($source,'Status == EPackageStoreEntryStatus::None || Status == EPackageStoreEntryStatus::Missing'));
$check('b005_epic_other_statuses_terminal',
 is_string($source)&&preg_match('/if\s*\(!bContinueSearch\)\s*\{[^}]*return Status;/s',$source)===1);
$check('b005_epic_exhausted_status_missing',
 is_string($source)&&str_contains($source,'return EPackageStoreEntryStatus::Missing;'));
$check('b005_epic_priority_descending_mount',
 is_string($source)&&str_contains($source,'LowerBoundBy(Backends, Priority, &FBackendAndPriority::Key, TGreater<>()'));
$check('b005_epic_new_mount_inserted_at_priority_lower_bound',
 is_string($source)&&str_contains($source,'Backends.Insert(MakeTuple(Priority, Backend), Index);'));
$check('b005_hybrid_editor_branch_explicitly_unverified',
 is_string($source)&&str_contains($source,'if (IsRunningHybridCookedEditor())')
 &&str_contains($source,'MakeShared<FHybridPackageStoreBackend>'));
$package='AABBCCDDEEFF0011';
$oracle=static function(array $backends,string $package):array {
 // Epic FPackageStore::Mount lowers by descending priority: at equal
 // priority the most recent backend is inserted before earlier mounts.
 $rows=[];
 foreach($backends as $i=>$b){$b['_test_mount_index']=$i;$rows[]=$b;}
 usort($rows,static fn($a,$b):int=>($b['priority']<=>$a['priority'])
     ?:($b['_test_mount_index']<=>$a['_test_mount_index']));
 foreach($rows as $b){
  $entry=$b['entries'][$package]??null;
  $status=(string)($entry['status']??'None');
  if($status==='None'||$status==='Missing'){continue;}
  return ['status'=>$status,'backend_id'=>$b['backend_id']];
 }
 return ['status'=>'Missing','backend_id'=>null];
};
$make=static fn(string $id,int $priority,string $status):array=>[
 'backend_id'=>$id,'priority'=>$priority,
 'entries'=>[$package=>['status'=>$status,'file_id'=>42]],'redirects'=>[]
];
$cases=[
 ['none_continues',[$make('high',20,'None'),$make('low',10,'Ok')]],
 ['missing_continues',[$make('high',20,'Missing'),$make('low',10,'Ok')]],
 ['pending_terminal',[$make('high',20,'Pending'),$make('low',10,'Ok')]],
 ['notinstalled_terminal',[$make('high',20,'NotInstalled'),$make('low',10,'Ok')]],
 ['ok_terminal',[$make('high',20,'Ok'),$make('low',10,'Pending')]],
 ['both_skip_to_missing',[$make('high',20,'Missing'),$make('low',10,'None')]],
 ['no_backends_missing',[]],
 ['equal_priority_newest_wins',[$make('old',10,'Ok'),$make('new',10,'Pending')]],
 ['descending_priority_not_call_order',[$make('low',1,'Ok'),$make('high',10,'NotInstalled')]],
];
foreach($cases as [$name,$backends]){
 $expected=$oracle($backends,$package);
 $actual=Arb::entry($backends,$package);
 $check('b005_independent_'.$name,
  ($actual['status']??null)===$expected['status']
  &&($actual['backend_id']??null)===$expected['backend_id']);
}
$nested=[['backend_id'=>'nested','priority'=>1,
 'entries'=>[$package=>['status'=>'Pending']],'redirects'=>[]]];
$explicit=[['backend_id'=>'nested','priority'=>1,
 'entries'=>[$package=>['status'=>'Ok']],'redirects'=>[]]];
$check('b005_nested_evidence_preserved',
 Arb::evidencedBackends(['package_store_backend_results'=>$nested],[])===$nested);
$check('b005_top_level_evidence_preserved',
 Arb::evidencedBackends([],['zen_package_store_backend_results'=>$explicit])===$explicit);
$check('b005_matching_context_and_top_level_evidence_allowed',
 Arb::evidencedBackends(['package_store_backend_results'=>$nested],
 ['zen_package_store_backend_results'=>$nested])===$nested);
$conflict=false;try{
 Arb::evidencedBackends(['package_store_backend_results'=>$nested],
  ['zen_package_store_backend_results'=>$explicit]);
}catch(RuntimeException){$conflict=true;}
$check('b005_conflicting_evidence_rejected',$conflict);
$badList=false;try{
 Arb::evidencedBackends(['package_store_backend_results'=>'not-a-list'],[]);
}catch(RuntimeException){$badList=true;}
$check('b005_non_list_evidence_rejected',$badList);
$src=dirname(__DIR__).'/src/Infrastructure/Metadata/';
$selector=(string)file_get_contents($src.'PdoUedb5PhysicalProviderSelector.php');
$rebuilder=(string)file_get_contents($src.'Uedb5DependencyRebuilder.php');
$resolver=(string)file_get_contents($src.'Uedb5Ue5ZenDependencyResolver.php');
$service=(string)file_get_contents($src.'Uedb5GameDependencyPassService.php');
$contextBuilder=(string)file_get_contents($src.'Uedb5ZenEffectivePackageStoreContextBuilder.php');
$check('b005_physical_selector_consults_shared_backend_evidence',
 str_contains($selector,'Uedb5ZenPackageStoreBackendArbitrator::evidencedBackends(')
 &&str_contains($selector,'Uedb5ZenPackageStoreBackendArbitrator::entry('));
$check('b005_dependency_rebuilder_uses_same_backend_evidence',
 str_contains($rebuilder,'Uedb5ZenPackageStoreBackendArbitrator::evidencedBackends('));
$check('b005_dependency_resolver_queries_same_priority_arbitrator',
 str_contains($resolver,'Uedb5ZenPackageStoreBackendArbitrator::entry('));
$check('b005_game_pass_preserves_evidenced_backend_results',
 str_contains($service,"'zen_package_store_backend_results'"));
$check('b005_effective_file_backend_keeps_priority',
 str_contains($contextBuilder,'Uedb5ZenPackageStoreBackendArbitrator::ordered('));
echo json_encode(['ok'=>$failures===[],'read_only'=>true,
 'scope'=>'ordinary non-hybrid FPackageStore backend statuses, mount ordering, selector/rebuilder evidence',
 'hybrid_cooked_editor_case'=>'open_source_inventory',
 'checks'=>$checks,'failures'=>$failures],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
