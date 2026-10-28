#!/usr/bin/env php
<?php
/** Original May17 1998 retail x86 compiled Core.dll genuinely rejects one
 * original FObjectManager-saved UFunction when FUNC_Net adds a RepOffset
 * beyond its declared SerialSize. Do NOT confuse an archive-value decode
 * with an accepted loaded UObject: source loader appErrorf rejects it.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;

$ev=dirname(__DIR__).'/compliance/evidence/o06/';
$saved=$ev.'20261028-G3-04-C98-original-1998-native-SAVE.u';
$bad=$ev.'20261028-G3-04-C98-badnet-original-one-byte-mutant.u';
$command=$ev.'20261028-G3-04-C98-badnet-original-numeric.cmd';
$trace=$ev.'20261028-G3-04-C98-badnet-original-numeric.log';
$engine='C:/Temp/UnrealDB/O06/Native-C98-1998-G304-BadNet/System/Core.dll';
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';
$hashes=[
 $saved=>'4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4',
 $bad=>'38777cdd1688e3eb0306678e9a471332d8117e166c57b9ab39a32eee584b2298',
 $command=>'33e299c3dea5268aadb079012fee31891f0db1af1408b61c3ac0132d5909e4e9',
 $trace=>'8fb96fd8f8ddac117ced29f419b46a2991c1b5977974fc2ce5512d0f414b9f0a',
 $engine=>'9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92',
 $source=>'ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291',
];
$checks=0;$fail=[];
$assert=static function(bool $ok,string $reason)use(&$checks,&$fail):void{
 ++$checks;if(!$ok)$fail[]=$reason;
};
foreach($hashes as $file=>$hash)
 $assert(is_file($file)&&hash_file('sha256',$file)===$hash,
   'genuine original binary/writer/one-byte mutant/source/CDB evidence SHA '.basename($file));
$good=(string)file_get_contents($saved);$mut=(string)file_get_contents($bad);
$assert(strlen($good)===1058&&strlen($mut)===1058,'same 1058-byte original retail FObjectManager package');
$diff=[];for($i=0;$i<1058;++$i)if($good[$i]!==$mut[$i])$diff[]=$i;
$assert($diff===[806]&&ord($good[806])===2&&ord($mut[806])===66,
 'ONE 1998 original saved FunctionFlags bit FUNC_Net mutation at physical806');
$cpp=(string)file_get_contents($source);
$assert(str_contains($cpp,'void UFunction::Serialize( FArchive& Ar )')
 &&str_contains($cpp,'if( FunctionFlags & FUNC_Net )')
 &&str_contains($cpp,'Ar << RepOffset;'),
 'independent source-defined May19 original FUNC_Net optional extra 2-byte archive read');
$cmd=(string)file_get_contents($command);
$log=(string)file_get_contents($trace);
$assert(str_contains($cmd,'bp Core!appErrorf')&&str_contains($cmd,'G304_C98_COMPILED_GOT'),
 'actual x86 debugger traps original native appErrorf call and reads numeric arguments');
$assert(str_contains($log,'Serial size mismatch: Got %i')
 &&str_contains($log,'G304_C98_COMPILED_GOT=42 EXPECTED=40'),
 'ORIGINAL 1998 compiled native rejection Got42 bytes Expected40');
$assert(str_contains($log,'G304C98Save.G304C98Acto')
 &&str_contains($log,'r.G304State.G304CanJump'),
 'original native error refers to specific genuine saved malformed UFunction export');
$g=new P($saved);$b=new P($bad);
$assert(($b->getHeader()['version']??null)===61
 &&($b->getHeader()['licenseeVersion']??null)===0
 &&count($b->getExports())===9,'actual May17 1998 genuine package version61/licensee0 9 exports');
foreach([$g,$b] as $pkg){
 $exp=$pkg->getExports()[6];
 $assert(($exp['objectNameText']??null)==='G304CanJump'
   &&($exp['serialOffset']??null)===770
   &&($exp['serialSize']??null)===40,
   'same compiler-emitted C98 function export boundary 770+40=810');
}
$read=static fn(P $pkg,string $file,int $index):array=>N::inspect(
 $file,$pkg,$index,T::inspect($file,$pkg,$index,S::EPIC_UE1),S::EPIC_UE1);
$goodFunction=$read($g,$saved,6);$badFunction=$read($b,$bad,6);
$assert(($goodFunction['status']??null)==='native_function_record_complete'
 &&($goodFunction['function_flags']??null)===2,
 'original C98 positive SAVE function reads cleanly');
foreach(['loaded_num_parms'=>1,'loaded_parms_size'=>4,'loaded_return_value_offset'=>0] as $field=>$value)
 $assert(($goodFunction[$field]??null)===$value,
   'original valid C98 function exposes actually loadable '.$field);
foreach(['status'=>'native_export_serial_size_mismatch',
 'source_serial_size_check_failed'=>true,
 'archive_cursor'=>812,'expected_export_end'=>810,
 'serial_size_overrun_bytes'=>2,'extension_bytes'=>0,
 'function_flags'=>66,
 'source_function_link_status'=>'native_export_rejected'] as $field=>$value)
 $assert(($badFunction[$field]??null)===$value,
  'original native rejected UFunction matches reader '.$field);
foreach(['loaded_num_parms','loaded_parms_size','loaded_return_value_offset'] as $field)
 $assert(($badFunction[$field]??null)===null,
   'original compiler rejection must never fabricate loaded '.$field);
$assert(($badFunction['runtime_function_param_chain_linked']??null)===false,
 'native original malformed UFunction cannot be claimed linked');
$assert(($badFunction['legacy_num_parms']??null)===1
 &&($badFunction['legacy_parms_size']??null)===4,
 'raw parsed archived old ABI retained distinctly from rejected loaded state');
$state=$read($b,$bad,5);
$assert(($state['status']??null)==='native_state_record_complete'
 &&($state['state_flags']??null)===0,
 'unmodified original SAVE UState reader unaffected by UFunction rejection');
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failures'=>$fail,
 'original_compiled_1998_native_Got'=>42,'original_compiled_Expected'=>40,
 'physical_cursor'=>812,'export_end'=>810,'overrun'=>2,
 'production_fix'=>'reject_loaded_fields_on_function_serial_overrun',
 'original_1998_positive_suite_reexecuted'=>false,
 'g3_04_FULL'=>'OPEN'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
