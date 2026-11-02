#!/usr/bin/env php
<?php
/** Frozen T029+T031: TWO genuine 2026 original shipped C227k UCC compile + original shipped Core.dll CDO LOAD; exact linker archive bytes. No mock archive is acceptance. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicAnyPropertyValueReader as A;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
$repo=dirname(__DIR__,2);$ev=$repo.'/catalog/compliance/evidence/o06/';
$count=0;$fail=[];
$ck=static function(bool $ok,string $message)use(&$count,&$fail):void{++$count;if(!$ok)$fail[]=$message;};
$sealed=[
'20261030-G3-B04-ANY-original-2026-NAME5.u'=>'9ff07cb368a4e71591b01f1eb185bc8c5bd121a58b3b409757aaea2d1577d37f',
'20261030-G3-B04-ANY-original-2026-OBJECT7.u'=>'66370a1ea9cca94863250d85342b308780a486fdde543a32f31779bb93fd323b',
'20261030-G3-B04-ANY-original-2026-NAME5-native-LOAD.log'=>'0668e321804c850a581f49242c875f98bc687f1546bbde610d398334e45fe31c',
'20261030-G3-B04-ANY-original-2026-OBJECT7-native-LOAD.log'=>'43fde188db29aac6d77962d3eed6be641eb9d67cdea93a93ad2f2d4513080dd3',
'20261030-G3-B04-ANY-original-2026-NAME5-UCC.log'=>'0c4cb009ff74821fe02a30826ab15e3e21e9c84dc33a55c71d4565f7f3c8af33',
'20261030-G3-B04-ANY-original-2026-OBJECT7-UCC.log'=>'0c4cb009ff74821fe02a30826ab15e3e21e9c84dc33a55c71d4565f7f3c8af33',
'20261030-G3-B04-ANY-original-2026-STRUCT6-REJECT.log'=>'2eedd2ec9666713fad5a0b71e90ef1a942651deb5be83d4e756e4b3c9f2f4648',
'20261030-G3-B04-ANY-original-C227k-genuine-CDO-native.cpp'=>'320a42b864d0570f30b3a0a04828bb642f23cd24280502b60f0e387db104a98b',
'20261030-G3-B04-ANY-original-UCC-3-source-variants.ps1'=>'93aa7b9c4c72ecbe491107864207da6b6e9edb70751ba9e0ec35bdf3f42a5825',
'20261030-G3-B04-ANY-original-native-two-CDO-run.ps1'=>'e7caafe69729758d440a5a4de8fe7b5f102efa108001ecb7da414be45972fe7c',
'20261030-G3-B04-ANY-original-2026-source-3-var-definitions-compiled.log'=>'0c4cb009ff74821fe02a30826ab15e3e21e9c84dc33a55c71d4565f7f3c8af33'
];
foreach($sealed as $name=>$sha)$ck(is_file($ev.$name)&&hash_file('sha256',$ev.$name)===$sha,'exact genuine original UCC/native SHA '.$name);
foreach([
'CatalogUE1ClassicAnyPropertyValueReader.php'=>'3c850791cba95f113813f0dbc5168c0d0685e369068a5ea88a3850c702fc7d59',
'CatalogUE1ClassicClassDefaultSchemaReader.php'=>'bfcd5e1be6a03059da60755d910c36a9df476e6ed6bac00953b3519f0bf1e4a2',
'CatalogUE1ClassicClassMetadataReader.php'=>'888e12fbc43dffd0d55cc0536f5e964acd017cb41ac68c16f744906435a0d4d4',
'CatalogUE1ClassicTaggedPropertyReader.php'=>'e377ad408d51db35288cbf8d5617238453b8f82bbd8beda3aed79dda1447268c',
] as $name=>$sha)$ck(hash_file('sha256',$repo.'/catalog/src/Infrastructure/Readers/'.$name)===$sha,'production source strict byte pin '.$name);
$ucc='C:/Temp/UnrealDB/O06/Native-227k/System/UCC.exe';
$ck(is_file($ucc)&&hash_file('sha256',$ucc)==='8377f0e1a8adcfb087a4ada317ff5dedbab406a7888226af904aca84908d907a','actual 2026 shipped UCC.exe unmodified source compiler');
$originalCompiled='C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll';
$ck(is_file($originalCompiled)&&hash_file('sha256',$originalCompiled)==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194','actual 2026 unmodified original Core.dll native engine loader');
$readLog=static function(string $name)use($ev):string{
 $raw=(string)file_get_contents($ev.$name);
 return str_starts_with($raw,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
};
$cpp=$readLog('20261030-G3-B04-ANY-original-C227k-genuine-CDO-native.cpp');
$ck(str_contains($cpp,'UObject::LoadPackage(')&&str_contains($cpp,'GetDefaultObject()')
 &&str_contains($cpp,'UAnyProperty::StaticClass()')&&str_contains($cpp,'val->GetName()')
 &&str_contains($cpp,'val->GetObject()->GetFullName()'),
 'independent actual compiled original Core.dll used real CDO loaded UAny property reflection/native name/object identity');
$script=$readLog('20261030-G3-B04-ANY-original-UCC-3-source-variants.ps1');
$ck(str_contains($script,"NameValue='G3NameAlpha'")
 &&str_contains($script,"ObjectValue=class'Engine.Actor'")
 &&str_contains($script,'[IO.File]::WriteAllBytes($ini,$originalIni)')
 &&str_contains($script,'[IO.File]::WriteAllBytes($package,$originalPackage)'),
 'two original compiler UCC source nonzero defaults, disposable core binary untouched and isolated UCC fixtures restored');
$structRejected=$readLog('20261030-G3-B04-ANY-original-2026-STRUCT6-REJECT.log');
$ck(str_contains($structRejected,'AnyProperty G3Any227K.G3Any227KActor.StructValue.ImportText failed')
 &&str_contains($structRejected,'1 warning(s)'),
 'T030 original compiled Any STRUCT(6) source failed ImportText; remains BLOCKED not fake pass');
$expected=[
 ['variant'=>'NonzeroNAME','file'=>'20261030-G3-B04-ANY-original-2026-NAME5.u',
 'tag'=>'NameValue','type'=>5,'hex'=>'0508','index'=>8,'resolved'=>'G3NameAlpha','kind'=>'native_any_linker_fname','offset'=>540,'nativeOffset'=>776],
 ['variant'=>'NonzeroOBJECT','file'=>'20261030-G3-B04-ANY-original-2026-OBJECT7.u',
 'tag'=>'ObjectValue','type'=>7,'hex'=>'0782','index'=>-2,'resolved'=>'Class Engine.Actor','kind'=>'native_any_linker_object','offset'=>526,'nativeOffset'=>780]
];
$source='C:/Temp/UnrealDB/O06/';
foreach($expected as $case){
 $variant=$case['variant'];$file=$source.'G3Any227K-original-2026-'.$variant.'.u';
 $sealedPath=$ev.$case['file'];
 $ck(is_file($file)&&hash_file('sha256',$file)===$sealed[$case['file']],
  'original actual source-dependent native package is exact sealed UCC output '.$variant);
 $pkg=new P($file);$h=$pkg->getHeader();
 $ck(($h['version']??null)===69&&($h['licensee']??null)===227
  &&count($pkg->getExports())===5,'actual original UCC .u native 69 lic227 five exports '.$variant);
 $ck($pkg->validatePackage()===[],'genuine original 227k compiled source package valid envelope '.$variant);
 $class=C::inspect($file,$pkg,4,true,S::UNREAL_V227K);
 $ck(($class['defaults_schema_verified']??false)===true
    &&($class['defaults_tag_count']??0)===3
    &&($class['source_file_archive_complete']??false)===true
    &&($class['source_file_archive_trailing_bytes']??-1)===0,
  'source original UClass default chain 3 actual UAnyProperty local declarations and fully consumed archive '.$variant);
 $report=$class['default_property_declaration_validation']??[];
 $ck(($report['status']??null)==='all_available_declarations_match'
  &&($report['known_incompatible_tags_skipped']??-1)===0
  &&($report['declaration_type_and_array_index_matches']??0)===3,
  'real Source GetID Vector tag alias 11->UAnyProperty no false vector mismatch/skip '.$variant);
 $tags=$class['default_property_tags']??[];
 $ck(count($tags)===3 && count(array_filter($tags,static fn($t)=>
  $t['native_load_policy']==='native_227k_resolved_AnyProperty_SerializeItem'))===3,
  'real local source linked All Three Any tags individually resolved, no heuristic bare Vector decode '.$variant);
 $match=null;
 foreach($tags as $tag)if($tag['name']===$case['tag'])$match=$tag;
 $ck(is_array($match)
  &&($match['type']??null)==='VectorProperty'
  &&($match['type_id']??null)===11
  &&($match['value_offset']??-1)===$case['offset']
  &&($match['serialized_size']??-1)===2
  &&($match['on_disk_value_bytes']??-1)===2,
  'genuine 227k UAny tagged FPropertyTag TYPE11 precise source value offset and archive2 bytes '.$variant);
 $payload=substr((string)file_get_contents($file),$case['offset'],2);
 $ck(bin2hex($payload)===$case['hex'],'exact original signed compact linker value bytes '.$variant.' '.bin2hex($payload));
 $decoded=$match['native_227k_source_resolved_Any_decoded']??[];
 $ck(($decoded['original_any_type_id']??null)===$case['type']
  &&($decoded['serialized_byte_count']??null)===2
  &&($decoded['raw_archive_bytes_hex']??null)===$case['hex']
  &&($match['value_kind']??null)===$case['kind'],
  'current real source-linked native subtype and byte consumption equal original Core.dll '.$variant);
 $value=$decoded['value']??[];
 $ck($case['type']===5
   ? (($value['name_index']??null)===8&&($value['resolved_text']??null)===$case['resolved'])
   : (($value['object_reference']??null)===-2&&($value['resolved_object']??null)===$case['resolved']),
  'real original ULinkerLoad name/ImportMap object identity translated without fake addresses '.$variant);
 $ck(A::saveVerified($case['type'],substr($payload,1),S::UNREAL_V227,$pkg)===$payload,
  'source-linked original 227k FAnyProperty SAVE reproduces original UCC compiled exact2 byte linker field '.$variant);
 $log=$readLog('20261030-G3-B04-ANY-original-2026-'.($case['type']===5?'NAME5':'OBJECT7').'-native-LOAD.log');
 $ck(str_contains($log,'ORIGINAL_C227K_ANY_NATIVE_LOAD package=')
  &&str_contains($log,'bad=0')
  &&str_contains($log,'ORIGINAL_C227K_ANY_NATIVE_CLASS')
  &&str_contains($log,'ORIGINAL_C227K_ANY_NATIVE_CDO'),
  'actual real compiled C227k UObject::LoadPackage and GetDefaultObject succeeded independently '.$variant);
 $ck(str_contains($log,'ORIGINAL_C227K_ANY_NATIVE_FIELD field='.$case['tag'].' offset='.$case['nativeOffset'])
  &&str_contains($log,'type='.$case['type']),
  'original genuine original Core.dll live source field UAnyProperty runtime address offset/type '.$variant);
 $ck(str_contains($log,'ORIGINAL_C227K_ANY_NATIVE_TEXT label='.$case['tag'].' value='.$case['resolved']),
  'original genuine native separately loaded actual original FName/UObject full text exactly identical to reader '.$variant);
 $ck(count(array_filter($tags,static fn($t)=>$t['value_kind']==='native_any_null'))===2,
  'two other original Any defaults are actual NULL, no fabricated Vector xyz '.$variant);
 $compilerLog=$readLog('20261030-G3-B04-ANY-original-2026-'.($case['type']===5?'NAME5':'OBJECT7').'-UCC.log');
 $ck(str_contains($compilerLog,'Saving package...')&&str_contains($compilerLog,'0 warning(s)')
  &&str_contains($compilerLog,'G3Any227KActor'),
  'actual 2026 original shipped UCC compiler accepted source without warnings '.$variant);
}
$np=new P($source.'G3Any227K-original-2026-NonzeroNAME.u');
$op=new P($source.'G3Any227K-original-2026-NonzeroOBJECT.u');
$ck(($np->getNames()[8]['name']??null)==='G3NameAlpha',
 'genuine actual 227k NameMap index8 resolved by native Core.dll');
$imports=$op->getImports();
$ck(($imports[1]['objectNameText']??null)==='Actor'
 &&($imports[1]['classNameText']??null)==='Class'
 &&($imports[1]['outerIndex']??null)===-3
 &&($imports[2]['objectNameText']??null)==='Engine'
 &&($imports[2]['classNameText']??null)==='Package',
 'genuine original 227k import -2 Class Actor owning original import -3 Package Engine');
foreach([
 ['name',"\x05\x3f",$np,'invalid name index'],
 ['object',"\x07\xbf",$op,'invalid linker object reference']
] as [$kind,$raw,$pkg,$message]){
 $pos=0;$read=static function(int $n)use($raw,&$pos):string{$s=substr($raw,$pos,$n);$pos+=strlen($s);return $s;};
 $error='';try{A::readFromArchive($read,$pos,S::UNREAL_V227,$pkg);}catch(Throwable $e){$error=$e->getMessage();}
 $ck(str_contains($error,$message),'actual source-specific invalid name/object reference fails closed, not native guessed behavior '.$kind);
}
$pos=0;$raw="\x06\x01";$read=static function(int $n)use($raw,&$pos):string{$b=substr($raw,$pos,$n);$pos+=strlen($b);return $b;};
$error='';try{A::readFromArchive($read,$pos,S::UNREAL_V227,$np);}catch(Throwable $e){$error=$e->getMessage();}
$ck(str_contains($error,'positive UObject class identity not verified'),
 'invalid Any6 positive-export UStruct reference remains fail-closed; genuine native Vector6 now separately verified as T030');
$ok=$fail===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$count,'failures'=>$fail,
 'full_frozen_g3_ids'=>['T029'=>'PASS','T031'=>'PASS'],
 'T030'=>'BLOCKED_original_227k_UCC_Any6_ImportText_failed',
 'native_source'=>'genuine original 2026 shipped UCC compiled and original Core.dll CDO loaded independently',
 'T029'=>['original_loaded_name'=>'G3NameAlpha','source_name_map_index'=>8,'native_type'=>5,'true_source_wire'=>'0508','serialized_bytes'=>2],
 'T031'=>['original_loaded_object'=>'Class Engine.Actor','source_import_map_ref'=>-2,'native_type'=>7,'true_source_wire'=>'0782','serialized_bytes'=>2],
 'previously_accepted_original_native_engine_executions_repeated'=>0,
 'overall_G3_complete'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
