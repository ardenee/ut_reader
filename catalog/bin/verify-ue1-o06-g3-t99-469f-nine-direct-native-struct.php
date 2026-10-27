<?php
/** G3-03 original compiled UT99 469f nine true direct UStruct objects, not UFunction prefix. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-T99-469F-NINE-DIRECT-UStruct-ORIGINAL-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$assert=static function(string $label,bool $v)use(&$checks,&$errors):void{
    $checks++;if(!$v&&count($errors)<25)$errors[]=$label;
};
$assert('new genuine original compiled direct class only, not UFunction prefix',
    $manifest['actual_compiled_direct_uscript_objects_observed']===9
    &&$manifest['G3_03_full_method_acceptance']===false
    &&count($manifest['original_native_DIRECT_UStruct_original_Core_u_records'])===9);
$assert('source game v469f original package 69/l0 cannot imply compiled patch',
    $manifest['no_prior_25_UFunction_native_proof_rerun']===true
    &&$manifest['no_claim_of_native_loaded_last_three_structs']===true
    &&$manifest['original_469f_Core_u_other_direct_UStruct_export_indices_NOT_loaded_by_this_commandlet']===[727,728,734]);
foreach($manifest['source_binary_SHA256_identity'] as $name=>$f){
    $assert('original immutable UT99 469f file SHA and length '.$name,
        is_file($f['path'])&&filesize($f['path'])===$f['bytes']
        &&hash_file('sha256',$f['path'])===$f['sha256']);
}
$assert('compiled genuine Core DLL SHA verifies actual matching UT99 release',
    $manifest['source_binary_SHA256_identity']['true_original_469f_Core_DLL']['sha256']
       ==='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2');
$assert('genuine archived Core.u original SHA retains exact binary identity',
    $manifest['source_binary_SHA256_identity']['true_original_469f_Core_u']['sha256']
       ==='7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0');
$logs=[];
foreach($manifest['original_compiled_debugger_evidence'] as $key=>$pin){
    $path=$repo.'/'.$pin['repo_path'];
    $assert('real compiled WinDbg transcript/script SHA pinned '.$key,
        is_file($path)&&filesize($path)===$pin['bytes']&&hash_file('sha256',$path)===$pin['sha256']);
    if(str_contains($key,'CDB_log')){
        $raw=(string)file_get_contents($path);
        $text=iconv('UTF-16','UTF-8',$raw);
        $logs[$key]=str_replace(["\r\n","\r"],"\n",(string)$text);
        $assert('no debugger expression failure real original process '.$key,
            !str_contains($logs[$key],'Numeric expression missing')
            &&str_contains($logs[$key],'G3_03_DIRECT'));
    }
}
$assert('2 authentic original compiled direct-object debugger recordings saved',
    count($logs)===2);
$original=$manifest['source_binary_SHA256_identity']['true_original_469f_Core_u']['path'];
$package=new Package($original);
$assert('entire authentic original 469f Core.u validates untouched',$package->validatePackage()===[]);
$assert('source version is UT99 69/0, not C227',
    $package->getHeader()['version']===69
    &&$package->getHeader()['licenseeVersion']===0);
$exports=$package->getExports();$imports=$package->getImports();
$expect=[0,1,32,53,486,535,546,551,590];
$seen=[];$actualChildren=0;$linkers=[];
foreach($manifest['original_native_DIRECT_UStruct_original_Core_u_records'] as $record){
    $i=$record['original_Core_u_export_index_zero_based'];
    $assert('distinct genuine original direct UStruct index '.$i,!isset($seen[$i]));
    $seen[$i]=true;
    $e=$exports[$i]??null;
    $assert('native original index maps to a real original Core.u export '.$i,is_array($e));
    if($e===null)continue;
    $class=(int)$e['classIndex'];
    $type=$class<0?strtolower((string)($imports[-$class-1]['objectNameText']??'')):'';
    $assert('original runtime direct UStruct not derived UFunction '.$i,$type==='struct');
    if($type!=='struct')continue;
    $loaded=Native::inspect($original,$package,$i,
        Tagged::inspect($original,$package,$i,Family::UT99_V469F),Family::UT99_V469F);
    $assert('UE1 reader consumed exact direct UStruct archive bytes '.$i,
        $loaded['status']==='native_struct_record_complete'&&$loaded['extension_bytes']===0);
    $assert('native loaded real UStruct FriendlyName matches independent package field '.$i,
        $loaded['friendly_name']['text']===$e['objectNameText']
        &&$record['actual_native_global_object_name_id']
           ===$record['actual_native_global_friendly_name_id']);
    $assert('native real direct UStruct ScriptSize equals PHP actual loaded '.$i,
        $record['actual_native_script_size']===$loaded['script_size']
        &&$loaded['script_size']===0);
    $assert('native direct UStruct Line field equals original archive '.$i,
        $record['actual_native_source_line']===$loaded['source_line']);
    $assert('native direct UStruct TextPos field equals original archive '.$i,
        $record['actual_native_source_text_position']===$loaded['source_text_pos']);
    $assert('native source ScriptText pointer is null and archive refers to no object '.$i,
        $record['actual_native_ScriptText_pointer_hex']==='00000000'
        &&$loaded['script_text_reference']===0);
    $assert('native source actual Children pointer present and PHP original signed reference '.$i,
        $record['actual_native_Children_pointer_hex']!=='00000000'
        &&$loaded['children_reference']>0
        &&isset($exports[$loaded['children_reference']-1]));
    $child=$record['actual_native_Children_export_index_if_dereferenced'];
    if($record['native_Children_dereference_observed']){
        $actualChildren++;
        $assert('genuine native Children pointer object linker export matches real saved compact reference '.$i,
            $child!==null&&$loaded['children_reference']===$child+1);
    }else{
        $assert('earlier 3 samples did not dereference child; no false native proof '.$i,
            in_array($i,[0,1,32],true)&&$child===null);
    }
    $linkers[$record['original_native_compiled_source_CDB_log']][]=$record['original_compiled_linker_hex'];
    // Require corresponding exact CDB marker in its ORIGINAL original debugger log.
    $log=$logs[$record['original_native_compiled_source_CDB_log']]??'';
    $marker=in_array($i,[0,1,32],true)
        ? match ($i) {0=>'G3_03_DIRECT_STRUCT_ZERO',1=>'G3_03_DIRECT_STRUCT_ONE',32=>'G3_03_DIRECT_STRUCT_32'}
        :'G3_03_469F_DIRECT_STRUCT_NATIVE_IDX_'.$i;
    $assert('actual original native debugger emitted source-specific direct UStruct '.$i,
        substr_count($log,"\n".$marker."\n")===1);
    if($record['native_Children_dereference_observed']){
        $assert('same debugger observed original native actual target object, not inferred index '.$i,
            substr_count($log,"\nG3_03_469F_DIRECT_CHILD_IDX_".$i."\n")===1);
    }
}
sort($expect);$got=array_keys($seen);sort($got);
$assert('exact nine original source direct struct indexes observed, no duplicate',$got===$expect);
$assert('each independently started original compiled debugger process has one genuine Core.u linker',
    count($linkers)===2
    &&count(array_unique($linkers[array_key_first($linkers)]))===1
    &&count(array_unique($linkers[array_key_last($linkers)]))===1);
$assert('six genuinely dereferenced children targets have exact native source identities',
    $actualChildren===6);
$assert('last 3 genuine direct UStruct exports did not load in actual compiled commandlet',
    count(array_intersect([727,728,734],$got))===0);
$ok=$errors===[];
echo json_encode([
    'ok'=>$ok,'passed'=>$checks-count($errors),'checks'=>$checks,'failed'=>$errors,
    'original_native_direct_struct_values_verified'=>count($seen),
    'original_native_children_linker_export_target_verified'=>$actualChildren,
    'other_original_direct_UStruct_assembly_classes_not_loaded'=>[727,728,734],
    'production_php_native_reader_changes_needed'=>false,
    'full_G3_03_method_accepted'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
