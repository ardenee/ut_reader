#!/usr/bin/env php
<?php
/** G5: genuine compiled UT99 v432 loaded UFont values and texture linker identity.
 * CDB captures were taken at Engine.dll post-Serialize archive RVA 0x8486B
 * on a disposable native ucc.exe load. This verifier does not run the engine.
 * Four raw CDB .writemem glyph arrays remain immutable under C:/Temp.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-FONT-NATIVE-LOADED.json'),
 true,512,JSON_THROW_ON_ERROR);
$errors=[];$checked=0;
$check=static function(string $name,bool $ok)use(&$errors,&$checked):void{
 $checked++;if(!$ok)$errors[]=$name;
};
$base='C:/Temp/UnrealDB/O06/';
$native=$base.'Native-UCC/System/';
$verifyFile=static function(string $name,string $hash)use($check):void{
 $check('sha_'.basename($name),is_file($name)&&hash_file('sha256',$name)===$hash);
};
$verifyFile($native.'Engine.u',$manifest['engine_package_sha256']);
$verifyFile($native.'Engine.dll',$manifest['native_engine_sha256']);
$verifyFile($native.'Core.dll',$manifest['native_core_sha256']);
$verifyFile($native.'ucc.exe',$manifest['native_ucc_sha256']);
foreach(['memory_log','glyph_dump_log','reference_log'] as $k){
 $verifyFile($manifest[$k],$manifest[$k.'_sha256']);
}
$contract=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-UT99-V432-COMPILED-SERIALIZERS.json'),true,512,JSON_THROW_ON_ERROR);
$check('compiled_native_UFont_method_rva',
 ($contract['native_methods']['UFont::Serialize']['compiled_method_rva']??null)===$manifest['compiled_method_rva']);
$check('compiled_UFont_archive_post_instruction',($manifest['post_archive_breakpoint_rva']??null)==='0x8486B');
$logs=[];
foreach(['memory_log','glyph_dump_log','reference_log'] as $k){
 $raw=is_file($manifest[$k])?(string)file_get_contents($manifest[$k]):'';
 // CDB .logopen /u writes original UTF-16LE with a BOM. SHA validation
 // above is over the untouched native bytes; decode only for parsing.
 $logs[$k]=str_starts_with($raw,"\xff\xfe")
    ?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
}
$memory=$logs['memory_log'];$dump=$logs['glyph_dump_log'];$reference=$logs['reference_log'];
$check('independent_compiled_Engine_module_loaded',
 str_contains($memory,'Engine.dll')&&str_contains($reference,'Engine.dll'));
$check('compiled_debugger_observed_post_serializer_before_exit',
 str_contains($memory,'G5_LOADED_FONT')
 &&str_contains($reference,'G5_FONT_AND_TEXTURE_LINKER')
 &&str_contains($memory,'NtTerminateProcess'));
$loads=array_slice(preg_split('/^G5_LOADED_FONT\r?\n/m',$memory)?:[],1);
$refSections=array_slice(preg_split('/^G5_FONT_AND_TEXTURE_LINKER\r?\n/m',$reference)?:[],1);
preg_match_all('/^G5_FONT_LOAD_([1-4])\r?$/m',$dump,$dumps);
$check('exact_four_native_loaded_UFont_records',count($loads)===4);
$check('exact_four_native_reference_records',count($refSections)===4);
$check('exact_four_native_full_glyph_array_writes',$dumps[1]===['1','2','3','4']
 &&substr_count($dump,'Writing 1000 bytes..')===4);
$readQuads=static function(string $s):array{
 preg_match_all('/^[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s*$/mi',$s,$m,PREG_SET_ORDER);
 return array_map(static fn(array $line):array=>array_map('hexdec',array_slice($line,1,4)), $m);
};
$readOne=static function(string $s):?int{
 return preg_match('/^[0-9a-f]{8}\s+([0-9a-f]{8})\s*$/mi',$s,$m)?hexdec($m[1]):null;
};
$package=new P($native.'Engine.u');
$check('genuine_Engine_u_valid',$package->validatePackage()===[]);
$check('genuine_Engine_u_v69',(int)($package->getHeader()['version']??-1)===69);
$exports=$package->getExports();$imports=$package->getImports();$found=[];$summary=[];
foreach($exports as $e){
 $ci=(int)($e['classIndex']??0);
 $name=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):
    ($ci>0?(string)($exports[$ci-1]['objectNameText']??''):'Class');
 if(strcasecmp($name,'Font')===0)$found[(int)$e['index']]=$e;
}
$check('exact_four_real_Engine_font_exports',count($found)===4&&count($manifest['font_records'])===4);
foreach($manifest['font_records'] as $i=>$spec){
 $id=(int)$spec['index'];$e=$found[$id]??null;$n=null;
 $check('font_export_identity_'.$i,is_array($e)&&$e['objectNameText']===$spec['name']);
 if(!is_array($e))continue;
 $tag=T::inspect($native.'Engine.u',$package,$id,'ut99-v432');
 $n=N::inspect($native.'Engine.u',$package,$id,$tag,'ut99-v432');
 $page=$n['pages'][0]??[];
 $blob=$base.$spec['glyph_filename'];
 $verifyFile($blob,$spec['glyph_sha256']);
 $check('compiled_glyph_memory_exact_4096_'.$i,is_file($blob)
   &&filesize($blob)===$spec['glyph_bytes']&&$spec['glyph_bytes']===4096);
 $check('production_glyph_bytes_identical_to_loaded_native_'.$i,
    ($page['glyph_data_sha256']??null)===$spec['glyph_sha256']
    &&($page['glyph_data_bytes']??null)===$spec['glyph_bytes']);
 $check('production_complete_native_file_archive_'.$i,
    ($tag['status']??null)==='tag_envelope_decoded'
    &&($n['status']??null)==='native_font_record_complete'
    &&($n['extension_bytes']??null)===0
    &&($n['characters_per_page']??null)===256
    &&($n['page_count']??null)===1
    &&($page['glyph_count']??null)===256
    &&($n['char_remap']['count']??null)===0
    &&($n['is_remapped_raw']??null)===0
    &&($page['texture_reference']??null)===$spec['texture_reference']);
 $texture=(int)$spec['texture_reference'];
 $check('source_page_texture_export_map_bounds_'.$i,
    $texture>0&&$texture<=count($exports)
    &&($texture-1)===$spec['native_texture_linker_index']);
 $mSection=$loads[$i]??'';
 $mem=explode('G5_LOADED_PAGE',$mSection,2);
 $objectDwords=$readQuads($mem[0]??'');
 $pageSegment=explode('G5_LOADED_GLYPH0',$mem[1]??'',2);
 $pageDwords=$readQuads($pageSegment[0]??'');
 $nativeDwords=$objectDwords[0]??[];
 $mapDwords=$objectDwords[1]??[];
 $flagDwords=$objectDwords[2]??[];
 $firstPage=$pageDwords[0]??[];
 $check('compiled_loaded_UFont_cpp_pages_'.$i,
    count($nativeDwords)===4 && $nativeDwords[0]===256
    &&$nativeDwords[1]>0&&$nativeDwords[2]===1&&$nativeDwords[3]>=1);
 $check('compiled_loaded_UFont_empty_char_remap_'.$i,
    count($mapDwords)===4&&$mapDwords[0]===0&&$mapDwords[1]===0&&$mapDwords[2]===0
    &&count($flagDwords)===4&&$flagDwords[1]===0);
 $check('compiled_loaded_page_texture_pointer_and_glyph_count_'.$i,
    count($firstPage)===4&&$firstPage[0]>0&&$firstPage[1]>0
    &&$firstPage[2]===256&&$firstPage[3]>=256);
 $ref=explode('G5_TEXTURE_OBJECT_LINKER',$refSections[$i]??'',2);
 $fontLinker=$readOne($ref[0]??'');
 $textureLinker=$readOne($ref[1]??'');
 $check('compiled_loaded_UObject_font_export_identity_'.$i,$fontLinker===$id);
 $check('compiled_loaded_UTexture_reference_identity_'.$i,$textureLinker===$texture-1);
 $summary[]=['font'=>$spec['name'],'font_export'=>$id,'texture_reference'=>$texture,
    'native_texture_linker_index'=>$textureLinker,'glyph_bytes'=>$spec['glyph_bytes'],
    'glyph_sha256'=>$spec['glyph_sha256']];
}
echo json_encode(['ok'=>$errors===[],'checks'=>$checked,'passed'=>$checked-count($errors),
  'errors'=>$errors,'native_engine'=>'installed UT99 v432 Engine.dll',
  'compiled_loader_calls'=>count($loads),'matched_fonts'=>count($summary),
  'matched_glyph_bytes'=>count($summary)*4096,'references'=>$summary,
  'limitations'=>'Four real v69 loaded fonts, exact glyph memory, selected native counts and export reference identity only. No nonempty CharRemap, native malformed-input exception parity, other font packages or O06-wide acceptance. Native batchexport intentionally lacked a Font txt exporter after load.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
