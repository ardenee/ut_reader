#!/usr/bin/env php
<?php
/** T057: original compiled C98, stock UT99 v432, original 2012 C227i nested scripts. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$e=$root.'/catalog/compliance/evidence/o06/';
$c98='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/';
$c227='C:/Temp/UE1-G3-NativeOracle/original227i/System/';
$n=0;$errors=[];$pins=[];
$ck=static function(bool $yes,string $what)use(&$n,&$errors):void{
 ++$n;if(!$yes)$errors[]=$what;
};
foreach([
 [$c98.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$c98.'Engine.dll','2f3ab0f8bc40960e6be5e1f540ca974a1537d9b23f2b9d7d06af589400b70069'],
 [$c98.'Core.u','7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c'],
 [$c98.'Engine.u','bdccc8ee1557b758554714bcc676edb2cb4474239c70bedaaf81f07e9a419b7d'],
 [$c98.'Unreal.exe','d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30'],
 [$e.'20261028-G3-T057-C98-original-Engine-Grab-source-discovery.json',
  '7f986e863651900e362ba144b3a4c2244f89f4a3761683a219ae026b135ba1df'],
 [$e.'20261028-G3-T057-C98-original-Engine-Grab-loaded-native-30.log',
  '9f0e601d360c98cf78505ecd548b20e59b9d24f52cb652bcdcddbaef5a892ef5'],
 [$e.'20261028-G3-T057-C98-original-Engine-Grab-loaded-native-30.cmd',
  'ef6c851f7d8e81808ca87e4e16ebdb6208712680483caed69a4e867fda7fa383'],
 [$c227.'Core.dll','287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'],
 [$c227.'Core.u','bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b'],
 [$c227.'UCC.exe','69813336c9fd278f30939abde10477a37c5da951b2be8ca0c394b5bfd5cd6672'],
 [$e.'20261027-G3-03-C227I-237-223-ORIGINAL-NATIVE-VALUES.json',
  '36923b929fa6bf05389f93be97203caa6ce08495b838bd53e877b6e31ae96b5c'],
 [$e.'20261027-G3-03-C227I-223-SCRIPT-MEMORY-REAL-CDB.log',
  '01cf2d729a0f23f99e92492eb597e178e674a937b4ae5b88d14464c01f455c3b'],
 [$e.'20261027-G3-03-C227I-223-SCRIPT-MEMORY-REAL-COMMAND.cmd',
  '819405efb1760c5fb28dab4438b5d27c0b8224577a895b7e52a6bfd6ab8acf4d'],
 [$e.'20261028-G3-T051-original-v432-control-flow-native-compare.json',
  '457fd47987390226c24c57ec92c203510d7c30bf4c0c505d885464b7f9482bee'],
 [$e.'20261028-G3-T051-v432-original-scriptall-native.log',
  '2f9a686c19f52be6e9866eac1fb521d42bc479f073510fbcc397a6d317843a11'],
 [$e.'20261028-G3-T051-v432-original-scriptall-source.json',
  'e89d05be26fc13708eccefbf50682c41ce2431783de91f308a32ca11274e9a67'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php',
  'b309c89b21c88233a4de459b183b23dcdd2c4e4deb1fc2ba56a61f2ad34330bd'],
] as [$f,$sha]){
 $good=is_file($f)&&hash_file('sha256',$f)===$sha;
 $ck($good,'SHA original '.basename($f));
 if($good)$pins[basename($f)]=$sha;
}
$compare=static function(array $s,string $native,int $size,int $known,
    array $kinds,string $label)use($ck):array{
 $src=hex2bin((string)($s['source_memory_bytes_hex']??''));
 $mask=hex2bin((string)($s['source_memory_known_mask_hex']??''));
 $valid=is_string($src)&&strlen($src)===$size
  &&is_string($mask)&&strlen($mask)===$size&&strlen($native)===$size;
 $ck($valid,$label.' actual native and full source memory/mask lengths');
 if(!$valid)return ['known'=>0,'masked'=>0,'mismatches'=>['length']];
 $match=0;$unknown=0;$errors=[];$locations=[];
 for($i=0;$i<$size;$i++){
   if(ord($mask[$i])===1){
      ++$match;$equal=$src[$i]===$native[$i];
      $ck($equal,$label.' exact compiled native byte +'.$i);
      if(!$equal)$errors[]=$i;
   }else{
      ++$unknown;$ck(ord($src[$i])===0,$label.' native process word never fabricated +'.$i);
   }
 }
 $ck($match===$known&&$unknown===$size-$known,
   $label.' source known and typed relocation byte count');
 $refs=$s['native_load_relocation_preview']??[];
 $ck(array_column($refs,'kind')===$kinds
     &&($s['native_load_relocation_count']??-1)===count($kinds),
   $label.' native typed relocations source array');
 foreach($refs as $ref){
    $o=(int)$ref['memory_offset'];$locations[]=$o;
    $ck($o>=0&&$o+4<=$size&&substr($mask,$o,4)==="\0\0\0\0",
       $label.' source-original four-byte native word at correct mask slot '.$o);
    $ck($o>=0&&$o+4<=$size&&unpack('V',substr($native,$o,4))[1]!==0,
       $label.' actual original compiled typed loaded process slot not zero '.$o);
 }
 $ck(count(array_unique($locations))===count($locations),
   $label.' independent native reference slots');
 $ck(($s['native_pointer_addresses_fabricated']??true)===false
   &&($s['runtime_refs_resolved']??true)===false
   &&($s['source_memory_full_byte_reconstruction']??false)===true,
   $label.' full source archive expansion with unknown runtime refs NEVER fabricated');
 return ['known'=>$match,'masked'=>$unknown,'mismatches'=>$errors,'references'=>$refs,
   'native_loaded_memory_hex'=>bin2hex($native),
   'physical_script_archive_bytes'=>$s['archive_bytes_consumed']??null];
};
// The original May17 1998 retail editor -make compiled loader reached the
// real stock Engine.u export1613 on a disposable installation.
$p98=new P($c98.'Engine.u');$ex98=$p98->getExports()[1613]??[];
$ck($p98->validatePackage()===[]&&($p98->getHeader()['version']??0)===61
   &&($ex98['objectNameText']??'')==='Grab'&&($ex98['classIndex']??0)===-3,
   '1998 genuine Engine.u loaded Grab export1613 source and version');
$r98=N::inspect($c98.'Engine.u',$p98,1613,
   T::inspect($c98.'Engine.u',$p98,1613,'epic-ue1'),'epic-ue1');
$s98=$r98['script_archive']??[];
$ck(($r98['script_size']??0)===30&&($s98['script_memory_decoded']??0)===30
 &&($s98['archive_bytes_consumed']??0)===23
 &&($s98['expression_count']??0)===13
 &&count($s98['expression_preview']??[])===7,
 'original 1998 recursive script 13 visits/7 top-level; native Script.Num30 / archive23');
$refs98=$s98['native_load_relocation_preview']??[];
$ck(array_column($refs98,'memory_offset')===[7,14,23]
 &&array_column($refs98,'archive_index')===[30,729,89]
 &&($refs98[0]['target_name']??'')==='carriedDecoration'
 &&($refs98[1]['resolved_name']??'')==='GrabDecoration'
 &&($refs98[2]['resolved_name']??'')==='DropDecoration',
 'real original source object and two FName targets grounded to archive name and index');
$log=(string)file_get_contents($e.'20261028-G3-T057-C98-original-Engine-Grab-loaded-native-30.log');
$tag='T057_C98_ORIGINAL_EDITOR_LOADED_ENGINE_EXPORT1613';
$ck(preg_match_all('/(?m)^'.preg_quote($tag,'/').'\r?$/',$log)===1,
 'one original compiled Core.dll real loaded export 1613 debugger event');
$fragment=substr($log,(int)strpos($log,"\n".$tag)+1);
$m=[];$k=[];
$first=preg_match('/(?m)^([0-9a-f]{8})  ([0-9a-f]{2}(?:[ -][0-9a-f]{2}){15})/i',$fragment,$m)===1;
$ck($first,'original CDB first sixteen actual native memory bytes from live Script.Data');
$addr=$first?hexdec($m[1]):0;
$last=$first&&preg_match('/(?m)^'.sprintf('%08x',$addr+16).'  ([0-9a-f]{2}(?:[ -][0-9a-f]{2}){13})/i',
    $fragment,$k)===1;
$ck($last,'original CDB last fourteen contiguous native memory bytes');
$native98=$first&&$last?hex2bin((string)preg_replace('/[^0-9a-f]/i','',$m[2].$k[1])):false;
$ck(is_string($native98)&&strlen($native98)===30
 &&str_contains($fragment,'0000064d')&&str_contains($fragment,'0000001e'),
 'original compiled actual native linker export1613 and Script.Num30');
$c98cmp=$compare($s98,is_string($native98)?$native98:'',30,18,
 ['object_pointer','fname','fname'],'C98 stock Engine.Grab');
$prior98=json_decode((string)file_get_contents($e.
 '20261028-G3-T057-C98-original-Engine-Grab-source-discovery.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($prior98['errors']??['error'])===[]
 &&($prior98['source_script_archive']['source_memory_known_mask_hex']??'')
 ===($s98['source_memory_known_mask_hex']??'')
 &&($prior98['original_native_selected_export_loaded']??true)===false,
 'previous bounded server-loader MISS remains clearly non-accepted');

// Authentic 2012 Unreal v227i compiled Core.dll original CDB captured
// ALL 223 nonzero native script buffers. RandRange is a newly selected
// real recursive sample; no original native machine is rerun.
$p27=new P($c227.'Core.u');
$e27=$p27->getExports()[628]??[];
$r27=N::inspect($c227.'Core.u',$p27,628,
  T::inspect($c227.'Core.u',$p27,628,'unreal-v227i'),'unreal-v227i');
$s27=$r27['script_archive']??[];
$reg=json_decode((string)file_get_contents($e.
 '20261027-G3-03-C227I-237-223-ORIGINAL-NATIVE-VALUES.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($e27['objectNameText']??'')==='RandRange'
 &&($reg['native_structs']['628']['native_script_size']??0)===26,
 'original compiled 2012 v227i loaded UFunction RandRange export628');
$ck(($r27['script_size']??0)===26&&($s27['archive_bytes_consumed']??0)===18
 &&($s27['expression_count']??0)===14&&count($s27['expression_preview']??[])===2,
 'real 2012 source recursively decoded fourteen expressions, two top-level, 26/18 bytes');
$hex=(string)($reg['native_script_first36_hex']['628']??'');
$native27=hex2bin(substr($hex,0,52));
$c227cmp=$compare($s27,is_string($native27)?$native27:'',26,14,
 ['object_pointer','object_pointer','object_pointer'],'2012 genuine Core.RandRange');
if(is_string($native27)&&strlen($native27)===26){
 $ck(substr($native27,3,4)===substr($native27,15,4),
 'same archived object index6 in genuine original v227i resolves identical live pointer twice');
}
// Prior 25/25 native source/loader comparison of original UT99 v432
// Engine.Inventory (EX_Skip and nested native call) is SHA-sealed.
$v=json_decode((string)file_get_contents($e.
 '20261028-G3-T051-original-v432-control-flow-native-compare.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($v['ok']??false)===true&&($v['checks']??0)===25&&($v['errors']??['bad'])===[]
 &&($v['Engine_u_class']??'')==='Inventory'&&($v['class_export_index']??null)===4,
 'UT99 v432 original compiled Engine.Inventory nested proof previously accepted25/25');
$ck(($v['memory_script_num']??0)===33&&($v['archive_bytes_consumed']??0)===25
 &&($v['current_decoded_expression_count']??0)===17
 &&($v['exact_original_native_memory_known_bytes']??0)===21
 &&($v['relocated_native_pointer_bytes']??0)===12,
 'original compiled v432 nested 33/25 memory/archive, 21 known and 12 relocation masked');
$ck(str_contains((string)($v['nested_tokens']??''),'82->9A->3A->01')
 &&str_contains((string)($v['control_flow']??''),'EX_Skip 0x18')
 &&($v['original_compiled_engines_reexecuted']??-1)===0,
 'v432 original compiled recursive nested opcode + skip, no repeated native probe');
$known=$c98cmp['known']+$c227cmp['known']+21;
$masked=$c98cmp['masked']+$c227cmp['masked']+12;
$ck($known===53&&$masked===36,
 'three exact real compiled source families 53 known byte matches and 36 typed ref bytes masked');
$ck(($c98cmp['mismatches']??['error'])===[]
 &&($c227cmp['mismatches']??['error'])===[],
 'original C98/C227i compiled known-byte source comparisons have zero mismatches');
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'errors'=>$errors,
 'original_compiled_source_profiles'=>['C98-May17-1998','UT99-v432','Unreal-C227i-2012'],
 'true_original_compiled_nested_script_samples'=>3,
 'recursive_expression_visits'=>['C98'=>13,'v432'=>17,'C227i'=>14],
 'real_native_known_bytes_equal'=>$known,
 'real_native_typed_relocation_bytes_masked'=>$masked,
 'C98_original_native'=>$c98cmp,'C227i_original_native'=>$c227cmp,
 'v432_sealed_compiled_native_known_bytes'=>21,
 'physical_archive_bytes'=>['C98'=>23,'v432'=>25,'C227i'=>18],
 'previously_completed_native_sessions_reexecuted'=>0,
 'old_acceptance_suites_replayed'=>0,
 'source_and_native_pin_sha256'=>$pins,'G3_12_overall'=>'OPEN'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
