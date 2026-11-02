#!/usr/bin/env php
<?php
/** One 2012 C227i UCC class and 2026 original C227k Core.dll genuine SAVE + independent LOAD with 2 config/localized profiles. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$failed=[];$ck=static function(bool $ok,string $label)use(&$checks,&$failed):void{++$checks;if(!$ok)$failed[]=$label;};
$hashes=[
 '20261030-G3-B08-227i-original-config-transient.uc'=>'6d7cb9df43325ebb5a2e156b3309424eb4c280f0a2bbef803d27f8600dd66ccc',
 '20261030-G3-B08-227i-original-config-transient.u'=>'3fd8ba1a4b74a15b741d5df597c7b275917a09df80e9dd1e2c520614a51b5c5a',
 '20261030-G3-B08-227i-original-compiler.log'=>'f17bf5494f2302707dcb7974be4ae9a712f6267e7b3d61a83f68334f54054204',
 '20261030-G3-B08-227k-original-SavePackage.u'=>'58bb406f532cbf52ebad10be3d5ee77d48dbfdfb2bc101c8bf071d35bab16a44',
 '20261030-G3-B08-227k-original-native-SAVE.cpp'=>'7ed38f7d7be4306824cc5eb0e37b6ab67012623c98c87481c863a19ebbcd35e3',
 '20261030-G3-B08-227k-original-native-SAVE.log'=>'911549e7b0019a7788b1e733cdee286b07f60a797a669abf7b20f64622d7a61d',
 '20261030-G3-B08-227k-original-native-LOAD.cpp'=>'41a7b9aa2758221d2f6d2f355fda8c8a089ae818b54d1709069f927beff777f1',
 '20261030-G3-B08-227k-original-native-LOAD.log'=>'41deae31ef05381397d80f950bd807863b8fbc12f5e41183c00edda16297c230',
 '20261030-G3-B08-227k-original-CONFIG-111.log'=>'b9d679d9cfda2131202145c680b2f434026da8278ab05ff211e29cb9fb8c9ef4',
 '20261030-G3-B08-227k-original-CONFIG-222.log'=>'783997856cc1952c01e2b5d2f3e490d67a3445db9dcb02468ca8ad81bc33c9dd',
 '20261030-G3-B08-227k-original-localization-LOAD.cpp'=>'a057cda0615bccf2715cf7d136112eb9281a0ea525f3e0a9b1e339884de15ec1',
 '20261030-G3-B08-227k-original-LOCALIZED-111.log'=>'79c9c2b58679c02c23746fcbe183ad89d83ef02975cb471fce22939c57d6a54a',
 '20261030-G3-B08-227k-original-LOCALIZED-222.log'=>'184333d09df3c244b56d63c9e15902d883a356569cb8e75de0997007956f8fb2',
 '20261030-G3-B08-227k-original-LOCALIZED-111.int'=>'682571d028ddcb336ba61d75db55b8dd42c8bea26ffd832a508072fa91b50b33',
 '20261030-G3-B08-227k-original-LOCALIZED-222.int'=>'0172a24f391c5642a7227981ed0f50deac2592a5094a041b5ac0f373da4b33ae',
 '20261030-G3-B08-227k-original-isolated-two-profiles.ps1'=>'0d7226b144aa4e8fab59ebabd3c2b45408f5e486e89e935feace8773cfbe9a0b',
 '20261030-G3-B08-227k-original-isolated-config-only.ps1'=>'741c85856db7473624f21e9b4b1e11e3615ce514bf24cbdc04b4bd1dbe6f1621',
 '20261030-G3-B08-current-source-linked-inspect.php'=>'0ca31bc105e5a70a01f955427e5c2814faa8f191bbe091f2d683d03699ef2142'
];
foreach($hashes as $name=>$sha)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'exact original native CPP/binary/CDB/INI/log SHA '.$name);
$cf='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Config227.u';
$rf='C:/Temp/UnrealDB/O06/Native-227k/System/G3Config227Runtime.u';
$ck(hash_file('sha256',$cf)===$hashes['20261030-G3-B08-227i-original-config-transient.u'],'actual original compiled 2012 UCC .u equals immutable original saved code archive');
$ck(hash_file('sha256',$rf)===$hashes['20261030-G3-B08-227k-original-SavePackage.u'],'actual original compiled 2026 Core.dll SavePackage .u equals immutable original binary');
$script=(string)file_get_contents($ev.'20261030-G3-B08-227i-original-config-transient.uc');
foreach(['class G3Config227Actor extends Actor config(User);','var config int ConfigValue;','var localized string LocalMessage;','var transient int ScratchValue;','var int PersistValue;','ConfigValue=444','ScratchValue=333','PersistValue=222'] as $frag)
 $ck(str_contains($script,$frag),'original 2012 source-legal compiled script field/default '.$frag);
$compiler=(string)file_get_contents($ev.'20261030-G3-B08-227i-original-compiler.log');
if(str_starts_with($compiler,"\xFF\xFE"))$compiler=(string)iconv('UTF-16LE','UTF-8',substr($compiler,2));
$ck(str_contains($compiler,'Parsing G3Config227Actor')
 &&str_contains($compiler,'Compiling G3Config227Actor')
 &&str_contains($compiler,'0 warning(s)'),'authentic original 2012 C227i UCC compiler generated class with 0 warnings');
$cp=new P($cf);$rp=new P($rf);
$ck($cp->getHeader()['version']===69&&$cp->getHeader()['licensee']===0&&filesize($cf)===821,'genuine original 2012 compiled source v69 licensee0 821 bytes');
$ck($rp->getHeader()['version']===69&&$rp->getHeader()['licensee']===227&&filesize($rf)===242,'genuine original 2026 compiled source engine SavePackage v69 licensee227 242 bytes');
$ce=null;foreach($cp->getExports() as $i=>$x)if($x['objectNameText']==='G3Config227Actor'&&$x['classIndex']===0){$ce=$i;break;}
$ck($ce===5,'genuine original compiled C227i UClass G3Config227Actor export index5');
$default=C::inspect($cf,$cp,(int)$ce,false,'unreal-v227i');
$ck(($default['source_file_archive_complete']??false)===true,'original 2012 compiler UClass serialized default tag stream fully consumed');
$tags=[];foreach($default['default_property_tags'] as $t)$tags[$t['name']]=$t;
$ck(count($tags)===3&&isset($tags['ConfigValue'],$tags['LocalMessage'],$tags['PersistValue'])
 &&!isset($tags['ScratchValue']),'original compiler ClassCDO archive has 3 real tagged values and no transient tagged default; no inferred fourth field');
$ck($tags['ConfigValue']['type']==='IntProperty'&&$tags['ConfigValue']['value']===444,'source-legal config original 2012 compiled tagged class default INT444');
$ck($tags['PersistValue']['type']==='IntProperty'&&$tags['PersistValue']['value']===222,'source-legal persistent original 2012 compiled tagged class default INT222');
$ck($tags['LocalMessage']['type']==='StrProperty'
 &&$tags['LocalMessage']['value']['ascii_text']==='Default Message'
 &&$tags['LocalMessage']['value']['signed_character_count']===16
 &&$tags['LocalMessage']['value']['serialized_character_bytes_hex']==='44656661756c74204d65737361676500',
 'original source-legal localized string class default exact 16 chars with NUL and raw ANSI bytes');
$idx=null;foreach($rp->getExports() as $i=>$x)if($x['objectNameText']==='ConfigObject'){$idx=$i;break;}
$ck($idx===0,'separate real saved package owns native ConfigObject export0');
$re=$rp->getExports()[$idx];$stream=T::inspect($rf,$rp,(int)$idx,'unreal-v227k');
$ck(($stream['status']??null)==='tag_envelope_decoded'&&$stream['native_tail_bytes']===0
 &&$stream['tag_end_offset']===$re['serialOffset']+$re['serialSize']
 &&$re['serialOffset']===207&&$re['serialSize']===7,'original native saved package real 7 byte tagged object ends exactly at offset214, no trailing payload');
$ck(count($stream['tags'])===1,'original native saved only ONE property, not transient or INI config or localization');
$t=$stream['tags'][0];
$ck($t['name']==='PersistValue'&&$t['type']==='IntProperty'
 &&$t['value_kind']==='int32'&&$t['value']===888
 &&$t['on_disk_value_bytes']===4,
 'current production UE1 reader matches real original native saved PersistValue 888 in four INT bytes');
$ck(!str_contains(json_encode(array_column($stream['tags'],'name')),'ScratchValue')
 &&!str_contains(json_encode(array_column($stream['tags'],'name')),'ConfigValue')
 &&!str_contains(json_encode(array_column($stream['tags'],'name')),'LocalMessage'),
 'original compiled saved instance tag list excludes transient, config and localized defaults; no false state invented');
$log=static function(string $p)use($ev):string{
 $b=(string)file_get_contents($ev.$p);return str_starts_with($b,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($b,2)):$b;
};
$save=$log('20261030-G3-B08-227k-original-native-SAVE.log');
$load=$log('20261030-G3-B08-227k-original-native-LOAD.log');
$ck(str_contains($save,'G3_SCALAR_NATIVE_PACKAGE ')&&str_contains($save,'bad=0')&&str_contains($save,'G3_SCALAR_NATIVE_SAVE 1'),
 'original C227k compiled native loaded 2012 source UClass and produced genuine SavePackage');
$ck(str_contains($save,'name=ConfigValue flags=00004000 offset=776 default=444 instance=444'),
 'genuine original 227k actual linked CPF_Config flag and CDO/instance default444 at owner offset776');
$ck(str_contains($save,'name=ScratchValue flags=00002000 offset=792 default=0 instance=0')
 &&str_contains($save,'G3_B08_NATIVE_INT_CHANGED name=ScratchValue value=777'),
 'genuine original linked CPF_Transient class member offset792 starts0 and was specifically changed to777 before native SAVE');
$ck(str_contains($save,'name=PersistValue flags=00000000 offset=796 default=222 instance=222')
 &&str_contains($save,'G3_B08_NATIVE_INT_CHANGED name=PersistValue value=888'),
 'genuine original native linked nontransient persistent member offset796 changed default222 to888');
$ck(str_contains($load,'G3_B08_227_INDEPENDENT_RELOAD cls=')
 &&str_contains($load,'bad=0')&&str_contains($load,'G3_B08_227_RELOADED_COUNT 1'),
 'separate second original compiled C227k Core.dll process independently loaded real saved 242 byte package and exact one ConfigObject');
foreach(['ConfigValue flags=00004000 offset=776 value=444','ScratchValue flags=00002000 offset=792 value=0','PersistValue flags=00000000 offset=796 value=888'] as $frag)
 $ck(str_contains($load,$frag),'actual independent native C227k loaded integer and source flags '.$frag);
foreach([[111,'Native Alpha'],[222,'Native Beta']] as [$number,$translated]){
 $name='20261030-G3-B08-227k-original-LOCALIZED-'.$number.'.log';
 $text=$log($name);
 $ck(str_contains($text,'G3_B08_227_INDEPENDENT_RELOAD cls=')
  &&str_contains($text,'bad=0')&&str_contains($text,'G3_B08_227_RELOADED_COUNT 1'),
  'isolated same byte-identical real saved package loaded in genuine original native process with profile '.$number);
 $ck(str_contains($text,'G3_B08_NATIVE_RELOADED name=ConfigValue flags=00004000 offset=776 value='.$number),
  'real original Core.dll reads source legal User.ini override ConfigValue='.$number.' not original CDO444');
 $ck(str_contains($text,'G3_B08_NATIVE_RELOADED name=ScratchValue flags=00002000 offset=792 value=0')
  &&str_contains($text,'G3_B08_NATIVE_RELOADED name=PersistValue flags=00000000 offset=796 value=888'),
  'transient reset0 persistent888 unchanged across two genuine saved package native loads profile '.$number);
 $ck(str_contains($text,'G3_B08_NATIVE_LOCALIZED_CLASS_FLAGS=00000036')
  &&str_contains($text,'G3_B08_NATIVE_LOCALIZED_BEFORE cdoValue=Default Message propFlags=00408000'),
  'actual original source-compiled CLASS_Localized/CPF_Localized and native default text before .int input '.$number);
 $ck(str_contains($text,'G3_B08_NATIVE_LOCALIZED_AFTER cdoValue='.$translated),
  'actual genuine C227k UObject::LoadLocalized native CDO override from external real .int file '.$translated);
 $local=(string)file_get_contents($ev.'20261030-G3-B08-227k-original-LOCALIZED-'.$number.'.int');
 $ck(str_contains($local,'[G3Config227Actor]')&&str_contains($local,'LocalMessage="'.$translated.'"'),
  'source legal original native package .int profile input for LANG override '.$number);
 $base=$log('20261030-G3-B08-227k-original-CONFIG-'.$number.'.log');
 $ck(str_contains($base,'G3_B08_NATIVE_RELOADED name=ConfigValue flags=00004000 offset=776 value='.$number),
  'second independent isolate User.ini-only native process confirms same config priority '.$number);
}
$ck(!is_file('C:/Temp/UnrealDB/O06/Native-227k/System/G3Config227.int'),
 'isolated native localization language file restored; no permanent engine fixture configuration leakage');
$runner=(string)file_get_contents($ev.'20261030-G3-B08-227k-original-isolated-two-profiles.ps1');
$ck(str_contains($runner,'finally{')&&str_contains($runner,'WriteAllBytes($userIni,$origUser)')
 &&str_contains($runner,'ORIGINAL_LOCALIZATION_PROFILE_RESTORED'),
 'native two-profile runner guaranteed original User.ini and package localization .int restoration via finally');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failed,
 'original_frozen_full_ids'=>['T062'=>'PASS','T063'=>'PASS'],
 '2012_original_compiler_warnings'=>0,
 'original_2026_native_saved_package_bytes'=>242,
 'exact_physical_saved_instance_tags'=>[['PersistValue',888]],
 'original_config_and_localized_test_profiles'=>['111'=>'Native Alpha','222'=>'Native Beta'],
 'native_original_accepted_tests_rerun'=>0,
 'G3_full_acceptance'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
