#!/usr/bin/env php
<?php
/** One changed-source v432 UObject method decision on real compiled
 * SerializeItem Tag.Size misuse: PointRegion and FString, four mutations.
 * Earlier 6+4 native cases and 113 genuine loaded property values retained,
 * no native loader execution, no replay of old test suites.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$root=dirname(__DIR__,2);
$get=static function(string $f):array{
    $v=(string)file_get_contents($f);
    if(str_starts_with($v,"\xEF\xBB\xBF"))$v=substr($v,3);
    return json_decode($v,true,512,JSON_THROW_ON_ERROR);
};
$manifest=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-SERIALIZEITEM-SIZE-4.json');
$loaded=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAGGED-113.json');
$checks=0;$fail=[];$matched=0;$struct=0;$str=0;
$ck=static function(string $key,bool $value)use(&$checks,&$fail):void{
    $checks++;if(!$value)$fail[]=$key;
};
$pin=static fn(array $p):bool=>isset($p['path'],$p['sha256'])
    &&is_file($p['path'])&&hash_file('sha256',$p['path'])===$p['sha256'];
foreach(['original','CoreDLL','launcher','native_record','dependency_Engine_u',
    'dependency_Core_u','source_PropertyTag','source_UArrayProperty_and_UStrProperty',
    'source_FString','selected_v432_PointRegion'] as $k){
    $ck('pin_'.$k,$pin($manifest[$k]));
}
$batch=$get($manifest['native_record']['path']);
$ck('real_native_batch_hashes',($batch['case_count']??null)===4
    &&$batch['original_sha256']===$manifest['original']['sha256']
    &&$batch['compiled_Core_sha256']===$manifest['CoreDLL']['sha256']
    &&$batch['native_launcher_sha256']===$manifest['launcher']['sha256']);
$ck('native_original_is_same_genuine_entry',
    $manifest['original']['sha256']===$loaded['archive']['sha256']
    &&$manifest['CoreDLL']['sha256']===$loaded['compiled_Core']['sha256']);
$ck('four_real_native_cases',count($manifest['cases'])===4);
$original=file_get_contents($manifest['original']['path']);
$ck('original_archive_unchanged',is_string($original)
    &&hash('sha256',$original)===$manifest['original']['sha256']);
$fixture='C:/Temp/UnrealDB/O06/g5-reference-php-fixtures';
if(!is_dir($fixture))mkdir($fixture,0777,true);
foreach(['Engine.u','Core.u'] as $name){
    $pinData=$manifest['dependency_'.str_replace('.','_',$name)];
    $copy=$fixture.'/'.$name;
    if(!is_file($copy)||hash_file('sha256',$copy)!==$pinData['sha256']){
        copy($pinData['path'],$copy);
    }
    $ck('fixture_source_'.$name,is_file($copy)
        &&hash_file('sha256',$copy)===$pinData['sha256']);
}
$mutable=$fixture.'/Entry.unr';
foreach($manifest['cases'] as $case){
    $name=$case['name'];$pos=$case['position'];
    $native=null;
    foreach($batch['cases'] as $row){
        if(($row['name']??null)===$name){$native=$row;break;}
    }
    $ck('record_consistency_'.$name,$native!==null
        &&$native['pos']===$pos&&$native['original_byte']===$case['original_byte']
        &&$native['new_byte']===$case['new_byte']
        &&$native['native_stdout_sha256']===$case['native_log']['sha256']
        &&$native['native_success']===true
        &&$native['original_restored']===true);
    $ck('compiled_log_sha_'.$name,$pin($case['native_log']));
    $stdout=(string)file_get_contents($case['native_log']['path']);
    $real=str_contains($stdout,'G5_NATIVE_OBJECT_COUNT 33')
        &&str_contains($stdout,'native_error=0')
        &&($case['tag_name']==='Title'
            ?str_contains($stdout,'G5_NATIVE_TAG_VALUE  Title="Untitled"')
            :str_contains($stdout,"G5_NATIVE_TAG_VALUE  Region=(Zone=LevelInfo'Entry.LevelInfo0',iLeaf=-1)"));
    $ck('compiled_loaded_value_'.$name,$real);
    $ck('original_tag_size_one_byte_'.$name,
        ord($original[$pos])===$case['original_byte']);
    $mutated=$original;$mutated[$pos]=chr($case['new_byte']);
    $parse=null;$err=null;
    try{
        if(file_put_contents($mutable,$mutated)!==strlen($mutated))
            throw new RuntimeException('Unable to write disposable archive fixture');
        $pkg=new P($mutable);
        $ck('selected_v432_source_'.$name,
            F::selectFromHeader($pkg->getHeader(),'ut99-v432')===F::UT99_V432);
        $parse=T::inspect($mutable,$pkg,$case['export_index'],'ut99-v432');
    }catch(Throwable $e){$err=$e->getMessage();}
    finally{file_put_contents($mutable,$original);}
    $tag=null;
    foreach(($parse['tags']??[]) as $t){
        if(($t['name']??null)===$case['tag_name']){$tag=$t;break;}
    }
    $value=$tag['value']??null;
    $ok=$err===null&&is_array($parse)
        &&($parse['status']??null)==='tag_envelope_decoded'
        &&($parse['native_tail_bytes']??null)===0
        &&is_array($tag)
        &&($tag['serialized_size']??null)===$case['new_byte']
        &&($tag['on_disk_value_bytes']??null)===$case['expected_consumed_bytes']
        &&($tag['native_load_policy']??null)==='native_source_property_SerializeItem'
        &&($tag['value_kind']??null)===$case['expected_value_kind']
        &&is_array($value)
        &&($case['tag_name']==='Title'
            ?($value['ascii_text']??null)===$case['expected_ascii']
                &&($value['signed_character_count']??null)===9
            :($value['zone_object_reference']??null)===$case['expected_zone_reference']
                &&($value['i_leaf']??null)===-1);
    $ck('php_compiled_exact_SerializeItem_'.$name,$ok);
    if($ok){$matched++;if($case['tag_name']==='Title')$str++;else $struct++;}
}
$ck('four_malformed_size_values_match_native',$matched===4&&$str===2&&$struct===2);
$ck('original_restored_and_untouched',
    hash_file('sha256',$manifest['original']['path'])===$manifest['original']['sha256']
    &&hash_file('sha256',$mutable)===$manifest['original']['sha256']);
$events=array_map(static fn($x)=>json_decode($x,true),
    array_filter(explode("\n",(string)file_get_contents(
        $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
$old=null;
foreach($events as $e){
    if(($e['state']??null)==='PASS'
        &&($e['test']??null)==='verify-ue1-o06-g5-uobject-current-full-decision.php')
        $old=$e;
}
$oldlog=$old!==null?$root.'/'.str_replace('\\','/',$old['log']):null;
$prior=$oldlog!==null&&is_file($oldlog)?$get($oldlog):[];
$ck('prior_six_native_reference_and_43_stateframe_pass_sha',
    $old!==null&&hash_file('sha256',$oldlog)===$old['output_sha256']
    &&hash_file('sha256',$root.'/catalog/bin/verify-ue1-o06-g5-uobject-current-full-decision.php')
        ===$old['test_sha256']
    &&($prior['ok']??null)===true
    &&($prior['passed']??null)===61
    &&($prior['real_native_reference_outcomes_matched']??null)===6
    &&($prior['previous_compiled_StateFrame_evidence_reused']??null)===43);
$ck('original_native_113_sha',$pin($loaded['compiled_loaded_values_log']));
$helpers=[
 ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-current-tags.php'],
 ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-native-ref-map.php'],
 ['python',$root.'/catalog/bin/ue1-o06-g5-uobject-compare-loaded-values.py']
];
$comparison=[];
foreach($helpers as $i=>[$bin,$script]){
    $out=[];$exit=255;
    exec(escapeshellarg($bin).' '.escapeshellarg($script).' 2>&1',$out,$exit);
    $ck('current_original_native_loaded_stage_'.$i,$exit===0);
    if($exit!==0){$fail[]='native comparison: '.substr(implode(' ',$out),0,300);break;}
    if($i===2)$comparison=json_decode(implode("\n",$out),true)??[];
}
$ck('original_loaded_113_values_same',
    ($comparison['original_33_loaded_classes']??null)===33
    &&($comparison['total_tags']??null)===113
    &&($comparison['exactly_compared']??null)===113
    &&($comparison['problems']??null)===[]);
$ck('real_original_Entry_no_changes_at_finish',
    hash_file('sha256',$manifest['original']['path'])===$manifest['original']['sha256']);
$ck('not_false_FULL',$manifest['full_method_accepted']===false);
echo json_encode([
    'ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
    'failures'=>$fail,'method'=>'T99:UObject::Serialize',
    'real_native_new_size_mutation_cases'=>$matched,
    'valid_struct_TagSize_mismatch_consumes_source_bytes'=>$struct,
    'valid_FString_TagSize_mismatch_consumes_source_bytes'=>$str,
    'previous_native_reference_cases_SHA_reused'=>6,
    'previous_native_property_error_cases_SHA_reused'=>4,
    'previous_StateFrame_decision_checks_SHA_reused'=>43,
    'compiled_original_tags_current_source_matched'=>$comparison['exactly_compared']??0,
    'compiled_original_exports_current_source_matched'=>$comparison['original_33_loaded_classes']??0,
    'compiled_native_loader_reruns'=>0,
    'full_method_decision'=>'BLOCKED',
    'remaining'=>'dynamic ArrayProperty nested inner values and malformed counts with live compiled oracle, legacy source-version gates, five uninstantiated Camera StateFrames and full independent seven-gate review'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
