# UnrealPakViewer → Epic UE5.8.3 → UnrealDB: bounded IoStore cross-check

Date: 2026-10-16. Source authority: local Epic UE5.8.3; external implementation is comparison evidence, **not** the engine specification.

## Source anchors

- UnrealPakViewer `PakAnalyzer/Private/IoStoreAnalyzer.cpp`: `InitializeGlobalReader`, `InitializeReaders`, `FillPackageInfo`, `PreLoadIoStore`, `TryDecryptIoStore`, `OnExtractFiles` (https://github.com/jashking/UnrealPakViewer/blob/master/PakAnalyzer/Private/IoStoreAnalyzer.cpp).
- Epic `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, `FScriptObjectEntry` serialization (near line 238) and `FCookedScriptObjectsDebug::LoadDebugData` (near line 6980 in the local 5.8.3 tree). The loader checks for the `EIoChunkType::ScriptObjects` chunk; when present it uses `LoadNameBatch`, reads the count, and associates `GlobalIndex`, `Mapped` name and `OuterIndex`.
- Epic `Engine/Source/Runtime/CoreUObject/Private/Serialization/PackageStore.cpp` and `Runtime/PakFile/Private/FilePackageStore.cpp` govern *runtime* package-store backend arbitration and container mount precedence; opening several UCAS files in a viewer does not establish that order.

## Findings and disposition

| Area | Comparison finding | UnrealDB implementation | Decision |
| --- | --- | --- | --- |
| Global script objects | Viewer reads the global `ScriptObjects` chunk and reconstructs script names/outer chains. Epic 5.8.3 retains the same chunk and object records for cooked script debug data; this is **not**, by itself, evidence that runtime script objects are available. | `Uedb5Ue5ZenDependencyResolver::resolveObjectIndex` returns `script_object_runtime_context_unavailable` for script imports. | **Open capability gap:** independently index *observed* global script-object records from a complete, trusted container family. Do not mark imports as runtime-resolved from a debug chunk alone. |
| TOC and compression | Viewer delegates to Epic `FIoStoreReader`. | `Uedb5IoStoreTocReader` reads TOC, compression blocks, partitioned UCAS, and Oodle codec. | Implemented; verify variant parity using real 5.8.3 fixtures. |
| Encryption | Viewer supplies AES keys to Epic reader; key GUID is container-scoped. | TOC reader supports optional AES-256 ECB block decryption; key currently arrives as hex. | No demonstrated parsing defect. Extend encrypted fixture matrix only when keys/test data are available; never infer a key or silently skip encrypted members. |
| Container headers | Viewer reads package IDs, store entries, optional-segment entries, redirects and localizations. | `Uedb5IoStoreContainerHeaderReader`, mounted-store/effective-store context builders. | Existing support; avoid importing viewer-era layout rules without checking specific Epic 5.8.3 header-version gates. |
| Runtime package identity | Viewer reconstructs display identities; multi-file viewing does not establish runtime backend priority. | Explicit context, source-backed backend priority, fail-closed unknown authority. | Keep fail-closed. |
| Package exports and paths | Viewer reconstructs names, classes, import/export paths for UI. | `Uedb5ZenPackageReader`, V5 snapshot builder and dependency resolver. | Compare concrete export paths and class resolution on selected fixtures. Do not adopt filename/class heuristics as authoritative. |
| AssetRegistry | Viewer can load optional `DevelopmentAssetRegistry.bin` to augment presentation. | Not established as part of the V5 package contract. | Potential **separate optional discovery/UI input**, never use registry references as the loader's authoritative VerifyImport result. |
| Extraction | Viewer invokes engine reader and extraction workers. | TOC chunk read/reconstruction and package publication. | Existing implementation; test checksum, missing partition, retries, and encrypted payloads. |

## Acceptance gates before implementing global script-object enrichment

1. Locate every Epic UE5.8.3 definition of cooked script object index encoding and serialization, including `FPackageObjectIndex` and name-batch formats; no guessing from the viewer's structs.
2. Reproduce a complete `ScriptObjects` fixture with independently verified chunk bytes. Track provenance of `GlobalIndex` and `OuterIndex`, including missing/duplicate/cyclic outers.
3. Keep diagnostic identity and runtime resolution distinct. A global debug-name map alone **must not** convert `script_object_runtime_context_unavailable` to `resolved`.
4. Add targeted regression tests to the V5 resolver; no full game rescan until a source-backed behavior change justifies it.

This cross-check does **not** claim UnrealDB is fully UE5.8.3 compliant or that a new parser defect has been proven.
