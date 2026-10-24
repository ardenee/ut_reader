#!/usr/bin/env php
<?php
/** ONE consolidated real UT99 v432 UObject method verdict.
 * Reuses SHA-pinned compiled native 14-of-19 package load and 3 malformed
 * offset oracles. Rechecks only corrected production negative behaviour.
 * No method acceptance without full transitive/native tag/error proof.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(
    $repo.'/catalog/compliance/UE1-O06-G5-UOBJECT-NATIVE-FULL-ACCEPTANCE-ATTEMPT.json'
),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$matched=0;$sourceCount=0;$loadedCount=0;$omitted=[];
$ck=static function(string $name,bool $pass)use(&$checks,&$errors):void{
    $checks++;
    if(!$pass&&count($errors)<25)$errors[]=$name;
};
$pin=static function(array $e,string $name)use($ck):string{
    $s=is_file($e['path'])?file_get_contents($e['path']):false;
    $ck('SHA256_'.$name,is_string($s)&&hash('sha256',$s)===$e['sha256']);
    return is_string($s)?$s:'';
};
$pin($manifest['compiled_Core'],'original_compiled_v432_Core_DLL');
$pin($manifest['compiled_Core_lib'],'original_v432_import_library');
$pin($manifest['selected_v432_header'],'original_UObject_flags_source');
$pin($manifest['inherited_v400_method_source'],'original_inherited_method_source');
$pin($manifest['compiled_native_launcher'],'compiled_real_native_engine_launcher');
$package=$pin($manifest['original_map'],'unmodified_original_Entry');
$log=$pin($manifest['original_load_logs'][0],'real_compiled_LoadPackage_stdout');
$log2=$pin($manifest['original_load_logs'][1],'real_compiled_14_StateFrame_referents');
if(str_starts_with($log2,"\xff\xfe"))$log2=(string)iconv('UTF-16LE','UTF-8',substr($log2,2));
$ck('actual_original_package_native_load_completed',
    str_contains($log,'G5_NATIVE_DIRECT_PACKAGE_BEGIN editor=1 client=0 server=0')
    &&preg_match('/G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=(?!00000000)[0-9A-F]+ editor=1/',$log)===1);
preg_match_all('/G5_NATIVE_LOADPACKAGE_FRAME idx=(\d+)/',$log,$native);
$seen=array_fill_keys(array_map('intval',$native[1]),true);
$ck('exactly_14_native_positive_StateFrame_calls',
    count($native[1])===$manifest['compiled_original_postSerialize_positive']
    &&count($seen)===14);
preg_match_all('/G5_NATIVE_FRAME_REF idx=(\d+)/',$log2,$referents);
$ck('prior_14_real_native_reference_records_match',
    count($referents[1])===14
    &&array_diff(array_keys($seen),array_map('intval',$referents[1]))===[]);
$p=new P($manifest['original_map']['path']);
$ck('source_original_Entry_v68',$p->getHeader()['version']===68);
foreach($p->getExports() as $exp){
    if(!((int)$exp['objectFlags']&0x02000000))continue;
    $sourceCount++;
    $id=(int)$exp['index'];
    if(!isset($seen[$id]))$omitted[]=$id;
}
sort($omitted);
$ck('all_19_genuine_StateFrames_identified',
    $sourceCount===$manifest['original_positive_RF_HasStack']);
$ck('five_not_native_loaded_exact_saved_editor_context_candidates',
    $omitted===$manifest['original_omitted_indices']);
foreach($omitted as $id){
    $row=$p->getExports()[$id];
    $classRef=(int)$row['classIndex'];
    $klass=$classRef<0?$p->getImports()[-$classRef-1]['objectNameText']:'';
    $ck('excluded_original_Camera_flags_'.$id,
        $klass==='Camera'
        &&sprintf('0x%08x',(int)$row['objectFlags'])===$manifest['original_omitted_flags']);
}
$ck('all_native_compiled_export_indices_match_original',
    array_diff(array_keys($seen),array_column($p->getExports(),'index'))===[]);
$ck('all_original_bytes_and_loader_modes_pinned',
    $package!==''&&str_contains($manifest['compiled_loader_mode'],'GIsEditor=1'));
$sourceFile=$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicStateFrameReader.php';
$parser=(string)file_get_contents($sourceFile);
$ck('production_source_script_num_validation_not_a_Brush_literal',
    str_contains($parser,'referencedNodeScriptNum(')
    &&str_contains($parser,'$codeOffset>=$nodeScriptNum')
    &&!str_contains($parser,"$"."targetName==='Brush'"));
$mapPath='C:/Temp/UnrealDB/O06/Native-UCC/Maps/Entry.unr';
$mapOriginal=is_file($mapPath)?file_get_contents($mapPath):false;
$ck('disposable_map_unchanged_before_negative_proofs',
    $mapOriginal!==false&&hash('sha256',$mapOriginal)===$manifest['original_map']['sha256']);
if(!is_string($mapOriginal)||$mapOriginal!==$package){
    $errors[]='can_not_run_negative_proofs_without_identical_unmodified_fixture';
}else{
    foreach($manifest['native_malformed_cases'] as $case){
        $nativeText=$pin($case,'genuine_compiled_negative_'.$case['label']);
        $ck('native_exact_error_'.$case['label'],
            str_contains($nativeText,$case['error'])
            &&str_contains($nativeText,'Exiting due to error')
            &&str_contains($nativeText,'SerializeStack <- UObject::Serialize'));
        $data=$package;
        $ck('original_one_byte_code_sentinel_'.$case['label'],ord($data[1821])===0x81);
        $data[1821]=chr((int)$case['byte']);
        $diff=0;
        for($i=0;$i<strlen($data);$i++){if($data[$i]!==$package[$i])$diff++;}
        $ck('exactly_one_mutated_source_byte_'.$case['label'],$diff===1);
        $message='';
        try{
            if(file_put_contents($mapPath,$data)!==strlen($data))
                throw new RuntimeException('Temporary fixture write failed');
            $modified=new P($mapPath);
            T::inspect($mapPath,$modified,2,'ut99-v432');
        }catch(\Throwable $e){$message=$e->getMessage();}
        finally{file_put_contents($mapPath,$mapOriginal);}
        $ck('production_native_error_exact_'.$case['label'],
            $message===$case['error']);
        $ck('original_fixture_restored_after_'.$case['label'],
            hash_file('sha256',$mapPath)===$manifest['original_map']['sha256']);
        if($message===$case['error'])$matched++;
    }
}
$ck('no_fake_full_method_acceptance',$manifest['full_method_accepted']===false);
$ck('native_errors_are_actually_replayed_only_in_PHP',$matched===3);
echo json_encode([
 'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),'failures'=>$errors,
 'method'=>$manifest['method'],
 'genuine_original_StateFrames'=>$sourceCount,
 'native_compiled_loader_positive_StateFrames'=>count($seen),
 'original_Camera_exports_context_excluded'=>$omitted,
 'native_malformed_offsets_php_exact_error_matches'=>$matched,
 'native_compiled_reruns_this_verification'=>0,
 'full_method_acceptance'=>'BLOCKED',
 'remaining'=>'complete inherited/tagged UObject loaded-property semantics and additional malformed object/property/reference errors plus independent final review'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
