#!/usr/bin/env php
<?php
/** One sealed original OldUnreal 227i compiled Core.u UConst loaded-value oracle. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C227I-UCONST-NATIVE-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];
$check=static function(string $name,bool $pass)use(&$checks,&$fails):void{
    ++$checks;if(!$pass){$fails[]=$name;}
};
$check('scope constrained to only sealed eight constants',
    ($manifest['status']??null)==='C227I_UCONST_EIGHT_NATIVE_LOADED_VALUES_VERIFIED_NOT_FULL_G3'
    &&($manifest['G3_full_contracts_accepted']??null)===0);
$original=$manifest['original_227i_files'];
foreach($original as $name=>$pin){
    $file=(string)$pin['path'];
    $check('original binary/package SHA '.$name,is_file($file)
        &&filesize($file)===(int)$pin['bytes']
        &&hash_file('sha256',$file)===(string)$pin['sha256']);
}
$check('official 227i compiled Core.dll exact SHA',
    $original['Core_dll']['sha256']==='287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897');
$check('original compiled v227i stock UCC.exe SHA',
    $original['UCC_exe']['sha256']==='69813336c9fd278f30939abde10477a37c5da951b2be8ca0c394b5bfd5cd6672');
$check('original compiled Core.u SHA',
    $original['Core_u']['sha256']==='bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b');
$sealed=$manifest['sealed_proof'];
foreach($sealed as $name=>$pin){
    $p=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,(string)$pin['path']);
    $check('sealed debugger script/log '.$name,is_file($p)
        &&filesize($p)===(int)$pin['bytes']
        &&hash_file('sha256',$p)===(string)$pin['sha256']);
}
$check('C227 import-library-versus-227i ABI discrepancy remains explicit',
    ($manifest['source_tree_227_Core_lib_g3_exports']??null)===57
    &&($manifest['g3_exact_export_name_matches_in_227i_dll']??null)===43
    &&count($manifest['g3_export_names_absent_or_changed_in_227i']??[])===14);
$callRaw=(string)file_get_contents($root.'/'.$sealed['method_call_cdb_log']['path']);
$callText=iconv('UTF-16','UTF-8',$callRaw);
if(!is_string($callText)){throw new RuntimeException('Cannot decode original CDB method-call log');}
$callKinds=['UFIELD'=>'UField','UPROPERTY'=>'UProperty',
    'UCONST'=>'UConst','USTRUCT'=>'UStruct'];
foreach($callKinds as $kind=>$className){
    $check('compiled 227i Core native call '.$kind,
        substr_count($callText,'G3_C227_NATIVE_'.$kind.'_HIT')>=2
        &&str_contains($callText,'Core!'.$className.'::Serialize'));
}
$raw=(string)file_get_contents($root.'/'.$sealed['uconst_cdb_log']['path']);
$log=iconv('UTF-16','UTF-8',$raw);
if(!is_string($log)){throw new RuntimeException('Cannot decode original CDB UConst log');}
$pattern='/^G3_C227_NATIVE_CONST_LOADED\s*\R[0-9a-f]{8}\s+[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s*\R[0-9a-f]{8}\s+"([^"]*)"/mi';
preg_match_all($pattern,$log,$found,PREG_SET_ORDER);
$check('exactly eight native UConst loaded-value debugger captures',count($found)===8);
$fromLog=[];
foreach($found as $m){
    $num=(int)hexdec($m[1]);$max=(int)hexdec($m[2]);$value=$m[3];
    $check('native C++ FString Num/Max agree '.$value,$num===$max&&$num===strlen($value)+1);
    $check('no duplicate native loaded string '.$value,!isset($fromLog[$value]));
    $fromLog[$value]=$num;
}
$fromManifest=[];
foreach($manifest['native_loaded_UConst'] as $record){
    $v=(string)$record['native_loaded_text'];
    $check('native manifest record replay '.$record['matched_Core_u_export_name'],
        isset($fromLog[$v])
        &&$fromLog[$v]===(int)$record['loaded_array_num']
        &&$fromLog[$v]===(int)$record['loaded_array_max']);
    $fromManifest[(string)$record['matched_Core_u_export_name']]=$record;
}
$check('eight distinct original 227i named constants',count($fromManifest)===8
    &&count(array_unique(array_column($manifest['native_loaded_UConst'],'native_loaded_text')))===8);
$path=(string)$original['Core_u']['path'];
$pkg=new Package($path);
$check('genuine original Core.u package validates',$pkg->validatePackage()===[]);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$check('genuine original 227i Core.u export count',count($exports)===934);
$actual=[];$scanned=0;
foreach($exports as $i=>$ex){
    $classIndex=(int)$ex['classIndex'];
    $class=$classIndex<0?(string)($imports[-$classIndex-1]['objectNameText']??''):'';
    if(strcasecmp($class,'Const')!==0){continue;}
    ++$scanned;$name=(string)$ex['objectNameText'];
    $check('native named Const available '.$name,isset($fromManifest[$name]));
    $tags=Tags::inspect($path,$pkg,$i,'unreal-v227');
    $native=Native::inspect($path,$pkg,$i,$tags,'unreal-v227');
    $pin=$fromManifest[$name]??null;
    if($pin===null){continue;}
    $value=(string)$pin['native_loaded_text'];
    $num=(int)$pin['loaded_array_num'];
    $check('real 227i UConst archive value equals stock native loaded '.$name,
        ($native['value']??null)===$value);
    $check('real 227i UConst FString Num equals loaded Num '.$name,
        ($native['value_count']??null)===$num);
    $check('real 227i UConst complete source archive '.$name,
        ($native['status']??null)==='native_const_record_complete'
        &&($native['extension_bytes']??null)===0
        &&($native['source_text_value_verified']??null)===true);
    $check('real Core.u archived bytes equal native text and terminator '.$name,
        ($native['value_sha256']??null)===hash('sha256',$value."\x00")
        &&($native['string_terminated']??null)===true);
    $actual[$name]=['export_index'=>$i,'native'=>$value,
        'archive_sha256'=>$native['value_sha256'],'loaded_array_num'=>$num];
}
$check('all eight genuine Core.u Const exports reconciled',$scanned===8&&count($actual)===8);
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($fails),
    'failed'=>$fails,'source_profile'=>'OldUnreal 227i original compiled Windows x86, eight Core.u Const objects only',
    'native_loaded_values'=>$actual,
    'original_227i_Core_dll_sha256'=>$original['Core_dll']['sha256'],
    'C227_abi_method_matches'=>43,'C227_abi_method_mismatches'=>14,
    'original_C98_loaded_value_proven'=>false,'G3_FULL'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
