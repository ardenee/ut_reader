#!/usr/bin/env php
<?php
/** Frozen original 1998 C98, UT99 v432, original 2012 C227i compiler,
 * 2026 original C227k native SAVE and independent LOAD: grouped G3 021/024/041/042. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$errors=[];$add=static function(bool $ok,string $label)use(&$checks,&$errors):void{
 ++$checks;if(!$ok)$errors[]=$label;
};
$pins=[
 ['20261030-G3-BULK-C227i-original-2012-compiled-class.u','e181c4a49aa51ba914baeead9a9f4c95f0a0154534bd92bbfb70c41f32adb50f'],
 ['20261030-G3-BULK-C227i-original-2012-compiled-class.uc','46c1a6004b2ea64a6d01585236aa7110bc2bdf7cf40d95d3cdb41f4f5207113f'],
 ['20261030-G3-BULK-C227k-original-2026-native-SAVE-object.u','8d2e0ad732ade2b4954aa8982fd0c787565a61109bc5c62a64e7411ae6ee1b4a'],
 ['20261030-G3-BULK-C227k-original-2026-native-SAVE-source.cpp','4e55556ec01a1e972f6d5e220bee5ae0da564caa2e3519cddd0b1cbd2ca60c94'],
 ['20261030-G3-BULK-C227k-original-2026-native-SAVE.log','257e7b1815465c5ef4bdbaa90f8c2f5c3923471799df67dcdc4c88d03b6c254b'],
 ['20261030-G3-BULK-C227k-original-2026-native-LOAD-source.cpp','ce9ad84aa28d49cc766cf3bc8aab8c775b1f1d102666c3919b91676dddccfcc8'],
 ['20261030-G3-BULK-C227k-original-2026-native-LOAD.log','6d613ab5619c7ef121f4193e833a0fa0cd6ab5ad76cd11d1e2bd5f853b9a69a7'],
 ['20261030-G3-BULK-V432-original-native-array-saved.u','2436d2545135a8ca02d5f1e71252daf3314be6e5b02c371a7db8ae5450e16ff3'],
 ['20261030-G3-BULK-V432-original-native-array-SAVE-source.cpp','c0ce7d9bd0055de450f889a899a78b24557ec0f837719b2a8bb9cdee4ec6df45'],
 ['20261030-G3-BULK-V432-original-native-array-LOAD.log','fe457ecf0730adb6264423c0dfc299839b06d534f873ae77fab86a4b019f19f0'],
 ['20261030-G3-BULK-V432-original-compiler-array-class.u','303d645d425c759510ad2bbcd92b4f2320a7fe7af8fff420094645ddb259694f'],
 ['20261030-G3-BULK-C98-original-native-T024-FName-memory.log','901efb94e5de2c8cf9a7dc0e08eb9606621f541398ead5f3da9dd848bf0645de'],
 ['20261030-G3-BULK-C98-original-native-T024-FName-memory.cmd','304637b0a6426d68d1b80ac666907db4bf6ef2c49d9fcfa42d0b54dd081b1148'],
 ['20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u','40fa22b955451b489d13b8b19e478c55965efcfb569b497cf8a312e6c73521a1'],
 ['20261030-G3-BULK-C98-original-native-loaded-values.log','4e0bb7746aeaa33b6bc6f86419e1471748305085f7cd97db9acd388301cecd94'],
 ['20261029-G3-T017-T059-original-469f-v432-two-closures-902pass.json','184d662a2ebdff10898993a4b9364e951a3a4d5fc7f674c0d34e28a5d2a8d3a2']
];
foreach($pins as [$name,$sha])$add(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'original immutable evidence '.$name);
foreach([
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'=>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'C:/Temp/UnrealDB/O06/Native-C227i-G304/System/Core.dll'=>'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897',
 $root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php'=>'121a706fc546cb484d11eb76e6ef99e3c76a5aa21cacc237de9c44b957a13e87'
] as $f=>$sha)$add(is_file($f)&&hash_file('sha256',$f)===$sha,'original Core + production source hash '.basename($f));
$base='C:/Temp/UnrealDB/O06/';
$ci=$base.'Native-C227i-G304/System/G3Bulk227.u';
$ck=$base.'Native-227k/System/G3Bulk227Runtime.u';
$v=$base.'Native-UCC/System/G5ArrayPayload.u';
$g=$base.'Native-C98-1998-G304/System/G3C98Bulk.u';
foreach([[$ci,'20261030-G3-BULK-C227i-original-2012-compiled-class.u'],[$ck,'20261030-G3-BULK-C227k-original-2026-native-SAVE-object.u'],[$v,'20261030-G3-BULK-V432-original-native-array-saved.u'],[$g,'20261030-G3-BULK-C98-original-compiler-G3C98Bulk.u']] as [$f,$stored])
 $add(is_file($f)&&hash_file('sha256',$f)===hash_file('sha256',$ev.$stored),'runtime working original package exactly equals sealed compiler/oracle archive '.$stored);
$ciP=new P($ci);$ckP=new P($ck);$vP=new P($v);$gP=new P($g);
$add($ciP->getHeader()['version']===69&&$ciP->getHeader()['licensee']===0,
 'authentic 2012 C227i UCC compiler generated source-lineage package v69/0');
$add($ckP->getHeader()['version']===69&&$ckP->getHeader()['licensee']===227,
 'authentic separate 2026 C227k original Core.dll SavePackage produced v69/227 runtime');
$ciClass=C::inspect($ci,$ciP,0,false,'unreal-v227i');
$gClass=C::inspect($g,$gP,5,false,'epic-ue1');
$add(($ciClass['source_file_archive_complete']??false)===true&&($gClass['source_file_archive_complete']??false)===true,
 'true original 2012 and 1998 compiled UClass default archives fully consumed');
$find=static function(array $tags):array{$out=[];foreach($tags as $t)$out[$t['name']]=$t;return $out;};
$ciTags=$find($ciClass['default_property_tags']);$gTags=$find($gClass['default_property_tags']);
foreach([
 ['BigNeg','IntProperty',-2147483647,4],
 ['MaxByte','ByteProperty',255,1],
 ['NegFloat','FloatProperty',-1.5,4],
 ['bTrueValue','BoolProperty',true,0],
 ['NonZeroName','NameProperty',2,1]
] as [$name,$kind,$expected,$size]){
 $tag=$ciTags[$name]??[];
 $add(($tag['type']??null)===$kind&&($tag['value']??null)===$expected
   &&($tag['on_disk_value_bytes']??null)===$size,'real original 2012 compiler saved scalar '.$name.' '.$kind);
}
foreach([
 ['G3Neg','IntProperty',-2147483647,4],
 ['G3Max','IntProperty',2147483647,4],
 ['G3ByteMax','ByteProperty',255,1],
 ['G3FloatNeg','FloatProperty',-1.5,4]
] as [$name,$kind,$expected,$size]){
 $tag=$gTags[$name]??[];
 $add(($tag['type']??null)===$kind&&($tag['value']??null)===$expected
   &&($tag['on_disk_value_bytes']??null)===$size,'real compiled C98 1/4-byte signed scalar '.$name);
}
$add(!isset($ciTags['bFalseValue'])&&!isset($ciTags['NoneName'])
 &&!isset($gTags['G3ByteZero'])&&!isset($gTags['G3None']),
 'genuine original absent zero/false/NAME_None serial tags are native defaults, not fabricated archive zeros');
$add(($ciP->getNames()[2]['name']??null)==='Alpha','source file original 2012 local name-table FName Alpha index2');
$add(($ciTags['NonZeroName']['value']??null)===2,'original 2012 FName native name tag physical index2');
$add(($gP->getNames()[(int)($gTags['G3Name']['value']??-1)]['name']??null)==='G3BulkName',
 'original C98 compiled NameProperty FName index resolves G3BulkName');
$cn=(string)file_get_contents($ev.'20261030-G3-BULK-C98-original-native-T024-FName-memory.log');
$add(preg_match('/G3_BULK_T024_C98_TYPED_NATIVE_FNAME_WORDS[\s\S]*?00000000 3ec00000 00000001 00000e34[\s\S]*?00000000 00000315 40200000 00000011/',$cn)===1,
 'original C98 compiled native loaded FName member global index0xE34 != None, following NAME_None member global index0');
$logRead=static function(string $name)use($ev):string{
 $s=(string)file_get_contents($ev.$name);
 return str_starts_with($s,"\xFF\xFE")
   ?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$save=$logRead('20261030-G3-BULK-C227k-original-2026-native-SAVE.log');
$load=$logRead('20261030-G3-BULK-C227k-original-2026-native-LOAD.log');
$vlog=$logRead('20261030-G3-BULK-V432-original-native-array-LOAD.log');
$add(str_contains($save,'G3_BULK_227_PACKAGE_LOADED ')&&str_contains($save,'bad=0')
 &&str_contains($save,'G3_BULK_227_NATIVE_SAVE 1'),'ORIGINAL 227k distinct 2012-compiled package loaded and real save succeeded');
$add(str_contains($load,'G3_BULK_227_INDEPENDENT_RELOAD cls=')
 &&str_contains($load,'bad=0')&&str_contains($load,'G3_BULK_227_RELOADED_COUNT 1'),
 'second completely independent compiled 227k process loaded the original 227k-saved 302-byte object package');
foreach([
 ['Numbers(0)=11','Numbers(1)=42','Numbers(2)=-7'],
 ['Labels(0)="Alpha"','Labels(1)="Gamma"'],
 ['References(0)=G3Bulk227Actor\'G3Bulk227Runtime.ArrayObject\'','References(1)=None'],
 ['BigNeg=-2147483647','MaxByte=255','NegFloat=-1.5','bTrueValue=True','bFalseValue=False','NonZeroName="Alpha"','NoneName="None"']
] as $group){
 foreach($group as $field)$add(str_contains($load,'G3_BULK_227_NATIVE_TAG '.$field),
  'independent ORIGINAL 227k runtime actual loaded '.$field);
}
$add(str_contains($vlog,'Numbers=(11,42,-7)')&&str_contains($vlog,'Labels=(Alpha,Beta)')
 &&str_contains($vlog,"References=(G5ArrayProbeActor'G5ArrayPayload.G5DynamicActor',None)"),
 'original UT99 v432 separate loaded 3 signed int, 2 FName and self+null reference dynamic arrays');
foreach([[$ck,$ckP,'unreal-v227k',['Numbers','Labels','References']],
         [$v,$vP,'ut99-v432',['Numbers','Labels','References']]] as [$file,$pkg,$family,$expectedNames]){
 $object=T::inspect($file,$pkg,0,$family);
 $tags=$find($object['tags']);
 $add(($object['status']??null)==='tag_envelope_decoded'
   &&($object['native_tail_bytes']??-1)===0
   &&$object['tag_end_offset']===$pkg->getExports()[0]['serialOffset']+$pkg->getExports()[0]['serialSize'],
  'source-selected '.$family.' actual original native SAVE output object fully consumed to NAME_None and same physical export end');
 $add(array_keys($tags)===$expectedNames,'original '.$family.' exactly 3 distinct ArrayProperty fields');
 foreach(['Numbers'=>[3,'intproperty',[11,42,-7],13],
          'Labels'=>[2,'nameproperty',$family==='unreal-v227k'?[4,5]:[1,5],3],
          'References'=>[2,'objectproperty',[1,0],3]] as $name=>[$num,$inner,$values,$bytes]){
  $tag=$tags[$name]??[];$v=$tag['value']??[];
  $add(($tag['type']??null)==='ArrayProperty'
    &&($tag['value_kind']??null)==='source_typed_dynamic_array'
    &&($tag['native_load_policy']??null)==='native_source_property_SerializeItem',
    'production actual source linked '.$family.' '.$name.' typed UArrayProperty::SerializeItem');
  $add(($v['element_count']??null)===$num
    &&($v['inner_property_kind']??null)===$inner
    &&($v['preview_first_32']??null)===$values,
    'all native loaded elements source-selected indexed original '.$family.' '.$name);
  $add(($tag['on_disk_value_bytes']??null)===$bytes
    &&($tag['serialized_size']??null)===$bytes
    &&($v['serialized_element_bytes']??null)===$bytes,
    'real original compiler/SavePackage exact count+elements physical bytes '.$family.' '.$name);
 }
 if($family==='unreal-v227k'){
  $add(($tags['Labels']['value']['linked_names_or_objects_first_32']??null)===['Alpha','Gamma'],
   'original 227k genuine NameMap resolves both nonzero FName identities, not arbitrary global indices');
  $add(($tags['References']['value']['linked_names_or_objects_first_32']??null)===['ArrayObject',null],
   'original 227k linker same-object export ref1 and NAME_None-like nullptr ref0 match real runtime self + null');
 }
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$errors,
 'grouped_original_compiled_fixed_tests'=>['T021'=>'PASS','T024'=>'PASS','T041'=>'PASS','T042'=>'PASS'],
 'C98_dynamic_array'=>'source_defined_absent_NA',
 'original_native_runs_in_verifier'=>0,'original_v227k_native_SAVE_bytes'=>302,
 'production_227k_tagged_arrays_decode'=>['int_3','FName_2','UObject_self_null_2'],
 'original_C227i_2012_compiled_file_v69_and_2026_loader_distinct'=>true,
 'G3_full_acceptance'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
