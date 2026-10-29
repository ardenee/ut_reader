#!/usr/bin/env php
<?php
/** Fixed UE1 G3 T016. Authentic May17 C98 retail compiler makes a v61
 * parent+child class. The original compiled CDB trace observes actual
 * UClass::Serialize post-tag native Defaults memory, not only saved tags.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$sys='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/';
$checks=0;$fails=[];
$ck=static function(bool $value,string $why)use(&$checks,&$fails):void {++$checks;if(!$value)$fails[]=$why;};
$items=[
 [$sys.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$sys.'Engine.dll','2f3ab0f8bc40960e6be5e1f540ca974a1537d9b23f2b9d7d06af589400b70069'],
 [$ev.'20261029-G3-T016-C98-original-native-CDO-memory.log','d428680c17d6d2e38786daeecdb984ac8d657c4578aec570d45bd012545ffe41'],
 [$ev.'20261029-G3-T016-C98-original-native-CDO-breakpoint.cmd','376bf8165dc275948efe550f236f70420f1e86811fc94e399fbd3937643de68c'],
 [$ev.'20261029-G3-T016-C98-original-compiler-G3C98Defaults.u','766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf'],
 [$ev.'20261029-G3-T016-C98-original-compiler-parent.uc','01615d512f5082715246a445abdb0c57a30251731230eb8e29e87720ab1c2da0'],
 [$ev.'20261029-G3-T016-C98-original-compiler-child.uc','45e545b3a1d8fc622fb76b26e4521a2f06a0f28ed76838e3c2ae7375db776e69'],
 [$ev.'20261029-G3-T016-C98-original-compiler-package-load.log','49ffb6204c3326162e8eea774f10c38f759a1d2763211332bc29293043c137c6'],
 [$root.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','842cfbbd1a9df0ac5b97ab82a5ec3df0dd5e145e80c5ddcd21df4f384b4c5468']
];
foreach($items as [$file,$sha])$ck(is_file($file)&&hash_file('sha256',$file)===$sha,
 'source/original native SHA '.basename($file));
$pkgFile=$sys.'G3C98Defaults.u';$archive=$ev.'20261029-G3-T016-C98-original-compiler-G3C98Defaults.u';
$ck(is_file($pkgFile)&&hash_file('sha256',$pkgFile)===hash_file('sha256',$archive),
 'same original compiled fixture as pinned source bytes in repository');
$pkg=new P($archive);
$ck((int)$pkg->getHeader()['version']===61,'authentic retail May 1998 v61 compiler');
$classes=[];$exports=$pkg->getExports();
foreach($exports as $id=>$e){
 if((int)$e['classIndex']!==0)continue;
 $name=(string)$e['objectNameText'];
 $r=C::inspect($archive,$pkg,$id,false,'epic-ue1');
 $ck(($r['source_file_archive_complete']??false)===true,'original C98 class archive fully consumed '.$name);
 $classes[$name]=['id'=>$id,'tags'=>[]];
 foreach(($r['default_property_tags']??[]) as $tag){
  $classes[$name]['tags'][(string)$tag['name']]=['type'=>$tag['type'],'value'=>$tag['value'],
   'offset'=>$tag['value_offset'],'bytes'=>$tag['serialized_size']];
 }
}
$ck(count($classes)===2&&isset($classes['G3C98Parent'],$classes['G3C98Child'])
 &&$classes['G3C98Parent']['id']===0&&$classes['G3C98Child']['id']===1,
 'actual two C98 serialized classes export 0 and 1');
$p=$classes['G3C98Parent']['tags']??[];
$c=$classes['G3C98Child']['tags']??[];
$ck(count($p)===2&&($p['G3Magic']['value']??null)===123
 &&($p['G3Magic']['type']??null)==='IntProperty'
 &&($p['G3Magic']['bytes']??null)===4,
 'original compiler parent saved explicit G3Magic INT32 123');
$ck(($p['G3Scale']['type']??null)==='FloatProperty'
 &&($p['G3Scale']['value']??null)===1.25&&($p['G3Scale']['bytes']??null)===4,
 'original compiler parent saved explicit G3Scale float32 1.25');
$ck(count($c)===1&&($c['G3Magic']['value']??null)===456
 &&!isset($c['G3Scale']),
 'original compiled child archive saves override INT32 456 but omits inherited float');
$archBytes=(string)file_get_contents($archive);
foreach([['G3C98Parent','G3Magic',123],['G3C98Child','G3Magic',456]] as [$name,$field,$expected]){
 $at=$classes[$name]['tags'][$field]['offset']??-1;
 $ck($at>=0&&(int)unpack('V',substr($archBytes,$at,4))[1]===$expected,
  'original compiler actual INT32 archive wire bytes '.$name.'.'.$field);
}
$at=$p['G3Scale']['offset']??-1;
$ck($at>=0&&bin2hex(substr($archBytes,$at,4))==='0000a03f',
 'original compiler FLOAT32 physical wire bytes exact 1.25');
$native=(string)file_get_contents($ev.'20261029-G3-T016-C98-original-native-CDO-memory.log');
$breakpoint=(string)file_get_contents($ev.'20261029-G3-T016-C98-original-native-CDO-breakpoint.cmd');
$load=(string)file_get_contents($ev.'20261029-G3-T016-C98-original-compiler-package-load.log');
$ck(str_contains($breakpoint,'Core+0x12b2d')
 &&str_contains($breakpoint,'poi(@esi+b0)+1bc L2'),
 'compiled C98 after UClass::Serialize loaded Defaults memory exact member offsets');
$ck(str_contains($load,'Init: Compiled: May 17 1998')
 &&str_contains($load,'Log: Loading: Package G3C98Defaults')
 &&str_contains($load,'Exit: Object subsystem successfully closed.'),
 'original retail engine actually loads generated class package to successful native exit');
preg_match_all('/G3_T016_ORIGINAL_C98_PARENT_CHILD_LOADED_CDO\s*\n\s*([0-9a-f]{8})\s+([0-9a-f]{8})\s*\n\s*([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s*\n\s*([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})/i',$native,$matches,PREG_SET_ORDER);
$ck(count($matches)===2,'original compiled CDB exactly two native class default memory observations');
$seen=[];
foreach($matches as $i=>$m){
 $export=hexdec($m[2]);$data=hexdec($m[4]);$size=hexdec($m[5]);
 $max=hexdec($m[6]);$mem=hexdec($m[7]);$loadedInt=hexdec($m[8]);$floatBits=hexdec($m[9]);
 $ck($export===$i,'actual original native same-linker exported class id in parent/child order');
 $ck($size===0x1c4&&$max===0x1c4,'native class default object fully allocated 452 bytes');
 $ck($mem===$data+0x1bc,'native observed G3Magic offset 444 then G3Scale offset 448');
 $ck($loadedInt===($i===0?123:456),'true original loaded magic value parent 123 then child override 456');
 $ck($floatBits===0x3fa00000,'true original native inherited G3Scale 1.25 survives child absent tag');
 $seen[]=['class'=>$i===0?'G3C98Parent':'G3C98Child',
  'native_object_size'=>$size,'magic_offset'=>0x1bc,'native_loaded_int'=>$loadedInt,
  'scale_offset'=>0x1c0,'native_loaded_float_bits'=>sprintf('%08x',$floatBits)];
}
$ok=$fails===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$fails,'fixed_test'=>'T016',
 'authentic_compiled_engine'=>'Unreal retail C98 May17 1998',
 'source_archive_version'=>61,'native_loaded_parent_child'=>$seen,
 'native_original_executions_in_verifier'=>0,
 'source_archive_only_is_not_native_proof'=>true,'G3_global_accepted'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
