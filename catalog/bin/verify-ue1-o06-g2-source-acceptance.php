#!/usr/bin/env php
<?php
/** Independent May/July 1997 G2 source/archive acceptance.
 * Reuses EXACT current-SHA prior DEV script results and build-selected
 * original C++ branches. Does not test stock original compiled runtime.
 */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$c=$root.'/catalog/compliance';
$matrix=json_decode(file_get_contents($c.'/UE1-O06-G2-ACCEPTANCE-MATRIX.json'),true,512,JSON_THROW_ON_ERROR);
$register=json_decode(file_get_contents($c.'/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$plan=file_get_contents($c.'/UE1-O06-G2-ACCEPTANCE-PLAN.md');
$events=[];
foreach(file($c.'/UE1-O06-TEST-EVENTS.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $raw)
    $events[]=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$raw),true,512,JSON_THROW_ON_ERROR);
$results=[];$n=0;$fail=[];
$check=static function(string $id,string $key,bool $yes)use(&$results,&$n,&$fail):void{
 ++$n;$results[$id]['checks']++;
 if(!$yes){$fail[]="$id:$key";$results[$id]['failed'][]=$key;}
};
for($i=1;$i<=18;$i++)$results[sprintf('G2-%02d',$i)]=[
 'checks'=>0,'failed'=>[],'status'=>'NOT_EVALUATED'];
$rootSources=$matrix['source_builds'];
$sourcePin=[];
foreach($rootSources as $branch=>$src){
 $dsp=$src.'/Engine/Engine.dsp';
 $unobj=$src.'/Engine/UnObj.cpp';
 $unres=$src.'/Engine/UnRes.cpp';
 $s=@file_get_contents($dsp);
 $check('G2-01',$branch.'_selected_DSP',is_string($s)
     &&preg_match('/SOURCE=\.[\\\\\/]+UnObj\.cpp/i',$s)===1
     &&preg_match('/SOURCE=\.[\\\\\/]+UnRes\.cpp/i',$s)!==1);
 foreach([$dsp,$unobj] as $f){
   $valid=is_file($f)&&is_readable($f);
   $check('G2-01',$branch.'_source_exists_'.basename($f),$valid);
   if($valid)$sourcePin[$branch][basename($f)]=hash_file('sha256',$f);
 }
 if($branch==='U86')$check('G2-01','may_unbuilt_duplicate_present',is_file($unres));
}
$mapped=[];
foreach($register['entries'] as $x)if(in_array($x['branch'],['U86','U97'],true))
    $mapped[$x['id']]=$x;
$seen=[];
foreach($matrix['source_class_matrix'] as $row){
 $id=$row['register_id'];$branch=$row['source_branch'];
 $check('G2-02','one_unique_mapping_'.$id,!isset($seen[$id]));
 $seen[$id]=true;
 $current=$mapped[$id]??null;
 $check('G2-02','registered_'.$id,is_array($current)
  &&$current['symbol']===$row['registered_symbol']
  &&$current['status']===$row['register_mapping_status']);
 $testFile=$root.'/catalog/bin/'.$row['existing_candidate_test'];
 $check('G2-18','frozen_current_candidate_script_sha_'.$id,
   is_file($testFile)&&hash_file('sha256',$testFile)
       ===$row['candidate_test_script_sha256_current']);
 $reader=$row['production_reader']??'';
 $check('G2-02','reader_test_route_'.$id,
     is_file($testFile)
     &&($row['register_mapping_status']==='EXCLUDED'
      ?$reader===''||$reader==='-'
      :is_string($reader)&&$reader!==''&&is_file($root.'/catalog/src/Infrastructure/Readers/'.basename($reader))));
 $check('G2-02','source_declared_in_original_tree_'.$id,
     isset($current['locations'])
     &&count(array_filter($current['locations'],
         static fn(array $v):bool=>isset($v['path'])
         &&is_file($rootSources[$branch].'/'.$v['path'])))>=1);
}
$check('G2-02','98_source_registration_pairs',count($seen)===98&&count($mapped)===98);
$check('G2-02','86_mapped_and_12_excluded',
 count(array_filter($mapped,static fn($v)=>$v['status']==='MAPPED'))===86
 &&count(array_filter($mapped,static fn($v)=>$v['status']==='EXCLUDED'))===12);
$testNameMap=[
 'G2-01'=>['verify-ue1-o06-1997-build-selected-source.php'],
 'G2-02'=>['verify-ue1-o06-g2-shared-transient.php','verify-ue1-o06-g2-camera-transient.php','verify-ue1-original-unrealfile-inheritance.php'],
 'G2-03'=>['verify-ue1-original-unrealfile-metadata.php','verify-ue1-original-unrealfile-object-bases.php'],
 'G2-04'=>['verify-ue1-o06-g2-rotation-bin-lineage.php','verify-ue1-o06-g2-stacknode-may-july.php','verify-ue1-original-unrealfile-script-may-july.php'],
 'G2-05'=>['verify-ue1-original-unrealfile-object-bases.php','verify-ue1-original-unrealfile-class-schemas.php','verify-ue1-original-unrealfile-properties.php','verify-ue1-original-unrealfile-external-schemas.php'],
 'G2-06'=>['verify-ue1-original-unrealfile-inheritance.php','verify-ue1-original-unrealfile-external-schemas.php'],
 'G2-07'=>['verify-ue1-o06-g2-rotation-bin-lineage.php','verify-ue1-original-unrealfile-external-schemas.php','verify-ue1-original-unrealfile-properties.php'],
 'G2-08'=>['verify-ue1-original-unrealfile-expression-token-inventory.php','verify-ue1-original-unrealfile-script-expressions.php','verify-ue1-original-unrealfile-script-may-july.php'],
 'G2-09'=>['verify-ue1-o06-g2-stacknode-may-july.php','verify-ue1-original-unrealfile-stacktree-data.php'],
 'G2-10'=>['verify-ue1-original-unrealfile-database-records.php'],
 'G2-11'=>['verify-ue1-o06-1997-bounds-raw-byte.php','verify-ue1-original-unrealfile-mesh-records.php'],
 'G2-12'=>['verify-ue1-original-unrealfile-bit-archives.php'],
 'G2-13'=>['verify-ue1-original-unrealfile-bsp-records.php','verify-ue1-original-unrealfile-polys-data.php','verify-ue1-original-unrealfile-lightmesh-data.php'],
 'G2-14'=>['verify-ue1-original-unrealfile-model.php','verify-ue1-original-unrealfile-mesh.php','verify-ue1-original-unrealfile-level.php','verify-ue1-original-unrealfile-primitive.php'],
 'G2-15'=>['verify-ue1-original-unrealfile-texture.php','verify-ue1-original-unrealfile-special-headers.php'],
 'G2-16'=>['verify-ue1-original-unrealfile-audio.php'],
 'G2-17'=>['verify-ue1-original-unrealfile-metadata.php','verify-ue1-original-unrealfile-bsp-records.php','verify-ue1-original-unrealfile-model.php','verify-ue1-original-unrealfile-script-expressions.php','verify-ue1-original-unrealfile-bit-archives.php'],
];
$allTests=[];
foreach($testNameMap as $id=>$names)foreach($names as $name){
 $allTests[$name]=true;
 $f=$root.'/catalog/bin/'.$name;
 $same=is_file($f);
 $last=null;
 foreach($events as $ev)if(($ev['test']??null)===$name &&($ev['target']??null)==='DEV')
     $last=$ev;
 $check($id,'latest_DEV_PASS_'.$name,$same&&is_array($last)
   &&($last['state']??null)==='PASS'
   &&($last['test_sha256']??null)===hash_file('sha256',$f));
 if(is_array($last)&&($last['state']??null)==='PASS'){
   $log=$root.'/'.str_replace('\\','/',$last['log']);
   $check($id,'original_output_SHA_'.$name,is_file($log)
     &&hash_file('sha256',$log)===$last['output_sha256']);
   $result=is_file($log)?json_decode(preg_replace('/^\xEF\xBB\xBF/','',(string)file_get_contents($log)),true):null;
   $check($id,'positive_negative_dev_results_'.$name,is_array($result)
      &&($result['ok']??null)!==false
      &&empty($result['failures']??$result['failed']??[]));
 }
}
// Every concrete serializer group needs an archived malformed/error fixture
// from its assigned source-typed reader, rather than relying on generic
// packet metadata failure to cover all native serializers.
foreach($matrix['serializer_groups'] as $group){
 if($group['id']==='G2-02_source_exclusions')continue;
 $per=[];
 foreach($matrix['source_class_matrix'] as $row){
   if($row['serializer_group']===$group['id'])$per[$row['existing_candidate_test']]=true;
 }
 $hasMalformed=false;
 foreach(array_keys($per) as $name){
   $source=@file_get_contents($root.'/catalog/bin/'.$name);
   if(is_string($source)&&preg_match('/invalid|truncat|reject|bad_|negative|mismatch|out_of_bounds/i',$source)){
      $hasMalformed=true;break;
   }
 }
 $check('G2-17','one_archived_negative_each_native_serializer_'.$group['id'],
    $hasMalformed);
}
$allMatrixTests=array_unique(array_column($matrix['source_class_matrix'],'existing_candidate_test'));
foreach($allMatrixTests as $name)$allTests[$name]=true;
foreach($allTests as $name=>$_){
 $last=null;foreach($events as $ev)
   if(($ev['test']??'')===$name&&($ev['target']??null)==='DEV')$last=$ev;
 $check('G2-18','matrix_test_latest_PASS_'.$name,
   is_array($last)&&($last['state']??null)==='PASS'
   &&is_file($root.'/catalog/bin/'.$name)
   &&$last['test_sha256']===hash_file('sha256',$root.'/catalog/bin/'.$name));
}
$may=$rootSources['U86'];$jul=$rootSources['U97'];
$sourceText=static fn($base,$f)=>(string)file_get_contents($base.'/'.$f);
$a=$sourceText($may,'Inc/UnMath.h');$b=$sourceText($jul,'Inc/UnMath.h');
$check('G2-07','May_3_WORD_FRotation',str_contains($a,'WORD wPitch')&&str_contains($a,'WORD wYaw')&&str_contains($a,'WORD wRoll'));
$check('G2-07','July_int_FRotation',str_contains($b,'R.Pitch')&&str_contains($b,'R.Yaw')&&str_contains($b,'R.Roll'));
$check('G2-14','May_level_Misc_only',str_contains($sourceText($may,'Inc/UnLevel.h'),'AR_OBJECT(Misc)')
  &&!str_contains($sourceText($jul,'Inc/UnLevel.h'),'AR_OBJECT(Misc)'));
$check('G2-09','source_distinct_stack',str_contains($sourceText($may,'Inc/UnScript.h'),'FStackNodeLink')
 &&str_contains($sourceText($jul,'Inc/UnScript.h'),'FStackNodePtr'));
$check('G2-13','both_BSP_source_present',str_contains($sourceText($may,'Inc/UnObj.h'),'FBspNode')
 &&str_contains($sourceText($jul,'Inc/UnObj.h'),'FBspNode'));
$check('G2-15','both_texture_derived_archive',str_contains($sourceText($may,'Inc/UnObj.h'),'UTexture')
 &&str_contains($sourceText($jul,'Inc/UnObj.h'),'UTexture'));
$mayBit=$sourceText($may,'Engine/UnObj.cpp');$julBit=$sourceText($jul,'Engine/UnObj.cpp');
$normalize=static function(string $s):string {
 $start=strpos($s,'void UBitArray::SerializeData(');
 if($start===false)return '';
 $end=strpos($s,'/*-----------------------------------------------------------------------------',$start);
 return preg_replace('/\s+/','',substr($s,$start,$end-$start));
};
$check('G2-12','May_July_bitRLE_semantically_identical', $normalize($mayBit)===$normalize($julBit));
foreach($matrix['gate_acceptance_contracts'] as $id)$check('G2-18','plan_contract_present_'.$id,str_contains($plan,'**'.$id.'**'));
$check('G2-18','source_reg_frozen_classes',count($seen)===98);
$check('G2-18','explicit_other_engine_scope',str_contains($plan,'G5 runtime/native original-game loaded-value certification'));
foreach($results as $id=>&$v) $v['status']=empty($v['failed'])?'VERIFIED':'BLOCKED';
unset($v);
$ok=empty($fail);
$proof=['ok'=>$ok,'decision'=>$ok?'G2_ACCEPTED_SOURCE_ARCHIVE_PROFILE_ONLY':'G2_BLOCKED',
 'scope'=>'May v0.86x/July v0.867 build-selected original UE1 Unrealfile v22/23 source and source-derived archive values; excludes missing original native runtime/genuine-all-assets oracles',
 'total_checks'=>$n,'passed'=>$n-count($fail),'failed'=>$fail,
 'contracts'=>$results,
 'registered_source_pairs'=>count($seen),'mapped_source_pairs'=>86,'source_exclusions'=>12,
 'unique_existing_test_proofs'=>count($allTests),
 'source_build_sha256'=>$sourcePin,
 'actual_original_native_loaded_values_certified'=>false,
 'g5_v432_method_results_modified'=>false,
 'other_engine_families_certified'=>false];
echo json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
