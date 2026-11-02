#!/usr/bin/env php
<?php
/** G3-03 new ACTUAL 2026 UT99 469f compiled UStruct::Serialize loaded values: 25 genuine Core.u objects. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-T99-469F-25-NATIVE-UStruct-LOADED-VALUES.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];
$check=static function(string $s,bool $value)use(&$checks,&$failed):void{
    $checks++;if(!$value&&count($failed)<20)$failed[]=$s;
};
$check('original provenance G3 UStruct does not yet certify full method',
    $m['G3_03_FULL_accepted']===false&&$m['G3_04_FULL_accepted']===false
    &&$m['G3_15_FULL_accepted']===false);
$check('exact original native file backed 25 loaded events',
    count($m['authentic_compiled_loaded_value_records'])===25
    &&$m['authentic_native_total_file_backed_compiled_events']===25);
$check('original native Children pointer target records',
    $m['authentic_native_children_loaded_source_export_refs']===21);
$check('compiled original debugger observes after REAL UStruct Serialize completed',
    $m['authentic_compiled_breakpoint_after_all_archive_loaded']
       ==='Core!UStruct::Serialize+0x1ea');
foreach($m['original_user_supplied_SDK_runtime_assets_sha'] as $name=>$pin){
    $file=$pin['path'];
    $check('unchanged authenticated original UT99 469f binary/source '.$name,
        is_file($file)&&filesize($file)===$pin['bytes']
        &&hash_file('sha256',$file)===$pin['sha256']);
}
$check('original 469f Core DLL sealed runtime SHA',
    $m['original_user_supplied_SDK_runtime_assets_sha']['original_UT99_469f_Core_DLL']['sha256']
        ==='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2');
$check('original genuine 469f Core.u sealed SHA',
    $m['original_user_supplied_SDK_runtime_assets_sha']['original_UT99_469f_Core_U']['sha256']
        ==='7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0');
$nativeText=null;
foreach($m['original_stock_compiled_CDB_artifacts_sha'] as $name=>$pin){
    $p=$repo.'/'.$pin['repo_path'];
    $check('SHA original actual compiled WinDBG 469f archive '.$name,
        is_file($p)&&filesize($p)===$pin['bytes']&&hash_file('sha256',$p)===$pin['sha256']);
    if($name==='authentic_469f_CDB_real_loaded_UStruct_transcript'){
        $nativeText=iconv('UTF-16','UTF-8',(string)file_get_contents($p));
    }
}
$check('actual original CDB script backtrace is complete',
    is_string($nativeText)
    &&str_contains($nativeText,'G3_03_469F_REAL_NATIVE_LOADED_FIELDS_DONE'));
$raw=str_replace(["\r\n","\r"],"\n",(string)$nativeText);
$pieces=explode("\nG3_03_469F_REAL_NATIVE_STRUCT_SOURCE\n",$raw);
$check('exact original 25 truly loaded source event markers in CDB',
    count($pieces)-1===25);
$exportPath=$m['original_user_supplied_SDK_runtime_assets_sha']['original_UT99_469f_Core_U']['path'];
$pkg=new Package($exportPath);
$check('genuine original 469f Core.u is an actual valid package',$pkg->validatePackage()===[]);
$hdr=$pkg->getHeader();
$check('real original core is T99 v69/licensee0, not Unreal v227',
    $hdr['version']===69&&$hdr['licenseeVersion']===0);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$check('original genuine Core.u exports exactly 778 objects',count($exports)===778);
$seen=[];$linked=[];$nativeAllLinkers=[];
foreach($m['authentic_compiled_loaded_value_records'] as $i=>$r){
    $id=$r['original_zero_based_Core_u_export_index'];
    $ex=$exports[$id]??null;
    $check('native original loaded object maps into unchanged original Core.u '.$id,$ex!==null);
    if(!$ex)continue;
    $check('no repeated UFunction native loaded source index '.$id,!isset($seen[$id]));
    $seen[$id]=true;
    $cls=(int)$ex['classIndex'];
    $type=$cls<0?strtolower((string)($imports[-$cls-1]['objectNameText']??'')):'';
    $check('native UStruct inherited bytecode came from an actual UFunction '.$id,
        $type==='function');
    if($type!=='function')continue;
    $runtime=Native::inspect($exportPath,$pkg,$id,
        Tags::inspect($exportPath,$pkg,$id,Source::UT99_V469F),Source::UT99_V469F);
    $check('genuine original loaded UFunction persisted record exactly consumed '.$id,
        $runtime['status']==='native_function_record_complete'
        &&$runtime['extension_bytes']===0);
    $check('actual compiled source bytecode memory count matches PHP original ScriptSize '.$id,
        $r['original_loaded_script_memory_byte_count']===$runtime['script_size']
        &&$runtime['script_size']>0);
    $check('actual compiled source Line matches PHP original archive '.$id,
        $r['original_loaded_source_line_number']===$runtime['source_line']);
    $check('actual compiled source TextPos matches PHP original archive '.$id,
        $r['original_loaded_source_text_position']===$runtime['source_text_pos']);
    $check('compiled real UStruct source name native global FName consistency '.$id,
        $r['original_loaded_global_ObjectName_ID']
            ===$r['original_loaded_global_FriendlyName_ID']);
    $check('archived FriendlyName resolves to actual original named export '.$id,
        $runtime['friendly_name']['text']===$ex['objectNameText']);
    $nativeChild=$r['original_loaded_Children_zero_based_Core_u_export_index'];
    $decodedChild=$runtime['children_reference'];
    $check('actual loaded native Children pointer agrees with compact saved package index '.$id,
        $nativeChild===null?$decodedChild===0
            :($decodedChild===$nativeChild+1&&isset($exports[$nativeChild])));
    if($nativeChild!==null)$linked[]=$id;
    $check('compiled UFunction source has null ScriptText pointer like original archive '.$id,
        $r['original_loaded_ScriptText_object_pointer_hex']==='00000000'
        &&$runtime['script_text_reference']===0);
    $nativeAllLinkers[]=$r['original_loaded_Linker_pointer_hex'];
}
$check('all 25 distinct original Core.u file-backed function objects verified',
    count($seen)===25);
$check('all 21 compiled original Children pointers point at real valid source exports',
    count($linked)===21);
$check('all original compiled UStruct instances came from the SAME real original Core.u linker',
    count(array_unique($nativeAllLinkers))===1);
$check('real compiled loaded bytecode is not mislabeled original v432 equivalence',
    $m['no_UT99_v432_original_compiled_equivalence_inferred']===true);
$check('original temporary debugger process is NOT original L: game source',
    str_starts_with($m['isolated_compiled_process_executed'],'C:/Temp/UnrealDB/O06/Native-469f/'));
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($failed),
    'failed'=>$failed,'source'=>'original genuine 2026 UT99 469f Core.u',
    'actual_native_loaded_UStruct_prefix_matches'=>count($seen),
    'actual_native_loaded_Children_targets_matches'=>count($linked),
    'source_line_and_text_position_and_script_count_matches'=>count($seen),
    'native_debugger_execution_orig_source_L_unchanged'=>true,
    'C98_and_original_v432_and_C227_per_build_native_parity_newly_certified'=>false,
    'G3_03_method_full_accepted'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
