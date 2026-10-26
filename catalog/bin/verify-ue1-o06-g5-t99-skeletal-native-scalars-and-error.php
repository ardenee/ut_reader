#!/usr/bin/env php
<?php
/** Original compiled v432 USkeletalMesh scalar + malformed ref.
 * Runs source PHP only; original Engine/Core DLLs and saved compiled logs
 * never rerun here. Does not promote whole USkeletalMesh yet.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-SKELETAL-NATIVE-SCALAR-AND-ERROR.json'),true,512,JSON_THROW_ON_ERROR);
$checked=0;$errors=[];
$check=static function(string $label,bool $value)use(&$checked,&$errors){$checked++;if(!$value)$errors[]=$label;};
$pin=static function(array $r,string $label)use($check){$p=$r['path'];$check('sha_'.$label,is_file($p)&&hash_file('sha256',$p)===$r['sha256']&&filesize($p)===$r['bytes']);};
$check('method',$m['method']==='T99:USkeletalMesh::Serialize');
$check('source',$m['source_profile']==='ut99-v432');
foreach($m['original_native_DLLs'] as $k=>$p)$pin($p,$k);
foreach(['compiled_probe_source','compiled_probe_exe','positive_php_comparator'] as $key)$pin($m[$key],$key);
foreach($m['originals'] as $index=>$case){
 $pin($case['original'],'original_'.$index);
 $pin($case['native_load'],'native_load_'.$index);
 $output=(string)file_get_contents($case['native_load']['path']);
 $check('compiled_skeletal_original_loaded_'.$index,
    str_contains($output,'SKEL_COMPLETE path='.$case['package'].'.'.$case['object_name'])
    &&str_contains($output,'SKEL_END success=1'));
}
$neg=$m['negative'];
foreach(['original','mutation','compiled_CDB_log','compiled_debugger_script',
 'compiled_exception_disposition','production_rejection_script'] as $key)$pin($neg[$key],'negative_'.$key);
$source=(string)file_get_contents($neg['original']['path']);
$mutated=(string)file_get_contents($neg['mutation']['path']);
$before=(int)$neg['before'];$after=(int)$neg['after'];$off=(int)$neg['byte_offset'];
$check('original_is_correct_pre_mutation_byte',ord($source[$off])===$before);
$source[$off]=chr($after);
$check('mutated_only_one_original_archive_byte',$source===$mutated);
$debug=(string)file_get_contents($neg['compiled_CDB_log']['path']);
if(str_contains(substr($debug,0,32),"\0"))$debug=(string)iconv('UTF-16LE','UTF-8',str_starts_with($debug,"\xff\xfe")?substr($debug,2):$debug);
$check('original_compiled_first_chance_cursor',
  str_contains($debug,'G5_SKELETAL_EXCEPTION_REAL')
  &&str_contains($debug,'LAST_NATIVE_POS='.$neg['first_chance_cursor']));
$check('actual_native_loader_rejects_bad_animation_ref',
  str_contains((string)file_get_contents($neg['compiled_exception_disposition']['path']),'SKEL_PACKAGE_EXCEPTION')
  &&str_contains((string)file_get_contents($neg['compiled_exception_disposition']['path']),'SKEL_END success=0'));
$cmd=static function(string $p):array{
 $pipes=[];$proc=proc_open([PHP_BINARY,$p],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('Cannot open source verifier');
 fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
 return ['stdout'=>$stdout,'stderr'=>$stderr,'exit'=>proc_close($proc)];
};
$p=$cmd($m['positive_php_comparator']['path']);
$positive=json_decode((string)$p['stdout'],true,512,JSON_THROW_ON_ERROR);
$check('positive_current_source_comparator_exit',$p['exit']===0&&$p['stderr']==='');
$check('all_original_native_scalar_matches',
 $positive['ok']&&$positive['passed']===$positive['checks']
 &&$positive['checks']===$m['compiled_positive_scalar_checks']
 &&count($positive['objects'])===5);
$n=$cmd($neg['production_rejection_script']['path']);
$check('current_PHP_rejects_corrupt_DefaultAnimation',
 $n['exit']===0&&$n['stderr']===''
 &&str_contains((string)$n['stdout'],'PHP_REJECTED UT99 v432 invalid DefaultAnimation index'));
$check('correct_native_after_CompactIndex_cursor',
 $neg['first_chance_cursor']===$off+1);
$check('nonempty_remap_native_not_falsely_claimed',
 $m['remap_nonempty_compiled_loaded_case_count']===0 &&$m['method_verdict']==='BLOCKED');
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checked,'passed'=>$checked-count($errors),'failed'=>$errors,
 'original_native_loaded_skeletal_objects'=>count($m['originals']),
 'native_scalar_and_inherited_field_checks'=>$positive['passed'],
 'original_native_scalar_comparisons_verified'=>true,
 'new_native_bad_animation_ref_cursor'=>$neg['first_chance_cursor'],
 'previous_native_skeletal_array_bytes_reused'=>$m['original_native_array_comparison_bytes_reused'],
 'real_nonempty_remap_covered'=>false,'method_decision'=>'BLOCKED',
 'new_native_engine_execution_by_verifier'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
