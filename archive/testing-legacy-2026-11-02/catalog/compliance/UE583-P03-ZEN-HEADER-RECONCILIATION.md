# P03 — Zen header source-branch reconciliation (2026-10-19)

Pinned UE5.8.3 Epic source: `Engine/Source/Runtime/CoreUObject/Private/Serialization/ZenPackageHeader.cpp`, `FZenPackageHeader::MakeView`. UnrealDB: `catalog/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php::parse()`.

**Concrete production correction:** Epic first loads the fixed `FZenPackageSummary`, checks `HeaderSize` against package memory size, then creates `FMemoryReaderView` limited to `[sizeof(FZenPackageSummary), HeaderSize)`. UnrealDB originally created the variable-field reader over the **entire reconstructed package chunk**, including appended export payload bytes. It now creates a new `Uedb5BinaryReader` limited to exactly the first `HeaderSize` bytes and seeks to `SUMMARY_SIZE` before versioning, cell offsets, name-map, pad and bulk-data-map reads. Malformed fields cannot draw bytes from export payload to satisfy variable header reads.

Reviewed source branches: nonzero `bHasVersioningInfo`, old/new cell offsets gated by UE5 VERSE_CELLS, bulk-data-map presence gated by DATA_RESOURCES, per-field imported hash/import/export/cell/bundle spans, and editor-only imported names. The existing reader has explicit bounds and record-count validation on those spans. These are a **primary source pass**, not native decoded-field parity.

Native oracle limitation: Epic UE5.8.3 `UnrealPackageTool.exe -j PackageInfo` reads classic `.uasset` but returns `MalformedTag` on Epic-extracted Satisfactory Zen `.uheader`. Prior Epic `UnrealPak` extraction independently matched 27,240 complete Zen package byte reconstructions (see UE583-EPIC-IOSTORE-CONTENT-DIFFERENTIAL-20261019.md); that **does not** prove native decoded-field parity. Keep SRC-003 partial until an Epic decoded Zen oracle is available or explicitly excluded from the supported certification claim. Do not rerun the large byte differential.

No P03 performance-intensive import was executed. The synthetic Zen foundation/metadata regressions validate the corrected reader boundary and V5 persistence. P04 is next in the fixed primary sequence.
