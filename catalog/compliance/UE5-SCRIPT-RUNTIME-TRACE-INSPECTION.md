# UE5.8.3 runtime trace inspection and editor installation incident

**Original audit scope remains unchanged:** 18 operations, 14 source-defined operations verified, four partial (`UE5-583-SCRIPT-003`, `UE5-583-SCRIPT-004`, `UE5-583-SCRIPT-005`, `UE5-583-B015-1`); 29 known source/UnrealDB differences. **None of the present work closes a runtime operation.**

## Confirmed installed engine and explicit limitation

On DEV (`game-amd`), Epic Games Launcher reports a genuine `H:\Epic Games\UE_5.8` **UE5.8.3, CL 58210709, ++UE5+Release-5.8**, with `UnrealEditor-Cmd.exe`. Its `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` SHA-256 **exactly matches** our existing pinned source `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`.

This corrects the earlier statement that there is no runnable editor: there is a *binary-installed* UE5.8.3 editor on DEV, separate from the unbuilt source checkout under `L:\Source\Engine\UE5\UE 5.8.3`. The unmodified binary does **not** contain our optional `UEDB_RT|` instrumentation, so running it alone cannot expose private `FGlobalImportStore` state or close runtime compliance gates.

**Installation incident (17 October 2026):** During an attempted read-only startup diagnostic, an invocation with `-run=ResavePackages -help` unexpectedly **executed the resave commandlet**. It was stopped. The second-run log contains **358 confirmed successful asset saves** and a subsequent scan found **757 Engine/Content files with modification timestamps** in the test window (approximately 90 MiB). The 757 assets were copied and independently SHA256-checked **757/757 matching their post-incident installed state** at:

- `D:\Temp\uedb5-ue583-runtime\pre-repair-content-snapshot`
- `D:\Temp\uedb5-ue583-runtime\engine-content-modified-since-audit-start.csv`
- `D:\Temp\uedb5-ue583-runtime\snapshot-byte-integrity.csv`
- `D:\Temp\uedb5-ue583-runtime\ue583-live-abs.log`

These backups contain **post-incident content**, not authenticated original Epic assets. We did **not** restore from them or claim they are clean. No UE5 engine-source file was modified; no plugin `.uasset`/`.umap` showed a modified timestamp in that same window. **Epic Games Launcher → Unreal Engine → Library → UE 5.8 → Verify** is required before further use of this installation. There is no independently observed completion of Launcher Verify in this checkpoint. No further UE binary commandlets should be run until verification has been confirmed. Do not put the raw runtime log or modified asset snapshot into GitHub.

## Fixed probe output

`catalog/bin/prepare-ue5-script-runtime-trace.php` still produces the same 15 source insertions into a **separate disposable patch** and refuses to modify the canonical Epic source. One genuine trace-output deficiency was corrected: all nine optional logged path expressions now use `UObject::GetPathName()`, not `GetFullName()`. `GetFullName()` includes the UObject class and would not be an exact input to `FPackageObjectIndex::FromScriptPath` hashing. Our path index checker relies on exact class-free source paths. A 16-check regeneration/determinism/patch-application test must remain passing after this change.

## Inspect a captured log (no UE process required)

`catalog/bin/inspect-ue5-script-runtime-trace.php` accepts only a local text file (up to 128 MiB) and reads the `UEDB_RT|` messages without altering anything:

```powershell
& 'D:\php8.5\php.exe' 'C:\Users\arden\source\repos\ut_reader\catalog\bin\inspect-ue5-script-runtime-trace.php' '--log=D:\Temp\uedb5-ue583-runtime\ue583-live-abs.log'
```

**Important:** That real but **un-instrumented** installed-engine startup log contains **zero `UEDB_RT` events**. The inspector returns `ok=false`, `events_seen=0`, `runtime_origin_attested=false`, and a nonzero exit code. This is the correct rejection, not failed engine compliance.

When a source-instrumented, verified disposable engine build becomes available, the inspector checks:

- Exact event and field schemas for lookup, add, remove_gc, add_cell, name, eligible_package, excluded and registration_complete; strict 16-nibble typed index representation, source hash, one completion marker, and build flags.
- **Independent original-B013 CityHash64 / UTF16LE script path hashing:** verifies that logged script package and object paths compute exactly the full 64-bit ScriptImport indices emitted by Epic. FullName/class-prefixed paths, mistyped indices and mismatched path/hash identities fail closed.
- Epic script/Verse VNI package category rules and editor versus non-editor registration / cooked-debug mode consistency.
- Live `FindRef` success separately from diagnostic `GetImportName` fallback. Cooked name **never** establishes a live UObject. Verse VM cells remain distinct from registered UObjects.
- Duplicate index identities, without pretending to resolve Epic's in-memory map precedence. GC removals are included for separate lifecycle review rather than assuming a prior lifetime from a snapshot.
- Report output **excludes all raw UObject paths**. It returns event counts, source marker checks, anonymized errors and the SHA-256 of the log.

An `ok=true` report certifies **only the internal shape of an untrusted text trace**, not the identity of the running binary, provenance of its logs, nor compliance. Every report sets `runtime_origin_attested=false`, `live_engine_behavior_independently_verified=false`, and `original_operation_dispositions_unchanged=true`. There is deliberately no `--accept-live` or automatic operation-closure option.

`catalog/bin/verify-ue5-script-runtime-trace-inspection.php` has **25 independent regression checks** covering a constructed good-looking synthetic log, wrong hashes, malformed and duplicate fields, false engine hashes, missing/duplicated completion, case-sensitive package classification, editor/debug inconsistencies, collisions, privacy, and refusal to certify either synthetic or uninstrumented real logs. The existing 16-check source-patch preparation verifier remains separate. Synthetic logs must **never** be cited as evidence of an actual engine run.

## Remaining acceptance

1. Run **Epic Launcher Verify** for the existing UE5.8.3 installation; do not claim it complete until independently observed.
2. Build a **disposable instrumented** engine matching the pinned source (not the production Unreal Editor binary), using the prepared source patch, and exercise explicit editor, non-editor check-only, cooked-debug and no-debug branches.
3. Capture full `UEDB_RT` traces and physical binary/build hashes, with independently corroborated launch provenance.
4. Inspect trace shape, then compare actual runtime behavior with B002/B003/B015 original source contracts, raw import identities and negative cases; only then evaluate whether each **original** operation can be formally closed.

No metadata backfill, package rescan, production MySQL write, UnrealDB runtime parser alteration, or UE installation repair was performed by the present trace-inspection work.
