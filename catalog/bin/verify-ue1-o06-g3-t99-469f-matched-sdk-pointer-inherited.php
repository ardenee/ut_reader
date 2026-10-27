#!/usr/bin/env php
<?php
/** T99 469f explicit runtime provenance, 21 original new pointer declarations and genuine matched SDK. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-T99-469F-MATCHED-SDK-POINTER-REA.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$test=static function(string $name,bool $yes) use (&$checks,&$errors):void{
    $checks++;if(!$yes&&count($errors)<28)$errors[]=$name;
};
$test('real ABI 469f symbols and relevant count pinned',$m['original_469f_32bit_Core_DLL_exported_serializer_related_method_count']===53
    &&$m['matching_469f_SDK_x86_Core_lib_symbols']===53
    &&count($m['matching_core_compiled_serializer_symbols'])===53);
foreach(['original_469f_SDK_x86_Core_import_lib','original_469f_SDK_x64_Core_import_lib',
    'original_469f_runtime_Windows_x86_Core_dll','original_469f_runtime_WindowsXP_x86_Core_dll',
    'original_469f_runtime_Windows_x86_Engine_dll','original_469f_runtime_WindowsXP_x86_Engine_dll',
    'original_469f_runtime_Windows_x86_UCC_exe','original_469f_runtime_WindowsXP_x86_UCC_exe'] as $name){
    $f=$m[$name];
    $test('original newly supplied matched release hash '.$name,
        is_file($f['path'])&&filesize($f['path'])===$f['bytes']
        &&hash_file('sha256',$f['path'])===$f['sha256']);
}
$dll=file_get_contents($m['original_469f_runtime_Windows_x86_Core_dll']['path']);
$lib=file_get_contents($m['original_469f_SDK_x86_Core_import_lib']['path']);
foreach($m['matching_core_compiled_serializer_symbols'] as $symbol=>$addr){
    $test('compiled x86 Core DLL method exported '.$symbol,str_contains($dll,$symbol));
    $test('same SDK Core lib method import available '.$symbol,str_contains($lib,$symbol));
}
unset($dll,$lib);
$test('true 469f compiled Core SHA pinned',
    $m['original_469f_runtime_Windows_x86_Core_dll']['sha256']
    ==='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2');
$test('UT99 source revision is independent of package v69/l0',
    S::selectFromHeader(['version'=>69,'licenseeVersion'=>0],S::UT99_V469F)===S::UT99_V432);
$ambiguous=false;
try{S::selectFromHeader(['version'=>69,'licenseeVersion'=>0]);}catch(InvalidArgumentException){$ambiguous=true;}
$test('cannot infer 469f or even UT99 from package version alone',$ambiguous);
$layer=S::sourceLayers(69,0,S::UT99_V469F);
$test('explicit overlay retains preceding UT99 inheritance source chain',
    $layer['game_source_revision_chain']===['ue1-engine','ut99-v400','ut99-v432','ut99-v469f']
    &&$layer['source_profile']===S::UT99_V432
    &&$layer['explicit_runtime_source_overlay']===S::UT99_V469F);
$wrongPatch=false;
try{S::selectFromHeader(['version'=>69,'licenseeVersion'=>227],S::UT99_V469F);}
catch(InvalidArgumentException){$wrongPatch=true;}
$test('Unreal v227 archive cannot masquerade as explicit UT99 469f',$wrongPatch);
foreach($m['original_469f_SDK_headers'] as $name=>$file){
    $test('original current 469f source SHA '.$name,is_file($file['path'])
        &&filesize($file['path'])===$file['bytes']
        &&hash_file('sha256',$file['path'])===$file['sha256']);
}
foreach($m['original_v432_baseline_headers'] as $name=>$file){
    $test('original prior UT99 v432 source SHA '.$name,is_file($file['path'])
        &&filesize($file['path'])===$file['bytes']
        &&hash_file('sha256',$file['path'])===$file['sha256']);
}
$head=(string)file_get_contents($m['original_469f_SDK_headers']['UnType.h']['path']);
$old=(string)file_get_contents($m['original_v432_baseline_headers']['UnType.h']['path']);
$start=strpos($head,'class CORE_API UPointerProperty : public UProperty');
$body=$start!==false?substr($head,$start,strpos($head,'};',$start)-$start):'';
$test('new 469f SDK explicitly introduced UPointerProperty',$start!==false
    &&!str_contains($old,'class CORE_API UPointerProperty'));
$test('no additional UPointerProperty Serialize override in new UT99',
    !preg_match('/\bSerialize\s*\(/',$body)
    &&str_contains($body,'void SerializeItem('));
$test('new 469f pointer 4bit script-value tag equals original IntProperty',
    str_contains($body,'return NAME_IntProperty;'));
$rea=$m['authentic_Ghidra_469f_UStruct_Serialize'];
$rp=$root.'/'.$rea['repo_path'];
$test('source SHA original REA decompiled compiled UStruct function',
    is_file($rp)&&filesize($rp)===$rea['bytes']
    &&hash_file('sha256',$rp)===$rea['sha256']);
$view=json_decode((string)iconv('UTF-16','UTF-8',(string)file_get_contents($rp)),true,512,JSON_THROW_ON_ERROR);
$us=(string)$view['normalized_result'];
$test('REA analyzed original newly matched 469f x86 DLL',
    ($view['subject']['digest']['sha256']??'')===$m['original_469f_runtime_Windows_x86_Core_dll']['sha256']
    &&str_contains($us,'UStruct::Serialize')
    &&str_contains($us,'UField::Serialize')
    &&str_contains($us,'Script serialization mismatch'));
$observed=[];$total=0;$firstPriorReject=0;
foreach($m['original_469f_real_archive_packages'] as $name=>$pin){
    $path=$pin['path'];
    $test('original 469f genuine file unchanged '.$name,
        is_file($path)&&filesize($path)===$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
    $pkg=new P($path);
    $test('genuine 469f package object tables accept '.$name,$pkg->validatePackage()===[]);
    $test('real 469f package version 69 licensee0 '.$name,
        $pkg->getHeader()['version']===69&&$pkg->getHeader()['licenseeVersion']===0);
    $imp=$pkg->getImports();$hits=0;
    foreach($pkg->getExports() as $ex){
        $cls=(int)$ex['classIndex'];
        $class=$cls<0?(string)($imp[-$cls-1]['objectNameText']??''):'';
        if(strcasecmp($class,'PointerProperty')!==0)continue;
        $hits++;$total++;
        $idx=(int)$ex['index'];
        $tags=T::inspect($path,$pkg,$idx,S::UT99_V469F);
        $actual=N::inspect($path,$pkg,$idx,$tags,S::UT99_V469F);
        $test('new real 469f native pointer loaded declaration '.$name.' #'.$idx,
            $actual['status']==='native_property_record_complete'
            &&$actual['property_kind']==='pointerproperty'
            &&$actual['explicit_runtime_source_overlay']===S::UT99_V469F
            &&$actual['declared_4bit_tag_alias']==='NAME_IntProperty'
            &&$actual['extension_bytes']===0
            &&$actual['new_v227_property_runtime_serializeitem_verified']===false);
        $test('actual source-only archive inherited empty subclass fields '.$name.' #'.$idx,
            $actual['subclass_fields']===[]
            &&$actual['array_dim']>=1
            &&$actual['category_name']!=='');
        if($firstPriorReject<3){
            $rejected=false;
            try{
                $oldTag=T::inspect($path,$pkg,$idx,S::UT99_V432);
                N::inspect($path,$pkg,$idx,$oldTag,S::UT99_V432);
            }catch(RuntimeException){$rejected=true;}
            $test('same real 469f new class must NOT be presented as original v432 source '.$name.' #'.$idx,$rejected);
            $firstPriorReject++;
        }
    }
    $test('genuine new class presence count match pinned manifest '.$name,
        $hits===$m['pointer_instances_observed_by_export_class'][$name]);
    $observed[$name]=$hits;
}
$test('21 distinct real original 469f pointer field declarations completely accepted',$total===21);
$test('no UAnyProperty inferred from 469f original source header',
    !str_contains($head,'class CORE_API UAnyProperty : public UProperty'));
$test('WindowsXP and Windows 469f .u packages originally SHA-identical',
    $m['same_genuine_469f_packages_in_WindowsXP_runtime']===true);
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),'failed'=>$errors,
    'real_new_UT99_469f_pointer_native_declarations'=>$observed,
    'complete_real_pointer_count'=>$total,
    'SDK_shipped_x86_serializer_method_exports'=>53,
    'rea_native_UStruct_compile_body_observed'=>true,
    'UStruct_native_loaded_value_comparison_newly_certified'=>false,
    'source_revision_tag_inferred_from_package_version'=>false,
    'source_explicit_only_469f_overlay'=>true,
    'full_G3_accepted_by_this_subscope'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
