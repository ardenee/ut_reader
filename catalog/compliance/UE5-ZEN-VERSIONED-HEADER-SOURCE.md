# UE5.8.3 Zen versioned-header loading — Epic runtime source contract

**Original obligations:** B009 `UE5-583-ZEN-VERSIONING-001` and B010
`UE5-583-ZEN-CONDITIONAL-LAYOUT-001`. No new compliance batch. Exact source:
`L:/Source/Engine/UE5/UE 5.8.3` (pin repository revision by the SHA256
values in B009/B010). This is source evidence, not an executed Epic runtime.

## Key correction to the previous acceptance rationale

The presence of `UnrealEditor.exe` is **not a prerequisite** to determining
how Epic handles a cooked Zen header. An "independent cooked versioned-header
reference" is a *testing fixture*, **not a UE file-format field**, external
version repository, or package-store resolution mechanism.

Engine source itself defines how a cooked package's version fields are
deserialized and how they influence later object payload deserialization.
A real cooked file can add independent empirical/differential confidence,
but cannot be required merely to establish the source-defined branching.

## Runtime call graph and exact behavior

| Epic call and lines | What happens | UnrealDB counterpart |
|---|---|---|
| `AsyncLoading2.cpp:8387–8420` `FAsyncPackage2::Event_ProcessPackageSummary` | Wait for Zen package IoStore bytes; call `FZenPackageHeader::MakeView`. Both the main chunk and optional segment (editor build) use this function. | `Uedb5Ue5ZenIoStoreSnapshotBuilder::build` calls `Uedb5ZenPackageReader::parse` with bytes from the selected TOC chunk and evidenced package-store entry. |
| `ZenPackageHeader.cpp:18–35` | Treat the summary's `uint32 bHasVersioningInfo` as truthy. Nonzero reads `FZenPackageVersioningInfo`; zero leaves `VersioningInfo` absent. No check requires an external versioned-header reference. | `Uedb5ZenPackageReader::parse` preserves null when absent; any nonzero flag enters `readVersioningInfo` (D010 fix). |
| `AsyncLoading2.cpp:164–170`, `ObjectVersion.cpp:34–40`, `CustomVersion.cpp:305–351` | Deserialize `ZenVersion` (`uint32`), UE4 and UE5 versions (`int32` each), licensee version (`int32`), optimized custom-version GUID + signed version array. | `readVersioningInfo` reads the same wire layout, keeps raw versions and records every custom GUID/version. |
| `ZenPackageHeader.cpp:37–47` | Absent version info **or** UE5 version >= `VERSE_CELLS` (1015): read two cell map offsets. Otherwise use `ExportBundleEntriesOffset` for both, creating empty cell maps. | B010 tests 1014/1015 and absent versioning, including import and dependency integrity. |
| `ZenPackageHeader.cpp:55–65` | Absent version info **or** UE5 version >= `DATA_RESOURCES` (1009): read a pad count, consume pad bytes and read the bulk-map size/view. Otherwise no bulk map. | B010 tests 1008/1009 and absent versioning. D011 removes an invented zero-byte content check and accepts the eight-byte scratch-buffer boundary. |
| `AsyncLoading2.cpp:8464–8474` | **Versioned:** apply serialized UE4/UE5 package version, licensee and custom versions to `LinkerRoot`. **Unversioned:** apply compiled `GPackageFileUEVersion` and `GPackageFileLicenseeUEVersion`. | UnrealDB records **serialized metadata** in the V5 snapshot. A null serialized version is not an unsupported file. It does not claim to instantiate an Epic `LinkerRoot` or load UObject payloads. |
| `ObjectVersion.cpp:17–20` | Engine defaults come from `VER_LATEST_ENGINE_UE4`, `EUnrealEngineObjectUE5Version::AUTOMATIC_VERSION` and `VER_LATEST_ENGINE_LICENSEEUE4`, not filename inference. | Keep the distinction between *absent serialized version* and *engine runtime effective version*. Do not write fabricated serialized versions to V5. |
| `AsyncLoading2.cpp:8544–8577` `InitializeExportArchive` | `Ar.SetUEVer`, `Ar.SetLicenseeUEVer`, conditionally `Ar.SetCustomVersions`. These then determine applicable UObject export payload deserializers. | Header decoding/metadata extraction is source-mapped. Full UE UObject export deserialization is **separate** from header parsing and remains in the relevant full-workflow coverage inventory. |
| `AsyncLoading2.cpp:8494–8505` | After the summary the runtime continues to `Event_ProcessDependencies`. | Dependency discovery and provider resolution belong to their original separate audit obligations; successfully parsing a header does not prove resolved dependencies. |

`FPackageFileVersion` has independent UE4/UE5 components; its UE5
comparison overload uses `FileVersionUE5` (`ObjectVersion.h:813–829`).
It does **not** require the two numbers to fall within manually chosen
game-profile intervals.

### What happens for a real versioned package

* **Valid versioned header:** decode all serialized version fields, read
  conditional structures according to the stored UE5 version, build
  import/export/dependency views, assign stored versions to LinkerRoot,
  and initialize the export archive with those versions before decoding
  its UObject payload. Any unsupported payload/serialization would be
  detected at its actual later serializer boundary, not at a fabricated
  global "versioned header reference" gate.
* **Valid header without versioning:** use the current cooked header
  sections and compiled package/licensee versions for the runtime archive.
  V5 metadata faithfully records that versioning was not serialized.
* **Malformed header:** engine assertions, short reads, corrupt export-bundle
  cardinalities, or downstream deserializer failure apply as reached.
  UnrealDB adds defensive bounds checks in a file scanner; these are not
  claims that arbitrary corrupt buffers have identical engine fatal/error
  behavior. Error-handling differences must be explicitly audited.
* **Editor presence:** runtime code is not under `WITH_EDITOR`. Only
  editor-specific optional header and imported-package-name handling is
  conditional on `WITH_EDITOR`. Do not conflate those with the main Zen
  header reader.

## Automated retained evidence

Run, with `UNREALDB_EPIC_SOURCE_ROOT` pointing to the pinned UE5 source:

```powershell
& C:\php8.5\php.exe catalog\bin\verify-uedb5-zen-runtime-version-source.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-ue5-zen-package.php
```

The first verifies **five source-file SHA256 values and fourteen structural
runtime-source assertions** — it verifies pinned source code, **not** the
execution of the Epic loader. The second builds byte-accurate source-layout
test headers independently of the production parser, tests versioning and
branch boundaries, and verifies V5 metadata persistence. The real retained
Satisfactory data supplies 15 confirmed unversioned examples, **not** a
positive versioned cooked sample.

**Acceptance interpretation:** B009/B010 source-header behavior is now
grounded in the engine's actual read-and-archive hand-off. The previous
requirement to obtain a built UnrealEditor binary was invalid and withdrawn;
a specifically cooked versioned fixture is optional additional differential
evidence rather than a precondition to understanding or testing the source
branches. The operation-level audit remains open only for specifically
unverified end-to-end consumers, error semantics and independent review
under the recovery acceptance contract. Avoid inflating that status into
full UE5 compliance.
