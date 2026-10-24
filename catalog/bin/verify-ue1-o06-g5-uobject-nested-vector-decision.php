#!/usr/bin/env php
<?php
/** Latest T99:UObject::Serialize source-linked dynamic UStructProperty
 * SerializeBin native oracle, source vs compiled restored archive.
 * Reuse prior native integer/name/object arrays and original Entry tags
 * exclusively through pinned existing PASS event; no compiled engine rerun.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$get=static function(string $path):array{
    $s=(string)file_get_contents($path);
    if(str_starts_with($s,"\xEF\xBB\xBF"))$s=substr($s,3);
    return json_decode($s,true,512,JSON_THROW_ON_ERROR);
};
$m=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-NESTED-VECTOR-3.json');
$checks=0;$fail=[];$matching=0;$fatal=0;$positive=0;
$ck=static function(string $key,bool $ok)use(&$checks,&$fail):void{
    ++$checks;if(!$ok)$fail[]=$key;
};
$pin=static fn(array $p):bool=>isset($p['path'],$p['sha256'])
    &&is_file($p['path'])&&hash_file('sha256',$p['path'])===$p['sha256'];
foreach(['original','native_batch','real_native_positive_output',
    'native_compiled_CoreDLL','native_compiled_UCC','generated_script',
    'compiled_class_package','native_save_source','native_save_executable',
    'native_load_source','native_load_executable','source_UnProp_cpp',
    'source_UnClass_cpp','source_UnScrCom_cpp'] as $key){
    $ck('pinned_'.$key,$pin($m[$key]));
}
$batch=$get($m['native_batch']['path']);
$ck('three_new_native_oracles',($batch['case_count']??null)===3
    &&$batch['all_restored']===true
    &&$batch['original_sha256']===$m['original']['sha256']
    &&$batch['compiled_core_sha256']===$m['native_compiled_CoreDLL']['sha256']
    &&$batch['compiled_loader_sha256']===$m['native_load_executable']['sha256']);
$nativeLine='G5_NATIVE_TAG_VALUE  Locations=((X=1.500000,Y=2.500000,Z=3.500000),(X=-4.000000,Y=5.000000,Z=-6.000000))';
$sourcePositive=(string)file_get_contents($m['real_native_positive_output']['path']);
$ck('actual_compiled_core_original_vector_loaded',
    str_contains($sourcePositive,$nativeLine)
    &&str_contains($sourcePositive,'G5_NATIVE_OBJECT_COUNT 1')
    &&str_contains($sourcePositive,'native_error=0'));
$original=(string)file_get_contents($m['original']['path']);
$ck('actual_generated_source_immutable',
    hash('sha256',$original)===$m['original']['sha256']);
$dir='C:/Temp/UnrealDB/O06/g5-native-nested-vector-php-fixtures';
if(!is_dir($dir))mkdir($dir,0777,true);
$dependencies=[
 'G5NestedProbe.u'=>$m['compiled_class_package'],
 'Core.u'=>['path'=>dirname($m['original']['path']).'/Core.u'],
 'Engine.u'=>['path'=>dirname($m['original']['path']).'/Engine.u']
];
foreach($dependencies as $name=>$file){
    $expected=$file['sha256']??hash_file('sha256',$file['path']);
    $copy=$dir.'/'.$name;
    if(!is_file($copy)||hash_file('sha256',$copy)!==$expected)
        copy($file['path'],$copy);
    $ck('native_actual_class_or_provider_'.$name,
        hash_file('sha256',$copy)===$expected);
}
$fixture=$dir.'/G5NestedPayload.u';
foreach($m['cases'] as $case){
    $name=$case['name'];$pos=(int)$case['byte_offset'];
    $ck('native_output_SHA_'.$name,$pin($case['native_stdout']));
    $contents=(string)file_get_contents($case['native_stdout']['path']);
    $nativeOk=$case['native_success']
      ?str_contains($contents,$nativeLine)
        &&str_contains($contents,'G5_NATIVE_OBJECT_COUNT 1')
        &&str_contains($contents,'native_error=0')
      :str_contains($contents,'native_error=1')
        &&str_contains($contents,'Exiting due to error')
        &&str_contains($contents,'History:')
        &&str_contains($contents,' '.(string)$case['native_fatal_offset'].'))');
    $ck('compiled_native_original_outcome_'.$name,$nativeOk);
    $ck('actual_original_one_byte_'.$name,
        ord($original[$pos])===(int)$case['original_byte']);
    $modified=$original;$modified[$pos]=chr((int)$case['mutated_byte']);
    $error=null;$record=null;
    try{
        if(file_put_contents($fixture,$modified)!==strlen($modified))
            throw new RuntimeException('Unable to save controlled fixture');
        $pkg=new P($fixture);
        $parsed=T::inspect($fixture,$pkg,0,'ut99-v432');
        $record=$parsed['tags'][0]??null;
    }catch(Throwable $e){$error=$e->getMessage();}
    finally{file_put_contents($fixture,$original);}
    $ok=false;
    if(!$case['native_success']){
        $ok=is_string($error)
            &&str_contains($error,'ULinkerLoad<<FName invalid name index')
            &&str_contains($error,' at archive offset '.$case['native_fatal_offset']);
        if($ok)++$fatal;
    }else{
        $preview=$record['value']['preview_first_32']??null;
        $v=[];
        if(is_array($preview)){
            foreach($preview as $row){
                $triplet=[];
                foreach($row['members']??[] as $field){
                    $triplet[]=$field['values'][0]??null;
                }
                $v[]=$triplet;
            }
        }
        $ok=$error===null&&is_array($record)
            &&($record['value_kind']??null)==='source_typed_dynamic_array'
            &&($record['name']??null)==='Locations'
            &&($record['serialized_size']??null)===(int)$case['mutated_byte']
            &&($record['on_disk_value_bytes']??null)===25
            &&($record['value']['element_count']??null)===2
            &&($record['value']['inner_property_kind']??null)==='structproperty'
            &&$v===$m['expected_vector_values'];
        if($ok)++$positive;
    }
    $ck('production_source_native_error_cursor_or_values_'.$name,$ok);
    if($ok)++$matching;
}
$ck('three_distinct_compiled_oracle_agreements',
    $matching===3&&$fatal===2&&$positive===1);
$ck('original_generator_package_fully_unchanged_after_acceptance',
    hash_file('sha256',$m['original']['path'])===$m['original']['sha256']
    &&hash_file('sha256',$fixture)===$m['original']['sha256']);
$journal=$root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl';
$events=array_map(static fn($line)=>json_decode($line,true),
    array_filter(explode("\n",(string)file_get_contents($journal))));
$prev=null;
foreach($events as $event){
    if(($event['test']??'')==='verify-ue1-o06-g5-uobject-dynamic-array-decision.php'
        &&($event['state']??'')==='PASS')$prev=$event;
}
$prevPath=$prev!==null?$root.'/'.str_replace('\\','/',$prev['log']):null;
$prevData=$prevPath!==null&&is_file($prevPath)?$get($prevPath):[];
$ck('previous_SIX_native_arrays_and_113_genuine_SHA_only',
    $prev!==null
    &&hash_file('sha256',$prevPath)===$prev['output_sha256']
    &&hash_file('sha256',$root.'/catalog/bin/verify-ue1-o06-g5-uobject-dynamic-array-decision.php')
        ===$prev['test_sha256']
    &&($prevData['ok']??null)===true
    &&($prevData['checks']??null)===61
    &&($prevData['new_compiled_dynamic_array_oracle_cases']??null)===6
    &&($prevData['native_original_113_loaded_property_values_prior_SHA_reused']??null)===113
    &&($prevData['prior_StateFrame_checks_SHA_reused']??null)===43);
$ck('no_false_full_closure',$m['full_method_accepted']===false);
echo json_encode([
    'ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
    'failures'=>$fail,'method'=>$m['method'],
    'real_compiled_native_nested_struct_cases_matched'=>$matching,
    'compiled_native_nested_vector_loaded_value_matches'=>$positive,
    'compiled_native_nested_array_fatal_cursor_matches'=>$fatal,
    'actual_native_nested_struct_array_consumed_bytes'=>25,
    'previous_six_dynamic_array_cases_SHA_reused'=>6,
    'previous_genuine_original_TaggedValues_SHA_reused'=>113,
    'previous_StateFrame_SHA_reused'=>43,
    'compiled_native_engine_reruns_in_verifier'=>0,
    'full_method_decision'=>'BLOCKED',
    'remaining'=>'Nonempty inner FString native oracle blocked by disposable MSVC fixture ABI; other nested/recursive struct types, older source versions, Camera not loaded, independent seven-gate full method signoff'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
