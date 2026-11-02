#!/usr/bin/env php
<?php
/** Fixed G3 T058: original retail C98 tagged UObject class-default LOAD and
 * actual compiled FArchiveFileLoad::Pos at NAME_None terminator.
 * Original CDB execution is frozen; this verifier only checks its bytes.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$ev=$root.'/catalog/compliance/evidence/o06/';
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/';
$checks=0;$errors=[];
$ck=static function(bool $ok,string $msg)use(&$checks,&$errors):void{++$checks;if(!$ok)$errors[]=$msg;};
$files=[
 [$ev.'20261030-G3-T058-C98-original-native-FArchive-Pos.log','f84395f6975f9392113195fd63577a9d3b47c1d813a6b2436aab413200d17d11'],
 [$ev.'20261030-G3-T058-C98-original-native-FArchive-Pos.cmd','da7d55d647b7e3a1763ae7770e6b4d4efef8a920c6c3b4b454a752c5c89e77e9'],
 [$ev.'20261029-G3-T016-C98-original-compiler-G3C98Defaults.u','766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf'],
 [$ev.'20261029-G3-T016-C98-original-native-CDO-memory.log','d428680c17d6d2e38786daeecdb984ac8d657c4578aec570d45bd012545ffe41'],
 [$ev.'20261029-G3-T016-C98-original-compiler-package-load.log','49ffb6204c3326162e8eea774f10c38f759a1d2763211332bc29293043c137c6'],
 [$source.'UnClass.cpp','ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291'],
 [$source.'UnLinker.h','82008da9b50ba09f8268b52d07f8d9e1fc2333ea35b229dcff7c30a521486b1f'],
 ['C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92']
];
foreach($files as [$f,$sha])$ck(is_file($f)&&hash_file('sha256',$f)===$sha,'source/native pin '.basename($f));
$s=(string)file_get_contents($source.'UnClass.cpp');
$ck(str_contains($s,'Ar << Tag;')&&str_contains($s,'if( Tag.Name == NAME_None )')&&str_contains($s,'SerializeTaggedProperties( Ar, &Defaults(0), GetSuperClass() );'),
 'original C98 same UStruct tagged LOAD used by UObject/C98 class defaults; NAME_None exits');
$link=(string)file_get_contents($source.'UnLinker.h');
$ck(str_contains($link,'char Filename[256];')&&str_contains($link,'INT Pos;')
 &&str_contains($link,'Pos += Length;')&&str_contains($link,'return appFtell( File );'),
 'original FArchiveFileLoad physically tracks Pos and Tell uses actual file pointer');
$native=(string)file_get_contents($ev.'20261030-G3-T058-C98-original-native-FArchive-Pos.log');
$cmd=(string)file_get_contents($ev.'20261030-G3-T058-C98-original-native-FArchive-Pos.cmd');
$ck(str_contains($cmd,'Core+0x12b2d')&&str_contains($cmd,'s -d @ebx L200 00000176'),
 'CDB original compiled UClass after-tag archive FArchiveFileLoad Pos and requested physical offset');
preg_match_all('/G3_T058_NATIVE_C98_ACTUAL_FARCHIVE_POS\s*\n\s*([0-9a-f]{8})\s+([0-9a-f]{8})\s*\n\s*([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})/i',$native,$rows,PREG_SET_ORDER);
$ck(count($rows)===2,'original compiled native loaded exactly both real parent/child source tagged defaults');
$pkgFile=$ev.'20261029-G3-T016-C98-original-compiler-G3C98Defaults.u';
$pkg=new P($pkgFile);$bytes=(string)file_get_contents($pkgFile);
$names=$pkg->getNames();
$ck(count($names)===16&&(string)$names[0]['name']==='None',
 'true original May1998 package FName None index 0');
$expected=[
 ['name'=>'G3C98Parent','id'=>0,'start'=>361,'end'=>374,'counts'=>2,'wire'=>'04227b00000007240000a03f00','magic'=>123],
 ['name'=>'G3C98Child','id'=>1,'start'=>460,'end'=>467,'counts'=>1,'wire'=>'0422c801000000','magic'=>456]
];
foreach($expected as $i=>$x){
 $ex=$pkg->getExports()[$i];
 $r=C::inspect($pkgFile,$pkg,$i,false,'epic-ue1');
 $t=T::inspectClassDefaults($pkgFile,$pkg,$x['start'],(int)$ex['serialOffset']+(int)$ex['serialSize'],61,'epic-ue1');
 $ck((string)$ex['objectNameText']===$x['name']&&$r['defaults_serial_offset']===$x['start']
 &&$r['source_file_archive_complete']===true,'authentic original class '.$x['name'].' and exact on-disk source tag start');
 $ck(count($t['tags'])===$x['counts']&&$t['tag_end_offset']===$x['end'],
 'same production tagged reader consumes '.$x['counts'].' actual tags and original NAME_None '.$x['name']);
 $ck((int)$ex['serialOffset']+(int)$ex['serialSize']===$x['end']
 &&bin2hex(substr($bytes,$x['start'],$x['end']-$x['start']))===$x['wire'],
 'physical original compiler type/name/index/wire/cursor after NAME_None '.$x['name']);
 $ck($t['tags'][0]['name']==='G3Magic'&&$t['tags'][0]['name_index']===4
 &&$t['tags'][0]['type']==='IntProperty'&&$t['tags'][0]['value']===$x['magic'],
 'real named original G3Magic indexed4, IntProperty loaded '.$x['magic'].' '.$x['name']);
 $ck(ord($bytes[$x['end']-1])===0&&(int)$ex['serialSize']>0,
 'real original FCompactIndex NAME_None terminal index0 only at final byte '.$x['name']);
 $n=$rows[$i]??[];
 $ck(count($n)===8 && hexdec($n[2])===$x['id']
 &&hexdec($n[4])===$x['end'],'original compiled native FArchiveFileLoad::Pos matches actual source terminator cursor '.$x['end']);
 $ck(count($n)===8 && $n[3]===($rows[0][3]??null)
 &&hexdec($n[6])===strlen($bytes),
 'original archive Pos memory address stable across both classes and physical EOF matches full package');
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$errors,'fixed_test'=>'T058',
 'compiled_native'=>'original retail May17 1998 Core.dll',
 'original_loaded_class'=>'C98 UClass::Serialize invokes UStruct::SerializeTaggedProperties on UObject default storage',
 'native_archive_physical_cursors'=>[374,467],
 'named_tag_counts'=>[2,1],'source_archive_name_none_index'=>0,
 'original_native_reruns_from_verifier'=>0,'g3_full_accepted'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
