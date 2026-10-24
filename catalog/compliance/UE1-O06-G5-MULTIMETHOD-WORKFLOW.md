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
