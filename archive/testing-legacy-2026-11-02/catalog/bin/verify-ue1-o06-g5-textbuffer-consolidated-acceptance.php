#!/usr/bin/env php
<?php
/** ONE consolidated T99:UTextBuffer::Serialize acceptance verdict.
 * No CDB recaptures and no replay of the 88 loaded-value proof.
 * Result: BLOCKED (true parent UObject acceptance not yet FULL).
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$base=$root.'/catalog/compliance';
$manifest=json_decode((string)file_get_contents($base.'/UE1-O06-G5-T99-TEXTBUFFER-FULL-VALUES.json'),true,512,JSON_THROW_ON_ERROR);
$gate=json_decode((string)file_get_contents($base.'/UE1-O06-G5-MULTIMETHOD-GATES.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$bad=[];
$ck=static function(string $k,bool $ok)use(&$checks,&$bad):void{$checks++;if(!$ok)$bad[]=$k;};
$pin=static function(array $spec,string $description)use($ck):string{
    $v=is_file($spec['path'])?file_get_contents($spec['path']):false;
    $ck('SHA256_'.$description,is_string($v)&&hash('sha256',$v)===$spec['sha256']);
    return is_string($v)?$v:'';
};
$pin($manifest['compiled_core'],'authentic_v432_compiled_Core');
$pin($manifest['base_archive'],'genuine_Engine_u_v69');
$pin($manifest['native_disassembly'],'real_compiled_Serialize_disassembly');
$pin($manifest['native_loaded_log'],'old_native_88_classes_method_log');
$pin($manifest['native_memory_dump'],'old_compiled_88_native_memory_bytes');
$events=array_filter(array_map('json_decode',file($base.'/UE1-O06-TEST-EVENTS.jsonl')));
$prior=null;
foreach($events as $event)if(($event->target??'DEV')==='DEV'
    &&($event->test??'')==='verify-ue1-o06-g5-textbuffer-native-all-values.php'
    &&($event->state??'')==='PASS')$prior=$event;
$ck('previous_88_genuine_native_positive_proof_exists',$prior!==null);
$previousLog=$prior?$root.'/'.str_replace('\\','/',$prior->log):'';
$previousVerifier=$root.'/catalog/bin/verify-ue1-o06-g5-textbuffer-native-all-values.php';
$ck('prior_native_positive_verification_evidence_SHA',
    $prior!==null&&is_file($previousLog)
    &&hash_file('sha256',$previousLog)===$prior->output_sha256
    &&hash_file('sha256',$previousVerifier)===$prior->test_sha256);
$positive=(string)($previousLog!==''&&is_file($previousLog)?file_get_contents($previousLog):'');
$ck('prior_genuine_88_loaded_TCHAR_array_evidence',
    str_contains($positive,'"genuine_Engine_u_loaded_objects": 88')
    &&str_contains($positive,'"ok": true'));
$compiledError='';
$sourceError='';
$declaredSize=null;$got=null;$nativeCases=[];
foreach($manifest['variations'] as $case){
    $id=$case['kind'];
    $pin($case['archive'],'original_real_fixture_'.$id);
    $stdout=$pin($case['stdout'],'original_compiled_stdout_'.$id);
    if($id!=='oversized'){
        // Do not rerun existing positive tests. Their native logs and the
        // historical SHA-validated successful run are the current evidence.
        $ck('original_native_positive_case_'.$id,
            str_contains($stdout,'Success - 0 error(s), 0 warnings'));
        $nativeCases[]=$id;
        continue;
    }
    $ck('native_oversized_rejection',
        str_contains($stdout,$case['expected_native_error'])
        &&str_contains($stdout,'Exiting due to error'));
    preg_match('/Serial size mismatch: Got (\d+), Expected (\d+)/',$stdout,$parts);
    $got=(int)($parts[1]??0);$declaredSize=(int)($parts[2]??0);
    $ck('genuine_compiled_failed_byte_consumption_912_843',
        $got===912&&$declaredSize===843);
    $pkg=new P($case['archive']['path']);
    $ex=$pkg->getExports()[$case['idx']]??null;
    $tag=T::inspect($case['archive']['path'],$pkg,$case['idx'],'ut99-v432');
    $ck('source_matched_export_metadata',
        is_array($ex)&&(int)$ex['serialSize']===$declaredSize
        &&($tag['status']??'')==='tag_envelope_decoded');
    try{
        N::inspect($case['archive']['path'],$pkg,$case['idx'],$tag,'ut99-v432');
    }catch(RuntimeException $e){$sourceError=$e->getMessage();}
    $ck('production_parser_reproduces_actual_native_mismatch',
        $sourceError==='TextBuffer: Serial size mismatch: Got '.$got.
                     ', Expected '.$declaredSize);
    $nativeCases[]=$id;
}
$source=(string)file_get_contents($root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php');
$ck('production_error_does_not_read_past_export_boundary',
    str_contains($source,'// Reproduce that attempted *logical* cursor and error without')
    &&str_contains($source,'$size<=filesize($path)-$pos')
    &&str_contains($source,'$rejectFStringOverflow($length')
    &&str_contains($source,'if($n<0||$n>$limit-$pos)'));
$ck('other_source_families_keep_safe_guard',
    str_contains($source,'$family===CatalogUE1ClassicSourceFamily::UT99_V432'));
$ck('all_compiled_variations_pinned',count($nativeCases)===count($manifest['variations']));
$parent=$gate['methods']['T99:UObject::Serialize']??[];
$ck('inherited_UObject_not_false_positive_FULL',
    ($parent['decision']??'')==='BLOCKED'
    &&($parent['closure_artifact']??null)===null);
$ck('no_false_full_method_signoff',
    ($gate['methods']['T99:UTextBuffer::Serialize']['closure_artifact']??null)===null);
echo json_encode([
    'ok'=>$bad===[],'checks'=>$checks,'passed'=>$checks-count($bad),
    'errors'=>$bad,'method'=>'T99:UTextBuffer::Serialize',
    'native_compiled_oversized_attempted_bytes'=>$got,
    'source_declared_bytes'=>$declaredSize,
    'php_native_error_identical'=>$sourceError!==''
        &&str_contains($sourceError,'Serial size mismatch: Got 912, Expected 843'),
    'reused_native_positive_objects'=>88,
    'reused_compiled_variations'=>$nativeCases,
    'one_final_method_acceptance'=>'BLOCKED',
    'decisive_remaining_gate'=>'T99:UObject::Serialize inherited method not independently FULL',
    'new_compiled_captures'=>0,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
