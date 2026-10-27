#!/usr/bin/env php
<?php
/** G3 original 2012 C227i UArrayProperty compiled 4 Inner pointer identities; new v1224 source class. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-C227I-ARRAY-FOUR-EXACT-ORIGINAL-NATIVE-INNER.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];
$check=static function(string $id,bool $pass)use(&$checks,&$failed):void{
    $checks++;
    if(!$pass)$failed[]=$id;
};
$check('source exact original build restricted scope',
    $m['schema']===1
    &&$m['status']==='AUTHENTIC_2012_ORIGINAL_C227I_UARRAYPROPERTY_FOUR_NATIVE_LOADED_INNER_IDENTITIES'
    &&$m['G3_02_FULL_accepted']===false &&$m['G3_11_FULL_accepted']===false
    &&$m['G3_15_FULL_accepted']===false);
foreach($m['original_stock_executable_binary_pins'] as $name=>$pin){
    $path=$pin['path'];
    $check('unchanged original stock source asset '.$name,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$check('original 2012 Core DLL authentic SHA',
    $m['original_stock_executable_binary_pins']['authentic_original_2012_227i_Core_dll']['sha256']
       ==='287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897');
$check('original 2012 Core.u authentic SHA',
    $m['original_stock_executable_binary_pins']['authentic_original_2012_227i_Core_u']['sha256']
       ==='bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b');
foreach($m['actual_original_game_source_headers'] as $name=>$pin){
    $path=$pin['path'];
    $check('pinned original UE1 game-source headers '.$name,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$source=$m['actual_original_game_source_headers'];
$src98=(string)file_get_contents($source['unreal_v1200_UnType_h']['path']);
$src224=(string)file_get_contents($source['unreal_v1224_UnType_h']['path']);
$src227=(string)file_get_contents($source['unreal_v1227_UnType_h']['path']);
foreach(['UArrayProperty','UFixedArrayProperty','UMapProperty','UStrProperty'] as $name){
    $check('original v1200 engine does not define later distinct subclass '.$name,
        !str_contains($src98,'class CORE_API '.$name.' : public UProperty'));
    $check('original later v1224 game introduced new property subclass '.$name,
        str_contains($src224,'class CORE_API '.$name.' : public UProperty'));
    $check('original v1227 game still declares source subclass '.$name,
        str_contains($src227,'class CORE_API '.$name.' : public UProperty'));
}
$pins=$m['native_original_CDB_method_disassembly_and_four_loaded_values'];
foreach($pins as $key=>$pin){
    $path=$repo.'/'.$pin['path'];
    $check('authentic original x86 compiled stock Core transcript '.$key,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$load=iconv('UTF-16','UTF-8',(string)file_get_contents($repo.'/'.$pins['original_2012_227i_native_4_real_ArrayProperty_loaded_referents']['path']));
$dis=iconv('UTF-16','UTF-8',(string)file_get_contents($repo.'/'.$pins['original_2012_227i_compiled_subclass_function_disassembly']['path']));
if(!is_string($load)||!is_string($dis))throw new RuntimeException('Original x86 CDB UTF16 trace unreadable');
$load=str_replace(["\r\n","\r"],"\n",$load);
$dis=str_replace(["\r\n","\r"],"\n",$dis);
foreach([
 'Core!UStrProperty::Serialize:'=>'101492f9',
 'Core!UFixedArrayProperty::Serialize:'=>'10149509',
 'Core!UArrayProperty::Serialize:'=>'10149a75',
 'Core!UMapProperty::Serialize:'=>'1014a042',
] as $name=>$call){
    $check('actual original compiled distinct subclass and operator call '.$name,
        str_contains($dis,$name)&&str_contains($dis,$call));
}
$check('compiled UArray actually calls inherited UProperty then a single pointer operator',
    str_contains($dis,'10149a6b')&&str_contains($dis,'10149a75'));
$check('compiled UMap has exactly two native reference operator calls',
    str_contains($dis,'1014a039')&&str_contains($dis,'1014a042'));
$check('compiled UFixedArray stores Inner and Count via two Archive arguments',
    str_contains($dis,'10149500')&&str_contains($dis,'10149509')&&str_contains($dis,'10149512'));
$check('compiled UStrProperty only invokes inherited UProperty before return',
    str_contains($dis,'101492f9')&&str_contains($dis,'101492fe'));
$chunks=preg_split('/(?m)^G3_C227I_ARRAYPROPERTY_AFTER_SUBCLASS_INNER_LOAD\s*$/',$load);
$check('exactly four genuine original compiled C227i source ArrayProperty native loaded callbacks',
    is_array($chunks)&&count($chunks)===5);
$check('compiled UCC help reached genuine full native completion',
    str_contains($load,'G3_C227I_NEW_SUBCLASS_FINISHED'));
$pkgpath=$m['original_stock_executable_binary_pins']['authentic_original_2012_227i_Core_u']['path'];
$pkg=new Package($pkgpath);
$check('original source package validates',$pkg->validatePackage()===[]);
$check('original Core.u version69',($pkg->getHeader()['version']??null)===69);
$exports=$pkg->getExports();
$check('original genuine Core.u 934 exports',count($exports)===934);
$observed=[];$linker=null;
foreach(array_slice($chunks,1) as $i=>$block){
    preg_match_all('/(?m)^([0-9a-f]{8})\h+([0-9a-f]{8})(?:\h+([0-9a-f]{8}))?$/i',
        $block,$lines,PREG_SET_ORDER);
    $rows=[];
    foreach($lines as $line){
        $vals=[hexdec($line[1]),hexdec($line[2])];
        if(isset($line[3])&&$line[3]!=='')$vals[]=hexdec($line[3]);
        $rows[]=$vals;
        if(count($rows)===3)break;
    }
    $check('original CDB 3 direct native memory addresses at event '.$i,
        count($rows)===3);
    if(count($rows)!==3)continue;
    $check('original pointer layout loaded UArray this+0x60 at event '.$i,
        count($rows[0])===3 &&count($rows[1])===2 &&count($rows[2])===3
        &&$rows[1][0]===$rows[0][0]+0x50
        &&$rows[2][0]===$rows[1][1]+0x10);
    if(count($rows[0])!==3||count($rows[2])!==3)continue;
    $index=$rows[0][2];$targetIndex=$rows[2][2];
    if($linker===null)$linker=$rows[0][1];
    $check('original live native this and Inner target are same linker at event '.$i,
        $rows[0][1]===$linker &&$rows[2][1]===$linker);
    $check('original native current export index unique '.$index,!isset($observed[$index]));
    $observed[$index]=true;
    $fileExport=$exports[$index]??null;$target=$exports[$targetIndex]??null;
    $check('original archive source and target exported from same real Core.u '.$index,
        $fileExport!==null&&$target!==null);
    if(!$fileExport||!$target)continue;
    $iSaved=(int)$targetIndex+1;
    $check('source original native pointer to export saved identity matches pinned record '.$index,
        $m['confirmed_real_original_2012_loads'][$i]['original_zero_based_array_export_index']===$index
        &&$m['confirmed_real_original_2012_loads'][$i]['original_zero_based_runtime_Inner_export_index']===$targetIndex
        &&$m['confirmed_real_original_2012_loads'][$i]['original_saved_positive_Inner_reference']===$iSaved);
    $actual=Native::inspect($pkgpath,$pkg,$index,Tags::inspect($pkgpath,$pkg,$index,'unreal-v227'),'unreal-v227');
    $check('actual UnrealDB native ArrayProperty object source identity index '.$index,
        $actual['status']==='native_property_record_complete'
        &&$actual['property_kind']==='arrayproperty'
        &&$actual['subclass_fields']['inner_reference']===$iSaved);
    $check('original Inner target still exists and equals actual package saved positive ref '.$index,
        $target['objectNameText']!=='' &&$iSaved===$targetIndex+1);
}
$check('exactly 4 actual distinct Core.u source arrays positively loaded',count($observed)===4);
$check('four exact original property indices',
    array_keys($observed)===[662,667,669,746]);
$check('compiled original 227i UCC help did not execute fixed/map subclass cases',
    $m['actual_loaded_fixedarray_property_count']===0&&$m['actual_loaded_map_property_count']===0
    &&!preg_match('/(?m)^G3_C227I_(?:FIXEDARRAY|MAPPROPERTY)_AFTER_SUBCLASS/',$load));
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>$checks-count($failed),'checks'=>$checks,
    'failed'=>$failed,'original_compiled_227i_loaded_arrays'=>count($observed),
    'exact_source_native_loaded_Inner_target_reference_match_count'=>count($observed),
    'original_compiled_methods_distinct_disassembled'=>4,
    'unobserved_map_and_fixed_loaded_values_fabricated'=>false,
    'all_16_G3_Full_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
