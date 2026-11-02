<?php
/** T054: genuine original v432 0 and large Script.Num with real loaded native bytes.
 * Sealed C98 and C227i zero/compact-width results are read, not re-executed.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$r=dirname(__DIR__,2);$e=$r.'/catalog/compliance/evidence/o06/';
$engine='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.u';
$nativeLog=$e.'20261028-G3-T054-v432-Pawn-original-native-memory.log';
$source=$e.'20261028-G3-T051-v432-original-scriptall-source.json';
$core=$e.'20261028-G3-T003-v432-31-DEV-PASS.json';
$errs=[];$n=0;
$ck=static function(bool $good,string $why)use(&$errs,&$n):void{$n++;if(!$good)$errs[]=$why;};
foreach([
 [$engine,'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 [$nativeLog,'4de2849090d8f76137cca84414fdd32713d8e747f11795300d84cafad8c39178'],
 [$source,'e89d05be26fc13708eccefbf50682c41ce2431783de91f308a32ca11274e9a67'],
 [$core,'cb333b56adaec157d56d69d436c686fe390ba0d6f77c971789345c32c818ea76']
]as [$f,$sha]){$ck(is_file($f)&&hash_file('sha256',$f)===$sha,'original file SHA '.basename($f));}
$p=new P($engine);
$ck(($p->getHeader()['version']??null)===69&&$p->validatePackage()===[],'original Engine.u valid 69');
$exports=$p->getExports();
$ck(($exports[0]['objectNameText']??null)==='Pawn'&&(int)$exports[0]['classIndex']===0,'genuine Pawn UClass export0');
$rec=C::inspect($engine,$p,0,false,'ut99-v432');
$s=$rec['script_archive']??[];
$ck(($rec['script_size']??null)===314&&($s['script_memory_decoded']??null)===314
 &&($s['status']??null)==='script_archive_expressions_decoded','genuine Pawn Script.Num314');
$ck(($s['archive_bytes_consumed']??null)===246&&314-246===68,'script compact archive byte footprint246');
$ck(($s['native_load_relocation_count']??null)===28
 &&$s['source_memory_full_byte_reconstruction']===true,'28 real compact object refs widened');
$prior=json_decode((string)file_get_contents($source),true,512,JSON_THROW_ON_ERROR)['Pawn'];
$ck($prior['size']===314&&$prior['source_memory_hex']===$s['source_memory_bytes_hex']
 &&$prior['source_memory_known_mask_hex']===$s['source_memory_known_mask_hex'],'stored original native source-indexed reconstruction matches current parser');
$ck($prior['relocations']===$s['native_load_relocation_preview'],'all original exact pointer relocation locations and index identities');
$nativeFile=(string)file_get_contents($nativeLog);
preg_match('/SCRIPT_FULL_HEX size=314 bytes=([0-9a-f]{628})/i',$nativeFile,$m);
$native=isset($m[1])?hex2bin($m[1]):false;
$bytes=hex2bin((string)($s['source_memory_bytes_hex']??''));
$mask=hex2bin((string)($s['source_memory_known_mask_hex']??''));
$ok=is_string($native)&&strlen($native)===314
  &&is_string($bytes)&&strlen($bytes)===314&&is_string($mask)&&strlen($mask)===314;
$ck($ok,'actual compiled original loaded native Pawn 314 bytes present');
$known=0;$unknown=0;$match=true;
if($ok){for($i=0;$i<314;$i++){
 if(ord($mask[$i])===1){$known++;if($bytes[$i]!==$native[$i])$match=false;}
 else{$unknown++;}
}}
$ck($match&&$known===202&&$unknown===112,'every deterministic original native byte identical; 112 relocated native pointer bytes masked');
$ck(substr_count($nativeFile,'SCRIPT_FULL_RELOC slot=')===28,'original compiled 28 pointer relocation entries');
$sealed=json_decode((string)file_get_contents($core),true,512,JSON_THROW_ON_ERROR);
$zero=$sealed['native_objects']['Vector']??[];
$ck($sealed['ok']===true&&$sealed['checks']===31&&$sealed['failed']===[]
 &&$zero['source_package']==='Core.u'&&$zero['native_script_bytes']===0
 &&$zero['archived_script_bytes']===0,'stock original compiled v432 Core.u zero Script.Num + zero physical bytes');
$c98=json_decode((string)file_get_contents($e.'20261027-G3-03-C98-MAY17-100-UStruct-96-SCRIPTS-NATIVE-VALUES.json'),true,512,JSON_THROW_ON_ERROR)['native_records'];
$c227=json_decode((string)file_get_contents($e.'20261027-G3-03-C227I-237-223-ORIGINAL-NATIVE-VALUES.json'),true,512,JSON_THROW_ON_ERROR)['native_structs'];
$ck($c98['454']['original_native_script_size']===0&&$c227['661']['native_script_size']===0
 &&$c98['449']['original_native_script_size']===10&&$c227['915']['native_script_size']===11,
 'sealed C98/227i genuine compiled zero and compact-width cases preserved, no native replay');
$okay=$errs===[];
echo json_encode(['ok'=>$okay,'checks'=>$n,'errors'=>$errs,'zero_v432_Vector'=>0,
 'long_v432_Pawn_memory_bytes'=>314,'archive_bytes'=>246,'memory_minus_archive'=>68,
 'original_compiled_known_bytes_identical'=>$known,'native_pointer_bytes_masked'=>$unknown,
 'original_C98_zero_script'=>0,'original_C227i_zero_script'=>0,
 'original_native_engines_reexecuted'=>0,'old_positive_verifiers_rerun'=>0,
 'current_script_reader_sha'=>hash_file('sha256',$r.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($okay?0:1);
