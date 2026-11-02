#!/usr/bin/env php
<?php
/**
 * Distinct G3-04 compiled 2026 Unreal v227k malformed FArchive::Tell
 * acceptance. Does not rerun original SAVE/LOAD or other native trace.
 * Original actual DLL, one-byte-mutated native package, CDB command,
 * compiled disassembly and numerical stack/register transcript pinned.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;

$root=dirname(__DIR__,2);
$ev=$root.'/catalog/compliance/evidence/o06/';
$path=$ev.'20261027-G304FunctionStateBadNet.u';
$checks=0;$fails=[];
$assert=static function(bool $ok,string $what)use(&$checks,&$fails):void{
    ++$checks;if(!$ok)$fails[]=$what;
};
$pinned=[
 'C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll'=>'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194',
 'C:/Temp/UnrealDB/O06/Native-227k/System/G304FunctionStateBadNetLoad.exe'=>'405e734b3b515c79da10d7aa29f04a5c21975a079c1f2b14395c7f8ea6dd7381',
 $path=>'39d31b8726fa99a3e80c491dd369c250f58ed9748d2017ce21e61a757661bcb1',
 $ev.'20261027-G304FunctionStateFixture.u'=>'1a9a98e156c15d66cf9c12e58110b50a622e7e965e24d1586b9b2dedcc4edb63',
 $ev.'20261028-g3-04-c227k-wrapped-boundary-actual-tell.cmd'=>'358a6fa27bdae447cc1ee932f2838fc9faf46ded275de9d9eea4d136e4830663',
 $ev.'20261028-g3-04-c227k-wrapped-boundary-actual-tell.log'=>'7718289c6883ea0c78c26fd1c2d502c41c402851eb26c3668168baf7346be76b',
 $ev.'20261028-g3-04-c227k-export-bounded-archive-disasm.log'=>'5f92f5a4a90289f89a45c06667404c06dab6d1bd4399f57cf661773208cd6b70',
];
foreach($pinned as $file=>$sha){
    $assert(is_file($file)&&hash_file('sha256',$file)===$sha,'original SHA: '.basename($file));
}
$log=file_get_contents($ev.'20261028-g3-04-c227k-wrapped-boundary-actual-tell.log');
$cmd=file_get_contents($ev.'20261028-g3-04-c227k-wrapped-boundary-actual-tell.cmd');
$dis=file_get_contents($ev.'20261028-g3-04-c227k-export-bounded-archive-disasm.log');
$assert(str_contains($cmd,'bp Core+0x611ff')&&str_contains($cmd,'bp Core+0x61208')
    &&str_contains($cmd,'bp Core+0x6129f'),'original debugger monitors underlying Tell, guard and actual read branch');
$assert(str_contains($dis,'call    dword ptr [eax+34h]')
    &&str_contains($dis,'cmp     eax,dword ptr [edi+20h]')
    &&str_contains($dis,'jle     Core!ULinkerLoad::Seek+0x19f'),'compiled DLL calls Tell, checks attempted end against declared export before read');
$assert(str_contains($log,'native=221 attempted_length=2')
    &&str_contains($log,'export_end=221'),'original compiled archive physical Tell = 221 before 2-byte read');
$assert(str_contains($log,'attempted_end=223 bound=221 length=2 tell_before=221'),
    'original compiled guard rejects 223 > 221 before advancing Tell');
$assert(str_contains($log,'Core!UFunction::Serialize+0xf4')
    &&str_contains($log,'Core!ULinkerLoad::Preload+0x163'),
    'native trace attributed to true loaded UFunction and ULinkerLoad Preload stack');
$assert(str_contains($log,'$t0=00000001')&&str_contains($log,'$t1=00000001'),
    'one rejected out-of-export read; earlier valid two-byte archive read allowed');
$assert(substr_count($log,'G304_ORIGINAL_227K_EXPORT_CHECK_DENIES_READ attempted_end=223')===1,
    'one and only one native denied attempted read in this case');
$assert(str_contains($log,'G304_ORIGINAL_227K_WRAPPED_ARCHIVE_CHECK_FINISHED'),
    'actual original compiled debugger run completed');
$head=new P($path);
$assert(($head->getHeader()['version']??null)===69
    &&($head->getHeader()['licenseeVersion']??null)===227,
    'original package selects Unreal game but does not uniquely identify compiled patch revision');
$assert(S::selectFromHeader($head->getHeader(),S::UNREAL_V227K)===S::UNREAL_V227,
    'explicit 227k compiled runtime overlay inherits Unreal 227 source archive family');
$assert((S::sourceLayers(69,227,S::UNREAL_V227K)['explicit_runtime_source_overlay']??null)===S::UNREAL_V227K,
    'selected patch-level runtime provenance is retained explicitly');
$read=static function(string $selected)use($path,$head):array{
    return N::inspect($path,$head,0,T::inspect($path,$head,0,$selected),$selected);
};
$validPath=$ev.'20261027-G304FunctionStateFixture.u';
$validPkg=new P($validPath);
$valid=N::inspect($validPath,$validPkg,0,
    T::inspect($validPath,$validPkg,0,S::UNREAL_V227K),S::UNREAL_V227K);
$assert(($valid['status']??null)==='native_function_record_complete'
    &&($valid['function_flags']??null)===2
    &&($valid['loaded_parms_size']??null)===0,
    'explicit 227k patched loader correctly decodes legitimate original engine SAVEd UFunction');
$actual=$read(S::UNREAL_V227K);
$assert(($actual['status']??null)==='native_export_serial_size_mismatch'
    &&($actual['native_compiled_export_check']??null)==='227k_before_read',
    'product reader enforces only positively selected original 227k archive boundary');
$assert(($actual['archive_cursor']??null)===221
    &&($actual['expected_export_end']??null)===221,
    'reader physical archive cursor equals true compiled native Tell and export end');
$assert(($actual['attempted_archive_end']??null)===223
    &&($actual['serial_size_overrun_bytes']??null)===2,
    'attempted end tracked separately from physical archive Tell');
$assert(($actual['extension_bytes']??null)===0
    &&($actual['source_serial_size_check_failed']??null)===true,
    'original rejection exposes no falsely consumed extension bytes');
$assert(!isset($actual['function_flags'])&&!isset($actual['rep_offset'])
    &&!isset($actual['source_function_loaded_fields_computed']),
    'invalid partial UFunction cannot masquerade as successfully loaded metadata');
$generic=$read(S::UNREAL_V227);
$assert(!isset($generic['native_compiled_export_check'])
    &&($generic['archive_cursor']??null)===223,
    'unverified v227 loader is not silently claimed to use the 2026 compiled pre-read guard');
$other=$read(S::UNREAL_V227I);
$assert(!isset($other['native_compiled_export_check']),
    'explicit 2012 v227i remains isolated from 2026 v227k compiled branch');
$pass=$fails===[];
echo json_encode(['ok'=>$pass,'checks'=>$checks,'failed'=>$fails,
    'true_native_Tell'=>221,'attempted_read_end'=>223,'export_end'=>221,
    'build'=>'original compiled 2026 227k x86 Core.dll only',
    'other_original_native_suites_reexecuted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($pass?0:1);
