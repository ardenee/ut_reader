<?php
/** Original compiled UT99 469f one-byte genuine direct UStruct malformed FriendlyName fatal. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as Stream;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G3-T99-469F-ORIGINAL-MALFORMED-UStruct-FRIENDLYNAME.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$bad=[];
$check=static function(string $n,bool $success)use(&$checks,&$bad):void{
    $checks++;if(!$success&&count($bad)<25)$bad[]=$n;
};
$check('exact original 469f direct UStruct method, no full-method overclaim',
    $m['original_compiled_error_caught']===true
    &&$m['full_G3_03_method_accepted']===false
    &&$m['error_cursor_original_compiled_Tell_in_debugger_verified']===false);
foreach(['true_original_user_source_Core_u','true_original_469f_compiled_Core_DLL',
    'true_original_469f_UCC_exe'] as $name){
    $p=$m[$name];
    $check('original genuine source immutable SHA '.$name,
        is_file($p['path'])&&filesize($p['path'])===$p['bytes']
        &&hash_file('sha256',$p['path'])===$p['sha256']);
}
$check('original code source genuine Core DLL SHA',
    $m['true_original_469f_compiled_Core_DLL']['sha256']
        ==='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2');
$check('original actual archive Core.u SHA pinned',
    $m['true_original_user_source_Core_u']['sha256']
        ==='7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0');
$native=null;
foreach($m['original_compiled_winDBG_evidence'] as $name=>$f){
    $p=$repo.'/'.$f['repo_path'];
    $check('original authentic CDB debugger artifact SHA '.$name,
        is_file($p)&&filesize($p)===$f['bytes']
        &&hash_file('sha256',$p)===$f['sha256']);
    if($name==='original_compiled_malformed_name_CDB_output'){
        $native=str_replace(["\r\n","\r"],"\n",
            (string)iconv('UTF-16','UTF-8',(string)file_get_contents($p)));
    }
}
$check('genuine original compiled CDB critical assert exactly once',
    $native!==null
    &&substr_count($native,"\nG3_03_469F_NATIVE_FRIENDLYNAME_ASSERT\n")===1);
$check('real original native CDB ran UStruct inherited serialized class',
    $native!==null&&str_contains($native,'Core!UStruct::Serialize+0x85')
    &&str_contains($native,'Core!ULinkerLoad::Preload+0x128')
    &&str_contains($native,'Core!appFailAssert'));
$check('exact original compiler FriendlyName source check and line',
    $native!==null&&str_contains($native,'Assertion failed: FriendlyName!=NAME_None')
    &&str_contains($native,'[Line: 840]'));
$check('source 469f compile original run not a simulated PHP only exception',
    $native!==null&&str_contains($native,'CommandLine: .\\UCC.exe make -nobind'));
$original=$m['true_original_user_source_Core_u']['path'];
$pkg=new Package($original);
$check('genuine original stock Core.u package object map parses',$pkg->validatePackage()===[]);
$ex=$pkg->getExports()[$m['genuine_export_zero_based_index']]??null;
$check('exact original BoundingBox direct UStruct export number and position',
    is_array($ex)&&$ex['objectNameText']==='BoundingBox'
    &&$ex['serialOffset']===7533&&$ex['serialSize']===22);
$tag=Tagged::inspect($original,$pkg,53,Family::UT99_V469F);
$check('real UObject tagged envelope ends before direct UStruct tail',
    $tag['tag_end_offset']===$m['tagged_UObject_envelope_end']
    &&$tag['tag_end_offset']===7535);
$sourceReader=new Stream($original);
$sourceReader->seek($tag['tag_end_offset']);
$fields=[];
foreach(['SuperField','Next','ScriptText','Children'] as $field){
    $fields[$field]=$sourceReader->compactIndex();
}
$check('unmodified source local UField and UStruct reference archive order',
    $fields===['SuperField'=>0,'Next'=>536,'ScriptText'=>0,'Children'=>479]);
$check('compiled source original direct FriendlyName physical byte location',
    $sourceReader->tell()===$m['file_offset_of_encoded_FName_FriendlyName']
    &&$sourceReader->tell()===7541);
$sourceIdx=$sourceReader->compactIndex();
$check('stock original native FriendlyName compact FName index 79',
    $sourceIdx===79
    &&$m['original_compact_FName_index']===79);
$orig=file_get_contents($original);
$check('stock original raw original compact FName bytes 4f01',
    substr($orig,7541,2)==="\x4F\x01"
    &&$m['genuine_export_zero_based_index']===53);
$stock=Native::inspect($original,$pkg,53,$tag,Family::UT99_V469F);
$check('unmodified genuine original source succeeds direct native struct',
    $stock['status']==='native_struct_record_complete'
    &&$stock['friendly_name']['text']==='BoundingBox');
$clone=$orig;
$clone[7541]="\x00";
$check('only one byte of genuine original compiled input modified and preserved length',
    strlen($clone)===strlen($orig)
    &&substr($clone,0,7541)===substr($orig,0,7541)
    &&substr($clone,7542)===substr($orig,7542)
    &&$orig[7541]==="\x4F"&&$clone[7541]==="\x00");
$check('isolated malformed fixture matches exact genuine compiled native oracle SHA',
    hash('sha256',$clone)===$m['isolated_mutant_SHA256']
    &&strlen($clone)===$m['isolated_mutant_bytes']);
$temp=tempnam(sys_get_temp_dir(),'G303BadFName');
if(!is_string($temp))throw new RuntimeException('Cannot allocate isolated native fixture');
try{
    if(file_put_contents($temp,$clone)!==strlen($clone))throw new RuntimeException('Isolated fixture write failed');
    $mutant=new Package($temp);
    $check('mutant only impacts native FName, export table still valid',
        $mutant->validatePackage()===[]);
    $mutTag=Tagged::inspect($temp,$mutant,53,Family::UT99_V469F);
    $check('malformed one-byte original export object tagged envelope same end',
        $mutTag['tag_end_offset']===$tag['tag_end_offset']);
    $msg='';
    try{
        Native::inspect($temp,$mutant,53,$mutTag,Family::UT99_V469F);
    }catch(RuntimeException $ex){
        $msg=$ex->getMessage();
    }
    $check('PHP original source-defined Native UStruct rejects FName None',
        $msg===$m['original_PHP_negative_expected']);
    $check('original compiled and PHP both refuse direct native FriendlyName None',
        str_contains((string)$native,'FriendlyName!=NAME_None')
        &&str_contains($msg,'FriendlyName cannot be NAME_None'));
}finally{@unlink($temp);}
$check('after malformed comparison original original Core.u still hash untouched',
    hash_file('sha256',$original)===$m['true_original_user_source_Core_u']['sha256']);
$ok=$bad===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($bad),'failed'=>$bad,
 'genuine_original_469f_compiled_error'=>'FriendlyName!=NAME_None',
 'original_single_byte_mutation_offset'=>7541,
 'PHP_and_original_compiled_fatal_parity'=>$ok,
 'error_cursor_not_native_verified'=>true,
 'full_G3_03_accepted'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
