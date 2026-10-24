# UE1 G5 multi-method package and compiled-oracle workflow

Implemented on DEV, 2026-10-24. FULL method acceptance still 0/256.

## Why this replaces repeated single-serializer scans

Before: inspect a package separately for each serializer and launch isolated native commandlets repeatedly. This produced duplicate package traversals and compiled-engine startup, but many partial test passes rather than fully accepted methods.

Now: construct ONE CatalogUE1PackageReader instance per authentic package, sharing its Name/Import/Export maps for ALL eight target methods. The same PHP worker uses existing bounded tagged-property and native-object readers to inspect six serializer types: TextBuffer, Font, SkeletalMesh, Animation, Sound and Music. The existing readers still seek to each bounded export range; this is not a claim of one physical disk read for every export. It removes redundant package-table and source discovery passes, without introducing a second parser or changing production source semantics. UObject/UClass entries are method candidates from export metadata, not asserted full independent runtime calls.

## Six genuine original source packages

- Engine.u (installed original UT99 v69): UClass/UObject/TextBuffer/Font and other candidates, 92 source-native records inspected.
- table.u (original PRD v69): skeletal/animation/text and metadata candidates.
- EcoTerror.u (original PRD v69): skeletal/animation/text and metadata candidates.
- d00d.u (original PRD v69): skeletal/animation/text and metadata candidates.
- DMatch.uax (original installed UT99 sound package, copied and SHA-matched to NVMe): 4 native sound records.
- Mission.umx (original installed UT99 music package, copied and SHA-matched to NVMe): 1 native music record.

The first completed multi-package run: six package contexts, 5,431 export rows, eight separate serializer-method acceptance queues, all 114 of 114 selected source-native payloads complete, 2,547,089 source archive bytes processed, zero production parser errors. These are not full independent native-loaded oracle results.

## One actual compiled native loader for three methods

A SINGLE real compiled v432 UCC commandlet package-load of untouched Engine.u under Microsoft CDB simultaneously captured:

- T99:UClass::Serialize: 90 native method-entry calls (Core.dll RVA 0x1203).
- T99:UTextBuffer::Serialize: 90 native method-entry calls (Core.dll RVA 0x31bb).
- T99:UFont::Serialize: 4 native method-entry calls (Engine.dll RVA 0x242d).

The CDB log and original DLL/package hashes are sealed in UE1-O06-G5-MULTIMETHOD-NATIVE-ONE-LOAD.json. Independent focused verifier verify-ue1-o06-g5-multimethod-native-one-load.php passed 11/11. Runtime-created native objects may add calls beyond file exports; dispatch counts are not a full field-value/reference/error oracle.

## Safe resume and fast scratch

On DEV:

    cd C:\Users\arden\source\repos\ut_reader
    python catalog\bin\run-ue1-o06-g5-multimethod.py
    python catalog\bin\run-ue1-o06-g5-multimethod.py --status
    powershell -NoProfile -ExecutionPolicy Bypass -File catalog\bin\run-ue1-o06-scoped.ps1 -Contract g5-multimethod-one-load-v432 -Tier focus

One verified SHA256/file-and-scanner keyed cache entry is stored for each package in C:\Temp\UnrealDB\O06\g5-multimethod. Unchanged archives are NOT parsed again. A modified package/scanner invalidates only its matching evidence. Each new package writes STARTED/PASS/FAIL into its own append-only discovery journal UE1-O06-G5-MULTIMETHOD-EVENTS.jsonl and updates the multi-method acceptance matrix after every completed package. This discovery journal is separate from the O06 independent test journal, so it cannot inflate native acceptance PASS numbers. The compiled single-load verifier is journaled separately in the canonical O06 test log.

Use NVMe C:\Temp\UnrealDB\O06\build for any future C++ temporary source/object/PDB/linker outputs; the Python worker sets TMP/TEMP/TMPDIR there. Explicitly pass output paths there to MSVC /Fo and /Fd, and LINK /OUT and /PDB. CDB, temporary fixture and input snapshots also remain on C:\Temp. No C++ recompilation was required for this workflow. Original Epic files, installed engine binaries and PRD files are untouched.

## Method acceptance policy

- UE1-O06-G5-MULTIMETHOD-PACKAGES.json lists immutable SHA-pinned inputs and eight simultaneous methods.
- UE1-O06-G5-MULTIMETHOD-ACCEPTANCE.json reports per-method candidate exports, complete production-reader native record counts, source archive bytes, independent previous native evidence classifications and remaining blockers.
- UE1-O06-G5-MULTIMETHOD-GATES.json defines seven independent sign-off criteria per method. A method can become ACCEPTED only after ALL criteria are VERIFIED, an individually reviewed closure artifact exists, and its SHA256 matches.
- UE1-O06-G5-MULTIMETHOD-NATIVE-ONE-LOAD.json captures independent same-load native method-entry evidence, without claiming complete parity.

The common UObject::Serialize prerequisite, UClass defaults, TextBuffer oversized-error byte-consumption divergence, Font variant/error cases, USkeletalMesh inherited ULodMesh, UAnimation other native variants and audio error semantics remain separately OPEN. Current fully accepted: 0/8 in the batch, 0/256 across G5. New genuine source packages can be added to the manifest without repeating unchanged package or compiled loaded-value passes.


## Current second batch — shared UObject and genuine positive StateFrame (2026-10-24)

The original six-package batch has been extended with the untouched installed UT99 version-68 Entry.unr map, SHA 4bd31b4af4195d257d4b100026c12c7ad511b000f3bc8653e4a34a30fbbf689c, copied to DEV NVMe. All seven immutable package contexts are cached. A second unchanged-input invocation returns **7/7 UNCHANGED_PACKAGE_SKIP** (no source re-reading).

Current seven-package totals: **5,469 exports**, eight method acceptance queues, **115/115 source native records complete**, **2,547,161 source native payload bytes**, zero source reader errors. The package-level scanner now also reuses the same reader to decode execution frames on RF_HasStack exports: **19/19 original Entry.unr StateFrames**, **285 serialized frame bytes**, **172 tagged properties**, zero errors. Previous Engine.u had no positive RF_HasStack export.

Two independently compiled-native oracle paths now exercise the *shared UObject::Serialize* in original UT99 v432:
- Engine.u: in ONE authentic compiled UCC package-load, native breakpoints observed 5,955 UObject::Serialize, 90 UClass::Serialize, 90 UTextBuffer::Serialize, 4 UFont::Serialize. Captured post-archive all 88 original TextBuffer and 4 Font exported object flags, genuine export identities, TextBuffer Pos/Top/length and common inherited property envelope. SHA-pinned actual CDB evidence in UE1-O06-G5-MULTIMETHOD-UOBJECT-LOADED-FLAGS.json; **564/564 independent focused checks PASS**, run 20261024T095321588Z-0849582f.
- Entry.unr: copied original dependencies Logo.utx and credits.utx to C:\Temp DEV NVMe; compiled original v432 UCC loaded the map and was stopped after the Actor T3D export command reported no exporter for LevelInfo (not a loader failure). At compiled UObject::Serialize Core.dll RVA 0x556b1, **14 actual positive RF_HasStack native FStateFrame objects** were captured with exact 64-bit ProbeMask, signed LatentAction, zero Code-pointer and genuine ExportMap linker identities, comparing every value against independent original on-disk PHP parsing. **152/152 focused checks PASS**, run 20261024T100737198Z-eac2b05f. Five of the original 19 positive frames were not reached by the compiled exporter. Native evidence and source/dependency SHAs in UE1-O06-G5-MULTIMETHOD-STATEFRAME-V68-NATIVE.json. A second native TextBuffer exporter invocation reached the same 14 records only; it has no separate acceptance claim.

These are positive/negative *source branch subsets* of the shared UObject method, but not full transitive native acceptance: five genuine StateFrames not exercised, Node/StateNode target object identities, error/byte-consumption differences and earlier UE1 source-family branches still OPEN. **Fully Epic-verified remains 0/8 in this batch, 0/256 across G5.**

The next work should target the explicit remaining five native frame objects, object reference identities and state-frame/error boundaries, then remove inherited blockers from UTextBuffer, UFont and UClass together. Keep CDB/temporary native files and all future compiled probes on C:\Temp NVMe; do not change the canonical Epic source or PRD.


### Resumed native pointer-reference closure — 28/28 names (2026-10-24)

After commit b3b7e90f, CDB in the untouched compiled UT99 v432 loader dereferenced the **real in-memory FStateFrame Node and StateNode UObject pointers** at Core.dll UObject::Serialize post-StateFrame RVA 0x556b1, read each object's FName index at +0x20, followed Core!FName::Names and the FNameEntry Unicode string. This is independent of the PHP archive parser. For **all 14 native-reached saved v68 Entry.unr frames**, both actual runtime FNames matched the original import/export referent names: **28/28 native reference names matched**. The exact native probe masks, signed LatentAction, Code pointer, flags and 15-byte serialized frame envelope were cross-checked simultaneously: **195/195 focused checks PASS**, DEV journal 20261024T104104103Z-cfcaa051. An initial test-only regex FAIL 20261024T104000173Z-9bea1caa (CDB emits records on one physical line) is preserved intact. No original source or production reader changed.

Manifest UE1-O06-G5-MULTIMETHOD-STATEFRAME-NATIVE-REFERENTS.json pins exact original native engine binary, original map, unmodified CDB log SHA, and limitations. The original source map has **19** positive RF_HasStack exports; the remaining **five** are all original *Camera* class objects (indices 12, 33, 34, 35, 36). A second compiled UCC load targeting Camera exports reached the same 14 but **no additional Camera objects** (SHA-pinned diagnostic log); repeating class-filtered batch export is therefore not a useful next action. A different real compiled loader entry point or disposable native commandlet is required before that specific five-object gap can be closed. The FName name equality is strong native referent identity evidence but does not yet independently establish each referent's full outer/class path.

**Acceptance remains 0/8 in this batch and 0/256 overall.** Next genuine closure actions: exercise unobserved Camera refs through an appropriate compiled loader and verify malformed StateFrame native bytes/errors and other source families, while working on UClass defaults and independent inherited readers in the same package sessions. CDB, compiler intermediates and fixtures remain under C:\Temp NVMe. Retain append-only PASS/FAIL journals, never replay current full-memory or package scans merely to add PASS counts.

### Compiled v432 UClass loaded member subset — 88/131 original classes (2026-10-24)

After the Access Matrix deployment at Git bd2bcc9c, the existing 7-package / 8-method UE1 batch resumed at T99:UClass::Serialize without replaying the cached package scans. Original Core.dll native UClass::Serialize RVA 0x1203 jumps to compiled body +0x27A60, calls UState::Serialize, then reads ClassFlags, 16-byte ClassGuid, Dependencies, PackageImports, ClassWithin, ClassConfigName and tagged defaults. Microsoft x86 CDB attached at the compiled load-return site +0x27C92 and recorded 90 actual native UClass calls in the genuine Engine.u batchexport run. Runtime live FName::Names disambiguated two *unrelated Core-package* class objects with colliding local linker indices (Core.Object idx2 and Core.Subsystem idx328), preventing false apparent source mismatches. The remaining **88 genuine original Engine.u UClass exports** matched the production class metadata reader for all selected loaded ClassFlags, GUID bytes, Dependencies array counts, PackageImports counts, nonnull ClassWithin pointers and full runtime FName ConfigName strings. Source archive metadata envelopes also decoded. **716/716 native/source checks PASS** focused run 20261024T111458040Z-305f115a. Manifest UE1-O06-G5-MULTIMETHOD-UCLASS-NATIVE-VALUES.json pins original compiled DLL, original Engine.u, exact CDB logs and unchanged source specimen.

This is a loaded-value partial subset, not FULL. Genuine Engine.u contains **131** class exports and 43 were not reached by the selected commandlet; native default-property values, individual dependency references, all inheritance/malformed cases remain OPEN. The separate eight-method gate stays OPEN and 0/256 methods fully accepted. No production PHP modified and PRD unchanged by this UE1 verification task; scratch native probes remain on C:\Temp NVMe.
