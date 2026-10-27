#!/usr/bin/env php
<?php
/** Genuine original 2012 227i compiled Core native: 668 exact ExportMap-indexed UProperty loaded memory records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents(
    $repo.'/catalog/compliance/UE1-O06-G3-C227I-UPROPERTY-668-EXACT-IDENTITIES.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $label,bool $ok)use(&$checks,&$errors):void{
    $checks++; if(!$ok&&count($errors)<30)$errors[]=$label;
};
$check('original compiled 227i positive LOAD only not full G3',
    $m['status']==='ORIGINAL_2012_COMPILED_227I_UPROPERTY_668_EXACT_ZEROBASED_LINKER_EXPORT_IDS_ALL_NATIVE_LOADED_VALUE_MATCHED'
    &&$m['all_G3_complete_contracts']===0);
foreach($m['original_stock_binary_package_executable_pins'] as $key=>$pin){
    $p=(string)$pin['path'];
    $check('actual 2012 227i original untouched binaries '.$key,is_file($p)
      &&filesize($p)===(int)$pin['bytes']
      &&hash_file('sha256',$p)===$pin['sha256']);
}
$check('published original stock 2012 Core.dll SHA',
    $m['original_stock_binary_package_executable_pins']['original_227i_stock_Core_DLL']['sha256']
    ==='287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897');
$check('genuine 227i Core.u source archive SHA',
    $m['original_stock_binary_package_executable_pins']['original_227i_genuine_Core_U']['sha256']
    ==='bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b');
foreach($m['actual_original_compiled_2012_CDB_and_unchanged_php_snapshot'] as $key=>$pin){
    $p=$repo.'/'.$pin['path'];
    $check('actual original Windows native debugger or original real Core.u saved record SHA '.$key,
        is_file($p)&&filesize($p)===(int)$pin['bytes']
         &&hash_file('sha256',$p)===$pin['sha256']);
}
$decl=$m['later_v227_header_for_decl_only_not_compiled_equivalence'];
$check('original later v227 source header hash, declaration only',
    is_file($decl['path'])&&filesize($decl['path'])===$decl['bytes']
    &&hash_file('sha256',$decl['path'])===$decl['sha256']);
$header=(string)file_get_contents($decl['path']);
$check('v227 source header declares linker index and distinct object name',
    preg_match('/\bINT\s+_LinkerIndex\s*;/',$header)===1
    &&preg_match('/\bFName\s+Name\s*;/',$header)===1);
$cap=$m['actual_original_compiled_2012_CDB_and_unchanged_php_snapshot'];
$trace=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $repo.'/'.$cap['native_668_per_export_memory_dump']['path']));
$archiveMode=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $repo.'/'.$cap['native_original_2012_archive_version_licensee_memory_dump']['path']));
$snapshot=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $repo.'/'.$cap['previous_673_original_real_CoreU_PHP_export_snapshot']['path']));
if(!is_string($trace)||!is_string($archiveMode)||!is_string($snapshot)){
    throw new RuntimeException('Original stock CDB UTF-16 evidence is unreadable');
}
// CDB writes CRLF under Windows; normalize only the decoded text, not the pinned evidence.
$trace=str_replace(["\r\n","\r"],"\n",$trace);
$check('original CDB debugger broke at 2012 compiled original property memory load instruction',
    str_contains($trace,'bp 10148182 ')&&str_contains($trace,'10148182')
    &&str_contains($trace,'C227_NATIVE_REAL_PROPERTY_IDENTITY'));
$check('original stock native v69 l227 archive memory evidence',
    str_contains($archiveMode,'C227_NATIVE_PROPERTY_ARCHIVE_MODE_DISCRIMINATION')
    &&preg_match('/(?m)^[0-9a-f]{8}\h+101890a4\h+00000045\h+000000e3\h+/i',$archiveMode)===1);
$reader=new Package((string)$m['original_stock_binary_package_executable_pins']['original_227i_genuine_Core_U']['path']);
$check('original genuine Core.u validates', $reader->validatePackage()===[]);
$headerInfo=$reader->getHeader();
$check('genuine original Core.u has UE1 archive version 69',($headerInfo['version']??null)===69);
$exports=$reader->getExports();
$check('original untouched Core.u has exactly 934 exports',count($exports)===934);
$old=json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);
$check('original 2012 source Core.u genuine 673 typed property archive snapshot',
    ($old['ok']??false)===true&&($old['property_read_count']??0)===673);
$properties=[];
foreach($old['properties'] as $p){
    $idx=(int)$p['export_index'];
    $check('unique original archive property identity from sealed original Core.u '.$idx,!isset($properties[$idx]));
    $properties[$idx]=$p;
}
$chunks=preg_split('/(?m)^C227_NATIVE_REAL_PROPERTY_IDENTITY\s*$/',$trace);
$check('actual original compiled Core.dll 668 after-serialize callback records',
    is_array($chunks)&&count($chunks)===669);
$seen=[];$count=0;$linker=null;$sample=[];$diag=[];
foreach(array_slice($chunks,1) as $i=>$body){
    preg_match_all('/(?m)^[0-9a-f]{8}\h+((?:[0-9a-f]{8}\h*){4})$/i',$body,$matches);
    $entries=array_map(static fn($a)=>array_map('hexdec',preg_split('/\h+/',trim($a))),
        $matches[1]);
    $check('two original x86 compiled native property field groups present '.$i,count($entries)>=2);
    if(count($entries)<2)continue;
    $first=$entries[0];$second=$entries[1];
    $check('source distinct native x86 field group widths '.$i,
        count($first)===4&&count($second)===4);
    if(count($first)!==4||count($second)!==4)continue;
    $id=(int)$first[3];
    $linkerHere=sprintf('%08x',$first[2]);
    if($linker===null)$linker=$linkerHere;
    $check('all original 668 native calls belong to same genuine source linker '.$i,
        $linkerHere===$linker);
    $check('C227I original native index unique at load '.$id,!isset($seen[$id]));
    $seen[$id]=true;
    $record=$properties[$id]??null;
    $check('original live compiled export index maps to actual file saved UProperty '.$id,$record!==null);
    $exp=$exports[$id]??null;
    $check('true zero-based property export exists in genuine Core.u '.$id,
        $exp!==null &&$exp['objectNameText']===($record['name']??null));
    if(!$record||!$exp)continue;
    $dim=$second[0]>=0x80000000?$second[0]-0x100000000:$second[0];
    $flags=$second[2];
    $check('genuine per-export loaded ArrayDim equals actual saved original archive '.$id,
        $dim===$record['array_dim']);
    $check('genuine per-export loaded PropertyFlags equals actual saved original archive '.$id,
        $flags===$record['property_flags']);
    $check('genuine stored reader snapshot status complete '.$id,
        $record['status']==='native_property_record_complete');
    $actual=Native::inspect($m['original_stock_binary_package_executable_pins']['original_227i_genuine_Core_U']['path'],
        $reader,$id,Tags::inspect($m['original_stock_binary_package_executable_pins']['original_227i_genuine_Core_U']['path'],$reader,$id,'unreal-v227'),'unreal-v227');
    $check('current production source reader real original archive status '.$id,
        ($actual['status']??null)==='native_property_record_complete');
    $check('real current UnrealDB native reader exact ArrayDim loaded value '.$id,
        ($actual['array_dim']??null)===$dim);
    $check('real current UnrealDB native reader exact PropertyFlags loaded value '.$id,
        ($actual['property_flags']??null)===$flags);
    if($i===0){
        $check('first compiled original this+0x14 933 is true linker-indexed export ReturnValue, NOT global name Advanced',
            $id===933&&$exp['objectNameText']==='ReturnValue'
            &&str_contains($body,'"Advanced"')&&$dim===1&&$flags===1408);
    }
    if(count($sample)<6)$sample[]=['export_index'=>$id,'name'=>$record['name'],'array_dim'=>$dim,'flags'=>$flags];
    $count++;
}
$check('full 668 genuine compiled native records independently loaded + 668 source indices',$count===668);
$check('all 668 export indices unique',count($seen)===668);
$missing=[];
foreach($properties as $id=>$p)if(!isset($seen[$id]))$missing[]=$id;
sort($missing);
$check('only five sealed source Core.u property exports not observed on this one commandlet startup',
    $missing===[3,4,6,634,655]);
$expect=array_column($m['original_property_exports_not_observed_on_this_UCC_startup'],'export_index');
$check('manifest does not silently claim five unobserved classes as loaded',$expect===$missing);
$check('only original 2012 227i profile source verified; later ABI remains separate',
    str_contains($m['native_other_abi_current_later_Core_lib_mismatch'],'43/57'));
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
    'failed'=>$errors,'original_2012_C227i_native_compiled_loaded_individual_export_matches'=>$count,
    'source_PHP_property_exports'=>count($properties),'unobserved_property_export_indices'=>$missing,
    'same_original_linker_ptr_within_this_run'=>$linker,'sample'=>$sample,
    'source_later_core_lib_method_ABI_equivalence_proved'=>false,
    'full_G3_07_15_16_accepted'=>false,'G3_FULL'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
