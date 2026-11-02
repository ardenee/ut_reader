#!/usr/bin/env php
<?php
/** Distinct genuine original May17 1998 compiled UState::Serialize rejection.
 * The native writer SAVED v61 State SerialSize62; ONE export-map byte 62->63.
 * Original Core.dll appErrorf: "State ...: Serial size mismatch: Got 62, Expected 63".
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$ev=dirname(__DIR__).'/compliance/evidence/o06/';
$good=$ev.'20261028-G3-04-C98-original-1998-native-SAVE.u';
$bad=$ev.'20261028-G3-04-C98-state-short-one-byte-original-mutant.u';
$cmd=$ev.'20261028-G3-04-C98-state-short-original-numeric.cmd';
$log=$ev.'20261028-G3-04-C98-state-short-original-numeric.log';
$hashes=[
 $good=>'4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4',
 $bad=>'5604134c593de30fe2a1ac3ef7f1cc4df6395144443884bbb13cd87f8c7461d2',
 $cmd=>'77cff6a3ad79afdeee115ebcaf1344a64aaa6a01d1757653698b6a6bbce2bc4b',
 $log=>'3201dae0681eec12b0b33f0deea062803d9d875772cd90aea2eb171736194c3b',
 'C:/Temp/UnrealDB/O06/Native-C98-1998-G304-StateShort/System/Core.dll'=>'9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'
];
$count=0;$fail=[];
$assert=static function(bool $ok,string $name)use(&$count,&$fail):void{
 ++$count;if(!$ok)$fail[]=$name;
};
foreach($hashes as $file=>$sha)$assert(is_file($file)&&hash_file('sha256',$file)===$sha,
 'native original compiled evidence SHA '.basename($file));
$a=(string)file_get_contents($good);$b=(string)file_get_contents($bad);
$assert(strlen($a)===1058&&strlen($b)===1058,'same original 1058-byte native writer file');
$diff=[];for($i=0;$i<1058;++$i)if($a[$i]!==$b[$i])$diff[]=$i;
$assert($diff===[1013]&&ord($a[1013])===62&&ord($b[1013])===63,
 'only C98 original FObjectExport State.SerialSize 62->63 at offset1013');
$trace=(string)file_get_contents($log);
$assert(str_contains($trace,'G304_C98_STATE_SHORT_COMPILED_GOT=62 EXPECTED=63'),
 'actual original May17 compiled appErrorf Got62 Expected63');
$assert(str_contains($trace,'State G304C98Save.G304C98Actor.G')
 &&str_contains($trace,'304State'),'original native error identifies actual UState method');
$assert(str_contains((string)file_get_contents($cmd),'bp Core!appErrorf')
 &&str_contains((string)file_get_contents($cmd),'G304_C98_STATE_SHORT_COMPILED_GOT'),
 'true x86 original compiled Core.dll error numeric CDB capture');
$p=new P($bad);$orig=new P($good);
$assert(($p->getHeader()['version']??null)===61
 &&($p->getHeader()['licenseeVersion']??null)===0
 &&count($p->getExports())===9,'original 1998 native writer v61/0 9 exports retained');
$exp=$p->getExports()[5];
$assert(($exp['objectNameText']??null)==='G304State'
 &&($exp['serialOffset']??null)===708&&($exp['serialSize']??null)===63,
 'only state export5 increased declared bound to 708+63=771');
$read=static fn(P $pkg,string $path):array=>N::inspect($path,$pkg,5,
 T::inspect($path,$pkg,5,S::EPIC_UE1),S::EPIC_UE1);
$valid=$read($orig,$good);$invalid=$read($p,$bad);
foreach(['status'=>'native_state_record_complete',
 'probe_mask_hex'=>'0000000000000000',
 'ignore_mask_hex'=>'ffffffffffffffff',
 'state_flags'=>0,'label_table_offset'=>12] as $field=>$value)
 $assert(($valid[$field]??null)===$value,'valid original compiled C98 saved UState '.$field);
foreach(['status'=>'native_export_serial_size_mismatch',
 'source_serial_size_check_failed'=>true,
 'archive_cursor'=>770,'expected_export_end'=>771,
 'serial_size_overrun_bytes'=>0,'serial_size_shortfall_bytes'=>1,
 'extension_bytes'=>0,'source_state_load_status'=>'native_export_rejected'] as $field=>$value)
 $assert(($invalid[$field]??null)===$value,'native 1998 UState rejection '.$field);
foreach(['probe_mask_hex','ignore_mask_hex','state_flags','label_table_offset'] as $field){
 $assert(array_key_exists($field,$invalid)&&$invalid[$field]===null,
   'original rejected state cannot expose loaded '.$field);
 $assert(($invalid['archived_'.$field]??null)===$valid[$field],
   'physical archived '.$field.' retained separately from failed native LOAD');
}
$assert(($p->getExports()[6]['serialOffset']??null)===770,
 'next original UFunction starts exactly after actual 62 state bytes');
echo json_encode(['ok'=>$fail===[],'checks'=>$count,'failed'=>$fail,
 'native_original_loaded_state_success'=>false,'native_Got'=>62,'native_Expected'=>63,
 'original_physical_archive_end'=>770,'original_declared_export_end'=>771,
 'original_compiled_positive_suites_reexecuted'=>false,'g3_04_full'=>'OPEN'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
