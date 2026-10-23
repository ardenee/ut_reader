#!/usr/bin/env php
<?php
/** DEV-only sealed native UT99 v432 oracle observation.
 * Compares every loaded TextBuffer UObject path to genuine Engine.u ExportMap.
 * No executable runs during this verifier. No PRD test or disk write.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
$src='C:/Temp/UnrealDB/O06/Native-UCC/System';
$trace='C:/Temp/UnrealDB/O06/Native-UCC/NativeEngineTextBufferLoad.log';
$expect=[
 'Engine.u'=>'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695',
 'ucc.exe'=>'90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f',
 'Core.dll'=>'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
 'Engine.dll'=>'f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac',
 'Editor.dll'=>'404a394b7edc4b759e3d30d3e7e5e90fe0af48d29a69cad1e71bfb057f0fa01e'
];
$checks=[];$errors=[];
$check=static function(string $id,bool $ok)use(&$checks,&$errors):void{
 $checks[$id]=$ok;if(!$ok)$errors[]=$id;
};
foreach($expect as $name=>$hash){
 $path="$src/$name";
 $check("native_fixture_integrity_$name",is_file($path)
     && hash_file('sha256',$path)===$hash);
}
$check('frozen_native_log_sha256',
 is_file($trace)&&hash_file('sha256',$trace)===
 '4c1a0139fd9cf56dbac332295e1fbdfd4a7025f2125ae089b48dbbacf908184e');
$log=(string)@file_get_contents($trace);
$check('native_engine_432_identified',str_contains($log,'Init: Version: 432'));
$check('native_compiled_LoadPackage_Engine',str_contains($log,'Loading package Engine.u...'));
$check('native_compiled_cleanup_completed',str_contains($log,'Exit: Object subsystem successfully closed.')
 &&str_contains($log,'Exit: Exiting.'));
$check('native_compiled_zero_errors_warnings',str_contains($log,'Success - 0 error(s), 0 warnings')
 &&!str_contains($log,'Critical:')&&!str_contains($log,'Assertion failed'));
preg_match_all('/^Log: Exported TextBuffer (\S+) to /m',$log,$matches);
$observed=$matches[1];$check('native_has_88_TextBuffer_exports',count($observed)===88);
$check('native_has_88_unique_export_paths',count(array_unique($observed))===88);
$package=new Package("$src/Engine.u");
$check('genuine_Engine_package_structure_valid',$package->validatePackage()===[]);
$exports=$package->getExports();$imports=$package->getImports();
$predicted=[];$badPaths=[];
foreach($exports as $ex){
 $cls=(int)($ex['classIndex']??0);
 if($cls>=0||strcasecmp((string)($imports[-$cls-1]['objectNameText']??''),'TextBuffer')!==0)continue;
 $segments=[(string)$ex['objectNameText']];
 $outer=(int)($ex['outerIndex']??0);$visited=[];
 while($outer>0){
  if(isset($visited[$outer])||count($visited)>32){$badPaths[]=$ex['index'];break;}
  $visited[$outer]=true;
  $parent=$exports[$outer-1]??null;
  if(!is_array($parent)){$badPaths[]=$ex['index'];break;}
  array_unshift($segments,(string)$parent['objectNameText']);
  $outer=(int)$parent['outerIndex'];
 }
 if($outer<0){$badPaths[]=$ex['index'];continue;}
 array_unshift($segments,'Engine');
 $predicted[]=implode('.',$segments);
}
$check('all_TextBuffer_outer_chains_source_valid',$badPaths===[]);
$check('source_export_count_88',count($predicted)===88);
$nativeSet=array_count_values(array_map('strtolower',$observed));
$readerSet=array_count_values(array_map('strtolower',$predicted));
$missing=array_diff_key($readerSet,$nativeSet);
$extra=array_diff_key($nativeSet,$readerSet);
$check('all_88_native_object_paths_match_reader',$nativeSet==$readerSet
 &&$missing===[]&&$extra===[]);
// Verify one REAL decoded UTextBuffer value against the compiled native
// exporter output. Epic v400 Editor/Src/UnEdExp.cpp trims leading/trailing
// CR/LF/space before Ar.Log(Start): trailing bytes must not be misdiagnosed.
$exporterSource='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Editor/Src/UnEdExp.cpp';
$srcText=(string)@file_get_contents($exporterSource);
$check('Epic_v400_exporter_trims_only_CRLF_and_spaces',
 str_contains($srcText,'UTextBufferExporterTXT::ExportText')
 &&str_contains($srcText,'while( Start<End &&')
 &&str_contains($srcText,'while( End>Start &&')
 &&str_contains($srcText,'Ar.Log( Start );'));
$lastObject=end($observed);
$check('native_last_export_ClipMarker_script',
 $lastObject==='Engine.ClipMarker.ScriptText');
$targetIndex=null;
foreach($exports as $ex){
 if((string)($ex['objectNameText']??'')!=='ScriptText')continue;
 $parent=(int)($ex['outerIndex']??0);
 if($parent<=0 || strcasecmp((string)($exports[$parent-1]['objectNameText']??''),'ClipMarker')!==0)continue;
 if($targetIndex!==null){$targetIndex=-1;break;}
 $targetIndex=(int)$ex['index'];
}
$check('local_ClipMarker_UTextBuffer_unique_export',is_int($targetIndex)&&$targetIndex>=0);
$nativeValue=null;$decodedText=null;
if(is_int($targetIndex)&&$targetIndex>=0){
 $prop=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect(
     "$src/Engine.u",$package,$targetIndex);
 $nativeValue=\UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader::inspect(
     "$src/Engine.u",$package,$targetIndex,$prop);
 $decodedText=(string)($nativeValue['text']??'');
}
$check('production_UTextBuffer_record_complete',
 ($nativeValue['status']??null)==='native_textbuffer_record_complete');
$exportFile='C:/Temp/UnrealDB/O06/Native-UCC/exports/EngineTextBuffer/ScriptText.txt';
$check('native_exported_value_file_sha256',
 is_file($exportFile)&&hash_file('sha256',$exportFile)===
 '7ee9596302f2944a8d3831cb3ab4eddfac15efccdb268b4837557451244fac57');
$nativeText=(string)@file_get_contents($exportFile);
$check('Epic_trim_matches_exact_native_exported_text_bytes',
 $decodedText!==null
 &&strlen($decodedText)===830
 &&strlen($nativeText)===826
 &&substr($decodedText,-4)==="\r\n\r\n"
 &&trim($decodedText," \r\n")===$nativeText);
$metadata=[
 'ok'=>$errors===[],'checks'=>count($checks),'passed'=>count($checks)-count($errors),
 'failed'=>$errors,'native_engine'=>'UT99 v432 installed DLLs/ucc.exe',
 'native_command'=>'ucc batchexport Engine.u TextBuffer txt C:\\Temp\\UnrealDB\\O06\\Native-UCC\\exports\\EngineTextBuffer',
 'log_sha256'=>hash('sha256',$log),'package_sha256'=>$expect['Engine.u'],
 'native_exported_count'=>count($observed),'php_exported_count'=>count($predicted),
 'matched_full_paths'=>count($predicted)-count($missing),
 'missing'=>array_keys($missing),'extra'=>array_keys($extra),
 'example_native_paths'=>array_slice($observed,0,4),
 'limitations'=>'Independent compiled v432 LoadPackage and UObject TextBuffer enumeration; no engine UClass property-value oracle, no compiled v400 equivalence, no full O06 acceptance.'
];
echo json_encode($metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
