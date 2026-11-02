<?php
/** Frozen T020: original compiled 1998 C98 UClass invalid SuperField,
 * original stock UT99 v432 and original 2026 Unreal 227k ClassWithin,
 * supported by T064 previously accepted malformed class defaults.
 * All original native programs were run separately and their evidence is SHA-pinned.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);$base=$repo.'/catalog/compliance/evidence/o06/';
$checks=0;$errors=[];
$check=static function(bool $ok,string $label)use(&$checks,&$errors){++$checks;if(!$ok)$errors[]=$label;};
$readNative=static function(string $file):string{
 $raw=(string)file_get_contents($file);
 return str_starts_with($raw,"\xFF\xFE")
   ?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
};
$files=[
 '20261031-G3-T020-v432-bad-within.u'=>'9b59f71e20c1eee1f393340a8649380b1a7106f2d562ab2c3724e0a7dde1d942',
 '20261031-G3-T020-v432-bad-within-native.log'=>'96fe72e6505ff01158b33c7b80cfd0389cd5fbac9ae23adfe5b1d2d692eb063f',
 '20261031-G3-T020-c227k-bad-within.u'=>'d855b9d63457a2ecd9fe8e8eb7fae60bfa7e079550423154596cac674d22b378',
 '20261031-G3-T020-c227k-bad-within-native.log'=>'ad6049e5a2db326a8d9a849dc877a7950d6f8ec6b261eb7c6b7a04bad81a2381',
 '20261031-G3-B08-T069-C98-original-super-invalid-import63.u'=>'a00d61d8379d612a3ea6f291a913cb50a2eab9d24e1d67919c3dc23f34ad2dc0',
 '20261031-G3-B08-T069-C98-original-super-invalid-export63.u'=>'97d302ae3cf2df73958978da22aa4715cd53b7f168acc5933e156bbb014c5080',
 '20261031-G3-B08-T069-C98-original-compiled-BadImport63-Editor.log'=>'06b488d27e7c8d6abf133f93118a7f3fa747776d59c9573eb085dbe9e1567bc7',
 '20261031-G3-B08-T069-C98-original-compiled-BadExport63-Editor.log'=>'6fd10b41f915ed598b4e17800ceea2e7a2dd59cbe0f347a6d178d9cd801eec67'
];
foreach($files as $name=>$sha)$check(is_file($base.$name)&&hash_file('sha256',$base.$name)===$sha,'original compiled evidence SHA '.$name);
$orig=[
 'C98'=>['C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98Defaults.u','766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf'],
 'v432'=>['C:/Temp/UnrealDB/O06/Native-UCC/System/G5DirectStringProbe.u','256b312328cb223e6bb02775ccbd339a63d5e2f5c55e6ddbeb96711a5b5a38e2'],
 'C227k'=>['C:/Temp/UnrealDB/O06/Native-227k/System/G3Scalar227.u','086961b8ecb58abf2e0887f1e07636c7fd865882fd683abe54542f1ac8470649']
];
foreach($orig as $name=>[$f,$sha])$check(is_file($f)&&hash_file('sha256',$f)===$sha,'original unmodified compiled class fixture restored '.$name);
foreach([
 ['C98','20261031-G3-B08-T069-C98-original-super-invalid-import63.u',275,0xbf,0,'epic-ue1','UE1 Bad import index 62/7 at archive cursor 276','Bad import index 62/7','LinkerLoad4 276 '],
 ['C98','20261031-G3-B08-T069-C98-original-super-invalid-export63.u',275,0x3f,0,'epic-ue1','UE1 Bad export index 62/6 at archive cursor 276','Bad export index 62/6','LinkerLoad4 276 '],
 ['v432','20261031-G3-T020-v432-bad-within.u',450,0xbf,2,'ut99-v432','UE1 Bad import index 62/7 at archive cursor 451','Bad import index 62/7','LinkerLoad1 451'],
 ['C227k','20261031-G3-T020-c227k-bad-within.u',509,0xbf,0,'unreal-v227k','UE1 Bad import index 62/9 at archive cursor 510','Bad import index 62/9','Got 510, Expected 562']
] as [$family,$file,$changedAt,$changedTo,$export,$profile,$expected,$nErr,$nCursor]){
 $origBytes=(string)file_get_contents($orig[$family][0]);
 $mutant=(string)file_get_contents($base.$file);
 $changes=[];
 if(strlen($mutant)===strlen($origBytes))
  for($i=0;$i<strlen($mutant);$i++)if($mutant[$i]!==$origBytes[$i])$changes[]=$i;
 $check(strlen($mutant)===strlen($origBytes)&&$changes===[$changedAt]&&ord($mutant[$changedAt])===$changedTo,
    'single byte controlled corruption of unmodified original compiled class source '.$file);
 $pkg=new P($base.$file);$header=$pkg->getHeader();
 $expectedLicensee=$family==='C98'?0:0;
 $expectedVer=$family==='C98'?61:69;
 $check($header['version']===$expectedVer&&$header['licensee']===$expectedLicensee,'source family and original version not incorrectly conflated '.$family);
 $error=null;$value=null;
 try{$value=C::inspect($base.$file,$pkg,$export,true,$profile);}catch(Throwable $e){$error=$e->getMessage();}
 $check($error===$expected&&$value===null,'reader exact original compiled error and FArchive cursor, no fabricated class default '.$file.': '.($error??'NONE'));
 $logName=$family==='C98'
   ?($changedTo===0xbf?'20261031-G3-B08-T069-C98-original-compiled-BadImport63-Editor.log':
                      '20261031-G3-B08-T069-C98-original-compiled-BadExport63-Editor.log')
   :($family==='v432'?'20261031-G3-T020-v432-bad-within-native.log':
                     '20261031-G3-T020-c227k-bad-within-native.log');
 $text=$readNative($base.$logName);
 $check(str_contains($text,$nErr)&&str_contains($text,$nCursor),'actual original source compiled Core DLL error and physical Tell '.$family);
 $check($family!=='v432'||str_contains($text,'native_error=1')
   ,'stock v432 invalid ClassWithin true null package native returned, no loaded object');
}
$previous=json_decode(ltrim((string)file_get_contents($base.'20261031-G3-T064-three-original-native-40pass.json'),"\xEF\xBB\xBF"),true);
$check(($previous['full_fixed_T064']??null)==='PASS'&&($previous['checks']??null)===40
  &&($previous['ok']??null)===true,'already accepted original all-family malformed class-default tag errors reused rather than rerun');
$out=['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,
 'full_fixed_T020'=>$errors===[]?'PASS':'BLOCKED',
 'original_compiled_native_superfield_C98'=>'invalid -63 import and +63 export physical Tell276',
 'original_compiled_native_classwithin_v432'=>'invalid -63 import physical Tell451',
 'original_compiled_native_classwithin_227k'=>'invalid -63 import physical Tell510',
 'original_native_engine_reruns_in_verifier'=>0,
 'actual_original_engine_227k_error_path_exited_after_log'=>true,
 'correct_runtime_absence'=>true,'global_G3_accepted'=>false];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
