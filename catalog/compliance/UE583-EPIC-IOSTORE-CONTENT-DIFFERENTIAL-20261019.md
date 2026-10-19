# UE5.8.3 — Independent Epic IoStore reconstructed-package byte parity

**Verified 19 October 2026: PASS, 27,240/27,240 genuine retained Satisfactory packages and 1,398,535,898 bytes.** Official Epic UE5.8.3 UnrealPak.exe independently extracts every cooked Zen chunk to a `.uheader` plus `.uexp` pair. Production `Uedb5IoStoreTocReader::readChunk` reconstructs the same original bytes. Per-package lengths and SHA-256 of concatenated Epic files were compared for **all 27,240** indexed packages, with **zero mismatches**.

## Exact source identity matters

Isolated DEV game 2, retained container 4, stored TOC SHA-256 `63e4f0e322276684718563e13619474c8cf5981b29e15e502620a4373e351ab5`, rooted at `C:/Temp/uedb5-iostore-retention-test/games/unreal2/iostore/<SHA>/FactoryGame-WindowsServer.utoc` with matching retained UCAS. The current installed Satisfactory Dedicated Server TOC had **different** SHA-256 `84d30d56ccbd26cfeb1c0319b92d3274b13dff4406facad267aa582217c51696`.

An initial run mistakenly compared the older isolated package records with **newer** currently installed Epic source files. It reported 27,239 matching contents and one apparently inconsistent `Beetle_skl_Skeleton` package: indexed old logical size 21,092 versus new source TOC/read length 20,793. Importantly, the apparent byte difference was **not** real: both new-source Epic and UnrealDB data hashes agreed for that package. The mismatch was exclusively a cross-source-revision comparison. The final verifier asserts the retained TOC SHA matches the isolated indexed container before comparing.

## Independent test method

1. Official `H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe` (built from pinned UE5.8.3) was run with the **retained** TOC `-Extract C:/Temp/ue583-epic-zen-retained-extract-20261019`, producing **54,480 actual files**: `.uheader` and `.uexp` pairs for 27,240 packages.
2. `catalog/bin/verify-ue5-583-epic-zen-content-differential.php` guards the isolated port3339 MySQL datadir and retained TOC SHA-256; maps native `-List` cooked paths to logical `/Game`, `/Engine`, and plugin mounts including `/InterchangeAssets`; reads each SQL package `toc_index` exactly once; and calculates independent SHA-256 of Epic `uheader+uexp` and UnrealDB IoStore reconstructed chunk.
3. Result: `ok=true`; **27,240 native package names**, **27,240 isolated SQL package records**, **27,240 byte-identical reconstructed package contents**, **1,398,535,898 matched bytes**, **0 failures**, PHP exit **0**.

This is **native Epic-versus-UnrealDB exact package byte parity** for one complete authentic Satisfactory Zen container, over all packages, including compression and UCAS block reconstruction. The earlier name-only differential `verify-ue5-583-epic-zen-native-package-names.php` passed 27,240 names against installed source; it is distinct from this source-SHA-pinned byte comparison.

## Certification limits

This does not, by itself, compare native Epic decoded import/export/dependency *semantics*, classic UE5 .uasset serialized package parser branches, alternate encrypted/on-demand/multipart container layouts, or all 16 production application workflow paths. Keep those separate gates open. Never run Satisfactory production ingestion without explicit user direction.
