#!/usr/bin/env php
<?php
/** T99 UObject consolidated compiled v432 malformed PropertyTag error-path
 * review plus genuine 33 loaded-object/113 tagged-value positive regression.
 * No compiled native loader is re-executed; only fresh production PHP decodes.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
    $root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAG-NEGATIVE-4.json'),true,512,JSON_THROW_ON_ERROR);
$old=json_decode((string)file_get_contents(
    $root.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-TAGGED-113.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$count=0;$correct=0;
$ck=static function(string $title,bool $ok)use(&$checks,&$errors):void{
    $checks++;if(!$ok)$errors[]=$title;
};
$sha=static fn(array $e):bool=>is_file($e['path'])
    &&hash_file('sha256',$e['path'])===$e['sha256'];
foreach(['original','CoreDLL','launcher','compiled_error_batch',
    'source_AActorSuperOnly'] as $key)$ck('SHA_'.$key,$sha($manifest[$key]));
foreach(['compiled_loaded_values_log','source_Engine_region',
    'source_Engine_csg_enum'] as $key)$ck('SHA_existing_'.$key,$sha($old[$key]));
$source=$manifest['original']['path'];
$pristine=is_file($source)?file_get_contents($source):false;
$ck('original_source_is_exactly_native_file',
    is_string($pristine)&&hash('sha256',$pristine)===$manifest['original']['sha256']);
$batch=json_decode((string)file_get_contents(
    $manifest['compiled_error_batch']['path']),true,512,JSON_THROW_ON_ERROR);
$ck('batch_native_original_and_Core_match',
    ($batch['original_Entry_sha256']??null)===$manifest['original']['sha256']
    &&($batch['compiled_Core_sha256']??null)===$manifest['CoreDLL']['sha256']
    &&($batch['compiled_launcher_sha256']??null)===$manifest['launcher']['sha256']);
$ck('four_native_oracle_cases_recorded',($batch['case_count']??0)===4);
foreach($manifest['cases'] as $case){
    $label=$case['name'];$ck('native_log_sha_'.$label,$sha($case['native_log']));
    $log=(string)file_get_contents($case['native_log']['path']);
    $native=$case['expected'];
    $ck('real_compiled_engine_correct_outcome_'.$label,
        $native==='native_serial_size_mismatch'
        ?str_contains($log,$case['error'])
            &&str_contains($log,'native_error=1')
            &&str_contains($log,'Exiting due to error')
        :str_contains($log,'G5_NATIVE_OBJECT_COUNT 33')
            &&str_contains($log,'native_error=0'));
    if($label==='invalid_type_zero'||$label==='known_unknown_property_name'){
        $ck('compiled_actual_skips_TimeSeconds_'.$label,
            !str_contains($log,'G5_NATIVE_TAG_VALUE  TimeSeconds='));
    }elseif($label==='declared_float_size12'){
        $ck('native_loaded_FLOAT_matches_original_bytes_'.$label,
            str_contains($log,'G5_NATIVE_TAG_VALUE  TimeSeconds=17.008888'));
    }
    $ck('original_source_one_byte_to_change_'.$label,
        is_string($pristine)
        &&ord($pristine[$case['position']])===$case['original_byte']);
    $mut=$pristine;
    $mut[$case['position']]=chr((int)$case['mutated_byte']);
    $diff=0;
    for($i=0;$i<strlen($mut);$i++)if($mut[$i]!==$pristine[$i])$diff++;
    $ck('exactly_one_mutated_byte_'.$label,$diff===1);
    $error=null;$tag=null;$found=null;
    try{
        if(file_put_contents($source,$mut)!==strlen($mut)){
            throw new RuntimeException('Failed to write disposable malformed archive');
        }
        $p=new P($source);
        $tag=T::inspect($source,$p,0,'ut99-v432');
        $found=$tag['tags'][0]??null;
    }catch(\Throwable $e){$error=$e->getMessage();}
    finally{
        file_put_contents($source,$pristine);
    }
    $ck('original_full_SHA_restored_after_'.$label,
        hash_file('sha256',$source)===$manifest['original']['sha256']);
    if($native==='native_serial_size_mismatch'){
        $match=$error===$case['error'];
        $ck('source_matches_native_exact_error_'.$label,$match);
    }else{
        $match=is_array($tag)&&($tag['status']??'')==='tag_envelope_decoded'
            &&count($tag['tags']??[])===$case['expected_original_tags']
            &&($tag['native_tail_bytes']??null)===0
            &&is_array($found)
            &&($found['name']??'')===$case['expected_first_name']
            &&($found['value_kind']??'')===$case['expected_kind']
            &&($found['native_load_policy']??'')===$case['expected_first_policy']
            &&($found['on_disk_value_bytes']??null)===$case['expected_consumed_bytes'];
        if($native==='native_load_pass_skip')$match=$match&&array_key_exists('value',$found)&&$found['value']===null;
        if($native==='native_load_pass_float4')$match=$match
            &&($found['serialized_size']??null)===$case['expected_tag_size']
            &&abs((float)($found['value']??0)-$case['expected_loaded_float'])<0.00002;
        $ck('source_matches_compiled_loaded_and_cursor_'.$label,$match);
    }
    if($match)$correct++;
    $count++;
}
$ck('four_compiled_real_outcomes_now_agree_with_PHP',
    $correct===4&&$count===4);
$ck('source_ACTOR_inheritance_requires_original_Engine_only',
    str_contains((string)file_get_contents($root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassDefaultSchemaReader.php'),
        "actor_super_only_archive")
    &&str_contains((string)file_get_contents($root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php'),
        "native_warning_skip_declared_tag_bytes"));
$ck('source_original_Entry_restored_before_positive_proof',
    hash_file('sha256',$source)===$manifest['original']['sha256']);

$helpers=[
    ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-current-tags.php'],
    ['php',$root.'/catalog/bin/ue1-o06-g5-uobject-extract-native-ref-map.php'],
    ['python',$root.'/catalog/bin/ue1-o06-g5-uobject-compare-loaded-values.py']
];
$comparison=null;
foreach($helpers as $index=>[$bin,$script]){
    $out=[];$exit=255;
    exec(escapeshellarg($bin).' '.escapeshellarg($script).' 2>&1',$out,$exit);
    $ck('existing_113_real_objects_current_parser_'.$index,$exit===0);
    if($exit!==0){$errors[]='reader '.$index.': '.substr(implode("\n",$out),0,200);break;}
    if($index===2)$comparison=json_decode(implode("\n",$out),true);
}
$ck('all_33_real_loaded_original_exports_preserved',
    ($comparison['original_33_loaded_classes']??0)===33);
$ck('all_113_saved_native_tagged_values_after_fix_still_match',
    ($comparison['total_tags']??0)===113
    &&($comparison['exactly_compared']??0)===113
    &&($comparison['problems']??null)===[]);
$ck('compiled_original_loaded_values_source_SHA',
    ($comparison['compiled_native_log_sha256']??'')===$old['compiled_loaded_values_log']['sha256']);
$ck('archive_remains_genuine_after_all_cases',
    hash_file('sha256',$source)===$manifest['original']['sha256']);
$ck('method_FULL_not_claimed',$manifest['full_method_accepted']===false);
echo json_encode([
    'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
    'errors'=>$errors,'method'=>$manifest['method'],
    'native_tag_error_cases_compared'=>$count,
    'native_tag_error_cases_matching'=>$correct,
    'original_native_loaded_exports_preserved'=>$comparison['original_33_loaded_classes']??0,
    'all_native_tagged_positive_values_matched'=>$comparison['exactly_compared']??0,
    'compiled_oracle_executable_replays'=>0,
    'native_tagged_error_full_method_acceptance'=>'BLOCKED',
    'remaining'=>'other unsupported tag/reference error and source-version branches, full method consumed byte semantics and independent full acceptance'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
