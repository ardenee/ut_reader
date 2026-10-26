#!/usr/bin/env php
<?php
/** One source- and stock compiled-runtime-verifiable May 1998 UProperty LOADED identity contract. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;

$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C98-MAY1998-UPROPERTY-1536-IDENTITIES.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$fails=[];$samples=[];
$check=static function(string $name,bool $ok)use(&$n,&$fails):void {
    ++$n;if(!$ok&&count($fails)<64)$fails[]=$name;
};
$check('strict authentic native loaded-only scope',
    ($m['status']??'')==='G3_C98_MAY17_NATIVE_UPROPERTY_1536_EXACT_EXPORT_IDENTITIES_POSITIVE_LOAD_ONLY'
    &&$m['G3_full_contracts_accepted']===0);
foreach($m['original_binary_and_packages'] as $name=>$ref){
    $file=(string)$ref['path'];
    $check('untouched authentic original May binary/package '.$name,
        is_file($file)&&filesize($file)===(int)$ref['bytes']
        &&hash_file('sha256',$file)===$ref['sha256']);
}
$check('original May17 Core.dll genuine archive SHA',
    $m['original_binary_and_packages']['original_May1998_Core_dll']['sha256']
      ==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
$check('original Core.u archive SHA',
    $m['original_binary_and_packages']['original_May1998_Core_u']['sha256']
      ==='7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c');
foreach($m['original_Epic_source_file_sha'] as $name=>$ref){
    $file=(string)$ref['path'];
    $check('original v1.200 source file SHA '.$name,
        is_file($file)&&filesize($file)===(int)$ref['bytes']
        &&hash_file('sha256',$file)===$ref['sha256']);
}
$cpp=(string)file_get_contents($m['original_Epic_source_file_sha']['UProperty_serialize_original_UnProp_cpp']['path']);
$fnameSrc=(string)file_get_contents($m['original_Epic_source_file_sha']['FName_case_insensitive_registry_original_UnName_cpp']['path']);
$fnameHead=(string)file_get_contents($m['original_Epic_source_file_sha']['FName_index_equality_original_UnName_h']['path']);
$check('original source UProperty explicit serialization order and load Offset reset',
    preg_match('/void UProperty::Serialize\s*\( FArchive& Ar \).*?UField::Serialize\( Ar \);.*?Ar << ArrayDim << PropertyFlags << Category;.*?PropertyFlags & CPF_Net.*?Ar << RepOffset;.*?Ar.IsLoading\(\).*?Offset = 0;/s',$cpp)===1);
$check('original FName intern case-insensitive original appStricmp',
    str_contains($fnameSrc,'appStrihash(ValidName)') && str_contains($fnameSrc,'appStricmp( ValidName, Hash->Name )==0'));
$check('original FName loaded identity tests index, not display casing',
    str_contains($fnameHead,'return Index==Other.Index;'));
foreach($m['original_compiled_debugger_proof'] as $name=>$ref){
    $file=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,(string)$ref['path']);
    $check('sealed original CDB evidence SHA '.$name,
        is_file($file)&&filesize($file)===(int)$ref['bytes']
        &&hash_file('sha256',$file)===$ref['sha256']);
}
$disasmFile=$root.'/'.$m['original_compiled_debugger_proof']['stock_compiled_UProperty_disassembly']['path'];
$disasm=iconv('UTF-16','UTF-8',(string)file_get_contents($disasmFile));
$check('original May compiled loader branch disassembled',
    is_string($disasm)&&str_contains($disasm,'10141470')&&str_contains($disasm,'101414e0')
    &&str_contains($disasm,'101414c7'));
$check('compiled method address is original executable, not import thunk',
    $m['compiled_UProperty_method_body']==='0x10141470'
    &&$m['compiled_positive_archive_fields_capture_PC']==='0x101414e7');

$traceFile=$root.'/'.$m['original_compiled_debugger_proof']['stock_1536_native_CDB_log']['path'];
$trace=iconv('UTF-16','UTF-8',(string)file_get_contents($traceFile));
if(!is_string($trace))throw new RuntimeException('Original CDB transcript UTF-16 invalid');
$sections=preg_split('/(?m)^MAY1998_C98_UPROPERTY_IDENTITY\s*$/',$trace);
if(!is_array($sections))throw new RuntimeException('Cannot split original CDB transcript');
$check('exact original 1536 compiled UProperty loaded-callback observations',count($sections)===1537);
$nativeRecords=[];$malformed=0;
foreach(array_slice($sections,1) as $index=>$section){
    $headers=[];$strings=[];
    foreach(preg_split('/\R/',$section) as $line){
        $line=trim($line);
        if(preg_match('/^[0-9a-f]{8}\s+((?:[0-9a-f]{8}\s*){1,4})$/i',$line,$match)){
            $headers[]=array_map('hexdec',preg_split('/\s+/',trim($match[1])));
        }
        if(preg_match('/^[0-9a-f]{8}\s+"([^"]*)"/i',$line,$match)){
            $strings[]=$match[1];
        }
    }
    if(count($headers)<3 || count($strings)<3
        ||count($headers[0])!==3 ||count($headers[1])!==4 ||count($headers[2])!==1){
        $malformed++;continue;
    }
    $nativeRecords[]=[
      'linker'=>sprintf('%08x',$headers[0][0]),
      'index'=>$headers[0][1],
      'name'=>$strings[0],
      'parent'=>$strings[1],
      'category'=>$strings[2],
      'array_dim'=>$headers[1][0],
      'property_flags'=>$headers[1][1],
      'rep_offset'=>$headers[1][3]&65535,
      'runtime_offset_after_load'=>$headers[2][0]
    ];
}
$check('all compiled property callback rows parsable with original fields',
    $malformed===0&&count($nativeRecords)===1536);

$links=$m['captured_original_linker_to_true_archive'];
$pkgs=[];$per=[];$seen=[];
foreach($links as $link=>$name){
    $original=$m['original_binary_and_packages']['original_May1998_'.str_replace('.u','_u',$name)];
    $path=(string)$original['path'];
    $package=new Package($path);
    $check('original package parses clean '.$name,$package->validatePackage()===[]);
    $exports=$package->getExports();$imports=$package->getImports();
    $check('source package version61 '.$name,$package->getHeader()['version']===61);
    $pkgs[$link]=['package'=>$package,'file'=>$path,'exports'=>$exports,'imports'=>$imports,'name'=>$name];
    $per[$name]=['observed'=>0,'matched'=>0,'all_property_exports'=>0,
        'network_rep_offset_verified'=>0,'non_default_category_verified'=>0,
        'display_case_variants'=>0,'source_errors'=>0];
    foreach($exports as $ex){
        $ci=(int)$ex['classIndex'];
        $kind=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):'';
        if(str_ends_with($kind,'Property'))$per[$name]['all_property_exports']++;
    }
    $seen[$name]=[];
}
foreach($nativeRecords as $i=>$row){
    $linker=$row['linker'];$info=$pkgs[$linker]??null;
    $check('native linker resolves to a real original source package '.$i,$info!==null);
    if($info===null)continue;
    $name=$info['name'];$per[$name]['observed']++;
    $idx=(int)$row['index'];$e=$info['exports'][$idx]??null;
    $check('original native linker export index exists '.$name.':'.$idx,$e!==null);
    if($e===null)continue;
    $check('native linked export index loaded once '.$name.':'.$idx,!isset($seen[$name][$idx]));
    $seen[$name][$idx]=true;
    $ci=(int)$e['classIndex'];
    $class=$ci<0?(string)($info['imports'][-$ci-1]['objectNameText']??''):'';
    $check('real property serializer class '.$name.':'.$idx,str_ends_with($class,'Property'));
    $outer=(int)$e['packageIndex'];
    $parentName=$outer>0?(string)($info['exports'][$outer-1]['objectNameText']??''):
        ($outer<0?(string)($info['imports'][-$outer-1]['objectNameText']??''):'');
    $saved=Native::inspect($info['file'],$info['package'],$idx,
        Tags::inspect($info['file'],$info['package'],$idx,'epic-ue1'),'epic-ue1');
    $checks=[
      'source_name_FName_identity'=>strcasecmp((string)$e['objectNameText'],$row['name'])===0,
      'source_parent_FName_identity'=>strcasecmp($parentName,$row['parent'])===0,
      'source_category_FName_identity'=>strcasecmp((string)($saved['category_name']??''),$row['category'])===0,
      'exact_loaded_ArrayDim'=>($saved['array_dim']??null)===$row['array_dim'],
      'exact_loaded_PropertyFlags'=>($saved['property_flags']??null)===$row['property_flags'],
      'exact_loaded_conditional_RepOffset'=>($saved['rep_offset']??null)===
            (($row['property_flags']&0x20)!==0?$row['rep_offset']:null),
      'source_class_serializer_completed'=>($saved['status']??'')==='native_property_record_complete',
      'compiled_UProperty_Load_cleared_Offset'=>$row['runtime_offset_after_load']===0,
    ];
    $success=true;
    foreach($checks as $field=>$valid){
        $check($field.' '.$name.':'.$idx,$valid);
        if(!$valid)$success=false;
    }
    if($success)$per[$name]['matched']++;else $per[$name]['source_errors']++;
    if(($row['property_flags']&0x20)!==0)$per[$name]['network_rep_offset_verified']++;
    if($row['category']!=='None')$per[$name]['non_default_category_verified']++;
    if((string)$e['objectNameText']!==$row['name']
        ||$parentName!==$row['parent']
        ||(string)($saved['category_name']??'')!==$row['category']){
        $per[$name]['display_case_variants']++;
    }
}
$expected=$m['identified_observed_per_original_package'];
foreach($expected as $name=>$total){
    $check('exact original native count for '.$name,$per[$name]['observed']===$total);
    $check('every native original property matched exact saved export '.$name,
        $per[$name]['matched']===$total);
}
$check('Core.u has original 327 property exports',$per['Core.u']['all_property_exports']===327);
$check('one unobserved original Core.u property is NOT claimed loaded',
    count($seen['Core.u'])===326 && !isset($seen['Core.u'][457])
    &&$pkgs['0238c140']['exports'][457]['objectNameText']==='ExecVtbl');
$check('Engine.u total original 2493 properties distinct from observed',
    $per['Engine.u']['all_property_exports']===2493);
$check('UnrealI.u total original 4576 properties distinct from observed',
    $per['UnrealI.u']['all_property_exports']===4576);
$check('all genuine original replicated property RepOffset fields tested',
    array_sum(array_column($per,'network_rep_offset_verified'))===107);
$check('all native loaded property runtime offsets cleared',
    count($nativeRecords)===1536);
$check('six original bird1 vs Bird1 case-only first-interned FName variations',
    array_sum(array_column($per,'display_case_variants'))===6
    &&$per['UnrealI.u']['display_case_variants']===6);
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($fails),
    'failed'=>$fails,'original_native_record_count'=>count($nativeRecords),
    'source_selected_original_package'=>array_values($links),
    'per_source_original'=>$per,
    'original_May_Core_dll_SHA256'=>$m['original_compiled_engine_May17_Sha'],
    'unobserved_source_properties_explicitly_unaccepted'=>true,
    'C227_T99_native_oracles_rerun'=>false,'G3_FULL'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
