<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as R;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as F;
$r=dirname(__DIR__,2);
$m=json_decode(file_get_contents($r.'/catalog/compliance/UE1-O06-G3-08-C227K-ORIGINAL-NATIVE-ANY-NAME5-ZERO.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$bad=[];$ok=function($label,$b)use(&$n,&$bad){$n++;if(!$b)$bad[]=$label;};
$ok('original native scope still partial',$m['G3_08_FULL']==='OPEN'&&$m['nonzero_FName_archive_name_table_mapping_unverified']===true);
$ok('original shipped DLL exact SHA',
 hash_file('sha256',$m['original_compiled_DLL_path'])===$m['original_compiled_DLL_sha256']);
foreach($m['new_native_evidence_artifacts'] as $name=>$a){
 $f=$r.'/'.$a['repo_path'];$ok('original compiled native evidence SHA '.$name,
  is_file($f)&&filesize($f)===$a['bytes']&&hash_file('sha256',$f)===$a['sha256']);
}
$f=$r.'/'.$m['new_native_evidence_artifacts']['real_original_227k_NAME5_ZERO_LOAD_SAVE_stdout']['repo_path'];
$s=file_get_contents($f);
if(str_starts_with($s,"\xff\xfe"))$s=iconv('UTF-16LE','UTF-8',substr($s,2));
$ok('original compiled native NAME5 LOAD exact source and byte cursor',
 str_contains($s,'type=5 cursor=5 calls=3 error=0'));
$ok('original compiled native NAME5 SAVE actual five bytes',
 str_contains($s,'cursor=5 calls=2 error=0 hex=0500000000'));
$ok('original native virtual FArchive FName at slot0x1c',$m['genuine_original_FArchive_FName_virtual_slot']==='0x1c');
$ok('production actual reader SHA pinned',hash_file('sha256',$r.'/'.$m['source_decoder'])===$m['source_decoder_sha256']);
$wire="\x05\0\0\0\0";$pos=0;
$read=function(int $n)use(&$pos,$wire){if($n<0||$pos+$n>strlen($wire))throw new RuntimeException('physical EOF');$x=substr($wire,$pos,$n);$pos+=$n;return $x;};
$val=R::readFromArchive($read,$pos,F::UNREAL_V227);
$ok('production actual original NAME5 ZERO parsed',$val['original_any_type_id']===5&&$val['value_kind']==='native_any_zero_fname'&&$val['value']['name_index']===0&&$pos===5);
$ok('production actual original NAME5 ZERO saved byte for byte',R::saveVerified(5,"\0\0\0\0",F::UNREAL_V227)===$wire);
foreach(["\x05\x01\0\0\0","\x05\0\0\0","\x06\0\0\0\0"] as $raw){
 $pos=0;$read=function(int $n)use(&$pos,$raw){if($pos+$n>strlen($raw))throw new RuntimeException('physical EOF');$x=substr($raw,$pos,$n);$pos+=$n;return $x;};
 $blocked=false;try{R::readFromArchive($read,$pos,F::UNREAL_V227);}catch(RuntimeException){$blocked=true;}
 $ok('unverified original NAME nonzero, truncated, STRUCT stay unsupported',$blocked);
}
$blocked=false;try{R::saveVerified(5,"\x01\0\0\0",F::UNREAL_V227);}catch(RuntimeException){$blocked=true;}
$ok('save nonzero NAME is not guessed',$blocked);
$blocked=false;try{R::saveVerified(5,"\0\0\0\0",F::UT99_V432);}catch(RuntimeException){$blocked=true;}
$ok('source original UT99 v432 does not inherit 227k native Any',$blocked);
echo json_encode(['ok'=>$bad===[],'checks'=>$n,'passed'=>$n-count($bad),'failed'=>$bad,'G3_08'=>'OPEN','previous_suites_rerun'=>0],JSON_PRETTY_PRINT).PHP_EOL;
exit($bad===[]?0:1);
