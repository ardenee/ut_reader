# D007 — IoStore ContainerHeader source contract (B006 mount prerequisite)

**Original operation:** `UE5-583-FILE-STORE-001` (B006). **Distinct supplemental contract** for the raw IoStore ContainerHeader that a file-store backend consumes; this is **not** mounted ordering, package-store backend arbitration, a new operation, or a newly discovered difference. Recorded source difference `UE5-D007` was corrected in UnrealDB at commit `f847f4ea`, but its source responsibility was left unregistered in the 15 original manifests. This record closes that traceability escape, **not** broader engine or unknown-version compliance.

## Exact Epic source and parser behavior

- `Engine/Source/Runtime/Core/Private/IO/IoContainerHeader.cpp`, pinned SHA-256 `fcc949dc6f95befd0e621294b422773610896a325fd457294e640b9d9e12a590`, operator `<<(FArchive&, FIoContainerHeader&)`, lines 49–118.
- `Engine/Source/Runtime/Core/Public/IO/IoContainerHeader.h`, pinned SHA-256 `03d325d4c496e3d75b0c91da4256be58162565496444b2942fbcad116aac3abd`: container header data/serial-info structure, including the default `Size=-1`.
- `Engine/Source/Runtime/PakFile/Private/FilePackageStore.cpp`, pinned SHA-256 `dfdd4e226b3a41f36476ddd93225aa34284ea63943f1752216e0f2a25c2a2418`: `FFilePackageStoreBackend::Mount(FIoContainerHeader*, Order)` and `Update()` consume **the deserialized `FIoContainerHeader`** to build package-entry pairs, optional segment entries and redirect/localization maps. Mount ordering and precedence remain separately covered by B006's existing tests.

The Epic loader checks the file signature and rejects **only versions below `NoExportInfo` (3)**. There is no load-time rejection for `Version > Latest` (5). Version 4 loads inline `SoftPackageReferences`; version **5 and later** follow the serialized `SoftPackageReferencesSerialInfo` branch. On loading, **only positive Size** advances the stream, with a bound against `Ar.TotalSize()` starting at the current `Ar.Tell()`. The recorded `Offset` is not a stream-position assertion. `Size=-1` is a declared default and is not rejected. The source does not require `Ar.Tell()==Ar.TotalSize()` after parsing the header. These precise differences were formerly incorrectly rejected by `Uedb5IoStoreContainerHeaderReader::parse()` and are fixed by D007.

## Actual UnrealDB production call-path inventory

`catalog/bin/verify-uedb5-b006-container-header-source.php` enumerates **every production PHP file** under `catalog/src` (564 at this checkpoint). The one direct parser caller is:

`Uedb5Ue5ZenIoStoreSnapshotBuilder::build()` → `$toc->readChunk(ContainerHeader)` → `Uedb5IoStoreContainerHeaderReader::parse()`.

Four audited entry points call that builder:

1. `CatalogZenIoStorePackagePublisher` — metadata import/publish;
2. `CatalogIoStoreMemberReimportService` — reimport/repair;
3. `CatalogIoStoreIndexedPackageReader` — isolated indexed metadata read;
4. `CatalogIoStorePackageNameIndexer` — name dictionary indexing.

The builder requires **exactly one** ContainerHeader chunk and its `ContainerId` to match the selected TOC. This ensures corrected header behavior reaches the real V5 snapshot source, without asserting that a single container by itself supplies the whole mounted-runtime context. The acceptance pins the raw bytes of both PHP reader and snapshot builder; new direct callers or unreviewed builder callers fail.

## Independent acceptance and limitations

The new source validator requires **25/25 checks**, including pinned Epic source/header hashes and symbols, exact byte-level UnrealDB implementation hashes, full production caller inventory, and running `verify-uedb5-ue5-iostore-foundation.php` (the existing **33/33** full-byte synthetic IoStore/header regression). In the latter fixture, four formerly failing conditions are now green: future version branch, mismatched recorded offset, default negative size and unconsumed trailing extension. Below-minimum version, malformed bool/name and compression/crypto guardrails still fail closed. Original B006 acceptance also runs this as a **seventh independent driver** and checks a new separate header-prerequisite assertion group; it retains its old mounted-store source tests.

Retained authentic **Satisfactory IoStore header** was previously parsed read-only (v5, 1,647,696 bytes, 27,240 package IDs) with bounded isolated MySQL evidence in `verify-uedb5-satisfactory-header-readonly-isolated.php`. That script is historical evidence, not a portable live-db gate, and **was not rerun** by this acceptance. Version 6 in our fixture is **synthetic**, not an independently cooked UE5 v6 container. Whole mounted-container sets and live backend arbitration are separate acceptance requirements, and no UE runtime session was executed.

This work reclassifies **D007** from unregistered source behavior to a **reciprocally mapped existing B006 supplemental source obligation**. The original 15 manifests / 18 operation identifiers remain unchanged. The `UE5-IOSTORE-HEADER` *accountability gap* can be recorded as accepted upon passing this formal source contract; broader unsupported version and source-to-production differential gaps remain open. No backfill, schema change, persistent database write, large package scan or change to Epic source was performed.
