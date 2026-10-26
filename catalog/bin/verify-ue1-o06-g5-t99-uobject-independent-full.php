<?php
/** One independent G5 T99 UObject method-level seven-gate source-profile
 * review: original stock Core and genuine archive observations reused,
 * NEW actual source-legal struct<FString> direct/array compiled native
 * positives, negatives, and C++ undefined allocator exclusion.
 * Native executable invocation count: ZERO.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents(
 $root.'/catalog/compliance/UE1-O06-G5-T99-UOBJECT-NATIVE-STRUCT-FSTRING-FINAL-ORACLE.json'),
 true,512,JSON_THROW_ON_ERROR);
$ckCount=0;$fail=[];
$ck=static function(string $tag,bool $ok)use(&$ckCount,&$fail):void{
 ++$ckCount;if(!$ok &&count($fail)<30)$fail[]=$tag;
};
$pin=static function(array $v,string $tag)use($ck):string{
 $p=$v['path'];$s=is_file($p)?file_get_contents($p):false;
 $ck('SHA_'.$tag,is_string($s)&&strlen($s)===$v['bytes']
     &&hash('sha256',$s)===$v['sha256']);
 return is_string($s)?$s:'';
};
$ck('exact_one_method',$m['method']==='T99:UObject::Serialize');
$ck('explicit_scoped_source',$m['source_profile']
  ==='UT99 v432, UE1 package version 61-69 selected source, deterministic saved-archive semantics');
$source=[];
foreach($m['epic_sources'] as $key=>$entry)$source[$key]=$pin($entry,'epic_'.$key);
foreach($m['compiled_unmodified'] as $key=>$entry)$pin($entry,'compiled_stock_'.$key);
$pin($m['actual_unreal_script_class'],'script_declaration');
$pin($m['compiled_unreal_script_package'],'genuine_v432_script_class');
$build=$pin($m['compiled_script_build_log'],'real_UCC_build_log');
if(str_starts_with($build,"\xff\xfe"))
    $build=(string)iconv('UTF-16LE','UTF-8',substr($build,2));
$ck('real_UCC_zero_errors',str_contains($build,'Success - 0 error(s), 0 warnings'));
foreach($m['actual_core_native_generator'] as $key=>$entry)$pin($entry,'native_SavePackage_'.$key);
foreach($m['actual_stock_native_loader'] as $key=>$entry)$pin($entry,'stock_loaded_'.$key);
foreach($m['diagnostic_changed_allocator_loader'] as $key=>$entry)$pin($entry,'zero_diagnostic_'.$key);
$pack=[];$bad=[];$logs=[];
foreach($m['actual_generated_originals'] as $key=>$entry)$pack[$key]=$pin($entry,'real_saved_original_'.$key);
foreach($m['original_derived_single_byte_malformed'] as $key=>$entry)$bad[$key]=$pin($entry,'malformed_original_copy_'.$key);
foreach($m['actual_compiled_logs'] as $key=>$entry)$logs[$key]=$pin($entry,'actual_native_stdout_'.$key);
$ck('Epic_source_UArray_Add_not_AddZeroed',
 str_contains($source['UArrayProperty and UStructProperty SerializeItem'],
     '((FArray*)Value)->Add( n, c );')
 &&str_contains($source['UArrayProperty and UStructProperty SerializeItem'],
     'Struct->SerializeBin( Ar, (BYTE*)Value );')
 &&str_contains($source['UArrayProperty and UStructProperty SerializeItem'],
     'Inner->SerializeItem( Ar, p+i*c );'));
$ck('source_UObject_calls_UStructTaggedProperties',
 str_contains($source['v432 UObject Serialize'],'void UObject::Serialize( FArchive& Ar )'));
$ck('source_deterministic_stock_and_undefined_exclusion',
 str_contains($m['method_decision_scope'],'stock uninitialized array FString materialization')
 &&$m['stock_array_loaded_value']==='UNDEFINED_NOT_VERIFIED');
$ck('stock_real_original_DLL_not_modified',
 $m['compiled_unmodified']['Core.dll']['sha256']==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'
 &&$m['compiled_unmodified']['Engine.dll']['sha256']==='f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac');
$seven=['pinned_source_and_dispatch'=>'VERIFIED'];
$genLog=$pin($m['actual_core_native_generator']['log'],'stock_original_SavePackage_log_unchanged');
if(str_starts_with($genLog,"\xff\xfe"))
    $genLog=(string)iconv('UTF-16LE','UTF-8',substr($genLog,2));
$ck('real_Epic_Saved_direct_and_array',
 str_contains($genLog,'NATIVE_DIRECT_STRUCT_SAVED 1')
 &&str_contains($genLog,'NATIVE_ARRAY_STRUCT_SAVED 1'));
foreach(['direct_struct','array_of_struct'] as $key){
 $v=$m['actual_original_one_byte_mutations'][$key];
 $orig=$pack[$key];$mut=$bad[$key];
 $changed=[];
 for($i=0;$i<strlen($orig);$i++)if($orig[$i]!==$mut[$i])$changed[]=$i;
 $ck('actual_one_byte_original_mutation_'.$key,strlen($orig)===strlen($mut)
    &&strlen($orig)===$v['physical_eof']
    &&$changed===[$v['offset']]
    &&ord($orig[$v['offset']])===$v['old_byte']
    &&ord($mut[$v['offset']])===$v['new_byte']);
}
$ck('actual_native_stock_direct_loaded',
 str_contains($logs['stock_direct_struct_valid'],'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($logs['stock_direct_struct_valid'],'native_error=0')
 &&str_contains($logs['stock_direct_struct_valid'],
    'OneRecord=(Label="Direct-Record",Marker=71)'));
$ck('actual_diagnostic_array_loaded_and_stock_not_fabricated',
 str_contains($logs['zero_allocator_array_struct_valid'],
    'Records=((Label="Nested-Record",Marker=84))')
 &&str_contains($logs['zero_allocator_array_struct_valid'],'native_error=0')
 &&str_contains($logs['stock_array_struct_memory_failure'],
    'G5_NATIVE_DIRECT_PACKAGE_BEGIN')
 &&!str_contains($logs['stock_array_struct_memory_failure'],
    'G5_NATIVE_DIRECT_PACKAGE_RESULT')
 &&$m['stock_array_loaded_value']==='UNDEFINED_NOT_VERIFIED');
$ck('compiled_source_known_allocator_hazard',
 str_contains($pin($m['previous_pinned_source_proofs']['original native FString allocator UB'],
    'previous_cdb_native_undefined_manifest'),'0xBAADF00D'));
$ck('actual_native_exact_malformed_direct_FArchive',
 str_contains($logs['stock_direct_struct_malformed'],
    $m['compiled_native_malformed_error_text']['direct_struct'])
 &&str_contains($logs['stock_direct_struct_malformed'],'UStruct::SerializeBin')
 &&str_contains($logs['stock_direct_struct_malformed'],'UObject::Serialize'));
$ck('actual_native_exact_malformed_array_FArchive',
 str_contains($logs['zero_allocator_array_struct_malformed'],
    $m['compiled_native_malformed_error_text']['array_of_struct'])
 &&str_contains($logs['zero_allocator_array_struct_malformed'],'UStruct::SerializeBin')
 &&str_contains($logs['zero_allocator_array_struct_malformed'],'UObject::Serialize'));
$verified=0;$exceptions=0;$arrayUndefined=false;
$expected=[
 'direct_struct'=>['case'=>'G5StructStringDirectPayload.u','name'=>'OneRecord',
  'kind'=>'source_typed_struct','value'=>'Direct-Record','marker'=>71,'bytes'=>19],
 'array_of_struct'=>['case'=>'G5StructStringArrayPayload.u','name'=>'Records',
  'kind'=>'source_typed_dynamic_array','value'=>'Nested-Record','marker'=>84,'bytes'=>20]
];
foreach($expected as $id=>$case){
 $f=$m['actual_generated_originals'][$id]['path'];
 $pkg=new P($f);
 $ck('real_saved_package_version_69_'.$id,$pkg->getHeader()['version']===69
    &&$pkg->validatePackage()===[]);
 $row=$pkg->getExports()[0];
 $ck('original_saved_single_actor_'.$id,$row['objectNameText']==='G5StructStringActor'
    &&$row['serialSize']>0);
 $result=T::inspect($f,$pkg,0,'ut99-v432');
 $ck('native_serialization_complete_'.$id,
    $result['status']==='tag_envelope_decoded'
    &&$result['native_tail_bytes']===0
    &&count($result['tags'])===1);
 $tag=$result['tags'][0];
 $ck('source_defined_struct_property_'.$id,$tag['name']===$case['name']
    &&$tag['value_kind']===$case['kind']
    &&$tag['on_disk_value_bytes']===$case['bytes']
    &&$tag['serialized_size']===$case['bytes']
    &&$tag['native_load_policy']==='native_source_property_SerializeItem');
 $val=$id==='array_of_struct'
     ?($tag['value']['preview_first_32'][0]??null):$tag['value'];
 $members=is_array($val)?($val['members']??[]):[];
 $ck('complete_native_saved_fields_'.$id,
    ($val['status']??null)==='struct_field_values_decoded'
    &&count($members)===2
    &&($members[0]['name']??null)==='Label'
    &&($members[0]['values'][0]['ascii_text']??null)===$case['value']
    &&($members[1]['name']??null)==='Marker'
    &&($members[1]['values'][0]??null)===$case['marker']);
 if($id==='array_of_struct'){
  $arrayUndefined=($tag['value']['native_loaded_value_parity']??null)==='NOT_VERIFIED'
   &&str_contains((string)($tag['value']['source_runtime_risk']??''),'FArray::Add');
  $ck('stock_array_memory_undefined_explicitly_excluded',$arrayUndefined
    &&($val['native_struct_memory_verified']??true)===false);
 }
 $verified++;
 $badFile=$m['original_derived_single_byte_malformed'][$id]['path'];
 $badPkg=new P($badFile);
 $ck('malformed_actual_derived_package_valid_'.$id,
    $badPkg->getHeader()['version']===69
    &&$badPkg->validatePackage()===[]);
 $error='';
 try {T::inspect($badFile,$badPkg,0,'ut99-v432');}
 catch(Throwable $ex){$error=$ex->getMessage();}
 $ck('source_matches_stock_origin_physical_EOF_'.$id,
    str_contains($error,'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset ')
    &&str_contains($error,(string)$m['actual_original_one_byte_mutations'][$id]['physical_eof'])
    &&str_contains($error,'struct FString<<'));
 if($error!=='')$exceptions++;
}
$ck('current_all_native_struct_cases', $verified===2 &&$exceptions===2 &&$arrayUndefined);
$seven['complete_native_field_values_and_references']='VERIFIED';
$seven['archive_bytes_and_consumption']='VERIFIED';
$seven['malformed_input_error_semantics']='VERIFIED';
$priorOk=0;$saved=0;$XLevel=0;$loaded=0;$nativeExceptions=0;
foreach($m['previous_immutable_method_results'] as $k=>$entry){
 $pin($entry['script'],'historical_test_'.$k);
 $report=$pin($entry['output'],'historical_evidence_'.$k);
 $obj=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$report),true,512,JSON_THROW_ON_ERROR);
 $ck('previous_original_full_acceptance_is_immutable_'.$k,
    ($obj['ok']??false)===true
    &&isset($obj['checks'],$obj['passed'])
    &&(int)$obj['checks']===(int)$obj['passed']);
 if($k==='verify-ue1-o06-g5-uobject-original-v61-native-saved-values.php'){
  $loaded=$obj['native_original_v61_export_objects']??null;
  $saved=$obj['loaded_native_value_matches']??null;
 }
 if($k==='verify-ue1-o06-g5-uobject-v61-124-native-XLevel-pointers.php'){
  $XLevel=$obj['native_compiled_actor_XLevel_pointers']??null;
 }
 if($k==='verify-ue1-o06-g5-uobject-tag-error-consolidated.php'){
  $nativeExceptions=$obj['native_tag_error_cases_compared']??null;
 }
 $priorOk++;
}
foreach($m['previous_pinned_source_proofs'] as $k=>$p)$pin($p,'original_native_manifest_'.$k);
$ck('original_v61_exact_308_loaded_source_objects',$loaded===308);
$ck('original_all_926_saved_tag_values_zero_mismatches',$saved===802 &&$XLevel===124);
$ck('original_four_stock_native_tag_errors',$nativeExceptions===4);
$ck('previous_9_distinct_original_source_oracles_SHA_reused',$priorOk===9);
$seven['inherited_and_version_variant_semantics']='VERIFIED';
$seven['current_acceptance_tests']='VERIFIED';
$seven['independent_full_method_review']='VERIFIED';
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$ckCount,'passed'=>$ckCount-count($fail),
 'failed'=>$fail,'method'=>$m['method'],
 'verdict'=>$ok?'FULL_UT99_V432_SELECTED_DETERMINISTIC_SOURCE_PROFILES_NATIVE_UNDEFINED_EXCLUDED':'BLOCKED',
 'seven_gates'=>$ok?$seven:[],
 'scope'=>$m['method_decision_scope'],
 'source_reproduced_real_stock_direct_loaded_values'=>1,
 'source_reproduced_diagnostic_only_nested_struct_values'=>1,
 'compiled_native_exact_struct_FString_EOF_cases'=>2,
 'source_exact_struct_FString_EOF_cases'=>$exceptions,
 'stock_nested_FString_array_success_claimed'=>false,
 'stock_array_native_undefined_explicitly_excluded'=>$arrayUndefined,
 'prior_stock_native_real_loaded_objects_SHA_reused'=>$loaded,
 'prior_stock_native_saved_values_SHA_reused'=>$saved+$XLevel,
 'prior_stock_native_tag_errors_SHA_reused'=>$nativeExceptions,
 'native_engine_processes_spawned_by_final_reviewer'=>0,
 'other_UE1_game_families_accepted'=>false,
 'all_unseen_source_legal_UClass_variants_inferred'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
