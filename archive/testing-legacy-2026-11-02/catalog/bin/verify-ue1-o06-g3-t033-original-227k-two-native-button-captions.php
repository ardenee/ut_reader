<?php
/** Frozen T033: original compiled 227k Core.dll loaded two UButtonProperty
 * captions, observed in x86 CDB after the genuine native Serialize routine. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$repo=dirname(__DIR__,2);$ev=$repo.'/catalog/compliance/evidence/o06/';
$sys='C:/Temp/UnrealDB/O06/Native-227k/System/';$n=0;$errors=[];
$ok=static function(bool $pass,string $label)use(&$n,&$errors):void{++$n;if(!$pass)$errors[]=$label;};
$pins=[
 [$sys.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$sys.'UCC.exe','8377f0e1a8adcfb087a4ada317ff5dedbab406a7888226af904aca84908d907a'],
 [$sys.'ScriptedAI.u','396abd174a6ec86631de7d7c442f9187c7658af4baf2eaa6a58a93eff9327d36'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php','5a4739d636f5916235f76f1503996033c166b7633b29e565c5ffa7f2d57d39be'],
 [$ev.'20261031-G3-T033-original-227k-two-UButtonProperty-native-CDB.log','6198a357d2e7843b260428f8915a65489dabb6beb2ffe595bfa2e9f685a4d707'],
 [$ev.'20261031-G3-T033-original-227k-UButtonProperty-Serialize-disassembly.log','74faab17a34a069f4990784ca9cb9cc4a2ab0c1b13ce8d8f386a572ab2856b50'],
 [$ev.'20261031-G3-T033-original-227k-UButtonProperty-native-break.cmd','3145a06d59ff0fa0abc0034aadecda6697af6b08a3015fcee09e23aa3cafa7f7'],
 [$ev.'20261031-G3-T033-original-227k-scriptedai-dumpint-CDB.stdout.log','204a2fe7f8a9b78b1daeab3e511e4acd53a0129d9665ed683ae4c8a5a106024e']
];
foreach($pins as [$f,$sha])$ok(is_file($f)&&hash_file('sha256',$f)===$sha,'original source/native evidence SHA256 '.basename($f));
$src='L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Inc/UnType.h';
$source=(string)file_get_contents($src);
$ok(str_contains($source,'class CORE_API UButtonProperty : public UProperty')&&str_contains($source,'FString Caption;')&&str_contains($source,'void Serialize(FArchive& Ar);'),'matched original 227k SDK UButtonProperty Caption FString and compiled native method declaration');
$disasm=(string)file_get_contents($ev.'20261031-G3-T033-original-227k-UButtonProperty-Serialize-disassembly.log');
foreach(['Core!UButtonProperty::Serialize:','5f8b001e 8d868c000000','lea     eax,[esi+8Ch]','call    Core!operator<<','ret     4'] as $fragment)
 $ok(str_contains($disasm,$fragment),'actual original compiled Core.dll UButton serialize field offset/ABI '.$fragment);
$command=(string)file_get_contents($ev.'20261031-G3-T033-original-227k-UButtonProperty-native-break.cmd');
$ok(str_contains($command,'bp Core+0x80030')&&str_contains($command,'du poi(@esi+0x8c)'),'debugger hooks AFTER source native archive operator loads Caption, typed FString pointer');
$log=(string)file_get_contents($ev.'20261031-G3-T033-original-227k-two-UButtonProperty-native-CDB.log');
$ok(str_contains($log,'ModLoad:')&&str_contains($log,'Core.dll')&&str_contains($log,'ScriptedAI')&&str_contains($log,'bp Core+0x80030'),'actual original stock binary loaded ScriptedAI with two native UButton serialization breakpoints');
preg_match_all('/^[0-9a-f]{8}\s+"(Move point to viewport|Go to point)"\s*$/mi',$log,$m);
$ok(count($m[1])===2&&$m[1]===['Move point to viewport','Go to point'],'exactly two native post-Serialize FString loaded values direct CDB du; no inferred archive substitution');
$ok(str_contains($log,'ntdll!NtTerminateProcess')&&!str_contains($log,'Bad expr token'),'native debugger captured original completed UCC commandlet, stopped only at termination breakpoint');
$stdout=(string)file_get_contents($ev.'20261031-G3-T033-original-227k-scriptedai-dumpint-CDB.stdout.log');
if(str_starts_with($stdout,"\xFF\xFE")){
 $stdout=(string)iconv('UTF-16LE','UTF-8',substr($stdout,2));
}
$ok(str_contains($stdout,'Checking for classes')&&str_contains($stdout,'Merging all language files')&&str_contains($stdout,'Saving'),'compiled original commandlet loaded source package before successfully dumping localization');
$file=$sys.'ScriptedAI.u';$pkg=new P($file);$summary=$pkg->getHeader();
$ok($summary['version']===69&&$summary['licensee']===227,'original source explicit 227k v69 licensee227, not same version UT99 v432');
$ok(count($pkg->getExports())===2411&&count($pkg->getImports())===260,'real original retail ScriptedAI.u original compiled package export/import maps');
$cases=[
 ['MoveCamButton',2285,292981,80,'Move point to viewport',23,'499e9492df77b284dcafd93b867be1e41765d2cf6435b1a747ef314c4e888b06'],
 ['MovePointButton',2286,293061,62,'Go to point',12,'3cfea8b0ef23761b61c2aa6568d511f5236731f8c3f45b2fcd38a91868d0cb99']
];
$found=[];
foreach($cases as [$name,$index,$offset,$size,$caption,$len,$hash]){
 $ex=$pkg->getExports()[$index]??[];
 $ok(($ex['objectNameText']??null)===$name&&$ex['serialOffset']===$offset&&$ex['serialSize']===$size,'original object path/linker index physical archive span '.$name);
 $result=N::inspect($file,$pkg,$index,T::inspect($file,$pkg,$index,'unreal-v227k'),'unreal-v227k');
 $fields=$result['subclass_fields']??[];
 $ok(($result['class_name']??null)==='ButtonProperty'&&($result['status']??null)==='native_buttonproperty_caption_record_complete','UnrealDB exact original native method source family and physical archive consumption '.$name);
 $ok(($fields['caption']??null)===$caption&&($fields['caption_count_signed']??null)===$len&&($fields['caption_unit_count']??null)===$len,'original compiled native loaded Caption equals reader text and counted signed ANSI units '.$name);
 $ok(($fields['caption_string_raw_sha256']??null)===$hash&&($fields['caption_terminator_is_nul']??false)===true,'on-disk compiled original FString exact NUL and byte SHA '.$name);
 $ok(($fields['caption_text_decoded']??false)===true&&($result['selected_source_family']??null)==='unreal-v227','native 227k explicit source provenance and correct class kind '.$name);
 $found[$name]=['original_native_loaded_caption'=>$caption,'actual_serial_offset'=>$offset,'serial_size'=>$size,'caption_count'=>$len];
}
$out=['ok'=>$errors===[],'checks'=>$n,'errors'=>$errors,
 'frozen_T033'=>$errors===[]?'PASS':'BLOCKED','original_actual_native_227k_UButton_Serialize_CDB_loaded_count'=>2,
 'original_compiled_binary_field_displacement_hex'=>'0x8C','original_native_compiled_engine_reruns_by_verifier'=>0,
 'genuine_original_game_package_sha256'=>'396abd174a6ec86631de7d7c442f9187c7658af4baf2eaa6a58a93eff9327d36',
 'original_game_binary_published'=>false,'cases'=>$found,'G3_full_acceptance'=>false];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
