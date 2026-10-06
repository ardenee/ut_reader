# Unreal / UE1 v227 Package Serialization and Pre-Dependency Preprocessing

## Scope

This specification records Section 4A of the source-conformance audit for the Unreal / Unreal Gold UE1 profile.

The audit starts from the newest local Unreal source independently of UT99, UE2, or later engines:

- source tree: `L:\Source\Games\Unreal\Unreal [v1.227]`
- repository mirror: `L:\src\Repos\Unreal\Unreal [v1.227]`
- revision: `2bd1ce95a78bfd95fb83e7834abbb878b40b3cb6`
- engine version: 227
- package version: 69
- package licensee version: 227
- minimum package version constant: 60

Historical Unreal-only source is used only where the latest public v227 tree does not contain an implementation body:

- `Unreal [v1.224] [1999-05-01] [INCOMPLETE]` — package version 68/minimum 60, headers/libs only for the relevant Core implementation.
- `Unreal [v1.200] [1998-05-19]` — complete Core source, package version 61/minimum 34.

No rule in this document is filled from UT99.

## Source-availability boundary

The v227 checkout contains `Core/Inc/UnLinker.h`, `UnArc.h`, `UnName.h`, `UnObjBas.h`, and `UnObjVer.h`, but it does not contain `Core/Src` linker/name/object implementation files. Git history for this checkout does not contain those missing Core implementation bodies.

Therefore:

- inline/header implementations are authoritative where present;
- declarations prove field existence but not serializer order or version gates;
- v1.200 is authoritative only for historical branches that its complete Unreal implementation actually contains;
- UT99 behavior must not be substituted for missing v224/v227 Unreal bodies;
- post-v69 package behavior is not source-certified by the supplied Unreal trees.

## v227 identification

`Core/Inc/UnObjVer.h` defines:

- `PACKAGE_FILE_TAG = 0x9E2A83C1`
- `PACKAGE_FILE_VERSION = 69`
- `PACKAGE_FILE_VERSION_LICENSEE = 227`
- `PACKAGE_MIN_VERSION = 60`

The v227 `FPackageFileSummary` exposes the serialized 32-bit version as low-16-bit package version plus high-16-bit licensee version through `GetFileVersion()` and `GetFileVersionLicensee()`.

## v227 package summary

The inline `FPackageFileSummary` serializer proves this order:

1. Tag
2. packed FileVersion
3. PackageFlags
4. NameCount / NameOffset
5. ExportCount / ExportOffset
6. ImportCount / ImportOffset

For low package version 68 or newer it then serializes:

7. Guid
8. GenerationCount
9. Generation records, each ExportCount then NameCount

On load, v227 clamps the serialized GenerationCount to the inclusive range 0..64 before allocating/reading generation records. The serialized value and the effective count are therefore distinct facts.

For versions below 68 the summary instead reads HeritageCount / HeritageOffset, temporarily seeks to the heritage table, reads the GUID history, restores the previous position, and creates one synthetic generation using the summary export/name counts.

UnrealDB now preserves both `generation_count` (serialized value) and `effective_generation_count` (v227 load value) for the Unreal game-aware UEDB5 path.

The v227 clamp is not projected onto UT99 or UE2 readers. The game-aware `unrealgold` snapshot path explicitly enables it.

## Name references

v227 `ULinkerLoad::operator<<(FName&)` is inline and source-proven:

- reads a name index through `AR_INDEX` / `FCompactIndex`;
- validates it against `NameMap`;
- resolves that runtime name entry.

The v227 public tree declares the `FNameEntry` serializer but does not provide its body. Consequently the exact v227 on-disk name-entry string serializer is unresolved in this source set.

The existing version-64 name-entry boundary is not claimed here as Unreal-v227 proof merely because it is known from another UE1 lineage.

## Imports and exports: v227 boundary

v227 declares persistent `FObjectImport` fields:

- ClassPackage
- ClassName
- PackageIndex
- ObjectName

It declares persistent `FObjectExport` fields:

- ClassIndex
- SuperIndex
- PackageIndex
- ArchetypeIndex
- ObjectName
- ObjectFlags
- SerialSize
- SerialOffset

The corresponding friend serializer bodies are absent.

This matters because v227 also defines `RF_HasArchtype` and includes it in `RF_Load`. The source therefore proves that archetype state is part of the v227 persistent object model, but it does **not** prove the byte order, condition, or version gate by which `ArchetypeIndex` is serialized.

UnrealDB must not claim a complete v227 export-table byte contract until that implementation body is available. The v69 Unreal source policy is therefore explicitly marked partial rather than borrowing the UT432 serializer policy.

## Current-serializer use proven by v227

`UWebAdmin/Src/WebAdminFile.cpp` constructs an archive using the package summary's current file version, deserializes the current `FNameEntry` and `FObjectImport` types, and identifies required root packages where:

- `PackageIndex == 0`
- `ClassName == Package`

This proves that v227 utilities consume packages through the current serializers. It does not expose the missing serializer bodies and therefore cannot supply their byte layout.

## Historical Unreal v1.200 implementation

The complete v1.200 `Core/Src/UnLinker.h` supplies source authority for older package branches without using UT99.

### Import layout

`FObjectImport::operator<<` serializes ClassPackage and ClassName first.

For version 50 or newer:

- PackageIndex is a fixed-width INT;
- legacy `_ObjectPackage` is not serialized.

Before version 50:

- `_ObjectPackage` is serialized as an FName;
- PackageIndex is not serialized and is initialized to zero on load.

UnrealDB already implemented this branch.

### Export layout correction

v1.200 `FObjectExport::operator<<` proves:

1. ClassIndex — compact
2. ParentIndex/SuperIndex — compact
3. PackageIndex — fixed-width INT only when version >= 50
4. ObjectName — FName
5. ObjectFlags — DWORD
6. SerialSize — compact
7. SerialOffset — compact whenever SerialSize is nonzero

Before version 50, PackageIndex is not serialized and is initialized to zero.

UnrealDB previously always consumed a four-byte export PackageIndex and therefore misaligned pre-v50 exports. Section 4A corrects that.

UnrealDB also previously consumed SerialOffset only when SerialSize was positive. The source condition is nonzero. Section 4A corrects the UE1 path without projecting this historical Unreal rule onto separately audited UE2 parsing.

### Compact-index boundary

v1.200 `FCompactIndex::operator<<` uses a fixed INT only for archive version <=31; later versions use the compact one-to-five-byte representation. The v1.200 loader's source-supported minimum is version 34, so every admitted v1.200 package uses the compact form for `AR_INDEX` fields.

## Pre-dependency preprocessing proven by v1.200

Before `VerifyImport()`, the complete Unreal v1.200 loader performs the following relevant work:

1. reads the summary and sets the archive version from the package;
2. validates the package tag and minimum-version policy;
3. allocates the name/import/export/heritage maps;
4. reads the heritage table;
5. reads names and forms the runtime NameMap using load-context flags;
6. reads imports;
7. reads exports;
8. derives each export's in-memory ClassPackage and ClassName from ClassIndex;
9. adds the linker to the loader set;
10. begins import verification.

The derived export class identity is:

- ClassIndex < 0: class name from the class import; package from that import's parent package import for version >=50, or legacy `_ObjectPackage` before 50;
- ClassIndex > 0: class package is the current linker root and class name is the referenced local export name;
- ClassIndex == 0: `Core.Class`.

v227 declares equivalent class-identity helper APIs, but their implementation bodies are absent. This historical preprocessing is therefore source-proven for v1.200 and must not be silently promoted to a complete v227 claim.

## Runtime name filtering versus persisted data

v1.200 maps a serialized name to runtime `NAME_None` when its name-entry flags do not intersect the active loader context flags. That is runtime preprocessing.

UnrealDB's metadata store must retain the serialized name entry rather than destructively replacing it with `None`. Any dependency semantic that depends on the live runtime context must be handled as a semantic decision, not as loss of source bytes.

The v227 linker still owns context flags, but the missing implementation body prevents this document from asserting an unchanged v227 filtering algorithm.

## UnrealDB source-policy correction

Section 4A removes source-policy labels that falsely implied stronger authority:

- versions 60..68 are now `ue1-unreal-v224-v60-68-public-source-partial`;
- version 69 is now `ue1-unreal-v227-v69-public-source-partial`;
- profile-admitted versions above 69 are now `ue1-unreal-post-v69-profile-admitted-unresolved`.

The previous v69 label `ue1-shared-v69-ut432-serializer` is no longer used for Unreal. UT99 remains an independent profile.

The v1.200 early policy remains `ue1-unreal-v120-loadable-v34-59` because its complete source body is available and was already the dependency-audit boundary.

## UEDB5 schema corrections

For Unreal packages below version 50:

- import and export outer PackageIndex are both recorded as not serialized;
- the export schema advances to `ue1.unreal.object-export.v2`;
- the summary records `export_outer_index_encoding = not-serialized`.

For v68+ Unreal snapshots using v227 summary semantics:

- `generation_count` preserves the serialized INT;
- `effective_generation_count` records the source-defined 0..64 clamp;
- `generations` contains only the effective number of records.

## Verification

Section 4A adds/extends regression gates:

- `catalog/bin/verify-ue1-pre50-import-layout.php`
  - pre-v50 import ObjectPackage branch;
  - pre-v50 export PackageIndex omission;
  - export alignment;
  - nonzero negative SerialSize still consumes SerialOffset;
  - UEDB5 import/export schema boundaries.
- `catalog/bin/verify-unreal-v227-summary-contract.php`
  - serialized generation count 65 becomes effective 64;
  - serialized negative generation count becomes effective 0;
  - both values survive UEDB5 projection.
- existing UE2 serialization and legacy-game persistence gates remain green.

## Migration impact

This checkpoint does not justify a full package scan.

The only byte-layout reparse known to be required is for Unreal packages below version 50, because their previous export table was decoded with a nonexistent four-byte PackageIndex.

The generation-count change requires reparse only if an Unreal v68+ package actually stores a count outside 0..64. Source-produced packages are expected to stay inside the normal range, but this must be established from staged/source data rather than assumed.

Source-policy labels for Unreal 60+ snapshots are also stale after this audit and must be refreshed before final validation/cutover. That label-only refresh does not require re-reading original package payloads if implemented from the retained UEDB5 snapshot.

The database currently connected to this checkout does not contain the Step-5 UEDB5 tables, so Section 4A does not invent an impact count. Impact discovery must be run against the actual staging database.

## Unresolved v227 items

The following remain unresolved because the supplied latest Unreal source does not contain their implementation bodies:

- exact v227 `FNameEntry` byte serializer;
- exact v227 `FObjectImport` byte serializer;
- exact v227 `FObjectExport` byte serializer;
- serialization order/gate for `ArchetypeIndex`;
- current v227 linker preprocessing/remapping implementation;
- exact v227 `VerifyImport` implementation;
- any post-v69 package-format extensions.

These are source gaps, not permission to inherit UT99 behavior.

## Section 4A result

Section 4A is complete as an audit of the source that is actually available.

It produced three source-backed implementation corrections:

1. pre-v50 Unreal exports no longer consume a nonexistent PackageIndex;
2. UE1 SerialOffset follows any nonzero SerialSize, matching complete Unreal v1.200 source;
3. the game-aware Unreal v227 summary path applies the source-defined generation-count clamp while preserving the serialized value.

It also removes the misleading UT432 source-policy attribution from Unreal and records the remaining v224/v227 implementation bodies as explicit blockers to claiming complete latest-Unreal serialization parity.
