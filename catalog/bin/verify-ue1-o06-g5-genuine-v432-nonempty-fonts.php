#!/usr/bin/env php
<?php
/** Genuine PRD-sourced, unmodified UT99 v69 UFont CharRemap 255 and 1183 maps.
 * SHA-pinned Microsoft CDB post-Serialize native objects vs production PHP.
 * No PRD code execution or writes. Does not claim malformed-input parity.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-PRD-NATIVE-NONEMPTY-FONTS.json'),
 true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];$totalFonts=0;$totalPairEntries=0;$reports=[];
$check=static function(string $label,bool $valid)use(&$checks,&$failed):void{
 $checks++;if(!$valid)$failed[]=$label;
};
$pin=static function(string $file,string $sha,string $id)use($check):string{
 $raw=is_file($file)?file_get_contents($file):false;
 $check('sha256_'.$id,is_string($raw)&&hash('sha256',$raw)===$sha);
 return is_string($raw)?$raw:'';
};
$pin('C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll',
 $m['compiled_engine_sha256'],'compiled_ut99_v432_engine');
$decodeLog=static function(string $raw):string{
 $text=str_starts_with($raw,"\xff\xfe")
  ? (string)iconv('UTF-16LE','UTF-8',substr($raw,2)) : $raw;
 return str_replace("\r",'',$text);
};
$parseNative=static function(string $text):array{
 $chunks=preg_split('/^G5_REAL_NONEMPTY_FONT_LOADED$/m',$text);
 $out=[];
 foreach(array_slice($chunks?:[],1) as $chunk){
  preg_match_all('/^([0-9a-f]{8})[ \t]+([0-9a-f]{8}(?:[ \t]+[0-9a-f]{8}){0,3})[ \t]*$/mi',
   $chunk,$matches,PREG_SET_ORDER);
  $rows=[];
  foreach($matches as $line){
   $words=preg_split('/[ \t]+/',trim($line[2]));
   $rows[]=['address'=>hexdec($line[1]),'words'=>array_map('hexdec',$words)];
  }
  if(count($rows)<4||count($rows[0]['words'])!==1)continue;
  $base=$rows[0]['address']-0x14;
  $id=$rows[0]['words'][0];$values=[];
  foreach($rows as $line){
   foreach($line['words'] as $i=>$value){$values[$line['address']-$base+4*$i]=$value;}
  }
  $out[$id]=['values'=>$values,'base'=>$base];
 }
 return $out;
};
foreach($m['cases'] as $c){
 $id=$c['stored'];$path=$c['local'];
 $pin($path,$c['package_sha256'],'original_package_'.$id);
 $log=$decodeLog($pin($c['log'],$c['log_sha256'],'compiled_loader_'.$id));
 $map=$pin($c['map'],$c['map_sha256'],'compiled_full_map_'.$id);
 $parsed=$parseNative($log);
 $check('all_native_font_loader_calls_'.$id,count($parsed)===$c['font_count']);
 $check('first_native_font_present_'.$id,isset($parsed[$c['first_export_index']]));
 $check('full_map_one_only_'.$id,substr_count($log,"\nG5_REAL_FONT_FIRST_FULL_MAP\n")===1);
 $check('native_full_map_size_'.$id,strlen($map)===$c['expected_remap_count']*8);
 $wire='';
 for($i=0;$i<$c['expected_remap_count'];$i++){$wire.=substr($map,$i*8+4,4);}
 $check('compiled_full_map_pairs_equal_recorded_source_sha_'.$id,
  hash('sha256',$wire)===$c['expected_source_map_wire_sha256']);
 $pkg=new P($path);
 $check('structure_'.$id,$pkg->validatePackage()===[]);
 $check('archive_version_69_'.$id,($pkg->getHeader()['version']??null)===69);
 $imports=$pkg->getImports();$exports=$pkg->getExports();$fontExports=[];
 foreach($exports as $e){
  $ci=(int)$e['classIndex'];
  $name=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):
   ($ci>0?(string)($exports[$ci-1]['objectNameText']??''):'Class');
  if(strcasecmp($name,'Font')!==0)continue;
  $fontExports[(int)$e['index']]=$e;
 }
 $check('font_export_count_'.$id,count($fontExports)===$c['font_count']);
 $first=null;
 foreach($fontExports as $idx=>$record){
  try{
   $tag=T::inspect($path,$pkg,$idx,'ut99-v432');
   $font=N::inspect($path,$pkg,$idx,$tag,'ut99-v432');
  }catch(Throwable $ex){$check('production_decoder_exception_'.$id.'-'.$idx,false);continue;}
  $check('source_archive_exactly_consumed_'.$id.'-'.$idx,
   ($tag['status']??null)==='tag_envelope_decoded'
   &&($font['status']??null)==='native_font_record_complete'
   &&($font['extension_bytes']??null)===0);
  $check('source_nonempty_pair_count_and_flag_'.$id.'-'.$idx,
   ($font['char_remap']['count']??null)===$c['expected_remap_count']
   &&($font['char_remap']['archive_data_bytes']??null)===$c['expected_remap_count']*4
   &&($font['is_remapped_raw']??null)===1);
  $native=$parsed[$idx]['values']??null;
  $check('compiled_loaded_font_export_idx_'.$id.'-'.$idx,is_array($native));
  if(!is_array($native))continue;
  $check('compiled_loaded_font_cpp_'.$id.'-'.$idx,
   ($native[0x28]??null)===($font['characters_per_page']??null));
  $check('compiled_loaded_font_pages_num_max_'.$id.'-'.$idx,
   ($native[0x2c]??0)>0&&($native[0x30]??null)===($font['page_count']??null)
   &&($native[0x34]??-1)>=($native[0x30]??0));
  $check('compiled_loaded_font_map_num_max_'.$id.'-'.$idx,
   ($native[0x38]??0)>0&&($native[0x3c]??null)===$c['expected_remap_count']
   &&($native[0x40]??-1)>=($native[0x3c]??0));
  $check('compiled_loaded_font_remap_active_'.$id.'-'.$idx,($native[0x4c]??null)===1);
  $totalFonts++;$totalPairEntries+=$c['expected_remap_count'];
  if($idx===$c['first_export_index'])$first=$font;
 }
 $check('native_first_font_name_'.$id,
   ($fontExports[$c['first_export_index']]['objectNameText']??null)===$c['first_font']);
 $check('native_first_font_source_cpp_'.$id,
   ($first['characters_per_page']??null)===$c['expected_cpp']
   &&($first['pages'][0]['glyph_count']??null)===$c['expected_glyphs']
   &&($first['char_remap']['archive_data_sha256']??null)===$c['expected_source_map_wire_sha256']);
 $check('loaded_full_TMap_identical_to_original_first_export_'.$id,
  ($first['char_remap']['archive_data_sha256']??null)===hash('sha256',$wire));
 $firstPage=$first ? (preg_match('/^G5_REAL_FONT_FIRST_PAGE\n[0-9a-f]{8}[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})$/mi',$log,$match)?$match:[]) : [];
 $check('first_compiled_loaded_glyph_array_'.$id,
   count($firstPage)===5 && hexdec($firstPage[1])>0
   &&hexdec($firstPage[2])>0 &&hexdec($firstPage[3])===$c['expected_glyphs']
   &&hexdec($firstPage[4])>=$c['expected_glyphs']);
 $reports[]=['original'=>$c['original'],'SHA256'=>$c['package_sha256'],
  'native_font_calls'=>count($parsed),'font_exports_verified'=>count($fontExports),
  'map_pairs_per_font'=>$c['expected_remap_count'],
  'full_first_TMap_native_archive_sha256'=>hash('sha256',$wire)];
}
$check('total_native_loaded_original_fonts',$totalFonts===62);
$check('loaded_two_independent_full_TMap_cases',$totalPairEntries===29*255+33*1183);
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'passed'=>$checks-count($failed),
 'errors'=>$failed,'genuine_native_font_exports'=>$totalFonts,
 'total_loaded_TMap_entries_across_all_fonts'=>$totalPairEntries,
 'full_TMap_bytewise_values_verified_for_first_font_of_each_case'=>2,
 'cases'=>$reports,
 'limitations'=>'Real v69 native loaded count/flag/CPP/page and two whole 255/1183 map arrays verified. Source-name provenance selected explicitly. Other 59 nonempty original fonts not individually CDB loaded; malformed boundary byte consumption still differs; O06 full method open.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
