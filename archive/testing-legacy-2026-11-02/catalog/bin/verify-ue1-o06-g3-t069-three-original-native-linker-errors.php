#!/usr/bin/env php
<?php
/** Frozen G3 T069 full three-source family linker FName / Import / Export wrong-index parity.
 * All original engine executions are already in immutable C98 1998 / stock v432 / 2026 C227k logs.
 * Source-only verification; no original native engine rerun and no new fixed IDs.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);$ev=$repo.'/catalog/compliance/evidence/o06/';
$checks=0;$bad=[];
$ck=static function(bool $ok,string $msg)use(&$checks,&$bad):void{++$checks;if(!$ok)$bad[]=$msg;};
$pins=[
'20261031-G3-B08-T069-C98-original-compiled-G3C98Defaults-761.u'=>'766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf',
'20261031-G3-B09-T069-C98-original-name-invalid63.u'=>'fa813b7bd2c3aab03e4e4b266ce7d52688e862f52b5882c1160483f720e95315',
'20261031-G3-B08-T069-C98-original-super-invalid-import63.u'=>'a00d61d8379d612a3ea6f291a913cb50a2eab9d24e1d67919c3dc23f34ad2dc0',
'20261031-G3-B08-T069-C98-original-super-invalid-export63.u'=>'97d302ae3cf2df73958978da22aa4715cd53b7f168acc5933e156bbb014c5080',
'20261031-G3-B08-T069-C98-original-compiled-BadName63-physical374-Editor.log'=>'3a2b28c626e252dbb53b2cbd79efb8042f2c07c8d160656558b090e7414140f0',
'20261031-G3-B08-T069-C98-original-compiled-BadImport63-Editor.log'=>'06b488d27e7c8d6abf133f93118a7f3fa747776d59c9573eb085dbe9e1567bc7',
'20261031-G3-B08-T069-C98-original-compiled-BadExport63-Editor.log'=>'6fd10b41f915ed598b4e17800ceea2e7a2dd59cbe0f347a6d178d9cd801eec67',
'20261031-G3-B09-T069-C98-original-native-BadImport-run.ps1'=>'31cbfb50e5dc56912b0bcac909da0f8a7251e543434ab31c1d795b1c1279fe8f',
'20261031-G3-B09-T069-C98-original-native-BadExport-run.ps1'=>'0b9c6033e918084f43a32cbba543dbb4936c0da0e2f8340aa44e5aa00a1cb514',
'20261031-G3-B09-T069-C98-original-native-BadName-run.ps1'=>'a92ed4710f99a297848fafb0d79c0466225604c4a289c0ef12a5686dc9a3a91f',
'20261031-G3-B09-T069-C98-original-three-current-parity.php'=>'811e9cef3181e43693e7d396596282ba5bd368b851dc8a8542d51e950cf0da65',
'20261028-G3-batch-T069-v432-227k-original-name-18pass-PARTIAL.json'=>'0b6fb3c55b196c6e88d4a159d3739c189386a5d6106246575bc2100539c1d9aa',
'20261028-G3-T069-original-227k-malformed-FName.stdout.log'=>'a86520cad668bd75f540ea15b683f2ff928208a06f9e1b42a2c24d13247d0129',
'20261028-G3-T069-original-v432-malformed-FName.stdout.log'=>'f2bd406eeb0496cd3626ccf81453620f1b6f44b8d618421d0408613c8e690eee',
'20261028-G3-batch-T009-native-error-regression-91pass.json'=>'',
];
foreach($pins as $name=>$sha){
 $f=$ev.$name;
 $ck(is_file($f)&&($sha===''||hash_file('sha256',$f)===$sha),
 'original native x86 C98, stock v432 and 227k source-only immutable SHA '.$name);
}
foreach([
 'CatalogUE1ClassicClassMetadataReader.php'=>'888e12fbc43dffd0d55cc0536f5e964acd017cb41ac68c16f744906435a0d4d4',
 'CatalogUE1ClassicTaggedPropertyReader.php'=>'e377ad408d51db35288cbf8d5617238453b8f82bbd8beda3aed79dda1447268c',
 'CatalogUE1ClassicStructNativeReader.php'=>'2b87b97b2c6c0d830205623cce6cf2594792952ef6ebf5314e88f0a2511f27b1',
] as $name=>$sha)$ck(hash_file('sha256',$repo.'/catalog/src/Infrastructure/Readers/'.$name)===$sha,
  'production source-selected current genuine original C98/C227/v432 method exact SHA '.$name);
foreach([
 '1998C98originalCore.dll'=>'C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/Core.dll',
 'v432stockCore.dll'=>'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll',
 'C227kCore.dll'=>'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll',
] as $label=>$path)$ck(is_file($path),'unmodified real compiled engine binary present '.$label);
$original=(string)file_get_contents($ev.'20261031-G3-B08-T069-C98-original-compiled-G3C98Defaults-761.u');
$ck(strlen($original)===761 && ord($original[275])===0x82 &&ord($original[373])===0,
 'authentic original 1998 C98 UCC class binary original package has import -2 and unmodified NAME_None');
foreach([
 ['name','20261031-G3-B09-T069-C98-original-name-invalid63.u',373,0x3f,63,16,374,'Bad name index 63/16',
    '20261031-G3-B08-T069-C98-original-compiled-BadName63-physical374-Editor.log','UE1 Bad name index 63/16 at archive cursor 374'],
 ['import','20261031-G3-B08-T069-C98-original-super-invalid-import63.u',275,0xbf,62,7,276,'Bad import index 62/7',
    '20261031-G3-B08-T069-C98-original-compiled-BadImport63-Editor.log','UE1 Bad import index 62/7 at archive cursor 276'],
 ['export','20261031-G3-B08-T069-C98-original-super-invalid-export63.u',275,0x3f,62,6,276,'Bad export index 62/6',
    '20261031-G3-B08-T069-C98-original-compiled-BadExport63-Editor.log','UE1 Bad export index 62/6 at archive cursor 276']
] as [$kind,$name,$index,$byte,$id,$count,$tell,$nativeNeedle,$logName,$readerNeedle]){
 $path=$ev.$name;$changed=(string)file_get_contents($path);
 $ck(strlen($changed)===761,'C98 original compiler single-byte physical file same size '.$kind);
 $d=[];
 for($i=0;$i<761;$i++)if($changed[$i]!==$original[$i])$d[]=$i;
 $ck($d===[$index] && ord($changed[$index])===$byte,
  'C98 same genuine unmodified compiler-built .u except precisely one native trigger at archive offset '.$index.' '.$kind);
 $p=new P($path);$h=$p->getHeader();
 $ck($h['version']===61&&$h['licensee']===0
  &&count($p->getNames())===16&&count($p->getImports())===7&&count($p->getExports())===6,
  'C98 original UCC v61 licensee0 genuine import/external linker directory exact '.$kind);
 $native=(string)file_get_contents($ev.$logName);
 $ck(str_contains($native,'Init: Compiled: May 17 1998') &&
    str_contains($native,'Core.dll') &&str_contains($native,'Class G3C98Defaults.G3C98Parent'),
   'genuine original independently 1998 May17 Core.dll executed this corrupted original UClass package '.$kind);
 $ck(str_contains($native,$nativeNeedle)
  &&str_contains($native,'LinkerLoad4 '.$tell.' ')
  &&str_contains($native,'ULinkerLoad<<')
  &&str_contains($native,'Exiting due to exception'),
  'ACTUAL original compiled C98 ULinkerLoad reported exact zero-based name/import/export index and physical FArchive Tell '.$kind);
 $error='';try{C::inspect($path,$p,0,true,'epic-ue1');}catch(Throwable $e){$error=$e->getMessage();}
 $ck($error===$readerNeedle,'production C98 source linker FName/IndexToObject exact original native message and archive physical Tell '.$kind.': '.$error);
}
$pristine='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98Defaults.u';
$ck(is_file($pristine)&&hash_file('sha256',$pristine)===$pins['20261031-G3-B08-T069-C98-original-compiled-G3C98Defaults-761.u'],
 'original compiled 1998 original UCC C98 source fixture restored exactly after all independent original x86 native errors');
$old=json_decode((string)file_get_contents($ev.'20261028-G3-batch-T069-v432-227k-original-name-18pass-PARTIAL.json'),true,512,JSON_THROW_ON_ERROR);
$ck($old['ok']===true&&$old['checks']===18&&$old['fixed_test']==='T069'
   &&$old['status']==='PARTIAL_ONLY'&&$old['original_compiled_profiles_verified']===2,
 'prior original 18/18 v432/227k true native FName partial reused as positive evidence only, now expanded by new C98 source/native');
$detail=[];
foreach($old['details'] as $x){
 $ck(($x['accepted_for_this_original_profile']??false)===true
     &&($x['native_loader_message']??null)===$x['reader_error'],
 'actual original stock v432 and C227k negative FName native and source linked current correct message/cursor '.$x['profile']);
 $detail[$x['profile']]=$x;
}
$ck(count($detail)===2
 &&$detail['v432']['physical_Tell']===43308
 &&$detail['227k']['physical_Tell']===67624
 &&$detail['v432']['archive_modified_byte']===43307
 &&$detail['227k']['archive_modified_byte']===67623,
 'native stock v432 -63/326 Tell43308 vs C227k -63/566 Tell67624 actual original real Core.u source artifact');
foreach([
 'v432'=>['20261028-G3-T069-original-v432-malformed-FName.stdout.log','Bad name index -63/326'],
 '227k'=>['20261028-G3-T069-original-227k-malformed-FName.stdout.log','Bad name index -63/566']
] as $label=>[$path,$marker]){
 $text=(string)file_get_contents($ev.$path);
 $ck(str_contains($text,$marker),'authentic original compiled '.$label.' executable actual negative name failure exact source index');
}
$prior=json_decode((string)file_get_contents($ev.'20261028-G3-batch-T009-native-error-regression-91pass.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($prior['ok']??null)===true&&($prior['checks']??null)===91&&($prior['errors']??null)===[],
 'previously sealed 91/91 three-original-compiled-engine T009 source negative IndexToObject native errors reused; NO accepted engine rerun');
foreach([
 '20261028-G3-T009-C98-Children-original-compiled-Tell.log'=>'Bad import index %i/%i',
 '20261028-G3-T009-v432-original-native-Children-Tell.log'=>'NATIVE_V432_CHILDREN_BADIMPORT_LOGF',
 '20261028-G3-T009-227k-original-native-Children-Tell.log'=>'T009_227K_BADIMPORT_ORIGINAL_LOGF_ENAME',
] as $name=>$marker){
 $s=(string)file_get_contents($ev.$name);
 $ck(str_contains($s,$marker),'original source C98/v432/227k ULinkerLoad bad object import original CDB executable evidence '.$name);
}
$result=['ok'=>$bad===[],'checks'=>$checks,'errors'=>$bad,
 'full_frozen_g3_ids'=>['T069'=>$bad===[]?'PASS':'BLOCKED'],
 'original_engine_profiles_proven'=>['1998-C98','stock-UT99-v432','2026-C227k'],
 'C98_original_native_linker_failures'=>[
 'Bad name index 63/16 at archive cursor 374',
 'Bad import index 62/7 at archive cursor 276',
 'Bad export index 62/6 at archive cursor 276'],
 'v432_original_native_bad_FName'=>'-63/326 Tell43308',
 '227k_original_native_bad_FName'=>'-63/566 Tell67624',
 'prior_original_three_engines_T009_invalid_import_accepted'=>91,
 'old_previously_accepted_native_engines_rerun'=>0,
 'original_C98_original_1998_class_file_restored'=>true,
 'full_G3_accepted'=>false];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
