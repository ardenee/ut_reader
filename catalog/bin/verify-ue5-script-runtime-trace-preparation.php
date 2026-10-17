<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
$root=dirname(__DIR__,2);
$tool=$root.'/catalog/bin/prepare-ue5-script-runtime-trace.php';
$sourceRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$relative='Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$source=$sourceRoot.'/'.$relative;
$check=[];$failed=[];$detail=[];
$assert=static function(string $key,bool $ok)use(&$check,&$failed):void{
 $check[$key]=$ok;
 if(!$ok)$failed[]=$key;
};
$temp=sys_get_temp_dir().'/uedb5-ue5-runtime-probe-check-'.bin2hex(random_bytes(5));
$originalSha=is_file($source)?hash_file('sha256',$source):'';
$assert('trace_pinned_original_ue583_sha',
 $originalSha==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$assert('trace_generator_has_no_apply_option',
 str_contains((string)file_get_contents($tool),'Unknown option:')
 &&!str_contains((string)file_get_contents($tool),'--apply='));
$created=[];
try{
 if(!mkdir($temp,0700,true))throw new RuntimeException('Unable to create isolated trace-test directory');
 $run=static function(array $command)use(&$detail):array {
  $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($proc))throw new RuntimeException('Unable to run generator or git test');
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
  $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
  return [proc_close($proc),(string)$out,(string)$err];
 };
 [$exit,$out,$err]=$run([PHP_BINARY,$tool,'--output-dir='.$temp]);
 $data=json_decode($out,true);
 $assert('trace_generator_success_and_machine_readable_manifest',$exit===0
  &&is_array($data)&&($data['ok']??false)===true);
 if(!is_array($data))throw new RuntimeException('Missing generated data: '.substr($err,0,200));
 $candidate=$temp.'/AsyncLoading2.instrumented.cpp';
 $patch=$temp.'/AsyncLoading2.script-runtime-probe.patch';
 $manifest=$temp.'/script-runtime-probe-manifest.json';
 $manifestData=is_file($manifest)?json_decode((string)file_get_contents($manifest),true):null;
 $assert('trace_exact_15_named_source_hooks',count($data['injections']??[])===15
  &&count(array_unique($data['injections']))===15);
 $assert('trace_generator_provenance_explicitly_not_runtime_evidence',
  ($data['is_actual_ue_runtime_trace']??true)===false
  &&($data['epic_source_modified']??true)===false
  &&($data['original_source_sha256']??null)===$originalSha);
 $assert('trace_artifacts_and_sha256_in_manifest',
  is_file($candidate)&&is_file($patch)&&is_array($manifestData)
  &&hash_file('sha256',$candidate)===($data['proposed_source_sha256']??null)
  &&$manifestData===$data);
 $instrumented=(string)file_get_contents($candidate);
 $assert('trace_hooks_include_registration_lookup_gc_debug_and_verse',
  str_contains($instrumented,'UEDB_RT|event=lookup|')
  &&str_contains($instrumented,'UEDB_RT|event=add_cell|')
  &&str_contains($instrumented,'UEDB_RT|event=remove_gc|')
  &&str_contains($instrumented,'UEDB_RT|event=name|branch=cooked_diagnostic|')
  &&str_contains($instrumented,'UEDB_RT|event=registration_complete|')
  &&str_contains($instrumented,'UEDB_RT|event=excluded|reason=verse_vni_non_uht|'));
 $patchContents=(string)file_get_contents($patch);
 $assert('trace_git_patch_uses_ue_relative_paths',
  str_contains($patchContents,'diff --git a/'.$relative.' b/'.$relative)
  &&str_contains($patchContents,'+++ b/'.$relative)
  &&!str_contains($patchContents,$temp)
  &&!str_contains($patchContents,$sourceRoot));
 $scratch=$temp.'/verify-root/'.$relative;
 if(!is_dir(dirname($scratch))&&!mkdir(dirname($scratch),0700,true))
  throw new RuntimeException('Unable to create disposable source test hierarchy');
 if(!copy($source,$scratch))throw new RuntimeException('Unable to copy original source fixture');
 [$preStatus,, $preError]=$run(['git','-C',$temp.'/verify-root','apply','--check',$patch]);
 $assert('trace_generated_patch_applies_to_unmodified_epic_source',
  $preStatus===0);
 [$applied,, $applyError]=$run(['git','-C',$temp.'/verify-root','apply',$patch]);
 $assert('trace_patch_applies_without_conflict',$applied===0);
 $patched=(string)file_get_contents($scratch);
 $assert('trace_git_patch_content_matches_instrumented_copy_after_crlf_normalization',
  hash('sha256',str_replace("\r\n","\n",$patched))
   ===hash('sha256',str_replace("\r\n","\n",$instrumented)));
 $assert('trace_original_epic_source_not_modified',
  hash_file('sha256',$source)===$originalSha);
 [$again,$againOut,$againErr]=$run([PHP_BINARY,$tool,'--output-dir='.$temp]);
 $againData=json_decode($againOut,true);
 $assert('trace_generation_is_deterministic_and_repeatable',
  $again===0 && ($againData['proposed_source_sha256']??null)===$data['proposed_source_sha256']
  &&hash_file('sha256',$candidate)===$data['proposed_source_sha256']);
 [$refuse,$refuseOut,$refuseErr]=$run([PHP_BINARY,$tool,'--output-dir='.$sourceRoot.'/Engine/Source/Runtime/CoreUObject']);
 $assert('trace_cannot_write_into_epic_source_directory',
  $refuse!==0 &&str_contains($refuseOut.$refuseErr,'Output directory must not be within the Epic source checkout'));
 $missing=$temp.'/no-such-source';
 putenv('UNREALDB_EPIC_SOURCE_ROOT='.$missing);
 [$bad,$badOutput,$badError]=$run([PHP_BINARY,$tool,'--output-dir='.$temp]);
 putenv('UNREALDB_EPIC_SOURCE_ROOT='.$sourceRoot);
 $assert('trace_rejects_unpinned_or_absent_epic_source',
  $bad!==0 &&str_contains($badOutput.$badError,'does not match the pinned original'));
 $assert('trace_verifier_denominator_and_runtime_authority_unchanged',
  count($data['operation_scope']??[])===4
  &&($data['epic_source_modified']??true)===false);
 $detail['generated_patch_bytes']=strlen($patchContents);
 $detail['source_normalized_sha256']=hash('sha256',str_replace("\r\n","\n",$patched));
 $detail['candidate_sha256']=$data['proposed_source_sha256']??null;
 $detail['apply_errors']=trim($preError.$applyError);
} catch(Throwable $e) {
 $failed[]='trace_exception';
 $detail['exception']=$e->getMessage();
} finally{
 $cleanup=static function(string $dir)use(&$cleanup):void{
  if(!is_dir($dir))return;
  foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $item){
   if($item->isDir())$cleanup($item->getPathname());
   else @unlink($item->getPathname());
  }
  @rmdir($dir);
 };
 $cleanup($temp);
}
echo json_encode([
 'ok'=>$failed===[], 'operations'=>[
  'UE5-583-SCRIPT-003','UE5-583-SCRIPT-004','UE5-583-SCRIPT-005','UE5-583-B015-1'],
 'disposition'=>'partial',
 'checks'=>$check,'failures'=>$failed,'details'=>$detail,
 'actual_epic_engine_runtime_executed'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
exit($failed===[]?0:1);
