<?php
/** Fixed G3 T051: original stock UT99 v432 Engine.u class script conditional SKIP
 * with nested expressions, original compiled script bytes and original Core.u
 * Script.Num proof. No native or previously sealed acceptance reexecution.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as ClassMeta;
$repo=dirname(__DIR__,2);
$engine='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u';
$nativeCore='C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll';
$nativeEngine='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll';
$nativeLog=$repo.'/catalog/compliance/evidence/o06/20261028-G3-T051-v432-original-scriptall-native.log';
$originalTranscript=$repo.'/catalog/compliance/evidence/o06/20261028-G3-T051-v432-original-scriptall-source.json';
$source='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp';
$sealedCoreResult=$repo.'/catalog/compliance/evidence/o06/20261028-G3-T003-v432-31-DEV-PASS.json';
$errors=[];$checks=0;
$check=static function(bool $ok,string $name) use (&$checks,&$errors):void {$checks++;if(!$ok)$errors[]=$name;};
$files=[
 $engine=>'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695',
 $nativeCore=>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
 $nativeEngine=>'f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac',
 $nativeLog=>'2f9a686c19f52be6e9866eac1fb521d42bc479f073510fbcc397a6d317843a11',
 $originalTranscript=>'e89d05be26fc13708eccefbf50682c41ce2431783de91f308a32ca11274e9a67',
 $source=>'6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380',
 $sealedCoreResult=>'cb333b56adaec157d56d69d436c686fe390ba0d6f77c971789345c32c818ea76',
];
foreach($files as $file=>$sha){
 $check(is_file($file)&&hash_file('sha256',$file)===$sha,'original native/source pinned SHA '.basename($file));
}
$savedCore=json_decode((string)file_get_contents($sealedCoreResult),true,512,JSON_THROW_ON_ERROR);
$check($savedCore['ok']===true&&$savedCore['checks']===31&&$savedCore['failed']===[]&&$savedCore['previous_native_verifiers_reexecuted']===false,'sealed genuine original compiled v432 Core.u + Engine.u T003 31/31');
$oldCore=$savedCore['native_objects']['NotEqual_FloatFloat'];
$check($oldCore['source_package']==='Core.u'&&$oldCore['original_memory_hex']==='29f8ce2b012940702b01'
  &&$oldCore['native_script_bytes']===10&&$oldCore['archived_script_bytes']===6
  &&$oldCore['object_pointer_relocations']===2,'genuine compiled v432 Core.u nonzero script, two object relocations, 10 native vs 6 archive');
$cpp=(string)file_get_contents($source);
$check(str_contains($cpp,'case EX_Skip:')&&str_contains($cpp,'case EX_NativeParm:'),'original Epic/UT99 source switch supports skip and nested operand branches');
$pkg=new Package($engine);
$check($pkg->validatePackage()===[]&&($pkg->getHeader()['version']??null)===69,'original v69 stock Engine.u header and import/export maps');
$inventory=[];
foreach($pkg->getExports() as $i=>$e){
 if((int)$e['classIndex']===0&&$e['objectNameText']==='Inventory')$inventory[]=$i;
}
$check($inventory===[4],'genuine Inventory UClass class export exactly 4');
$rec=ClassMeta::inspect($engine,$pkg,4,false,'ut99-v432');
$sc=$rec['script_archive']??[];
$check(($rec['script_size']??null)===33&&($sc['status']??null)==='script_archive_expressions_decoded',
 'current class UStruct script decoder fully decoded all 33 memory bytes');
$check(($sc['script_memory_decoded']??null)===33&&($sc['archive_bytes_consumed']??null)===25,
 'original saved archive is 25 physical bytes, 33 after in-memory pointer widening');
$check(($sc['native_load_relocation_count']??null)===3
 &&$sc['source_memory_full_byte_reconstruction']===true,'three typed original UObject relocations, full memory coverage');
$top=$sc['expression_preview'][0]??[];
$nested=$top['children'][0]??[];
$conversion=$nested['children'][0]??[];
$field=$conversion['children'][0]??[];
$skip=$top['children'][1]??[];
$check(($top['token']??null)===0x82&&($nested['token']??null)===0x9a
  &&($conversion['token']??null)===0x3a&&($field['token']??null)===0x01
  &&($field['value']??null)===22,'real v432 deeply nested native-call/conversion/source property expression');
$check(($skip['token']??null)===0x18&&($skip['value']??null)===7
  &&($skip['children'][0]['token']??null)===0x2d,
  'source-defined EX_Skip control-flow byte offset7 with nested expression');
$prior=json_decode((string)file_get_contents($originalTranscript),true,512,JSON_THROW_ON_ERROR);
$nativeSource=$prior['Inventory']??[];
$check(count($prior)===16&&($nativeSource['size']??null)===33
 &&count($nativeSource['relocations']??[])===3,'sealed 16 genuine native class records and Inventory original class source-script 33');
$check($sc['source_memory_bytes_hex']===$nativeSource['source_memory_hex']
 &&$sc['source_memory_known_mask_hex']===$nativeSource['source_memory_known_mask_hex'],
 'current real v432 export script bytes and reloc known-mask exactly historical native input');
foreach($nativeSource['relocations'] as $j=>$rel){
 $current=$sc['native_load_relocation_preview'][$j]??[];
 $check($current['kind']===$rel['kind']
  &&$current['memory_offset']===$rel['memory_offset']
  &&$current['archive_index']===$rel['archive_index']
  &&$current['target_name']===$rel['target_name'],'typed actual original source relocation '.$j);
}
$log=(string)file_get_contents($nativeLog);
$found=preg_match('/SCRIPT_ALL_BEGIN class=Inventory\s+.*?SCRIPT_FULL_HEX size=33 bytes=([0-9a-fA-F]{66})/s',$log,$hits)===1;
$check($found,'actual original compiled v432 Engine.Inventory native full memory dump exists');
$native=$found?hex2bin($hits[1]):'';
$current=hex2bin($sc['source_memory_bytes_hex']??'');
$mask=hex2bin($sc['source_memory_known_mask_hex']??'');
$known=0;$unknown=0;$matching=true;
if(is_string($native)&&is_string($current)&&is_string($mask)&&strlen($native)===33&&strlen($current)===33&&strlen($mask)===33){
 for($i=0;$i<33;$i++){
  if(ord($mask[$i])===1){$known++;if($current[$i]!==$native[$i])$matching=false;}
  else $unknown++;
 }
}else{$matching=false;}
$check($known===21&&$unknown===12&&$matching,'compiled original engine 21 exact known Script bytes; 12 native pointers masked, no ASLR fabrication');
$check(str_contains($log,'SCRIPT_ALL_END class=Inventory')&&str_contains($log,'SCRIPT_FULL_RELOC slot=4 kind=object original=22 resolved=ByteProperty Engine.Actor.Role'),
  'original compiled class reached script post-load and correct Role pointer referent');
$ok=$errors==[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'errors'=>$errors,
 'real_compiled_native_source'=>'genuine UT99 v432 Core.dll/Engine.dll Engine.u Inventory UClass',
 'original_stock_Core_u_script'=>$oldCore,
 'Engine_u_class'=>'Inventory','class_export_index'=>4,
 'memory_script_num'=>33,'archive_bytes_consumed'=>25,
 'current_decoded_expression_count'=>$sc['expression_count']??null,
 'nested_tokens'=>'82->9A->3A->01(source Role)',
 'control_flow'=>'EX_Skip 0x18 source offset7 nested EX_IntOne 0x2D',
 'exact_original_native_memory_known_bytes'=>$known,'relocated_native_pointer_bytes'=>$unknown,
 'original_compiled_engines_reexecuted'=>0,'old_positive_verifiers_replayed'=>0,
 'current_script_reader_sha256'=>hash_file('sha256',$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php')],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
