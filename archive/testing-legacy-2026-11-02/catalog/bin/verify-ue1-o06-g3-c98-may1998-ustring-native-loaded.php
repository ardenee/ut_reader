#!/usr/bin/env php
<?php
/** Source-proven native original 1998 UStringProperty::StringSize, exactly 66 loaded objects. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C98-MAY1998-USTRING-66-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $id,bool $ok)use(&$checks,&$errors):void {
    $checks++;if(!$ok)$errors[]=$id;
};
$check('source-selected only original May UStringProperty positive native loaded',
    $m['status']==='ORIGINAL_MAY1998_C98_USTRINGPROPERTY_66_INDIVIDUAL_LOADED_STRING_SIZES_VERIFIED_NOT_FULL_G3_08'
    &&$m['G3_full_contracts_accepted']===0
    &&$m['not_all_original_package_string_properties_or_error_branches']===true);
foreach($m['original_binaries_packages'] as $name=>$pin){
    $file=(string)$pin['path'];
    $check('unmodified original native stock file '.$name,
        is_file($file)&&filesize($file)===(int)$pin['bytes']
        &&hash_file('sha256',$file)===$pin['sha256']);
}
$check('original original May stock Core.dll SHA',
    $m['original_binaries_packages']['Core.dll']['sha256']==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
foreach($m['original_May_v1200_source_sha'] as $name=>$pin){
    $file=(string)$pin['path'];
    $check('actual original v1.200 source hash '.$name,
        is_file($file)&&filesize($file)===(int)$pin['bytes']
        &&hash_file('sha256',$file)===$pin['sha256']);
}
$source=(string)file_get_contents($m['original_May_v1200_source_sha']['Core/Src/UnProp.cpp']['path']);
$header=(string)file_get_contents($m['original_May_v1200_source_sha']['Core/Inc/UnType.h']['path']);
$check('original exact UStringProperty::Serialize source archive load',
    preg_match('/void UStringProperty::Serialize\s*\( FArchive& Ar \).*?UProperty::Serialize\( Ar \);\s*Ar << StringSize;/s',$source)===1);
$check('original UStringProperty StringSize declared in source',
    preg_match('/class CORE_API UStringProperty\b.*?StringSize\s*;/s',$header)===1);
foreach($m['sealed_compiled_original_CDB_proof'] as $name=>$pin){
    $p=$root.'/'.$pin['path'];
    $check('sealed original compiled CDB script and log '.$name,
        is_file($p)&&filesize($p)===(int)$pin['bytes']
        &&hash_file('sha256',$p)===$pin['sha256']);
}
$disasmRaw=file_get_contents($root.'/'.$m['sealed_compiled_original_CDB_proof']['original_compiled_UStringProperty_disasm']['path']);
$disasm=iconv('UTF-16','UTF-8',(string)$disasmRaw);
$check('original May UStringProperty compiled function read offset 0x48',
    is_string($disasm)&&str_contains($disasm,'101439c0')&&str_contains($disasm,'101439ed')
    &&str_contains($disasm,'101439f4')&&str_contains($disasm,'101439fc'));
$check('compiled archive LOAD observation PC correct',
    $m['original_compiled_UStringProperty_body']==='0x101439c0'
    &&$m['native_loaded_StringSize_cdb_after_4_byte_archive_read']==='0x101439fe'
    &&$m['native_StringSize_object_offset']===72);
$logRaw=file_get_contents($root.'/'.$m['sealed_compiled_original_CDB_proof']['stock_original_native_66_loaded_log']['path']);
$log=iconv('UTF-16','UTF-8',(string)$logRaw);
if(!is_string($log))throw new RuntimeException('Cannot decode original native x86 debugger evidence');
$parts=preg_split('/(?m)^MAY1998_C98_USTRING_SUBCLASS_LOADED\s*$/',$log);
if(!is_array($parts))throw new RuntimeException('Bad original StringSize CDB delimiters');
$check('exactly 66 C98 original stock UStringProperty native loaded callbacks',count($parts)===67);
$rows=[];
foreach(array_slice($parts,1) as $i=>$sec){
    $hdr=[];$texts=[];
    foreach(preg_split('/\R/',$sec) as $line){
        $line=trim($line);
        if(preg_match('/^[0-9a-f]{8}\s+((?:[0-9a-f]{8}\s*){1,2})$/i',$line,$mm)){
            $hdr[]=array_map('hexdec',preg_split('/\s+/',trim($mm[1])));
        }
        if(preg_match('/^[0-9a-f]{8}\s+"([^"]+)"/i',$line,$mm))$texts[]=$mm[1];
    }
    $check('native stock original property fields captured '.$i,
        count($hdr)>=2 &&count($hdr[0]??[])===2 &&count($hdr[1]??[])===1 &&count($texts)>=1);
    if(count($hdr)<2||count($hdr[0])!==2||count($hdr[1])!==1||count($texts)<1)continue;
    $rows[]=['linker'=>sprintf('%08x',$hdr[0][0]),'export_index'=>$hdr[0][1],
        'native_StringSize'=>$hdr[1][0],'name'=>$texts[0]];
}
$check('all source-native 66 StringSize records parsed',count($rows)===66);
$known=$m['loaded_original_linker_to_package'];
$packages=[];$counts=[];$sizes=[];$seen=[];
foreach($known as $ptr=>$name){
    $p=$m['original_binaries_packages'][$name]['path'];
    $reader=new Package($p);
    $check('unmodified original source package opens '.$name,$reader->validatePackage()===[]);
    $check('original v61 package '.$name,$reader->getHeader()['version']===61);
    $packages[$ptr]=['reader'=>$reader,'path'=>$p,'exports'=>$reader->getExports(),'imports'=>$reader->getImports()];
    $counts[$name]=0;$seen[$name]=[];
}
foreach($rows as $row){
    $ptr=$row['linker'];$package=$packages[$ptr]??null;
    $check('compiled runtime String property has known original file linker '.$ptr,$package!==null);
    if($package===null)continue;
    $name=$known[$ptr];$idx=(int)$row['export_index'];
    $exp=$package['exports'][$idx]??null;
    $check('compiled loaded export index maps real saved object '.$name.':'.$idx,$exp!==null);
    if($exp===null)continue;
    $check('original UStringProperty native loaded object unique '.$name.':'.$idx,!isset($seen[$name][$idx]));
    $seen[$name][$idx]=true;
    $classIdx=(int)$exp['classIndex'];
    $kind=$classIdx<0?(string)($package['imports'][-$classIdx-1]['objectNameText']??''):'';
    $check('original compiled UStringProperty subclass matches source wire class '.$name.':'.$idx,
        $kind==='StringProperty');
    $check('real loaded object name exact '.$name.':'.$idx,
        $exp['objectNameText']===$row['name']);
    $record=Native::inspect($package['path'],$package['reader'],$idx,
        Tags::inspect($package['path'],$package['reader'],$idx,'epic-ue1'),'epic-ue1');
    $check('real StringSize native loaded value equals archived subclass field '.$name.':'.$idx,
        ($record['subclass_fields']['string_size']??null)===$row['native_StringSize']);
    $check('original source subclass archive exact consumed '.$name.':'.$idx,
        ($record['status']??null)==='native_property_record_complete');
    $counts[$name]++;$value=$row['native_StringSize'];$sizes[$value]=($sizes[$value]??0)+1;
}
foreach($m['loaded_subclass_object_counts'] as $name=>$cnt){
    $check('all true observed distinct subclass instances '.$name,
        $counts[$name]===$cnt &&count($seen[$name])===$cnt);
}
$actualHistogram=[];
ksort($sizes,SORT_NUMERIC);
foreach($sizes as $size=>$count)$actualHistogram[(string)$size]=$count;
$check('all 66 actual native StringSize values histogram exact',
    $actualHistogram===$m['native_StringSize_value_histogram']);
$check('no native malformed string length claimed',
    array_sum($counts)===66 &&min(array_keys($sizes))>0);
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
    'failed'=>$errors,'original_native_subclass_loaded_objects'=>array_sum($counts),
    'by_source_package'=>$counts,'original_loaded_StringSize_values'=>$actualHistogram,
    'scope'=>'Original May17 compiled x86 UStringProperty native StringSize+0x48 loaded, original Core and Engine exports only',
    'malformed_negative_or_other_source_versions_proven'=>false,'G3_FULL'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
