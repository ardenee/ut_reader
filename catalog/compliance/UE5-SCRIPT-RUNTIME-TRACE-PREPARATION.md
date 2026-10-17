# UE5.8.3 script runtime evidence — opt-in source trace preparation

**Scope:** the same four original partial operations only: `UE5-583-SCRIPT-003`, `UE5-583-SCRIPT-004`, `UE5-583-SCRIPT-005` and `UE5-583-B015-1`. This is an **evidence-capture facility**, not an Epic compliance certificate, a new B batch, or an identified production parser defect. Verified count remains **14/18**, seven open evidence gates.

## Why this exists

The pinned UE5.8.3 checkout at `L:\Source\Engine\UE5\UE 5.8.3` contains source but no built editor. A separate Epic Launcher UE5.8.3 installation **does** exist at `H:\Epic Games\UE_5.8`, with `AsyncLoading2.cpp` matching our pinned source byte-for-byte. However, that installed editor is not instrumented and its **Engine/Content integrity verification is still outstanding** after an unintended asset-resave commandlet; do not run it until Launcher Verify is confirmed. See [UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md](UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md). The previous 7-function native execution used test doubles and cannot establish a genuine `FGlobalImportStore` state. This facility only generates a patch for a future **disposable, instrumented build**; it never patches the pristine source itself.

Source pin: `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, SHA-256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`.

## Prepare the reversible patch (DEV)

From PowerShell, choose a temporary directory outside both the Unreal Engine source and UnrealDB repository:

```powershell
$env:UNREALDB_EPIC_SOURCE_ROOT = 'L:\Source\Engine\UE5\UE 5.8.3'
New-Item -ItemType Directory -Force C:\Temp\uedb5-ue5-script-trace | Out-Null

& 'D:\php8.5\php.exe' 'C:\Users\arden\source\repos\ut_reader\catalog\bin\prepare-ue5-script-runtime-trace.php' '--output-dir=C:\Temp\uedb5-ue5-script-trace'

& 'D:\php8.5\php.exe' 'C:\Users\arden\source\repos\ut_reader\catalog\bin\verify-ue5-script-runtime-trace-preparation.php'
```

Outputs are **three temporary artifacts**, not checked into Git:

- `AsyncLoading2.script-runtime-probe.patch`: ordinary relative-path unified Git patch for `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`.
- `AsyncLoading2.instrumented.cpp`: the proposed full C++ file, for offline inspection and SHA comparison only.
- `script-runtime-probe-manifest.json`: original and proposed source fingerprints, 15 named probe insertions and the explicit `is_actual_ue_runtime_trace=false` flag.

The generator checks that the original UE source SHA matches, refuses an output directory inside the UE source checkout, allows **no auto-apply option**, and records no observed engine behavior. Its 16-check regression creates a disposable tree, runs `git apply --check` and `git apply` **only on that disposable copy**, validates the reconstructed LF-normalized C++ bytes, confirms the original source unchanged, checks deterministic generation and tests the rejection paths. Raw patched-file SHA can differ between CRLF and LF Windows copies, so the regression normalizes line endings for patch equality; the source input pin is always its **raw original SHA**.

## Instrumentation events

Every injected logging statement begins `UEDB_RT|` and uses Epic's existing `UE_LOGF(LogStreaming, Display, ...)`. The injection is in a separately generated patch; no parser/upload/SQL code is changed. Its 15 stable insertion sites cover:

| Event | Epic location | Evidence offered if executed in real UE |
|---|---|---|
| `lookup` | `FindScriptImportObject` | Typed ScriptImport index and whether `ScriptObjects.FindRef` returned an actual UObject |
| `add` (initial, editor package, nested public) | `AddScriptObject` | Actual registered index/path after insertion; distinguish nested public objects |
| `remove_gc` | `RemoveUnreachableScriptObjects` | Identity removed when the actual UObject became unreachable |
| `add_cell` | `AddScriptCellPackage` | Distinct Verse VM `ScriptCells` map entry; not proof of a script UObject |
| `name` (live / cooked diagnostic / missing) | `GetImportName` | Actual branch selection; **cooked diagnostic name never establishes a live UObject** |
| `eligible_package` (script / Verse VNI) | `FindAllRuntimeScriptPackages` | Actual `PKG_CompiledIn` package accepted by source prefix tests |
| `add` (enumerated editor package / public object) | `FindAllScriptObjects` | Editor-only enumerated registration; **not** initial `NRP_Finished` |
| `excluded` (non-UHT Verse VNI) | `FindAllScriptObjects` | Compiler-generated Verse VNI public object filtered out |
| `registration_complete` | `RegistrationComplete` | UE source fingerprint embedded in the log, `WITH_EDITOR` / `DO_CHECK` / cooked-debug compile flags, and actual stored entry count |

`UE_LOGF` output may be large and contain game or object paths. It must be captured locally and treated as potentially private diagnostic data, not automatically uploaded. Instrumented compilation must be used **only in a disposable test build**, not a production gameplay release; event logging costs CPU and I/O.

## Actual runtime capture remains a separate manual/controlled operation

When a full UE5.8.3 build toolchain and runnable editor/test target are available, inspect the generated patch first. **Only if intentionally instrumenting an isolated source/build checkout**, back up the exact C++ file, run `git apply --check` from the engine root, apply the patch, compile and run a controlled test, collect real `UEDB_RT|` lines along with exact build flags and engine commit/source hashes, then restore the source from the backup. Never apply the patch over modified/different Epic source, copy the instrumented C++ into UnrealDB, or deploy it with the PHP application.

The real capture must exercise editor registration and public nested objects, `NRP_Finished`, non-editor check-only missing-registration warnings, live versus missing `FindRef`, compiled cooked diagnostics and non-debug mode, GC removal and distinct Verse VNI UHT/ScriptCells branches. The presence of a `registration_complete` log by itself does **not** close any original operation. The observed events must be analyzed against the exact original source, prior B002/B003/B015 source contracts and independent B013 path hashes. Until those observations exist and pass, **all four original operations remain partial**.

## Files of record

- Generator: `catalog/bin/prepare-ue5-script-runtime-trace.php`
- Regression: `catalog/bin/verify-ue5-script-runtime-trace-preparation.php` (**16** checks)
- Earlier independent native source execution: `catalog/compliance/B015-NATIVE-SCRIPT-SOURCE-EXECUTION.md`
- Original partial operations: `catalog/compliance/batches/B002-ue5-script-consumers.json`, `B003-ue5-runtime-script-registration.json`, `B015-ue5-script-registration-recursion.json`

**Updated probe path contract:** nine trace log paths now use \`GetPathName()\` rather than class-prefixed \`GetFullName()\`, permitting independent B013 ScriptImport hash verification by the read-only inspector documented in [UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md](UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md). Do not apply the probe to the installed engine without a disposable build and prior Launcher Verify.
