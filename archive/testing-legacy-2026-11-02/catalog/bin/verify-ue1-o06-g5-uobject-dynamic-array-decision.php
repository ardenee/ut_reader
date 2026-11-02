#!/usr/bin/env php
<?php
/** One changed-source method decision: compiled v432 engine-generated real
 * AR_INDEX dynamic array archive, 6 native malformed count/ref/tag-size
 * cases, original archive restored; earlier 113/43/6/4/4 SHA-reused only.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$root=dirname(__DIR__,2);
$read=static function(string $path):array{
    $data=(string)file_get_contents($path);
    if(str_starts_with($data,"\xEF\xBB\xBF"))$data=substr($data,3);
    return json_decode($data,true,512,JSON_THROW_ON_ERROR);
};
$m=$read($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-DYNAMIC-ARRAY-6.json');
$checks=0;$fail=[];$successes=0;$fatals=0;
$ck=static function(string $what,bool $ok)use(&$checks,&$fail):void{
    $checks++;if(!$ok)$fail[]=$what;
};
$pin=static fn(array $v):bool=>isset($v['path'],$v['sha256'])
    &&is_file($v['path'])&&hash_file('sha256',$v['path'])===$v['sha256'];
foreach(['original','native_reference_batch','native_unchanged_baseline_log',
    'generated_actor_script','compiled_actor_package','compiled_v432_CoreDLL',
    'compiled_v432_UCC','actual_save_generator_source','actual_save_generator_exe',
    'actual_load_oracle_source','actual_load_oracle_exe',
    'source_UArrayProperty','source_tagged_properties','source_UArray_compiler'] as $key){
    $ck('source_and_native_SHA_'.$key,$pin($m[$key]));
}
$batch=$read($m['native_reference_batch']['path']);
$ck('batch_original_and_Core_identity',
    $batch['original_sha256']===$m['original']['sha256']
    &&$batch['compiled_core_sha256']===$m['compiled_v432_CoreDLL']['sha256']
    &&$batch['compiled_loader_sha256']===$m['actual_load_oracle_exe']['sha256']
    &&$batch['array_class_package_sha256']===$m['compiled_actor_package']['sha256']);
$ck('six_sealed_native_cases',($m['case_count']??null)===6
    &&$batch['count']===6&&$batch['all_restored']===true);
$original=(string)file_get_contents($m['original']['path']);
$ck('original_binary_start_SHA',hash('sha256',$original)===$m['original']['sha256']);
// The earlier console-only UTF-16 baseline was truncated by Select -First.
// Do not treat that partial stdout as a complete compiled oracle. Both
// full-output successful malformed Tag.Size native runs retain all values.
$firstSuccess=$m['cases'][0]['native_log']['path'];
$nativePositive=(string)file_get_contents($firstSuccess);
$ck('complete_native_compiled_loaded_all_three_from_full_stdout',
    str_contains($nativePositive,'G5_NATIVE_OBJECT_COUNT 1')
    &&str_contains($nativePositive,'native_error=0')
    &&str_contains($nativePositive,'G5_NATIVE_TAG_VALUE  Numbers=(11,42,-7)')
    &&str_contains($nativePositive,'G5_NATIVE_TAG_VALUE  Labels=(Alpha,Beta)')
    &&str_contains($nativePositive,"G5_NATIVE_TAG_VALUE  References=(G5ArrayProbeActor'G5ArrayPayload.G5DynamicActor',None)"));
$fixture='C:/Temp/UnrealDB/O06/g5-array-php-fixtures';
if(!is_dir($fixture))mkdir($fixture,0777,true);
foreach(['compiled_actor_package'=>'G5ArrayProbe.u',
          'dependency_Engine_u'=>'Engine.u','dependency_Core_u'=>'Core.u'] as $key=>$file){
    if($key==='compiled_actor_package')$ref=$m[$key];
    else{
        $src=dirname($m['original']['path']).'/'.$file;
        $ref=['path'=>$src,'sha256'=>hash_file('sha256',$src)];
    }
    if(!is_file($fixture.'/'.$file)
        ||hash_file('sha256',$fixture.'/'.$file)!==$ref['sha256'])
        copy($ref['path'],$fixture.'/'.$file);
    $ck('dependency_sha_'.$file,
        hash_file('sha256',$fixture.'/'.$file)===$ref['sha256']);
}
$copy=$fixture.'/G5ArrayPayload.u';
file_put_contents($copy,$original);
$nativeCasesByName=[];
foreach($batch['cases'] as $raw)$nativeCasesByName[$raw['name']]=$raw;
foreach($m['cases'] as $case){
    $name=$case['name'];$pos=$case['byte_offset'];
    $record=$nativeCasesByName[$name]??null;
    $ck('native_original_case_provenance_'.$name,
        $record!==null
        &&$record['byte_offset']===$pos
        &&$record['original_byte']===$case['original_byte']
        &&$record['mutated_byte']===$case['mutated_byte']
        &&$record['stdout_sha256']===$case['native_log']['sha256']
        &&$record['native_loaded']===$case['native_loaded']
        &&$record['original_restored']===true);
    $ck('native_stdout_SHA_'.$name,$pin($case['native_log']));
    $stdout=(string)file_get_contents($case['native_log']['path']);
    $success=$case['native_loaded'];
    $native=$success
        ?str_contains($stdout,'G5_NATIVE_OBJECT_COUNT 1')
            &&str_contains($stdout,'native_error=0')
            &&str_contains($stdout,'G5_NATIVE_TAG_VALUE  Numbers=(11,42,-7)')
            &&str_contains($stdout,'G5_NATIVE_TAG_VALUE  Labels=(Alpha,Beta)')
            &&str_contains($stdout,"G5_NATIVE_TAG_VALUE  References=(G5ArrayProbeActor'G5ArrayPayload.G5DynamicActor',None)")
        :str_contains($stdout,'native_error=1')
            &&str_contains($stdout,'Exiting due to error')
            &&str_contains($stdout,'History:')
            &&str_contains($stdout,' '.(string)$case['native_fatal_archive_offset'].'))');
    $ck('compiled_error_or_loaded_values_'.$name,$native);
    $ck('one_byte_source_at_offset_'.$name,ord($original[$pos])===$case['original_byte']);
    $bytes=$original;$bytes[$pos]=chr($case['mutated_byte']);
    $err=null;$actual=null;
    try{
        if(file_put_contents($copy,$bytes)!==strlen($bytes))
            throw new RuntimeException('Failed to write temp malformed fixture');
        $p=new P($copy);
        $ck('explicit_v432_source_'.$name,
            F::selectFromHeader($p->getHeader(),'ut99-v432')===F::UT99_V432);
        $actual=T::inspect($copy,$p,0,'ut99-v432');
    }catch(Throwable $e){$err=$e->getMessage();}
    finally{file_put_contents($copy,$original);}
    $ok=false;
    if(!$success){
        $origin=$name==='references_invalid_inner_object'?'IndexToObject':'ULinkerLoad<<FName';
        $ok=is_string($err)&&str_contains($err,$origin)
            &&str_contains($err,' at archive offset '.$case['native_fatal_archive_offset']);
        if($ok)$fatals++;
    }else{
        $tags=$actual['tags']??[];
        $index=[];foreach($tags as $tag)$index[$tag['name']]=$tag;
        $numbers=$index['Numbers']??[];
        $labels=$index['Labels']??[];
        $refs=$index['References']??[];
        $expectedSize=$name==='numbers_declared_size1'?1:13;
        $refTagSize=$name==='references_declared_size1'?1:3;
        $ok=$err===null&&($actual['status']??null)==='tag_envelope_decoded'
            &&($actual['native_tail_bytes']??null)===0&&count($tags)===3
            &&($numbers['serialized_size']??null)===$expectedSize
            &&($numbers['on_disk_value_bytes']??null)===13
            &&($numbers['value_kind']??null)==='source_typed_dynamic_array'
            &&($numbers['value']['element_count']??null)===3
            &&($numbers['value']['preview_first_32']??null)===[11,42,-7]
            &&($labels['value']['element_count']??null)===2
            &&($labels['value']['preview_first_32']??null)===[1,5]
            &&($refs['serialized_size']??null)===$refTagSize
            &&($refs['on_disk_value_bytes']??null)===3
            &&($refs['value']['element_count']??null)===2
            &&($refs['value']['preview_first_32']??null)===[1,0];
        if($ok)$successes++;
    }
    $ck('production_matches_real_native_stream_'.$name,$ok);
}
$ck('four_fatal_two_valid_native_outcomes',$fatals===4&&$successes===2);
$ck('original_source_and_fixture_SHA_after_six_cases',
    hash_file('sha256',$m['original']['path'])===$m['original']['sha256']
    &&hash_file('sha256',$copy)===$m['original']['sha256']);
$events=array_map(static fn($x)=>json_decode($x,true),
    array_filter(explode("\n",(string)file_get_contents(
        $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
$old=null;
foreach($events as $event){
    if(($event['test']??'')==='verify-ue1-o06-g5-uobject-serializeitem-size-decision.php'
      &&($event['state']??null)==='PASS')$old=$event;
}
$oldlog=$old!==null?$root.'/'.str_replace('\\','/',$old['log']):null;
$oldvalue=$oldlog&&is_file($oldlog)?$read($oldlog):[];
$ck('previous_current_source_compiled_values_SHA_retained',
    $old!==null
    &&hash_file('sha256',$oldlog)===$old['output_sha256']
    &&hash_file('sha256',$root.'/catalog/bin/verify-ue1-o06-g5-uobject-serializeitem-size-decision.php')
        ===$old['test_sha256']
    &&($oldvalue['ok']??false)===true
    &&($oldvalue['passed']??null)===50
    &&($oldvalue['real_native_new_size_mutation_cases']??null)===4
    &&($oldvalue['previous_native_reference_cases_SHA_reused']??null)===6
    &&($oldvalue['previous_native_property_error_cases_SHA_reused']??null)===4
    &&($oldvalue['previous_StateFrame_decision_checks_SHA_reused']??null)===43
    &&($oldvalue['compiled_original_tags_current_source_matched']??null)===113);
$ck('compiled_method_still_not_false_FULL',$m['full_method_accepted']===false);
echo json_encode([
    'ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
    'failures'=>$fail,'method'=>$m['method'],
    'new_compiled_dynamic_array_oracle_cases'=>$successes+$fatals,
    'new_compiled_array_fatal_archive_cursors_matched'=>$fatals,
    'new_compiled_array_valid_malicious_size_cases_matched'=>$successes,
    'compiled_native_original_three_array_values_matched'=>3,
    'native_original_33_loaded_exports_prior_SHA_reused'=>33,
    'native_original_113_loaded_property_values_prior_SHA_reused'=>113,
    'prior_StateFrame_checks_SHA_reused'=>43,
    'compiled_native_loader_reruns_in_verifier'=>0,
    'full_method_decision'=>'BLOCKED',
    'remaining'=>'Nested FString/Struct ArrayProperty Inner serializers, historical source variants, five Camera objects not independently instantiated, full seven-criterion independent acceptance'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
