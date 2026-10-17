<?php
declare(strict_types=1);

/**
 * Opt-in, reproducible source-instrumentation proposal for an actual Epic
 * UE5.8.3 run. Never modifies the canonical local Epic source checkout.
 *
 * php prepare-ue5-script-runtime-trace.php --output-dir=C:/Temp/trace-proposal
 */
$root = rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'), "/\\");
$relative = 'Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$original = $root.'/'.$relative;
$expected = '43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9';
$out = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output-dir=')) {
        if ($out !== '') throw new RuntimeException('Only one output directory is supported.');
        $out = substr($arg, strlen('--output-dir='));
    } else {
        throw new RuntimeException('Unknown option: '.$arg);
    }
}
if ($out === '' || !is_dir($out)) {
    throw new RuntimeException('Provide an existing writable --output-dir= directory (outside Epic source).');
}
if (!is_file($original) || hash_file('sha256', $original) !== $expected) {
    throw new RuntimeException('Epic UE5.8.3 source SHA does not match the pinned original. No proposal generated.');
}
$origDir = realpath(dirname($original));
$outputDir = realpath($out);
if (!$origDir || !$outputDir || str_starts_with(strtolower(str_replace('\\','/',$outputDir)).'/', strtolower(str_replace('\\','/',$root)).'/')) {
    throw new RuntimeException('Output directory must not be within the Epic source checkout.');
}
$before = str_replace("\r\n", "\n", (string)file_get_contents($original));
$code = $before;
$events = [];
$replace = static function(string $begin, string $end, string $needle, string $replacement, string $event)
    use (&$code, &$events): void {
    $p = strpos($code, $begin);
    if ($p === false) throw new RuntimeException('Missing function boundary: '.$begin);
    $q = strpos($code, $end, $p + strlen($begin));
    if ($q === false || $q <= $p) throw new RuntimeException('Missing end boundary: '.$end);
    $sub = substr($code, $p, $q-$p);
    if (substr_count($sub,$needle) !== 1) throw new RuntimeException('Ambiguous pinned injection site: '.$event);
    $sub = str_replace($needle, $replacement, $sub);
    $code = substr($code,0,$p).$sub.substr($code,$q);
    $events[]=$event;
};
$log = static fn(string $v): string => "\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|".$v."\");\n";
$replace('inline UObject* FindScriptImportObject(FPackageObjectIndex GlobalIndex) const',
    'Verse::VCell* FindPublicExportCell(',
    "\t\tObject = ScriptObjects.FindRef(GlobalIndex);\n",
    "\t\tObject = ScriptObjects.FindRef(GlobalIndex);\n"
    ."\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=lookup|index=%016llX|found=%d\", GlobalIndex.Value(), Object != nullptr);\n",
    'lookup');
$replace('void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)',
    'void RemoveUnreachableScriptObjects()',
    "\t\tScriptObjects.Add(PackageGlobalImportIndex, Object->GetOutermost());\n",
    "\t\tScriptObjects.Add(PackageGlobalImportIndex, Object->GetOutermost());\n"
    ."\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add|kind=editor_package|index=%016llX|path=%ls\", PackageGlobalImportIndex.Value(), *Object->GetOutermost()->GetFullName());\n",
    'add_editor_package');
$replace('void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)',
    'void RemoveUnreachableScriptObjects()',
    "\t\tScriptObjects.Add(GlobalImportIndex, Object);\n",
    "\t\tScriptObjects.Add(GlobalImportIndex, Object);\n"
    ."\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add|kind=initial_object|index=%016llX|path=%ls\", GlobalImportIndex.Value(), *Object->GetFullName());\n",
    'add_initial');
$replace('void AddScriptObject(FStringView PackageName, FStringView Name, UObject* Object)',
    'void RemoveUnreachableScriptObjects()',
    "\t\t\t\t\tScriptObjects.Add(SubObjectGlobalImportIndex, SubObject);\n",
    "\t\t\t\t\tScriptObjects.Add(SubObjectGlobalImportIndex, SubObject);\n"
    ."\t\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add|kind=nested_public|index=%016llX|path=%ls\", SubObjectGlobalImportIndex.Value(), *SubObject->GetFullName());\n",
    'add_nested_public');
$replace('void RemoveUnreachableScriptObjects()',
    'void Shrink()',
    "\t\t\t\tUE_LOGF(LogStreaming, Verbose, \"RemoveUnreachableScriptObjects: %ls\", *ScriptObject->GetName());\n",
    "\t\t\t\tUE_LOGF(LogStreaming, Verbose, \"RemoveUnreachableScriptObjects: %ls\", *ScriptObject->GetName());\n"
    ."\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=remove_gc|index=%016llX|path=%ls\", Entry.Key.Value(), *ScriptObject->GetFullName());\n",
    'remove_gc');
$replace('void AddScriptCellPackage(Verse::VPackage* Package)',
    'struct FAsyncPackageHeaderData',
    "\t\t\tScriptCells.Add(GlobalImportIndex, Cell);\n",
    "\t\t\tScriptCells.Add(GlobalImportIndex, Cell);\n"
    ."\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add_cell|index=%016llX\", GlobalImportIndex.Value());\n",
    'verse_cell_separate');
$replace('FString GetImportName(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)',
    'void GetUnresolvedCDOs(',
    "\t\t\treturn Import->GetFName().ToString();\n",
    "\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=name|branch=live|index=%016llX\", GlobalIndex.Value());\n"
    ."\t\t\treturn Import->GetFName().ToString();\n",
    'name_live');
$replace('FString GetImportName(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)',
    'void GetUnresolvedCDOs(',
    "\t\t\treturn GlobalImportStore.CookedScriptObjectsDebug.GetPathName(GlobalIndex);\n",
    "\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=name|branch=cooked_diagnostic|index=%016llX\", GlobalIndex.Value());\n"
    ."\t\t\treturn GlobalImportStore.CookedScriptObjectsDebug.GetPathName(GlobalIndex);\n",
    'name_cooked_diagnostic');
$replace('FString GetImportName(const FAsyncPackageHeaderData& Header, FPackageObjectIndex GlobalIndex)',
    'void GetUnresolvedCDOs(',
    "\t\treturn FString();\n",
    "\t\tif (GlobalIndex.IsScriptImport())\n"
    ."\t\t{\n\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=name|branch=missing|index=%016llX\", GlobalIndex.Value());\n\t\t}\n"
    ."\t\treturn FString();\n",
    'name_missing');
$replace('void FindAllRuntimeScriptPackages(FRuntimeScriptPackages& OutPackages)',
    '#ifndef ALT2_VERIFY_LINKERLOAD_MATCHES_IMPORTSTORE',
    "\t\t\t\tOutPackages.Script.Add(Package);\n",
    "\t\t\t\tOutPackages.Script.Add(Package);\n"
    ."\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=eligible_package|kind=script|path=%ls\", *Package->GetFullName());\n",
    'package_script');
$replace('void FindAllRuntimeScriptPackages(FRuntimeScriptPackages& OutPackages)',
    '#ifndef ALT2_VERIFY_LINKERLOAD_MATCHES_IMPORTSTORE',
    "\t\t\t\tOutPackages.VerseVNI.Add(Package);\n",
    "\t\t\t\tOutPackages.VerseVNI.Add(Package);\n"
    ."\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=eligible_package|kind=verse_vni|path=%ls\", *Package->GetFullName());\n",
    'package_verse_vni');
$replace('void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)',
    '#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING',
    "\t\t\t\t\tScriptObjects.Add(PackageGlobalImportIndex, Package);\n",
    "\t\t\t\t\tScriptObjects.Add(PackageGlobalImportIndex, Package);\n"
    ."\t\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add|kind=enumerated_editor_package|index=%016llX|path=%ls\", PackageGlobalImportIndex.Value(), *Package->GetFullName());\n",
    'add_enumerated_package');
$replace('void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)',
    '#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING',
    "\t\t\t\t\t\t\tScriptObjects.Add(GlobalImportIndex, Object);\n",
    "\t\t\t\t\t\t\tScriptObjects.Add(GlobalImportIndex, Object);\n"
    ."\t\t\t\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=add|kind=enumerated_public|index=%016llX|path=%ls\", GlobalImportIndex.Value(), *Object->GetFullName());\n",
    'add_enumerated_public');
$replace('void FGlobalImportStore::FindAllScriptObjects(bool bVerifyOnly)',
    '#if ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING',
    "\t\t\t\t\t\t\tcontinue;\n",
    "\t\t\t\t\t\t\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=excluded|reason=verse_vni_non_uht|path=%ls\", *Object->GetFullName());\n"
    ."\t\t\t\t\t\t\tcontinue;\n",
    'excluded_verse_vni');
$replace('void FGlobalImportStore::RegistrationComplete()',
    'void FAsyncPackage2::ImportPackagesRecursiveInner(',
    "\tScriptObjects.Shrink();\n",
    "\tScriptObjects.Shrink();\n"
    ."\tUE_LOGF(LogStreaming, Display, \"UEDB_RT|event=registration_complete|epic_sha=$expected|editor=%d|check=%d|debug=%d|count=%d\", WITH_EDITOR, DO_CHECK, ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING, ScriptObjects.Num());\n",
    'registration_complete');
if (count($events) !== 15) throw new RuntimeException('Incomplete instrumentation operation count.');
$file=$out.'/AsyncLoading2.instrumented.cpp';
$patch=$out.'/AsyncLoading2.script-runtime-probe.patch';
$manifest=$out.'/script-runtime-probe-manifest.json';
if (file_put_contents($file,$code)!==strlen($code)) throw new RuntimeException('Cannot write proposed C++ file.');
$process=proc_open(['git','diff','--no-index','--',$original,$file],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) throw new RuntimeException('git diff unavailable.');
fclose($pipes[0]);$diff=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);
$err=(string)stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
if ($exit!==1 || !str_contains($diff, '@@')) throw new RuntimeException('Cannot build diff: '.$err);
$diff=preg_replace('/^diff --git .*$/m', 'diff --git a/'.$relative.' b/'.$relative,$diff,1);
$diff=preg_replace('/^--- .*$/m','--- a/'.$relative,$diff,1);
$diff=preg_replace('/^\+\+\+ .*$/m','+++ b/'.$relative,$diff,1);
if (file_put_contents($patch,$diff)!==strlen($diff)) throw new RuntimeException('Cannot write opt-in patch.');
$details=[
    'ok'=>true,'operation_scope'=>[
        'UE5-583-SCRIPT-003','UE5-583-SCRIPT-004',
        'UE5-583-SCRIPT-005','UE5-583-B015-1'],
    'source_relative_path'=>$relative,'original_source_sha256'=>$expected,
    'proposed_source_sha256'=>hash('sha256',$code),
    'injections'=>$events,'trace_prefix'=>'UEDB_RT',
    'is_actual_ue_runtime_trace'=>false,'epic_source_modified'=>false,
    'patch_file'=>basename($patch),'instrumented_source_file'=>basename($file)
];
file_put_contents($manifest,json_encode($details,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($details,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
