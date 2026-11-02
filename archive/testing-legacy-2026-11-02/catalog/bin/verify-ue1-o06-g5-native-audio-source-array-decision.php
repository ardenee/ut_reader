#!/usr/bin/env php
<?php
/** One source-specific genuine archive test, no native v432 oracle replay.
 * Source type overrides global package version when reading TLazyArray.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-SOURCE-AUDIO-ARRAY-DISPATCH.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];$ck=static function(string $id,bool $yes)use(&$checks,&$fail):void{
 $checks++;if(!$yes)$fail[]=$id;
};
foreach($m['source_headers'] as $id=>$item){
 $ck('Epic_SHA_'.$id,is_file($item['path'])&&hash_file('sha256',$item['path'])===$item['sha256']);
}
$epic=(string)file_get_contents($m['source_headers']['v1200_TArray_audio']['path']);
$ut99=(string)file_get_contents($m['source_headers']['ut99_v400_TLazyArray_audio']['path']);
$v227=(string)file_get_contents($m['source_headers']['v227_TLazyArray_audio']['path']);
$ck('source_v1200_audio_TArray_sounds_and_music',substr_count($epic,'TArray<BYTE>')>=2);
$ck('source_ut99_lazy_sound_and_music',substr_count($ut99,'TLazyArray<BYTE>')>=2);
$ck('source_v227_lazy_sound_and_music',substr_count($v227,'TLazyArray<BYTE>')>=2);
$production=(string)file_get_contents($repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php');
$ck('production_seek_requires_lazy_source_and_greater61',
 str_contains($production,'$lazyAudio=$family!==CatalogUE1ClassicSourceFamily::EPIC_UE1;')
 &&str_contains($production,'$seekEnd=$lazyAudio&&$ver>61?$int():null;'));
$tot=0;$modern=0;$legacy=0;$allTypes=[];
foreach($m['genuine_originals_SHA'] as $label=>$item){
 $p=$item['path'];$expected=$item['audio_exports_expected'];$family=$item['source'];
 $ck($label.'_original_SHA256',hash_file('sha256',$p)===$item['sha256']);
 $pkg=new P($p);$hdr=$pkg->getHeader();
 $ck($label.'_header',(int)$hdr['version']===(($family==='epic-ue1')?61:69)
    &&(int)$hdr['licenseeVersion']===(($family==='epic-ue1')?0:227));
 $observed=0;
 foreach($pkg->getExports() as $ix=>$ex){
  if((int)$ex['serialSize']<=0)continue;
  $obj=T::inspect($p,$pkg,$ix,$family);
  $r=N::inspect($p,$pkg,$ix,$obj,$family);
  $cls=strtolower((string)($r['class_name']??''));
  if($cls!=='sound'&&$cls!=='music')continue;
  $observed++;$tot++;$allTypes[$cls]=($allTypes[$cls]??0)+1;
  $lazy=$family!=='epic-ue1';
  if($lazy)$modern++;else $legacy++;
  $ck($label.'_native_status_'.$ix,($r['status']??'')==='native_'.$cls.'_record_complete');
  $ck($label.'_declared_array_'.$ix,($r['source_audio_array_type']??null)===($lazy?'TLazyArray<BYTE>':'TArray<BYTE>')
    &&($r['source_lazy_seek_header_present']??null)===$lazy);
  $ck($label.'_source_byte_bounds_'.$ix,
     ($r['extension_bytes']??-1)===0&&($r['audio_data_bytes']??-1)>=0
     &&($r['audio_data_offset']??-1)>=0
     &&(int)$r['audio_data_offset']+(int)$r['audio_data_bytes']<=(int)filesize($p));
  $ck($label.'_lazy_header_'.$ix,
    $lazy?(is_int($r['lazy_seek_pos'])&&$r['lazy_seek_pos']===$r['audio_data_offset']+$r['audio_data_bytes'])
         :$r['lazy_seek_pos']===null);
 }
 $ck($label.'_real_audio_count',$observed===$expected);
 $ck($label.'_file_unchanged',hash_file('sha256',$p)===$item['sha256']);
}
$ck('real_original_audio_totals',$tot===35&&$legacy===2&&$modern===33&&$allTypes===['music'=>3,'sound'=>32]||$tot===35&&$legacy===2&&$modern===33&&($allTypes['sound']??0)===32&&($allTypes['music']??0)===3);
$ck('native_oracles_not_replayed',$m['v432_compiled_engine_retest']===0&&$m['method_FULL_decision']==='BLOCKED');
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
 'failed'=>$fail,'genuine_real_original_audio_exports'=>$tot,
 'v1200_TArray_audio_records'=>$legacy,
 'v227_TLazyArray_audio_records'=>$modern,
 'compilation_engine_reruns'=>0,
 'method_full_decision'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
