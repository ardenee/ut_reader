#!/usr/bin/env php
<?php
/** One current-source UObject method decision. Reuse immutable compiled
 * evidence: six new-reference cases, earlier four tag cases, 113 genuine
 * loaded values and 43 StateFrame checks. No native engine execution.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$root=dirname(__DIR__,2);
$get=static function(string $path):array{
    $bytes=(string)file_get_contents($path);
    if(str_starts_with($bytes,"\xEF\xBB\xBF"))$bytes=substr($bytes,3);
    return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
};
$m=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-REFERENCE-NEGATIVE-6.json');
$prior=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAG-NEGATIVE-4.json');
$loaded=$get($root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAGGED-113.json');
$checks=0;$fail=[];
$ck=static function(string $label,bool $value)use(&$checks,&$fail):void{
    ++$checks;if(!$value)$fail[]=$label;
};
$good=static fn(array $file):bool=>isset($file['path'],$file['sha256'])
    &&is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256'];
foreach(['original','CoreDLL','launcher','native_batch',
         'dependency_Engine_u','dependency_Core_u',
         'source_inherited_FPropertyTag','source_inherited_ULinkerLoad'] as $k){
    $ck('new_native_SHA_'.$k,$good($m[$k]));
}
$ck('original_native_same_legacy_batch',
    $m['original']['sha256']===$prior['original']['sha256']);
$ck('source_compiled_DLL_unchanged',
    $m['CoreDLL']['sha256']===$loaded['compiled_Core']['sha256']);
$batch=$get($m['native_batch']['path']);
$ck('native_all_six_immutable',
    ($batch['case_count']??null)===6
    &&($batch['original_sha256']??null)===$m['original']['sha256']
    &&($batch['compiled_Core_sha256']??null)===$m['CoreDLL']['sha256']
    &&($batch['native_compiled_launcher_sha256']??null)===$m['launcher']['sha256']);
$nativeLogMatch=0;$phpMatch=0;$fatalMatch=0;$skipMatch=0;
$pristine=file_get_contents($m['original']['path']);
$ck('pristine_genuine_archive',is_string($pristine)
    &&hash('sha256',$pristine)===$m['original']['sha256']);
$fixture='C:/Temp/UnrealDB/O06/g5-reference-php-fixtures';
if(!is_dir($fixture))mkdir($fixture,0777,true);
foreach(['Engine.u','Core.u'] as $file){
    $source=$m['dependency_'.str_replace('.','_',$file)];
    $dest=$fixture.'/'.$file;
    if(!is_file($dest)||hash_file('sha256',$dest)!==$source['sha256']){
        copy($source['path'],$dest);
    }
    $ck('fixture_pinned_'.$file,is_file($dest)
        &&hash_file('sha256',$dest)===$source['sha256']);
}
$archive=$fixture.'/Entry.unr';
foreach($m['cases'] as $case){
    $name=$case['name'];
    $record=null;
    foreach($batch['cases'] as $row){
        if($row['name']===$name){$record=$row;break;}
    }
    $ck('native_manifest_identity_'.$name,$record!==null
        &&$record['source_file_offset']===$case['position']
        &&$record['original_byte']===$case['original_byte']
        &&$record['mutant_byte']===$case['mutated_byte']
        &&$record['native_direct_package_success']===$case['native_success']
        &&$record['native_stdout_sha256']===$case['native_log']['sha256']
        &&$record['originals_restored']===true);
    $ck('native_original_error_log_SHA_'.$name,$good($case['native_log']));
    $log=(string)file_get_contents($case['native_log']['path']);
    $success=$case['native_success'];
    $native=$success
       ?str_contains($log,'G5_NATIVE_OBJECT_COUNT 33')
            &&str_contains($log,'native_error=0')
       :str_contains($log,'Exiting due to error')
            &&str_contains($log,'native_error=1')
            &&str_contains($log,'History:')
            &&str_contains($log,' '.(string)$case['native_offset_on_fatal'].'))');
    $ck('native_original_behavior_'.$name,$native);
    if($native)++$nativeLogMatch;
    $position=(int)$case['position'];
    $ck('mutation_original_byte_'.$name,ord($pristine[$position])===(int)$case['original_byte']);
    $modified=$pristine;$modified[$position]=chr((int)$case['mutated_byte']);
    $error=null;$parsed=null;
    try{
        if(file_put_contents($archive,$modified)!==strlen($modified))
            throw new RuntimeException('fixture write failed');
        $pkg=new P($archive);
        $ck('v68_selects_ut99_v432_'.$name,
            F::selectFromHeader($pkg->getHeader(),'ut99-v432')===F::UT99_V432);
        $parsed=T::inspect($archive,$pkg,0,'ut99-v432');
    }catch(Throwable $e){$error=$e->getMessage();}
    finally{file_put_contents($archive,$pristine);}
    $match=false;
    if(!$success){
        $method=$name==='invalid_fname_ref'?'ULinkerLoad<<FName':'IndexToObject';
        $match=is_string($error)&&str_contains($error,$method)
            &&str_contains($error,' at archive offset '.$case['native_offset_on_fatal']);
        if($match)++$fatalMatch;
    }else{
        $selected=null;
        foreach($parsed['tags']??[] as $tag){
            if($tag['name']!==$case['checked_tag'])continue;
            if($name==='out_of_bounds_AIProfile_index'
               &&$tag['array_index']!==127)continue;
            $selected=$tag;break;
        }
        $match=$error===null&&is_array($parsed)
            &&($parsed['native_tail_bytes']??null)===0
            &&count($parsed['tags']??[])===15
            &&is_array($selected)
            &&($selected['native_load_policy']??null)===$case['expected_policy']
            &&($selected['value_kind']??null)==='epic_skipped_declared_tag_bytes'
            &&($selected['on_disk_value_bytes']??null)===$case['expected_skipped_bytes']
            &&array_key_exists('value',$selected)&&$selected['value']===null;
        if($match)++$skipMatch;
    }
    $ck('PHP_native_fatal_or_skip_and_consumption_'.$name,$match);
    if($match)++$phpMatch;
}
$ck('all_six_compiled_reference_cases_match', $nativeLogMatch===6
    &&$phpMatch===6&&$fatalMatch===4&&$skipMatch===2);
$ck('original_never_written_during_new_cases',
    hash_file('sha256',$m['original']['path'])===$m['original']['sha256']);
$events=array_map(static fn($x)=>json_decode($x,true),
    array_filter(explode("\n",(string)file_get_contents(
        $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
foreach([
 'verify-ue1-o06-g5-uobject-tag-error-consolidated.php'=>49,
 'verify-ue1-o06-g5-uobject-consolidated-acceptance.php'=>43
] as $name=>$count){
    $event=null;
    foreach($events as $entry){
        if(($entry['state']??null)==='PASS'&&($entry['test']??null)===$name)
           $event=$entry;
    }
    $file=$event===null?null:$root.'/'.str_replace('\\','/',$event['log']);
    $proof=is_string($file)&&is_file($file)?$get($file):[];
    $ck('reused_sealed_prior_'.$name,$event!==null
        &&is_file($file)&&hash_file('sha256',$file)===$event['output_sha256']
        &&($proof['ok']??null)===true&&($proof['checks']??null)===$count
        &&($proof['passed']??null)===$count);
}
$ck('pinned_compiled_positive_loaded_values',$good($loaded['compiled_loaded_values_log']));
$helpers=[
 ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-current-tags.php'],
 ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-native-ref-map.php'],
 ['python',$root.'/catalog/bin/ue1-o06-g5-uobject-compare-loaded-values.py']
];
$comparison=[];
foreach($helpers as $n=>[$exe,$script]){
    $out=[];$exit=255;
    exec(escapeshellarg($exe).' '.escapeshellarg($script).' 2>&1',$out,$exit);
    $ck('single_current_source_113_comparison_stage_'.$n,$exit===0);
    if($exit!==0){$fail[]='loaded native helper failed: '.substr(implode(' ',$out),0,300);break;}
    if($n===2)$comparison=json_decode(implode("\n",$out),true)??[];
}
$ck('all_113_actual_compiled_values_current_source',
    ($comparison['original_33_loaded_classes']??null)===33
    &&($comparison['total_tags']??null)===113
    &&($comparison['exactly_compared']??null)===113
    &&($comparison['problems']??null)===[]);
$ck('original_reference_archive_unchanged_after_acceptance',
    hash_file('sha256',$m['original']['path'])===$m['original']['sha256']);
$ck('method_not_false_closed', $m['full_method_accepted']===false);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
 'failures'=>$fail,'method'=>$m['method'],
 'previous_compiled_native_malformed_tag_cases_reused'=>4,
 'previous_compiled_StateFrame_evidence_reused'=>43,
 'new_compiled_native_reference_cases_reused'=>6,
 'real_native_reference_outcomes_matched'=>$phpMatch,
 'fatal_archive_offset_outcomes_matched'=>$fatalMatch,
 'nonfatal_exact_skip_bytes_outcomes_matched'=>$skipMatch,
 'current_source_compiled_loaded_values_matched'=>$comparison['exactly_compared']??0,
 'current_source_compiled_loaded_exports'=>$comparison['original_33_loaded_classes']??0,
 'new_compiled_engine_invocations'=>0,
 'full_method_decision'=>'BLOCKED',
 'remaining'=>'other source-version property serialization, malformed nested dynamic array/struct variants, excluded Camera contexts and independent full review'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
