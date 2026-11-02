#!/usr/bin/env php
<?php
/** Original May17 1998 compiled native C98 UFunction undersized body:
 * a single original export-map SerialSize 40->41 edit; no payload changed.
 * Real original Core.dll appErrorf reported Got 40 Expected 41.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$e=dirname(__DIR__).'/compliance/evidence/o06/';
$good=$e.'20261028-G3-04-C98-original-1998-native-SAVE.u';
$bad=$e.'20261028-G3-04-C98-short-original-size-plus-one.u';
$cmd=$e.'20261028-G3-04-C98-short-original-native-numeric.cmd';
$log=$e.'20261028-G3-04-C98-short-original-native-numeric.log';
$hashes=[
 $good=>'4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4',
 $bad=>'6ee5e7a92411baccd00105d11c9bb9ef08cf89b3e3636416f0782303aa5e5bcc',
 $cmd=>'4f411eba369c815f0ef38b0021a84a8d067df0a507e69e54f4f7e7c3071356c3',
 $log=>'bfb39b1ef23a16780a0973da02aff8c60025883a306172d8dd9f4b14234f3ec5',
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304-Short/System/Core.dll'=>'9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92',
];
$checks=0;$failed=[];
$ok=static function(bool $passed,string $label)use(&$checks,&$failed):void{
 ++$checks;if(!$passed)$failed[]=$label;
};
foreach($hashes as $path=>$hash)$ok(is_file($path)&&hash_file('sha256',$path)===$hash,
 'genuine original fixture/runtime CDB SHA '.basename($path));
$a=(string)file_get_contents($good);$b=(string)file_get_contents($bad);
$ok(strlen($a)===1058&&strlen($b)===1058,'original native SAVE physical bytes unchanged');
$changes=[];for($i=0;$i<min(strlen($a),strlen($b));++$i){
 if($a[$i]!==$b[$i])$changes[]=$i;
}
$ok($changes===[1027]&&ord($a[1027])===40&&ord($b[1027])===41,
 'only original compact ExportMap Function.SerialSize 40->41 at byte1027');
$raw=(string)file_get_contents($log);
$ok(str_contains($raw,'G304_C98_SHORT_COMPILED_GOT=40 EXPECTED=41'),
 'actual original 1998 x86 compiled Core!appErrorf Got40 Expected41');
$ok(str_contains($raw,'G304C98Save.G304C98Acto')
 &&str_contains($raw,'r.G304State.G304CanJump'),
 'native original loader identifies exact UFunction export');
$ok(str_contains((string)file_get_contents($cmd),'bp Core!appErrorf')
 &&str_contains((string)file_get_contents($cmd),'G304_C98_SHORT_COMPILED_GOT'),
 'native error trapped at original x86 appErrorf with actual numeric arguments');
$p=new P($bad);$exp=$p->getExports();
$ok(($p->getHeader()['version']??null)===61
 &&($p->getHeader()['licenseeVersion']??null)===0
 &&count($exp)===9,'original 1998 native writer package retained v61/0 nine exports');
$ok(($exp[6]['serialOffset']??null)===770
 &&($exp[6]['serialSize']??null)===41
 &&($exp[6]['objectNameText']??null)==='G304CanJump',
 'source-defined export bounds physical start770 expected end811');
$n=N::inspect($bad,$p,6,T::inspect($bad,$p,6,S::EPIC_UE1),S::EPIC_UE1);
foreach(['status'=>'native_export_serial_size_mismatch',
 'source_serial_size_check_failed'=>true,
 'archive_cursor'=>810,'expected_export_end'=>811,
 'serial_size_overrun_bytes'=>0,'serial_size_shortfall_bytes'=>1,
 'extension_bytes'=>0,'source_function_link_status'=>'native_export_rejected',
 'legacy_num_parms'=>1,'legacy_parms_size'=>4] as $k=>$value)
 $ok(($n[$k]??null)===$value,'original C98 native cursor/reader '.$k);
foreach(['loaded_num_parms','loaded_parms_size','loaded_return_value_offset'] as $k)
 $ok(($n[$k]??null)===null,'rejected 1998 UFunction must not expose loaded '.$k);
$ok(($n['runtime_function_param_chain_linked']??null)===false,
 'rejected 1998 function cannot be linked as a runtime object');
$g=new P($good);
$valid=N::inspect($good,$g,6,T::inspect($good,$g,6,S::EPIC_UE1),S::EPIC_UE1);
$ok(($valid['status']??null)==='native_function_record_complete'
 &&($valid['loaded_num_parms']??null)===1
 &&($valid['loaded_parms_size']??null)===4,
 'unaltered original native SAVE function still loads correctly');
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'failed'=>$failed,
 'true_original_native_Got'=>40,'true_original_native_Expected'=>41,
 'archive_cursor'=>810,'declared_export_end'=>811,'native_original_probes_repeated'=>false,
 'G3_04_FULL'=>'OPEN'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
