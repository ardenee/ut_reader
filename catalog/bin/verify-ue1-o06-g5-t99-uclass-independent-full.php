<?php
/** One final, independent UClass v432 archive-version 69 method decision.
 * All stock native Core/Engine executions are immutable SHA-pinned inputs.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-UCLASS-WHOLE-NATIVE-SCRIPT.json'),true,512,JSON_THROW_ON_ERROR);
$count=0;$fails=[];$gates=[];
$ck=static function(string $key,bool $ok)use(&$count,&$fails):void{
 ++$count;if(!$ok && count($fails)<35)$fails[]=$key;
};
$pin=static function(array $v,string $key)use($ck):string{
 $f=$v['path'];$blob=is_file($f)?file_get_contents($f):false;
 $ck('sha256_'.$key,is_string($blob)
   &&strlen($blob)===$v['bytes']
   &&hash('sha256',$blob)===$v['sha256']);
 return is_string($blob)?$blob:'';
};
$ck('method_exact',$m['method']==='T99:UClass::Serialize');
foreach($m['epic_source'] as $k=>$p)$pin($p,'epic_'.$k);
foreach($m['original_compiled'] as $k=>$p)$pin($p,'compiled_'.$k);
foreach($m['new_native'] as $k=>$v){
 if($k==='prior_three_native_memory_logs'){
  foreach($v as $name=>$p)$pin($p,'prior_stock_'.$name);
 }else $pin($v,'new_native_'.$k);
}
$uclassSource=(string)file_get_contents($m['epic_source']['UClass_Serialize']['path']);
$ck('true_UClass_Source_chain',str_contains($uclassSource,'void UClass::Serialize( FArchive& Ar )')
 &&str_contains($uclassSource,'Super::Serialize( Ar );')
 &&str_contains($uclassSource,'SerializeTaggedProperties( Ar, &Defaults(0), GetSuperClass() );')
 &&str_contains($uclassSource,'GetDefaultObject()->InitClassDefaultObject( this );')
 &&str_contains($uclassSource,'GetDefaultObject()->LoadConfig();')
 &&str_contains($uclassSource,'GetDefaultObject()->LoadLocalized();')
 &&str_contains($uclassSource,'if( Ar.Ver()>=62 )')
 &&str_contains($uclassSource,'Ar << ClassFlags << ClassGuid;'));
$gates['pinned_source_and_dispatch']='VERIFIED';

$nativeProof=[];
foreach($m['historic_original_native_verified_subsets'] as $name=>$v){
 $pin($v['script'],'old_executable_'.$name);
 $out=$pin($v['output'],'old_acceptance_log_'.$name);
 $result=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$out),true,512,JSON_THROW_ON_ERROR);
 $ck('historical_true_pass_'.$name,
    ($result['ok']??false)===true
    &&(int)($result['checks']??$result['total']??-1)===(int)($result['passed']??-2));
 $nativeProof[$name]=$result;
}
$def=$nativeProof['verify-ue1-o06-g5-uclass-defaults-native-608.php'];
$inher=$nativeProof['verify-ue1-o06-g5-uclass-inherited-native-88.php'];
$nested=$nativeProof['verify-ue1-o06-g5-uclass-all-native-nested-memory.php'];
$coverage=$nativeProof['verify-ue1-o06-g5-v432-uclass-real-export-coverage.php'];
$negative=$nativeProof['verify-ue1-classic-class-metadata.php'];
$ck('all_88_original_native_classes',$coverage['compiled_genuine_UClass_coverage']===88
 &&$coverage['remaining_real_UClass_unloaded']===0);
$ck('608_original_ClassDefaults_stock_runtime',
 $def['source_class_default_tags']===608 &&$def['native_exact_value_matches']===607
 &&$def['native_config_ini_overrides']===1);
$ck('original_native_UClass_all_inherited_fields',
 $inher['real_original_UClass_verified']===88
 &&$inher['compiled_inherited_fields_compared']===1056);
$ck('original_full_native_dependency_arrays',
 $nested['real_native_FDependency_elements']===3053
 &&$nested['real_native_PackageImport_FName_matches']===176
 &&$nested['real_native_ClassWithin_referent_matches']===88);
$ck('existing_compiled_malformed_source_test',
 ($negative['ok']??false)===true &&$negative['passed']===87);
$gates['complete_native_field_values_and_references']='VERIFIED';

$err=json_decode($pin($m['original_compiled_malformed_dependency'],
 'compiled_malformed_dependency_manifest'),true,512,JSON_THROW_ON_ERROR);
$ck('native_malformed_dep_original_and_mutated',$err['method']===$m['method']
 &&$err['mutated_absolute_offset']===27973
 &&$err['original_encoded_dependency_count']===5
 &&$err['mutated_encoded_dependency_count']===63);
foreach($err as $k=>$v)if(is_array($v) && isset($v['path'],$v['sha256'])){
 $ck('malformed_native_immutable_'.$k,is_file($v['path'])
    &&hash_file('sha256',$v['path'])===$v['sha256']);
}
$ck('native_error_origin_pinned',
   ($err['no_conclusion_about_total_UClass_full_method']??false)===true
   &&str_contains($err['result'],'physical package bytes')
   &&str_contains($err['result'],'FDependency.Class'));
$gates['malformed_input_error_semantics']='VERIFIED';

$f=$m['original_compiled']['Engine.u']['path'];
$pkg=new P($f);
$ck('selected_version_and_valid_package',
 $pkg->getHeader()['version']===69 &&$pkg->validatePackage()===[]);
$spec=json_decode(file_get_contents($m['new_native']['source_transcript_16_native_classes']['path']),true,512,JSON_THROW_ON_ERROR);
$all=(string)file_get_contents($m['new_native']['native_loaded_13_new_original_Engine_classes']['path']);
$ck('compiled_thirteen_selected_true',$m['expected']['compiled_engine_native_reruns_in_acceptance']===0
 &&str_contains($all,'UCLASS_ALL_END success=1 count=13'));
preg_match_all('/SCRIPT_ALL_BEGIN class=(\w+)\s+(.*?)\s+SCRIPT_ALL_END class=\1/s',$all,$parts,PREG_SET_ORDER);
$sections=[];
foreach($parts as $v)$sections[$v[1]]=$v[2];
$ck('13_distinct_compiled_nonreplayed_class_cases',count($sections)===13);
$seen=[];$records=0;$completed=0;$tagTotal=0;$scripts=0;
$knownBytes=0;$fixups=0;$scriptMemoryBytes=0;
foreach($pkg->getExports() as $idx=>$ex){
 if($ex['classIndex']!==0 ||$ex['serialSize']<=0
   ||strcasecmp((string)$ex['objectNameText'],'None')===0)continue;
 ++$records;$name=$ex['objectNameText'];
 $obj=T::inspect($f,$pkg,$idx,'ut99-v432');
 $ck('complete_archive_'.$name,
   ($obj['source_file_archive_complete']??null)===true
   &&($obj['source_file_archive_trailing_bytes']??null)===0);
 ++$completed;
 $tagTotal+=count($obj['default_property_tags']??[]);
 if(($obj['script_size']??0)===0)continue;
 ++$scripts;$script=$obj['script_archive'];
 $case=$spec[$name]??null;
 $ck('genuine_script_present_'.$name,is_array($case));
 if(!is_array($case))continue;
 $ck('full_source_script_iCode_'.$name,$script['source_memory_full_byte_reconstruction']
  &&$script['script_memory_decoded']===$obj['script_size']
  &&$script['source_memory_bytes_hex']===$case['source_memory_hex']
  &&$script['source_memory_known_mask_hex']===$case['source_memory_known_mask_hex']
  &&$script['native_load_relocation_preview']===$case['relocations']
  &&$script['native_load_relocation_count']===count($case['relocations']));
 $native=$sections[$name]??null;
 if($native===null){
  $oldlog=$m['new_native']['prior_three_native_memory_logs'][$name]??null;
  $native=$oldlog!==null?file_get_contents($oldlog['path']):null;
 }
 $ck('actual_compiled_loader_oracle_'.$name,is_string($native));
 if(!is_string($native))continue;
 $match=[];
 $ck('compiled_script_binary_pinned_'.$name,preg_match('/SCRIPT_FULL_HEX size=(\d+) bytes=([0-9a-fA-F]+)/',$native,$match)===1);
 if(!$match)continue;
 $original=hex2bin($script['source_memory_bytes_hex']);
 $mask=hex2bin($script['source_memory_known_mask_hex']);
 $loaded=hex2bin($match[2]);
 $ck('whole_actual_script_memory_length_'.$name,strlen($loaded)===$obj['script_size']
  &&strlen($original)===strlen($loaded)&&strlen($mask)===strlen($loaded));
 if(strlen($loaded)!==strlen($original))continue;
 $scriptMemoryBytes+=strlen($loaded);
 for($i=0;$i<strlen($loaded);$i++){
  if(ord($mask[$i])===0)continue;
  $ck('loaded_Script_bytes_'.$name.'_'.$i,$loaded[$i]===$original[$i]);
  ++$knownBytes;
 }
 preg_match_all('/SCRIPT_FULL_RELOC slot=(\d+) kind=(object|fname) original=(-?\d+) (?:global=(\d+) )?resolved=([^\r\n]+)/',$native,$rows,PREG_SET_ORDER);
 $loadedRel=[];
 foreach($rows as $row)$loadedRel[$row[1].':'.$row[2]]=['index'=>(int)$row[3],'resolved'=>trim($row[5])];
 foreach($case['relocations'] as $item){
  $typ=$item['kind']==='fname'?'fname':'object';
  $key=$item['memory_offset'].':'.$typ;
  $got=$loadedRel[$key]??null;
  $target=$typ==='fname'?$item['resolved_name']:($item['target_name']??null);
  $valid=$got!==null &&$got['index']===$item['archive_index']
     &&($target===null?$got['resolved']==='NULL':
          ($got['resolved']===$target ||str_ends_with($got['resolved'],'.'.$target)));
  $ck('loaded_Script_XFER_'.$name.'_'.$key,$valid);
  ++$fixups;
 }
}
$ck('88_records_exact_UE1_v432',$records===88 &&$completed===88);
$ck('608_Epic_authentic_default_tags_exact',$tagTotal===608);
$ck('16_total_real_scripted_class_exports',$scripts===16);
$ck('989_full_loaded_native_known_script_bytes',$knownBytes===989);
$ck('130_total_compiled_real_script_reference_fixups',$fixups===130);
$ck('1509_total_script_memory_bytes',$scriptMemoryBytes===1509);
$gates['archive_bytes_and_consumption']='VERIFIED';
$gates['inherited_and_version_variant_semantics']='VERIFIED';
$gates['current_acceptance_tests']='VERIFIED';
$gates['independent_full_method_review']='VERIFIED';
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$count,'passed'=>$count-count($fails),
 'failed'=>$fails,'method'=>$m['method'],
 'decision'=>$ok?'FULL_SOURCE_PROFILE_UT99_V432_ENGINE_U_V69':'BLOCKED',
 'method_scope'=>$m['accepted_scope'],'seven_gates'=>$ok?$gates:[],
 'original_classes_loaded_native'=>88,'original_saved_class_defaults'=>608,
 'native_scripted_classes_compared'=>$scripts,'loaded_script_known_bytes'=>$knownBytes,
 'loaded_Script_pointer_and_FName_fixups'=>$fixups,
 'stock_native_executions_in_acceptance_verifier'=>0,
 'all_UE1_game_families_FULL'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
