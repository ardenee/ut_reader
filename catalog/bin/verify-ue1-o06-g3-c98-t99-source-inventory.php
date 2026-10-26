<?php
/** G3 C98 vs T99 source-navigation and previous evidence HASH reuse only.
 * Does not infer compiled v432 method equivalence or C227 implementation.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);$c=$root.'/catalog/compliance';
$a=json_decode((string)file_get_contents($c.'/UE1-O06-G3-C98-T99-CLASS-PROPERTY-METHOD-DIFF.json'),true,512,JSON_THROW_ON_ERROR);
$m=json_decode((string)file_get_contents($c.'/UE1-O06-G3-ACCEPTANCE-MATRIX.json'),true,512,JSON_THROW_ON_ERROR);
$failed=[];$num=0;
$check=static function(string $id,bool $ok)use(&$failed,&$num):void{++$num;if(!$ok)$failed[]=$id;};
$paths=[
 'C98'=>'L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src',
 'T99_inherited_v400'=>'L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src'];
$source=[];
foreach($a['source_file_sha256'] as $branch=>$files){
 foreach($files as $name=>$hash){
  $f=$paths[$branch].'/'.$name;
  $check('source file SHA '.$branch.' '.$name,
   is_file($f)&&hash_file('sha256',$f)===$hash);
  $source[$branch][$name]=is_file($f)?file_get_contents($f):'';
 }
}
$rows=$a['method_signature_source_lexical_inventory'];
$check('33 classic method lexical signatures',count($rows)===33);
$check('16 symbols present both original source families',$a['counts']['both']===16);
$check('no trivial guaranteed C98 T99 body equivalence',
 $a['counts']['whitespace_identical']===0
 &&$a['counts']['different_or_missing']===33);
foreach($rows as $x){
 $sym=$x['symbol'];
 $check('unique source symbol label '.$sym,
    preg_match('/^U[A-Za-z0-9_]+::[A-Za-z0-9_]+$/',$sym)===1);
 foreach($paths as $branch=>$base){
  $f=$x[$branch.'_implementation_file'];
  $sha=$x[$branch.'_method_source_sha256'];
  $check('source present or absent explicitly '.$branch.' '.$sym,
   ($f===null&&$sha===null)
   ||($f!==null &&isset($source[$branch][$f])
      &&preg_match('/\b'.preg_quote($sym,'/').'\s*\(/',$source[$branch][$f])===1
      &&is_string($sha)&&preg_match('/^[0-9a-f]{64}$/',$sha)===1));
 }
}
// The acceptance runner updates each affected row's last_verification
// and timestamps after every DEV test. Those are test HISTORY, not a
// change to the original serializer source mapping. Hash only stable
// source declarations and retain all immutable class/reader/test fields.
$register=json_decode((string)file_get_contents(
 $c.'/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
unset($register['updated_at_utc']);
foreach($register['entries'] as &$entry){
 unset($entry['updated_at_utc'],$entry['last_verification']);
}
unset($entry);
$stableRegister=json_encode($register,
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
$check('stable original registered-source SHA ignoring only mutable journal history',
 $m['register_immutable_source_mapping_except_test_history_sha256']===hash('sha256',$stableRegister));
$names=0;$ready=0;$logReady=0;
foreach($m['existing_candidate_tests'] as $name=>$t){
 $names++;$f=$root.'/'.$t['path'];
 $script=is_file($f)?hash_file('sha256',$f):'';
 $check('past PASS current test sha '.$name,$script===$t['current_test_sha256']
   &&$t['historical_pass_script_sha_equal']===true);
 $check('prior log SHA verified original '.$name,$t['historical_pass_log_sha_equal']===true);
 if($t['historical_pass_script_sha_equal'])$ready++;
 if($t['historical_pass_log_sha_equal'])$logReady++;
}
$check('no old native replays', $names===24&&$ready===24&&$logReady===24);
$check('C227 separate missing CPP', $m['C227_original_exported_methods_requiring_implementation_or_exclusion']===57
 && $m['required_tests_count']===16);
$ok=!$failed;
echo json_encode(['ok'=>$ok,'checks'=>$num,'passed'=>$num-count($failed),'failed'=>$failed,
 'source_scope'=>'C98 original v1200 C++ versus T99 inherited v400 C++ lexical source navigation; v432 implementation equality not inferred',
 'source_class_methods_indexed'=>count($rows),
 'both_engine_Cpp_bodies_present'=>$a['counts']['both'],
 'C98_T99_body_source_equivalence_claimed'=>false,
 'historical_test_script_and_log_SHA_candidates'=>$names,
 'historical_test_files_or_compiled_native_reexecuted'=>0,
 'C227_compiled_symbol_implementations_unverified'=>57,
 'G3_whole_gate_decision'=>'OPEN'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
