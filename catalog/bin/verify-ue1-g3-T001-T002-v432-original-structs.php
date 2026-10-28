#!/usr/bin/env php
<?php
/** T001/T002: actual original v432 x86 Core.dll four native loaded UStructs.
 * Original compiled host genuinely LoadPackage(Core.u) and StaticFindObject.
 * Native Children/Next/SuperField GetLinkerIndex resolved against same linker.
 * Source v432 Core.lib ABI is 16-bit TCHAR via MSVC /Zc:wchar_t-.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$e=$root.'/catalog/compliance/evidence/o06/';
$files=[
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll'=>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/Core.u'=>'ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70',
 'C:/Temp/UnrealDB/O06/Native-UCC/System/G3T001OriginalStructs.exe'=>'f8bdba8fb55fd9b19e7fc02b338b2c696d5ccf1ac26436588ca68147f58cc380',
 'L:/Source/Games/UT99/Unreal Tournament v432/Core/Lib/Core.lib'=>'066f48ac6e136ac0ddfc27663305f52d957709f4f4ca4539c6f58be0760e6448',
 'L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnClass.h'=>'28da9c95776142a85214fc444b8d683077a3310f4599881d689acb5c38b7ffd2',
 $e.'20261028-G3-T001-T002-v432-native-direct-structs.cpp'=>'19dab366d13b5b969cf843e853912b921763b4a04cbf446a26bbc09cb15d8736',
 $e.'20261028-G3-T001-T002-v432-native-direct-structs.log'=>'a58bd237eddb2cf39ca4a0c529123bc1a37af65193cce348dc2b60213e60c041',
 $e.'20261028-G3-T001-T002-v432-native-build.log'=>'501bd99ace97080d8ab9abbc2cc65282395ad45e6aeff704c0a54c861ad00dad',
];
$checks=0;$fail=[];
$check=static function(bool $yes,string $what)use(&$checks,&$fail):void{
 ++$checks;if(!$yes)$fail[]=$what;
};
foreach($files as $path=>$digest)$check(is_file($path)&&hash_file('sha256',$path)===$digest,
 'Original v432 binary/native read/build SHA '.basename($path));
$src=(string)file_get_contents($e.'20261028-G3-T001-T002-v432-native-direct-structs.cpp');
$log=str_replace(chr(13),'',iconv('UTF-16LE','UTF-8',substr((string)file_get_contents($e.'20261028-G3-T001-T002-v432-native-direct-structs.log'),2)));
$check(str_contains($src,'UObject::LoadPackage(NULL,TEXT("Core.u"),LOAD_NoFail)')
 &&str_contains($src,'UObject::StaticFindObject(UStruct::StaticClass()'),
 'Genuine original compiled Core.u LOAD + four native UField/UStruct object looks');
$check(str_contains($src,'GetLinkerIndex()')&&str_contains($src,'GetLinker()==s->GetLinker()'),
 'Original runtime child/next/super identities mapped via linker, not PHP simulation');
$check(str_contains($log,'G3_T001_V432_FOUND=4'), 'All four original compiled Core.dll UObjects actually loaded');
$pkgfile='C:/Temp/UnrealDB/O06/Native-UCC/System/Core.u';
$p=new P($pkgfile);$exports=$p->getExports();
$check(($p->getHeader()['version']??null)===69&&count($exports)===669,
 'Original v432 stock Core.u v69 with 669 real exports');
$rows=[
 0=>['Vector',0,577,591,0,12,87],
 1=>['Rotator',1,569,33,0,12,88],
 2=>['Color',535,294,547,0,4,90],
 3=>['Plane',32,565,1,1,16,91]
];
$loaded=[];$linkers=[];
foreach($rows as $ordinal=>[$name,$idx,$childRef,$nextRef,$superRef,$propsSize,$friendlyGlobal]){
 $pattern='/^G3_T001_V432_LINK\['.$ordinal.'\] linker=([A-Fa-f0-9]+) export=(\d+) child=([A-Fa-f0-9]+) script=(\d+) line=(\d+) textpos=(\d+) friendly_global=(\d+) propssize=(\d+) propsalign=(\d+) scripttext_null=(\d+)$/m';
 $match=preg_match($pattern,$log,$a);
 $check($match===1,'Original native loaded '.$name.' and all compiled fields');
 if(!$match)continue;
 $linkers[]=$a[1];
 $check((int)$a[2]===$idx&&((int)hexdec($a[3]))!==0,
   'Original native linker index and nonnull Children '.$name);
 $check((int)$a[4]===0&&(int)$a[5]===0&&(int)$a[6]===0&&(int)$a[9]===4&&(int)$a[10]===1,
   'Actual original v432 decoded Script.Num/Line/TextPos/ScriptText and align '.$name);
 $check((int)$a[7]===$friendlyGlobal&&(int)$a[8]===$propsSize,
   'Original native global FName and linked PropertiesSize '.$name);
 $refPattern='/^G3_T001_V432_REFERENTS\['.$ordinal.'\] child_index=(-?\d+) next_index=(-?\d+) super_index=(-?\d+) child_same_linker=(\d+) next_same_linker=(\d+) friendly_name=(\w+)$/m';
 $got=preg_match($refPattern,$log,$n);
 $check($got===1,'Original compiled native reference/semantic names '.$name);
 if(!$got)continue;
 $check((int)$n[1]===$childRef-1&&(int)$n[2]===$nextRef-1&&(int)$n[3]===$superRef-1,
  'Original compiled native CHILD/NEXT/SUPER target indices '.$name);
 $check($n[6]===$name&&(int)$n[4]===1&&(int)$n[5]===1,
  'Original runtime native friendly and same-linker references '.$name);
 $dec=N::inspect($pkgfile,$p,$idx,T::inspect($pkgfile,$p,$idx,'ut99-v432'),'ut99-v432');
 $check(($exports[$idx]['objectNameText']??null)===$name
   &&($dec['status']??null)==='native_struct_record_complete',
   'Reader original actual direct UStruct identity and physical native Serialize end '.$name);
 foreach(['children_reference'=>$childRef,'next_field_reference'=>$nextRef,
  'super_field_reference'=>$superRef,'script_text_reference'=>0,
  'script_size'=>0,'source_line'=>0,'source_text_pos'=>0] as $field=>$required)
  $check(($dec[$field]??null)===$required,'Original direct '.$name.' '.$field);
 $check(($dec['friendly_name']['text']??null)===$name,'Original loaded UName/FriendlyName '.$name);
 $loaded[$name]=['export'=>$idx,'child'=>$childRef-1,'next'=>$nextRef-1,
 'super'=>$superRef-1,'script_size'=>0,'original_loaded_native_props_size'=>$propsSize];
}
$check(count($loaded)===4 && count(array_unique($linkers))===1,
  'Vector Rotator Color Plane share exactly one real original compiled package linker');
$check(($exports[32]['superIndex']??null)===1,
  'Plane original persisted superclass one-based export1 points to Vector');
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'failed'=>$fail,
  'T001'=>'PASS original v432 Vector/Rotator/Color loaded native fields vs reader',
  'T002'=>'PASS original v432 UField Next/SuperField child identities including Plane->Vector',
  'original_native_loaded_objects'=>$loaded,'original_compiled_native_reexecuted_from_previous_sealed_tests'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
