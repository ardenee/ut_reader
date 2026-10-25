#!/usr/bin/env php
<?php
/** Conclusive method-specific loaded native values + malformed outcomes.
 * Reuse pinned original v432 compiled Core+Engine oracle logs; no native
 * reexecution, no PRD file modification and no false full UObject claim.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-AUDIO-NATIVE-LOADED-AND-MALFORMED-ACCEPTANCE.json'),true,512,JSON_THROW_ON_ERROR);
$pass=0;$bad=[];
$check=static function(string $name,bool $okay) use(&$pass,&$bad):void{
 if($okay){$pass++;}else{$bad[]=$name;}
};
$verify=static function(string $name,array $file)use($check):void{
 $check($name,is_file($file['path'])&&hash_file('sha256',$file['path'])===$file['sha256']);
};
foreach($m['engine_source_and_executables'] as $name=>$file){$verify('native_or_source_SHA_'.$name,$file);}
foreach($m['native_loaded_value_stdout_SHA'] as $name=>$file){$verify('native_stdout_SHA_'.$name,$file);}
foreach($m['negative_compiled_stdout_SHA'] as $name=>$file){$verify('negative_stdout_SHA_'.$name,$file);}
foreach($m['negative_original_derived_DEV_only_sha'] as $i=>$file){$verify('negative_bytes_SHA_'.$i,$file);}
foreach($m['genuine_original_archive'] as $name=>$file){$verify('original_archive_SHA_'.$name,$file);}
foreach($m['generated_local_comparison'] as $name=>$file){$verify('compiled_report_SHA_'.$name,$file);}
$source=(string)file_get_contents($m['engine_source_and_executables']['UT99_v400_Engine_USound_UMusic_Serialize']['path']);
$lazy=(string)file_get_contents($m['engine_source_and_executables']['UT99_v400_Core_TLazyArray_operator']['path']);
$check('Epic_USound_WAV_Duration_computed_by_GetPeriod',
    str_contains($source,'Owner->Duration = GetPeriod();')
    &&str_contains($source,'FWaveModInfo WaveInfo;')
    &&str_contains($source,'pWaveDataSize * 8.f / (FLOAT)DurDiv'));
$check('Epic_empty_sound_dereferences_WavData_0',
    str_contains($source,'&WavData(0)'));
$check('Epic_lazy_SeekPos_skips_after_Load',
    str_contains($lazy,'Ar.Seek( SeekPos );')
    &&str_contains($lazy,'if( !GLazyLoad )'));
$loaded=json_decode((string)file_get_contents($m['generated_local_comparison']['native_11_loaded_values']['path']),true,512,JSON_THROW_ON_ERROR);
$durations=json_decode((string)file_get_contents($m['generated_local_comparison']['nine_native_Sound_Duration_exact_float_bits']['path']),true,512,JSON_THROW_ON_ERROR);
$check('real_native_11_loaded_objects',$loaded['native_real_loaded_memory_parity_objects']===11);
$check('real_native_9_sound_2_music',$loaded['native_sound_count']===9&&$loaded['native_music_count']===2);
$check('real_native_over_1MB_all_byte_exact',$loaded['native_real_loaded_memory_parity_bytes']===1216472);
$check('all_9_native_sound_duration_float_bits_exact',
    count($durations)===9&&!in_array(false,array_column($durations,'exact'),true));
$records=[];
foreach($loaded['records'] as $item){$records[$item['name']]=$item;}
$totalRead=0;$numObjects=0;$noBadTail=0;
foreach($m['genuine_original_archive'] as $filename=>$file){
 $archive=$file['path'];$package=new P($archive);
 $exports=$package->getExports();$imports=$package->getImports();
 $check('version_'.$filename,(int)$package->getHeader()['version']===(int)$file['version']);
 $count=0;
 foreach($exports as $i=>$ex){
  $ci=(int)$ex['classIndex'];
  $cls=$ci<0?($imports[-$ci-1]['objectNameText']??''):($ci>0?($exports[$ci-1]['objectNameText']??''):'Class');
  if(!in_array($cls,['Sound','Music'],true))continue;
  $parents=[];$outer=(int)$ex['outerIndex'];$limit=0;
  while($outer>0&&++$limit<16){
   $par=$exports[$outer-1];
   array_unshift($parents,$par['objectNameText']);$outer=(int)$par['outerIndex'];
  }
  $path=$cls.' '.pathinfo($filename,PATHINFO_FILENAME).'.'
    .($parents?implode('.',$parents).'.':'').$ex['objectNameText'];
  $decoded=T::inspect($archive,$package,$i,'ut99-v432');
  $native=N::inspect($archive,$package,$i,$decoded,'ut99-v432');
  $exp=$records[$path]??null;
  $check('real_native_record_source_'.$path,
     $exp!==null&&($native['status']??null)==='native_'.strtolower($cls).'_record_complete'
     &&($native['source_audio_array_type']??null)==='TLazyArray<BYTE>'
     &&($native['audio_data_bytes']??-1)===$exp['memory_archive_bytes_identical']
     &&($native['file_type_name']??null)===$exp['type']
     &&($native['audio_data_sha256']??null)===$exp['archive_sha256']
     &&($native['lazy_forward_skipped_unloaded_bytes']??-1)===0
     &&($native['extension_bytes']??-1)===0);
  if($exp!==null)$totalRead+=$native['audio_data_bytes'];
  $numObjects++;$count++;
 }
 $check('real_original_native_export_count_'.$filename,$count===$file['audio_export_count']);
}
$check('loaded_objects_all_cross_verified_once',$numObjects===11&&count($records)===11);
$check('cross_verified_exact_memory_bytes',$totalRead===1216472);
$neg=[];
$readNegative=static function(string $path)use(&$neg):array{
 $p=new P($path);$out=['file'=>basename($path)];
 try{
  $tag=T::inspect($path,$p,0,'ut99-v432');
  $n=N::inspect($path,$p,0,$tag,'ut99-v432');
  $out['status']=$n['status'];$out['bytes']=$n['audio_data_bytes'];
  $out['skip']=$n['lazy_forward_skipped_unloaded_bytes']??0;
  $out['extension']=$n['extension_bytes']??null;
 }catch(Throwable $e){$out['error']=$e->getMessage();}
 $neg[]=$out;return $out;
};
$files=$m['negative_original_derived_DEV_only_sha'];
$paths=[];
foreach($files as $f){$paths[basename($f['path'])]=$f['path'];}
$musicZero=$readNegative($paths['G5AudioMusicZeroCount.umx']);
$check('compiled_Music_zero_count_success',
 ($musicZero['status']??null)==='native_music_record_complete'
 &&$musicZero['bytes']===0&&$musicZero['skip']===734772&&$musicZero['extension']===0);
$musicType=$readNegative($paths['G5AudioMusicInvalidFileType.umx']);
$check('compiled_Music_invalid_name_fails',
 str_contains((string)($musicType['error']??''),'Audio FileType FName out of range'));
$musicSeek=$readNegative($paths['G5AudioMusicLazySeekEarly.umx']);
$check('compiled_Music_backward_seek_fails',
 str_contains((string)($musicSeek['error']??''),'Audio lazy SeekPos mismatch'));
$soundName=$readNegative($paths['G5AudioSoundInvalidFileType.uax']);
$check('compiled_Sound_invalid_name_fails',
 str_contains((string)($soundName['error']??''),'Audio FileType FName out of range'));
$soundEmpty=$readNegative($paths['G5AudioSoundZeroCount.uax']);
$check('compiled_Sound_zero_data_native_WAV_crash_guard',
 str_contains((string)($soundEmpty['error']??''),'native FSoundData::GetPeriod dereferences WavData(0)'));
$nativeMusicZero=iconv('UTF-16','UTF-8',(string)file_get_contents($m['negative_compiled_stdout_SHA']['Music_zero_count_compiled_load_ok']['path']));
$check('compiled_Music_zero_native_output',
 $nativeMusicZero!==false&&str_contains((string)$nativeMusicZero,'bytes=0 original_size=0'));
$nativeSoundCrash=file_get_contents($m['negative_compiled_stdout_SHA']['Sound_zero_count_cdb_access_violation']['path']);
$check('compiled_Sound_crash_disposition',
 $nativeSoundCrash!==false&&str_contains((string)$nativeSoundCrash,'Access violation - code c0000005'));
$check('method_specific_scope_not_FULL',
 $m['native_compiled_full_method_decision']==='BLOCKED_INHERITED_UObject_FINAL_SEVEN_GATE'
 &&$m['source_specific_audio_fields_and_malformed_slices']==='VERIFIED');
$result=['ok'=>count($bad)===0,'checks'=>$pass+count($bad),'passed'=>$pass,
 'failed'=>$bad,'compiled_loaded_sound_objects'=>9,'compiled_loaded_music_objects'=>2,
 'native_memory_bytes_match'=>1216472,'Sound_Duration_exact_bits'=>9,
 'compiled_distinct_malformed_cases'=>5,'Music_zero_lazy_skipped_unloaded_bytes'=>734772,
 'native_loaded_and_malformed_acceptance'=>'VERIFIED_SCOPE',
 'full_Sound_Music_method'=>'BLOCKED_SHARED_UOBJECT_AND_FINAL_GATES',
 'negative_outcomes'=>$neg];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
