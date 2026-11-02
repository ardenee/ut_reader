# Epic UE5.8.3 — native script-registry source execution without a UE runtime

**Checkpoint:** Source-extracted C++ branch substitute. **Not** a running Unreal Engine/editor, not a captured `FGlobalImportStore` session, not runtime parity certification. This evidence applies to the four pre-existing partial original operations `UE5-583-SCRIPT-003`, `UE5-583-SCRIPT-004`, `UE5-583-SCRIPT-005` and `UE5-583-B015-1`. Their dispositions remain **partial** and the original 18-operation denominator is unchanged. It does not introduce an additional difference identifier.

## Why this was built

No `UnrealEditor.exe` or `UnrealEditor-Cmd.exe` exists in the checked **UE5.8.3** source tree. A separate UE5.8.3 Launcher binary was later found on DEV, but it was not built with our instrumentation; after an unintended asset resave, its Engine/Content requires Launcher Verify before any further runs. The authorized PRD machine has Satisfactory Dedicated Server installations, but those binaries cannot substitute for a source-identical UE5.8.3 editor or provide authoritative access to its private `FGlobalImportStore` maps. The existing synthetic PHP test suite interpreted the source. The present test strengthens it by **executing the original Epic C++ function bodies** with tiny stand-in engine types in an independent native executable.

## Exact source extraction and method

`catalog/bin/verify-uedb5-b015-native-source-execution.php` reads **exact pinned** Epic `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`. It finds and copies the source bodies, unmodified, for these **seven functions**:

1. `FindAllRuntimeScriptPackages(FRuntimeScriptPackages&)` — compiled-in `UPackage` and case-sensitive script/Verse VNI selection.
2. `FGlobalImportStore::FindAllScriptObjects(bool)` — editor package keys, nested `RF_Public` object keys, UHT-generated Verse VNI filter, non-editor check-only.
3. `FGlobalImportStore::RegistrationComplete()` — editor add, check-only verify, no-enumeration branch and optional debug trim.
4. `FGlobalImportStore::FindScriptImportObject(FPackageObjectIndex) const` — actual `ScriptObjects.FindRef` on the stand-in map.
5. `FGlobalImportStore::AddScriptObject(FStringView,FStringView,UObject*)` — initial registration plus public nested object traversal.
6. `FGlobalImportStore::RemoveUnreachableScriptObjects()` — independently removes unreachable entries, without removing still-reachable public children.
7. `FPackageImportStore::GetImportName(...)` — present live UObject FName wins over cooked diagnostic; missing live UObject uses cooked diagnostic only with `ALT2_ENABLE_SCRIPT_IMPORTS_DEBUGGING`, else returns empty.

The unchanged extracted bodies are placed in `catalog/tests/fixtures/ue5_script_runtime_native_harness.cpp.tpl`, which contains a small, auditable set of **mock-only** `UPackage`, `UObject`, `FPackageObjectIndex`, global ScriptObjects map, logging macros, Verse UHT predicate and script-debug dictionary. Those doubles deliberately **do not recreate engine identity hashing**: the separate verified B013 native oracle tests the real Epic CityHash64 and UTF-16/UTF-8 hashes against authentic Satisfactory object identity. The mock index is keyed by full name solely to isolate branch behavior.

The test compiles a **fresh disposable C++ binary for each configuration**, using native GNU C++ available on the authorized machine. Neither extracted Epic source nor executables are persisted to the repository. The temporary build tree is removed on completion. Both source and template SHA-256 values are pinned so changing branches or doubles fails the test.

## Executed branches

| Configuration | Result against original Epic C++ functions |
| --- | --- |
| `WITH_EDITOR=1, DO_CHECK=1, DEBUG=1` | 5 entries: two compiled-in package keys and three eligible public object keys, including nested public and UHT-generated Verse VNI |
| `WITH_EDITOR=0, DO_CHECK=1, DEBUG=1` | 0 entries, three missing-initial-registration warnings: **verification does not repair registration** |
| `WITH_EDITOR=0, DO_CHECK=0, DEBUG=0` | 0 entries, 0 warnings: no source enumeration in shipping-style branch |
| `WITH_EDITOR=0, DO_CHECK=1, DEBUG=0` | 0 entries, three warnings; no diagnostic trim |

All four independently assert the original source registration/lookup/GC behavior: initial `AddScriptObject` creates a live-map entry and public nested entry but not private descendant; `FindScriptImportObject` sees the initial registration; `GetImportName` chooses the live UObject's name over a deliberately contradictory cooked record, returns a cooked fallback for a **missing** object only when debugging is compiled, and never resolves a missing UObject merely from its diagnostic name; `RemoveUnreachableScriptObjects` removes a now-unreachable registered object while preserving a reachable nested public child.

The source verifier reports every exact body extracted and each C++ variant successfully compiled/executed. `catalog/bin/verify-uedb5-b015-runtime-script-enumeration-acceptance.php` requires this native driver as part of its **six-driver, 18-assertion** boundary acceptance; the bounded UE5 suite also executes the native driver independently.

## Still missing before closing any original operation

All objects and package flags in this harness are **test doubles**; the keys returned by its stand-in map are **not** Epic's actual runtime `TAsyncLoadingStripedMap` entries. The test does not execute `FAsyncLoadingThread2`, real `NRP_Finished` callbacks or `Verse::VCell` lifecycle; it does not load cooked Satisfactory packages under the pinned UE5.8.3 runtime. Engine startup flags, GC scheduling, UHT classifier behavior and map ownership in an actual editor/game binary remain unobserved. The build macros exercised here select Epic's source branches inside the substitute, but they are not compiler output of the full engine.

**Next actual closure step:** build/run the pinned Epic UE5.8.3 source and capture authoritative live `FGlobalImportStore` events, keys and `GetImportName` results across editor, non-editor check, and cooked-debug modes, including registration and GC transitions, then compare those traces against these source-executed predictions. No claim of 18/18 compliance is made, and no production V5 metadata, MySQL tables or game files were changed.

**Next capture facility:** \`catalog/compliance/UE5-SCRIPT-RUNTIME-TRACE-PREPARATION.md\` generates a reversible original-source probe patch without altering Epic's checkout. No live runtime acceptance has been performed.
