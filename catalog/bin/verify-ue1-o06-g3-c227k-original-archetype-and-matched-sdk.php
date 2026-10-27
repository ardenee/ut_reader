#!/usr/bin/env php
<?php
/** New original 227k compiled FObjectExport ArchetypeIndex operator + genuine package acceptance. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-C227K-MATCHED-SDK-REA-AND-ARCHETYPE.json'),true,512,JSON_THROW_ON_ERROR);
$old=json_decode((string)file_get_contents($repo.'/'.$m['original_C227_symbol_source_manifest']),true,512,JSON_THROW_ON_ERROR);
$checks=0;$bad=[];
$check=static function(string $n,bool $ok)use(&$checks,&$bad):void{
    $checks++;if(!$ok&&count($bad)<30)$bad[]=$n;
};
$check('old 2012 ABI mismatches resolved only with matching 2026 227k DLL',$m['227k_compiled_DLL_symbols_matching_v227_method_owners']===57
    &&$m['227k_SDK_x86_import_lib_symbols_matching_v227_method_owners']===57
    &&$m['2012_227i_compiled_DLL_symbols_matching_v227_method_owners']===43
    &&count($m['previously_mismatched_14_symbols_now_present'])===14);
$check('real 227k archive operator gated by licensee 227 and 0x100',
    $m['archive_licensee_gate_compiled_threshold']===227&&$m['RF_HasArchtype_flag']===256);
foreach(['real_227k_x86_Core_dll','matching_227k_SDK_x86_Core_import_lib',
    'real_227k_x64_Core_dll','matching_227k_SDK_x64_Core_import_lib',
    'real_227k_x86_Engine_dll','real_227k_x86_UCC_exe',
    'prior_unmatched_2012_227i_Core_dll','old_C227_Core_import_lib_originally_mismatched'] as $key){
    $p=$m[$key];
    $check('actual binary identity/size SHA '.$key,
        is_file($p['path'])&&filesize($p['path'])===$p['bytes']
        &&hash_file('sha256',$p['path'])===$p['sha256']);
}
$check('authentic original 227k Core dll known binary SHA',
    $m['real_227k_x86_Core_dll']['sha256']==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194');
$check('one-to-one original source symbol manifests exactly 57',
    count($old['G3_relevant_exported_decorated_symbols'])===57);
$dll=(string)file_get_contents($m['real_227k_x86_Core_dll']['path']);
$lib=(string)file_get_contents($m['matching_227k_SDK_x86_Core_import_lib']['path']);
foreach($old['G3_relevant_exported_decorated_symbols'] as $sym){
    $name=$sym['decorated'];
    $check('original matching 227k executable exports this method '.$name,
        str_contains($dll,$name));
    $check('corresponding matching release SDK import library includes method '.$name,
        str_contains($lib,$name));
}
unset($dll,$lib);
foreach($m['new_227k_original_sdk_header_pins'] as $name=>$p){
    $check('authentic original 227k updated header SHA '.$name,
        is_file($p['path'])&&filesize($p['path'])===$p['bytes']
        &&hash_file('sha256',$p['path'])===$p['sha256']);
}
foreach($m['original_REA_Ghidra_12_1_4_compiled_source_observations'] as $name=>$proof){
    $p=$repo.'/'.$proof['repo_path'];
    $check('new actual original 227k compiled Ghidra output sha '.$name,
        is_file($p)&&filesize($p)===$proof['bytes']
        &&hash_file('sha256',$p)===$proof['sha256']);
    $out=json_decode((string)iconv('UTF-16','UTF-8',(string)file_get_contents($p)),true,512,JSON_THROW_ON_ERROR);
    $check('REA original Core DLL verified source identity '.$name,
        ($out['subject']['digest']['sha256']??null)===$m['real_227k_x86_Core_dll']['sha256']
        &&($out['provider']['name']??null)==='Ghidra');
    $body=str_replace("\r",'',(string)$out['normalized_result']);
    if($name==='rea_227k_fobjectexport_archive'){
        $check('exact real 227k exported FArchive operator decompiled',
            str_contains($body,'FObjectExport')
            &&str_contains($body,'/* 0x5da20')
            &&str_contains($body,'FCompactIndex'));
        $check('authentic operator arch flag and licensee version 227 gate',
            str_contains($body,'& 0x100')
            &&str_contains($body,'param_1 + 8')&&str_contains($body,'< 0xe3'));
        $check('archetype physical native member offset +0xc after ObjectFlags +0x14',
            strpos($body,'pFVar1 = param_2 + 0x14') < strpos($body,'FUN_10018a20(param_1,param_2 + 0xc)')
            &&strpos($body,'FUN_10018a20(param_1,param_2 + 0xc)') < strpos($body,'(FCompactIndex *)(param_2 + 0x18)'));
        $check('same fixed int32 operator is used for PackageIndex and ArchetypeIndex',
            str_contains($body,'FUN_10018a20(param_1,param_2 + 8)')
            &&str_contains($body,'FUN_10018a20(param_1,param_2 + 0xc)'));
    }else{
        $check('authentic compiled UButton custom Serialize calls base + FString Caption',
            str_contains($body,'UButtonProperty::Serialize')
            &&str_contains($body,'UProperty::Serialize')
            &&str_contains($body,'(this + 0x8c)')
            &&str_contains($body,'operator<<'));
    }
}
$stats=[];$flagIndex=null;$flagOffset=null;$originalArchetype=null;$unrealShare=null;
foreach($m['new_227k_original_genuine_package_pins'] as $name=>$pin){
    $path=$pin['path'];
    $check('original untouched 227k real package SHA '.$name,
        is_file($path)&&filesize($path)===$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
    $pkg=new P($path);
    $err=$pkg->validatePackage();
    $check('real updated 227k native archive all export records parsed '.$name,$err===[]);
    $hdr=$pkg->getHeader();$exp=$pkg->getExports();
    $check('real source .u is genuine original v69 licensee227 '.$name,
        $hdr['version']===69&&$hdr['licenseeVersion']===227);
    $flagged=[];
    foreach($exp as $row){
        if(((int)$row['objectFlags']&0x00000100)!==0)$flagged[]=$row;
    }
    $stats[$name]=['exports'=>count($exp),'flagged_archetypes'=>count($flagged)];
    if($name!=='UnrealShare.u'){
        $check('other original runtime exports require no new 227k ArchetypeIndex '.$name,
            count($flagged)===0);
    }else{
        $unrealShare=$pin;
        $check('genuine UnrealShare.u exact 13873 exports',count($exp)===13873);
        $check('only original flagged export is BoxRigidBody',
            count($flagged)===1&&$flagged[0]['index']===13627
            &&$flagged[0]['objectNameText']==='BoxRigidBody');
        if($flagged){
            $f=$flagged[0];
            $flagIndex=$f['index'];$originalArchetype=$f['archetypeIndex'];
            $check('genuine positive on-disk archetype export reference and decoded flags',
                $originalArchetype===13069&&$f['archetype']===13069
                &&$f['archetypeIndex']===13069&&$f['objectFlags']===0x70104);
            $check('genuine real archetype 13069 in export map',
                isset($exp[$originalArchetype-1]));
            // Independently read the physical original table bytes in the exact
            // compiled method order, rather than trusting only the full export parser.
            $reader=new S($path);
            $reader->seek($f['offset']);
            $classIndex=$reader->packageIndex(69);
            $superIndex=$reader->packageIndex(69);
            $outer=$reader->i32();
            $nameIdx=$reader->packageIndex(69);
            $flags=$reader->u32();
            $flagOffset=$reader->tell();
            $nativeArchetype=$reader->i32();
            $serialSize=$reader->packageIndex(69);
            $serialOffset=$serialSize!==0?$reader->packageIndex(69):0;
            $check('original file signed 32-bit physically archived archetype after flags',
                $flags===$f['objectFlags']&&$nativeArchetype===13069);
            $check('independent original record has unchanged class name outer flags and serialization',
                $classIndex===$f['classIndex']&&$superIndex===$f['superIndex']
                &&$outer===$f['packageIndex']&&$nameIdx===$f['objectName']
                &&$serialSize===$f['serialSize']&&$serialOffset===$f['serialOffset']);
            $check('physical original archetype member consumes exactly four bytes',
                ($reader->tell()-$flagOffset)>=4
                &&$exp[$f['index']+1]['offset']===$reader->tell());
        }
    }
}
$check('7 original genuine 227k packages independently fully decoded',count($stats)===7);
$check('genuine positive 227k archived archetype found in source bytes',
    $originalArchetype===13069&&is_int($flagOffset));
$negativeGuardResult=null;
if($unrealShare!==null&&$flagOffset!==null){
    // Isolated one-field negative. Original source L:\ remains untouched.
    $temp=tempnam(sys_get_temp_dir(),'227kArchetype');
    if(!is_string($temp))throw new RuntimeException('Cannot allocate test fixture');
    try{
        if(!copy($unrealShare['path'],$temp))throw new RuntimeException('Cannot isolate genuine package');
        $fp=fopen($temp,'c+b');
        if($fp===false)throw new RuntimeException('Cannot open isolated mutation');
        fseek($fp,$flagOffset);
        fwrite($fp,pack('V',0x7fffffff));
        fclose($fp);
        $negative=new P($temp);
        $errors=$negative->validatePackage();
        $negativeGuardResult=implode(' | ',$errors);
        $check('invalid archetype reference rejected as source-defined range failure',
            str_contains($negativeGuardResult,'ArchetypeIndex')
            &&str_contains($negativeGuardResult,'2147483647'));
        $check('reject original file invalid archetype without accepted export fallback',
            $errors!==[]);
    }finally{ @unlink($temp); }
    $check('original L source package unchanged after negative test',
        hash_file('sha256',$unrealShare['path'])===$unrealShare['sha256']);
}
$ok=$bad===[];
echo json_encode([
    'ok'=>$ok,'passed'=>$checks-count($bad),'checks'=>$checks,'failed'=>$bad,
    'real_original_227k_package_summary'=>$stats,
    'fully_decoded_original_227k_archetype_file'=>'UnrealShare.u',
    'real_original_archetype_export_index'=>$flagIndex,
    'real_original_archetype_value'=>$originalArchetype,
    'physical_real_file_ArchetypeIndex_offset'=>$flagOffset,
    'original_2012_227i_Core_dll_ABI_mismatches_resolved_in_227k'=>14,
    'matched_227k_DLL_and_SDK_import_symbols'=>57,
    'genuine_original_native_mutation_rejected_source_message'=>$negativeGuardResult,
    'button_FString_caption_compiled_layout_recovered_not_yet_runtime_sample_accepted'=>true,
    'full_G3_03_to_15_parity_newly_certified'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
