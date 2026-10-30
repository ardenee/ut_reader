#!/usr/bin/env php
<?php
/** Frozen G3 T043,T044. True original 2012 C227i script + 2026 C227k SAVE/independent LOAD. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$n=0;$fail=[];$ck=static function(bool $ok,string $label)use(&$n,&$fail):void{++$n;if(!$ok)$fail[]=$label;};
$sha=[
 '20261030-G3-B06-AGG-C227i-original-source.uc'=>'0c38d2b524c55a27e729db8fa4d7fe108d21c9889eab6eb5737a0ce2c9964552',
 '20261030-G3-B06-AGG-C227i-original-compiler.u'=>'ddea6bb9110b1c061f24efadae4b5ce3d81eb4a8ba7294d170e1e320263287e5',
 '20261030-G3-B06-AGG-C227i-original-compiler.log'=>'c035e667aa143fc979c93981fded7a516da74f794f9b2d59e6964813cba14bb7',
 '20261030-G3-B06-AGG-C227k-original-SAVE.u'=>'34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7',
 '20261030-G3-B06-AGG-C227k-original-SAVE.cpp'=>'483c1c653b77e3a21718dc416a48faa5ed9ae6933643bf7654d672c0c4958d19',
 '20261030-G3-B06-AGG-C227k-original-SAVE.log'=>'6413757d2ae778b2f39f9d25b8ac5b06003b9245528ef1875bd410548ec2842d',
 '20261030-G3-B06-AGG-C227k-original-LOAD.cpp'=>'915baad2aa044f3591bdd97c464bd14a025c781951dc34e372c585657cd9d0ee',
 '20261030-G3-B06-AGG-C227k-original-LOAD.log'=>'f48e72ab189703938f8c4b775d55046973569e1a7565163a7cf3aa5958cd6b78',
 '20261030-G3-B06-AGG-C227k-arrayZero.u'=>'7e93a957b6aad48775150da8b46e7d9471f52099a62952e63bbc9531a437c333',
 '20261030-G3-B06-AGG-C227k-arrayNegOne.u'=>'23f29615f4086c7b70ca6df96b9b3a4e437f846bfff85d1843b5032b652719ee',
 '20261030-G3-B06-AGG-C227k-arrayZero-native.log'=>'e3b5858aa37af3fac5b53571abd36fae819a66cfe50400cc272606e73f7e250f',
 '20261030-G3-B06-AGG-C227k-arrayNegOne-native.log'=>'ba8025fd13d955ebd86d84a5859deee6f5f211e3d449bfb3391bd7ee347bf23d',
 '20261030-G3-B06-AGG-C227k-arrayMalformed-native.cpp'=>'4bd70731e4db14a4a2dd328a9653ca9f8f31d5ede5cbea1580e3f999c91fd7cf',
 '20261030-G3-B06-AGG-source-current-compare.php'=>'c672e977fc77a53b90e0f19bbf830e65d0a5bc398f21e252613a089e9fb95702',
 '20261030-G3-B06-AGG-source-original-struct-layout.php'=>'08d0c39731607a878189846b556f93742976a335af4032d8450c5283eaf21fd4',
 '20261030-G3-B06-AGG-source-original-saved-spans.php'=>'79db02ff6618cf473815a2f6727bdd8de5e985ca9e22f480fb56185997e57a5f'
];
foreach($sha as $name=>$expected)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$expected,'original compiler/native/log/source evidence SHA '.$name);
$cf='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u';
$nf='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u';
$ck(is_file($cf)&&hash_file('sha256',$cf)===$sha['20261030-G3-B06-AGG-C227i-original-compiler.u'],'2012 original UCC binary working .u byte-identical sealed original');
$ck(is_file($nf)&&hash_file('sha256',$nf)===$sha['20261030-G3-B06-AGG-C227k-original-SAVE.u'],'2026 original Core.dll saved runtime binary .u working byte-identical sealed native');
$source=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227i-original-source.uc');
foreach(['struct G3Tuple','var() int Number;','var() float Fraction;','var() name Label;','var() array<G3Tuple> Pairs;','var() array<string> Strings;','Pairs(0)=(Number=11,Fraction=1.5,Label=Alpha)','Pairs(1)=(Number=-7,Fraction=-2.5,Label=Gamma)','Strings(0)=""','Strings(1)="Hello-227"'] as $frag)
 $ck(str_contains($source,$frag),'source legal ORIGINAL C227i scripted aggregate/empty/nonempty declaration '.$frag);
$ck(str_contains($source,'StaticInts(0)=123')&&str_contains($source,'StaticNames(1)=Beta'),'original 2012 static ArrayDim2 control, deliberately not UFixedArrayProperty');
$cLog=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227i-original-compiler.log');
if(str_starts_with($cLog,"\xFF\xFE"))$cLog=(string)iconv('UTF-16LE','UTF-8',substr($cLog,2));
$ck(str_contains($cLog,'Compiling G3Array227Actor')&&str_contains($cLog,'0 warning(s)'),'2012 original C227i UCC compiler actually accepted all two array/struct/empty default variants, 0 warnings');
$cp=new P($cf);$np=new P($nf);
$ck($cp->getHeader()['version']===69&&$cp->getHeader()['licensee']===0&&filesize($cf)===1268,'real original C227i compiled package 1268 bytes source v69 lic0');
$ck($np->getHeader()['version']===69&&$np->getHeader()['licensee']===227&&filesize($nf)===308,'real original C227k SavePackage 308 bytes source v69 lic227');
$class=C::inspect($cf,$cp,2,false,'unreal-v227i');
$ck(($class['source_file_archive_complete']??false)===true,'original C227i native UClass default archive whole class consumed');
$default=[];foreach($class['default_property_tags'] as $t)$default[$t['name']][]=$t;
$ck(($default['Pairs'][0]['type']??null)==='ArrayProperty'&&($default['Pairs'][0]['on_disk_value_bytes']??null)===19,'2012 original compiler default struct two members encoded exactly 19 archive bytes');
$ck(($default['Strings'][0]['type']??null)==='ArrayProperty'&&($default['Strings'][0]['on_disk_value_bytes']??null)===13,'2012 original compiler empty and nonempty FString array encoded 13 archive bytes');
$ck(count($default['StaticInts']??[])===2 && count($default['StaticNames']??[])===2,'static two-dimensional UE1 UProperty ArrayDim2 archived as two indexed tags; NOT native UFixedArrayProperty');
$ck(($default['StaticInts'][0]['value']??null)===123&&($default['StaticInts'][1]['value']??null)===-456,'original static ArrayDim2 primitive two default value words identical');
$ck(count($cp->getExports())===12,'genuine original C227i UCC compiled array/struct class property export metadata count12');
$struct=S::resolve($cf,$cp,1,'unreal-v227i');
$ck(($struct['status']??null)==='struct_local_field_layout_resolved','source-linked G3Tuple original UStruct::SerializeBin member field layout resolved');
$fields=$struct['fields']??[];
$ck(array_column($fields,'name')===['Number','Fraction','Label'],'native serializer exact source TFieldIterator order int,float,name');
$ck(array_column($fields,'kind')===['intproperty','floatproperty','nameproperty'],'real original source native UStruct member kinds determine 4+4+AR_INDEX footprint');
$ck(($struct['source_field_order_verified']??false)===true&&($struct['runtime_offsets_verified']??true)===false,'source linked archived field order without falsely claiming runtime C++ struct offsets');
$tag=T::inspect($nf,$np,0,'unreal-v227k');
$ex=$np->getExports()[0];
$ck(($tag['status']??null)==='tag_envelope_decoded'&&$ex['serialOffset']===231&&$ex['serialSize']===49
 &&($tag['tag_end_offset']??null)===280&&($tag['native_tail_bytes']??null)===0,
 'genuine original 227k SavePackage export0 serial offset231 49 bytes full source-selected archive to280');
$tags=[];foreach($tag['tags'] as $t)$tags[$t['name']]=$t;
$ck(count($tags)===2&&isset($tags['Pairs'],$tags['Strings']),'original native 227k instance output contains exact two aggregate tags');
$pair=$tags['Pairs'];$str=$tags['Strings'];
$ck($pair['type']==='ArrayProperty'&&$pair['native_load_policy']==='native_source_property_SerializeItem'
 &&$pair['value_kind']==='source_typed_dynamic_array','real v227 source-linked genuine original nested UStruct native array typed decoder selected');
$ck($pair['on_disk_value_bytes']===19&&$pair['serialized_size']===19&&$pair['value_offset']===234,
 'source selected original 2026 native array<UStruct> count2 and 18 member bytes exactly19, offset234');
$ck(bin2hex(substr((string)file_get_contents($nf),234,19))==='02650000000000a03f0436ffffff000030c003',
 'native SavePackage original v227 exact two struct binary bytes, with real FName linker index and float');
$pv=$pair['value'];$elements=$pv['preview_first_32']??[];
$ck($pv['element_count']===2&&$pv['inner_property_kind']==='structproperty'&&$pv['serialized_element_bytes']===19
 &&$pv['all_values_previewed']===true&&count($elements)===2,
 'original 227k true source-defined two-struct element count and physical bytes correctly decoded');
$want=[[101,1.25,4],[-202,-2.75,3]];
foreach($elements as $i=>$elem){
 $values=array_map(static fn($m)=>$m['values'][0]??null,$elem['members']??[]);
 $ck($elem['status']==='struct_field_values_decoded'&&$elem['serialized_bytes']===9
   &&$values===$want[$i],
 'actual source-linked struct number/float/FName index matches genuine original native C227k instance element'.$i);
}
$names=$np->getNames();
$ck(($names[4]['name']??$names[4]['text']??null)==='Alpha'
 &&($names[3]['name']??$names[3]['text']??null)==='Gamma',
 'real SavePackage linker FName index4 Alpha index3 Gamma; compare native names not ASLR addresses');
$ck($str['type']==='ArrayProperty'&&$str['native_load_policy']==='native_source_property_SerializeItem'
 &&$str['value_kind']==='source_typed_dynamic_array','original 227k genuine FString inner native SerializeItem class selected and decoded');
$ck($str['on_disk_value_bytes']===23&&$str['serialized_size']===23&&$str['value_offset']===256,
 'original 227k dynamic array of 2 source FString entries 23 physical bytes and offset256');
$ck(bin2hex(substr((string)file_get_contents($nf),256,23))==='020a416c7068612d323237000a47616d6d612d32323700',
 'genuine native string array compact count2 and two source ASCII UTF TCHAR NUL strings exact archived bytes');
$sv=$str['value'];$strings=$sv['preview_first_32']??[];
$ck($sv['element_count']===2&&$sv['inner_property_kind']==='strproperty'
 &&$sv['all_values_previewed']&&count($strings)===2,'two independently native saved FString element count source-linked and exactly decoded');
foreach([0=>'Alpha-227',1=>'Gamma-227'] as $i=>$expected){
 $v=$strings[$i]??[];
 $ck(($v['ascii_text']??null)===$expected
  &&($v['signed_character_count']??null)===10
  &&($v['nul_terminated']??null)===true
  &&($v['archive_value_kind']??null)==='fstring_ansi_archive',
 'genuine native C227k FString element text/count/NUL exact '.$i);
}
$log=static function(string $name)use($ev):string{
 $s=(string)file_get_contents($ev.$name);
 return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;
};
$save=$log('20261030-G3-B06-AGG-C227k-original-SAVE.log');
$load=$log('20261030-G3-B06-AGG-C227k-original-LOAD.log');
$ck(str_contains($save,'G3_BULK_227_PACKAGE_LOADED ')&&str_contains($save,'bad=0')
 &&str_contains($save,'G3_BULK_227_NATIVE_SAVE 1'),
 'actual separate 2026 original native Core.dll loaded original 2012 class and saved real runtime .u');
$ck(str_contains($save,'G3_B06_ORIGINAL_PAIRS class_default_count=2 actual_count=2 inner_bytes=12 type=StructProperty'),
 'real original C227k runtime 2 genuine UStruct array instances, inner memory stride12 bytes');
foreach([
 'pair=0 field=Number default=11 updated=101',
 'pair=0 field=Fraction default=1.500000 updated=1.250000',
 'pair=1 field=Number default=-7 updated=-202',
 'pair=1 field=Fraction default=-2.500000 updated=-2.750000',
 'pair=0 field=Label default=Alpha updated=Alpha',
 'pair=1 field=Label default=Gamma updated=Gamma'
] as $frag)$ck(str_contains($save,$frag),'actual original native struct in-memory before/after modification '.$frag);
$ck(str_contains($save,'G3_B06_ORIGINAL_STRINGS class_default_count=2 actual_count=2 inner_bytes=12')
 &&str_contains($save,'G3_B06_ORIGINAL_STRING value0=Alpha-227 value1=Gamma-227'),
 'original Core.dll native real initialized FString array two elements with exact nonempty assigned text');
$ck(str_contains($save,'property=StaticInts property_class=IntProperty arraydim=2 elementsize=4')
 &&str_contains($save,'property=StaticNames property_class=NameProperty arraydim=2 elementsize=4'),
 'real original C227k native confirms static literal[2] are UProperty ArrayDim2 NOT UFixedArrayProperty, T045/46 left blocked');
$ck(str_contains($load,'G3_B06_227_INDEPENDENT_RELOAD cls=')
 &&str_contains($load,'bad=0')&&str_contains($load,'G3_B06_227_RELOADED_COUNT 1'),
 'second separate original 2026 compiled Core.dll loader loaded genuine original 308-byte saved runtime and found exact one actor');
$ck(str_contains($load,'G3_B06_NATIVE_CDO_STRINGS count=2 innerSize=12'),
 'real original C227k native CDO loaded 2012 script class two string array elements');
$ck(str_contains($load,'G3_B06_NATIVE_CDO_STRING index=0 text= len=0')
 &&str_contains($load,'G3_B06_NATIVE_CDO_STRING index=1 text=Hello-227 len=9'),
 'actual original C227k native class LOAD proves separate empty and nonempty C227i source-compiled FString array members, no allocator substitution');
foreach([
 'G3_B06_NATIVE_RELOADED_ARRAY name=Pairs count=2 innerSize=12',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Number value=101',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Fraction value=1.250000',
 'G3_B06_NATIVE_RELOADED_PAIR index=0 field=Label value=Alpha',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Number value=-202',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Fraction value=-2.750000',
 'G3_B06_NATIVE_RELOADED_PAIR index=1 field=Label value=Gamma',
 'G3_B06_NATIVE_RELOADED_ARRAY name=Strings count=2 innerSize=12',
 'G3_B06_NATIVE_RELOADED_STRING index=0 text=Alpha-227 len=9',
 'G3_B06_NATIVE_RELOADED_STRING index=1 text=Gamma-227 len=9'
] as $frag)$ck(str_contains($load,$frag),'actual original independent native Core.dll loaded values '.$frag);
$zero=$log('20261030-G3-B06-AGG-C227k-arrayZero-native.log');
$neg=$log('20261030-G3-B06-AGG-C227k-arrayNegOne-native.log');
$orig=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227k-original-SAVE.u');
foreach([['Zero',0],['NegOne',129]] as [$suffix,$byte]){
 $b=(string)file_get_contents($ev.'20261030-G3-B06-AGG-C227k-array'.$suffix.'.u');
 $diff=[];for($i=0;$i<strlen($orig);$i++)if($orig[$i]!==$b[$i])$diff[]=$i;
 $ck(strlen($b)===308&&$diff===[234]&&ord($b[234])===$byte,
   'malformed array scoped PARTIAL exact sole mutation in genuine original runtime count at absolute offset234 '.$suffix);
}
$ck(str_contains($zero,'G3_B06_NATIVE_RELOADED_ARRAY name=Pairs count=0 innerSize=12')
 &&str_contains($zero,'G3_B06_227_RELOADED_COUNT 1'),
 'partial T048 positive original 227k zero-size source legal dynamic native loaded count0, NOT a distinct full pass');
$ck(str_contains($neg,'<?int?Core.Errors.SerialSize?>')
 &&!str_contains($neg,'G3_B06_227_RELOADED_COUNT 1'),
 'partial T048 source-negative original native serial-size error then process access violation; not a trustworthy cursor oracle; T048 BLOCKED');
$ck(hash_file('sha256',$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php')==='90f161b06b08900e8171ca0ba597f4123f09097d1c0c4e127cc7291dbc57f4dd',
 'production UE1 source-linked inner struct and FString codec exact new implementation pinned');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'failures'=>$fail,
 'full_original_fixed_ids'=>['T043'=>'PASS','T044'=>'PASS'],
 'original_2012_compiler_warnings'=>0,
 'real_original_2026_SavedPackage_bytes'=>308,
 'real_original_2026_native_struct_values'=>[[101,1.25,'Alpha'],[-202,-2.75,'Gamma']],
 'real_original_2026_native_class_default_strings'=>['','Hello-227'],
 'real_original_2026_native_loaded_instance_strings'=>['Alpha-227','Gamma-227'],
 'original_227k_native_negative_array'=>'PARTIAL_CRASH_NOT_ARCHIVE_CURSOR_T048_BLOCKED',
 'original_fixed_array'=>'UProperty_ArrayDim2_not_UFixedArrayProperty_T045_T046_BLOCKED',
 'prior_accepted_native_probes_reexecuted'=>0,
 'G3_full_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
