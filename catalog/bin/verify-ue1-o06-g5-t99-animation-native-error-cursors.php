#!/usr/bin/env php
<?php
/** DEV only: six SHA-pinned compiled v432 first-chance UAnimation error cursors.
 * Original native exe/DLLs are NOT re-executed. No blanket method FULL.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-ANIMATION-NATIVE-ERROR-CURSORS.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];
$ok=static function(string $id,bool $pass)use(&$checks,&$fail):void{$checks++;if(!$pass)$fail[]=$id;};
$sha=static fn(string $path):?string=>is_file($path)?hash_file('sha256',$path):null;
$engine='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll';
$core='C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll';
$ok('method',($m['method']??'')==='T99:UAnimation::Serialize');
$ok('source_profile',($m['source_family']??'')==='ut99-v432'&&($m['version']??0)===69);
$ok('sealed_native_engine',$sha($engine)===$m['immutable_original_Engine_DLL_sha256']);
$ok('sealed_native_core',$sha($core)===$m['immutable_original_Core_DLL_sha256']);
$ok('original_TArray_source',$sha('L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnTemplate.h')===$m['source_TArray_sha256']);
$ok('original_ULinkerLoad_source',$sha('L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnLinker.h')===$m['source_ULinkerLoad_sha256']);
$ok('original_debugger_script',$sha($m['cdb_source_path'])===$m['cdb_source_sha256']);
$ok('six_original_native_failures',count($m['exact_cursors'])===6);
$source=(string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnTemplate.h');
$ok('negative_TArray_uses_Empty_NewNum',str_contains($source,'A.Empty( NewNum )'));
$ok('negative_TArray_iterates_for_i_less_than_NewNum',str_contains($source,'i<NewNum'));
$nativeObserved=[];$phpObserved=[];
foreach($m['exact_cursors'] as $item){
    $id=$item['case'];
    $ok('original_source_sha_'.$id,$sha($item['original_path'])===$item['original_sha256']);
    $ok('original_mutated_sha_'.$id,$sha($item['mutated_path'])===$item['mutated_sha256']);
    $raw=(string)file_get_contents($item['original_path']);
    foreach($item['mutations'] as $mut){
        $at=(int)$mut['offset'];$old=(int)$mut['old'];$new=(int)$mut['new'];
        $ok('actual_source_mutated_byte_'.$id.'_'.$at,ord($raw[$at])===$old);
        $raw[$at]=chr($new);
    }
    if($item['trim_bytes']>0)$raw=substr($raw,0,-(int)$item['trim_bytes']);
    $ok('no_undocumented_mutations_'.$id,$raw===file_get_contents($item['mutated_path']));
    $logRaw=(string)file_get_contents($item['compiled_cdb_log_path']);
    $ok('actual_compiled_exception_log_SHA_'.$id,hash('sha256',$logRaw)===$item['compiled_cdb_log_sha256']);
    $log=str_contains(substr($logRaw,0,32),"\0")
        ?(string)iconv('UTF-16LE','UTF-8',str_starts_with($logRaw,"\xff\xfe")?substr($logRaw,2):$logRaw)
        :$logRaw;
    $log=str_replace("\r",'',$log);
    preg_match_all('/^NATIVE_FILE_POS=(\d+)$/m',$log,$nativePos);
    preg_match_all('/^NATIVE_FILE_TOTAL=(\d+)$/m',$log,$nativeTotal);
    $actual=(int)($nativePos[1][0]??-1);
    $size=(int)($nativeTotal[1][0]??-1);
    $ok('one_compiled_position_'.$id,count($nativePos[1]??[])===1);
    $ok('one_compiled_total_'.$id,count($nativeTotal[1]??[])===1);
    $ok('unchanged_native_first_chance_'.$id,str_contains($log,'ORIGINAL_DLL_UANIMATION_FIRST_THROW')
        &&str_contains($log,'ARCHIVE_POINTER=')&&str_contains($log,'Core!')&&str_contains($log,'Engine!'));
    $ok('compiled_cursor_exact_'.$id,$actual===(int)$item['compiled_cursor']);
    $ok('compiled_archive_total_exact_'.$id,$size===(int)$item['compiled_total']);
    $nativeObserved[$id]=$actual;
    $which=['table'=>23,'EcoTerror'=>6,'d00d'=>7][(string)($item['original_path']!==''?pathinfo($item['original_path'],PATHINFO_FILENAME):'')]??null;
    $ok('known_animation_export_'.$id,is_int($which));
    $phpError='';
    try{
        $p=new Package($item['mutated_path']);
        $tag=Tag::inspect($item['mutated_path'],$p,$which,'ut99-v432');
        Native::inspect($item['mutated_path'],$p,$which,$tag,'ut99-v432');
    }catch(Throwable $e){$phpError=$e->getMessage();}
    $kind=$item['kind'];
    $regex=$kind==='linker_fname'?'/ULinkerLoad<<FName cursor=(\d+)/':'/native physical EOF cursor=(\d+)/';
    preg_match($regex,$phpError,$mPos);
    $phpCursor=(int)($mPos[1]??-1);
    $ok('exact_source_current_error_kind_'.$id,$phpCursor>=0);
    $ok('exact_source_current_error_cursor_'.$id,$phpCursor===$actual);
    $phpObserved[$id]=$phpCursor;
}
$ok('not_false_general_method_full',($m['source_method_closed']??true)===false);
$report=['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),'failed'=>$fail,
  'native_cdb_confirmed_cursors'=>$nativeObserved,'production_reported_cursors'=>$phpObserved,
  'compiled_Engine_modified'=>false,'original_native_reexecutions_in_verifier'=>0,
  'method_global'=>'BLOCKED','scope'=>'6 original compiled v432 UAnimation malformed first-chance file archive errors'];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
