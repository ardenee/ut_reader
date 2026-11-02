#!/usr/bin/env php
<?php
/** Authentic UT99 v432 compiled UObject::Serialize: positive StateFrame
 * branch on genuine v68 Entry.unr, source vs loaded native values.
 * Partial ONLY: 14/19 loaded, Node object identities not independently resolved.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$m=json_decode(file_get_contents($root.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-STATEFRAME-V68-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$fail=[];$checks=0;$matched=0;$tagsCount=0;
$check=static function(string $x,bool $v)use(&$checks,&$fail):void{$checks++;if(!$v)$fail[]=$x;};
$pin=static function(string $file,string $sha,string $name)use($check):string{
 $buf=is_file($file)?file_get_contents($file):false;
 $check('sha256_'.$name,is_string($buf)&&hash('sha256',$buf)===$sha);
 return is_string($buf)?$buf:'';
};
$pin($m['original_map'],$m['original_map_sha256'],'genuine_Entry_unr');
$pin($m['core'],$m['core_sha256'],'original_compiled_Core_dll');
foreach($m['dependencies'] as $dep)$pin($dep['path'],$dep['sha256'],'original_dependency_'.basename($dep['path']));
$buf=$pin($m['native_log'],$m['native_log_sha256'],'native_compiled_debugger');
$log=str_starts_with($buf,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($buf,2)):$buf;
$log=str_replace("\r",'',$log);
$check('compiled_breakpoint_after_real_StateFrame_archive',
 str_contains($log,'bp Core+0x556b1'));
preg_match_all('/G5_NATIVE_STATE idx=(\d+) flags=([0-9a-f]{8}) probe_lo=([0-9a-f]{8}) probe_hi=([0-9a-f]{8}) latent=([0-9a-f]{8}) code=([0-9a-f]{8})/i',$log,$observed,PREG_SET_ORDER);
$check('compiled_native_loaded_positive_stateframe_count',
 count($observed)===$m['compiled_positive_loaded_records']);
$check('compiled_run_reached_method_after_Genuine_Load',
 preg_match('/G5_NATIVE_ENTRY_STATEFRAME_COMPLETE count=(\d+)/',$log,$end)===1
 &&(int)$end[1]===count($observed));
$pkg=new P($m['original_map']);$exportMap=$pkg->getExports();
$check('actual_UT99_v68_map_package',
 $pkg->validatePackage()===[]&&(int)$pkg->getHeader()['version']===$m['original_package_version']);
$positive=[];
foreach($exportMap as $ex){
 if(((int)$ex['objectFlags']&0x02000000)!==0)$positive[(int)$ex['index']]=$ex;
}
$check('all_19_real_map_positive_frame_flags',
 count($positive)===$m['original_positive_state_frame_exports']);
$seen=[];$nativeByteCounts=0;
foreach($observed as $row){
 [, $idxRaw,$flagsRaw,$p0,$p1,$latentRaw,$codeRaw]=$row;
 $idx=(int)$idxRaw;$key='Entry_map_native_frame_'.$idx;
 $check('native_export_matches_real_positive_source_'.$key,isset($positive[$idx]));
 if(!isset($positive[$idx]))continue;
 $check('native_export_unique_'.$key,!isset($seen[$idx]));$seen[$idx]=true;
 $ex=$positive[$idx];$rawFlags=hexdec($flagsRaw);
 $check('native_original_export_flags_preserved_'.$key,
   ($rawFlags&(int)$ex['objectFlags'])===(int)$ex['objectFlags']);
 $check('native_UObject_RF_DebugSerialize_'.$key,
   ($rawFlags&0x40000000)!==0);
 $tag=T::inspect($m['original_map'],$pkg,$idx,'ut99-v432');
 $frame=$tag['serialized_state_frame']??null;
 $check('source_actual_TagEnvelope_'.$key,$tag['status']==='tag_envelope_decoded');
 $check('source_frame_present_'.$key,is_array($frame)&&$frame['has_stack_object_flag']===true);
 if(!is_array($frame))continue;
 $probe=strtolower(bin2hex(pack('V2',hexdec($p0),hexdec($p1))));
 $check('actual_compiled_ProbeMask64_'.$key,$probe===$frame['probe_mask_le_hex']);
 $nativeLatent=hexdec($latentRaw);
 if($nativeLatent>=0x80000000)$nativeLatent-=0x100000000;
 $check('actual_compiled_LatentAction32_'.$key,$nativeLatent===$frame['latent_action']);
 $check('actual_compiled_NodeOffset_NULL_'.$key,
   $frame['script_code_offset']===-1 && hexdec($codeRaw)===0);
 $check('real_StateFrame_archive_size_'.$key,
   $frame['serialized_size']===15
   &&$frame['serialized_start']===(int)$ex['serialOffset']);
 $nativeByteCounts+=$frame['serialized_size'];$tagsCount+=count($tag['tags']);$matched++;
}
$check('all_fourteen_post_archive_native_source_value_matches',$matched===14);
$check('method_still_not_entirely_native_verified',
 count($positive)-$matched===$m['unloaded_positive_exports']);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
 'failures'=>$fail,'genuine_original_positive_StateFrames'=>count($positive),
 'compiled_post_StateFrame_objects_verified'=>$matched,
 'unloaded_original_positive_StateFrames'=>count($positive)-$matched,
 'actual_StateFrame_bytes_correlated'=>$nativeByteCounts,
 'correlated_tagged_property_records'=>$tagsCount,
 'full_method_acceptance'=>'OPEN',
 'gap'=>'Five original Entry objects not exercised; Node/StateNode actual native identities and malformed code offsets and other source versions unverified.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
