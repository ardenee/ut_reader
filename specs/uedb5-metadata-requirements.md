# UEDB5 metadata requirements

## Scope

UEDB5 is the next UnrealDB compact metadata format. Its purpose is not merely to add fields to UEDB4; it establishes a lossless source-identity boundary for dependency and package semantics that UEDB4 cannot represent for all supported engine generations.

The normative container and logical-schema contract is `uedb5-format.md`. This document records the source audit, engine-specific requirements, implementation status, and migration rationale that feed that contract.

If wording here conflicts with `uedb5-format.md` about physical framing or canonical UEDB5 schema semantics, `uedb5-format.md` controls. The authoritative engine/game source and the corresponding per-engine specification continue to control engine behavior.

Authoritative local source roots used for this specification:

- UE2 / UE2.5: `L:\Source\Engine\UE2`
- UE3: `L:\Source\Engine\UE3`
- UE4: `L:\Source\Engine\UE4`
- UE5: `L:\Source\Engine\UE5`
- UDK: `L:\Source\Engine\UDK`
- Unreal: `L:\Source\Games\Unreal`
- Unreal II: `L:\Source\Games\Unreal II`
- UT99: `L:\Source\Games\UT99`
- UT2003: `L:\Source\Games\UT2003`
- UT2004: `L:\Source\Games\UT2004`
- UT3: `L:\Source\Games\UT3`
- UT4: `L:\Source\Games\UT4`
- supporting tools: `L:\Source\Tools`

The UE5 audit in this document is based on `L:\Source\Engine\UE5\UE 5.8.3`, Git branch `release`, commit `396c9f059903aed5fec78ecd3d437a40c6415368`. `Engine/Build/Build.version` reports Major=5, Minor=8, Patch=3 and BranchName=`UE5`.

## Core invariant

Any serialized field that can change Epic's package loading, import/provider selection, object visibility, outer traversal, dependency classification, or cooked-package identity must be retained losslessly in UEDB5.

Derived paths, hashes, normalized names and SQL projections are accelerators. They must never replace the serialized source identity from which they were derived.

UEDB5 must explicitly distinguish the package representation being stored, including at least:

- classic LinkerLoad package tables (`FObjectImport` / `FObjectExport`);
- UE5 Zen / IoStore package headers and object references.

## Common package/version identity

UEDB5 must retain enough version information to choose the source-correct serializer before interpreting any version-gated field:

- engine/source policy or equivalent audited reader policy;
- package serialization family (classic vs Zen/IoStore);
- complete `FPackageFileVersion`, including both the UE4 and UE5 version components used by current UE5 packages;
- licensee version;
- complete custom-version container where serialized;
- whether the package is unversioned and, if so, the externally selected parser/source policy rather than a fabricated serialized version;
- package flags and byte-order/tag information needed by the reader.

The format must not use one generic `UE3`, `UE4`, or `UE5` rule when the audited game/source revision differs. UT3 version 512 is an existing example of why the source policy must be explicit.

## UE3 requirement: complete ObjectFlags QWORD

UE3 `FObjectExport::ObjectFlags` is serialized as a QWORD. For UT3 package version 512, the January-2008 source defines:

`RF_Public = 0x0000000400000000`

UEDB4 metadata produced by the former reader kept only the low 32 bits and therefore discarded `RF_Public`.

UEDB5 must store the complete serialized value canonically as:

`object_flags: uint64`

A temporary decoder may expose high/low halves, but those halves must not become competing canonical fields. Dependency projections must be derived from the full value.

This information cannot be recovered from a truncated UEDB4 container. Affected UE3 files require reparsing of the original Unreal package bytes.

## UE4 requirement: serialized import PackageName

For package versions at and after `VER_UE4_NON_OUTER_PACKAGE_IMPORT` (520), `FObjectImport` serializes `PackageName` in addition to:

- `ClassPackage`
- `ClassName`
- `OuterIndex`
- `ObjectName`

Provider package identity can therefore no longer be reconstructed solely from the outer chain. UEDB5 must preserve the serialized `PackageName` FName, including its raw Name index/number where available and its source-correct effective value after load-time fixups.

For `PKG_FilterEditorOnly`, Epic still serializes the PackageName slot. When saving a None package name it can serialize `ObjectName` as a placeholder and reset it to None after loading. A reader must consume that serialized field and then reproduce the load-time reset rule; it must not skip the field from the record.

UEDB4's fixed import row does not retain PackageName, so affected UE4 packages require reparsing from the original package bytes for a lossless UEDB5 cutover.

## UE5 package-summary parsing requirements

A source-correct UE5 reader must consume the complete version-gated `FPackageFileSummary` layout before any table offsets are trusted. Parsing a field does not automatically mean UEDB5 must persist that field; persistence is required only when it contributes to identity, loading/dependency semantics, verification, or a deliberately supported inspection feature.

Important UE5 5.8.3 summary gates include:

- `FPackageFileVersion` carries separate UE4 and UE5 version components;
- `SavedHash` (`FIoHash`) is serialized at `PACKAGE_SAVED_HASH`, with `TotalHeaderSize` moving alongside that layout change;
- the summary still serializes the `PackageName` slot (formerly `FolderName`; currently deprecated/unused by the engine);
- soft-object-path count/offset at `ADD_SOFTOBJECTPATH_LIST`;
- cell export/import count and offsets at `VERSE_CELLS`;
- `MetaDataOffset` at `METADATA_SERIALIZATION_OFFSET`;
- ImportTypeHierarchies count/offset at `IMPORT_TYPE_HIERARCHIES`;
- `NamesReferencedFromExportDataCount` at `NAMES_REFERENCED_FROM_EXPORT_DATA`;
- `PayloadTocOffset` at `PAYLOAD_TOC`;
- `DataResourceOffset` at `DATA_RESOURCES`.

The reader must also consume older inherited UE4 summary fields in their source-defined order. UEDB5 must not preserve obsolete offsets merely for completeness when the parsed data they point to is already stored in a source-shaped metadata block.

## UE5 classic LinkerLoad packages

UE5 classic packages continue to use `FObjectImport` and `FObjectExport`, but their dependency semantics are no longer equivalent to UE3-style exact tuple matching.

### Imports

UEDB5 must retain the raw serialized import graph and fields:

- import index;
- `ClassPackage` FName;
- `ClassName` FName;
- signed `OuterIndex` / `FPackageIndex`;
- `ObjectName` FName;
- explicit `PackageName` FName when version-gated into the archive;
- `bImportOptional` when `EUnrealEngineObjectUE5Version::OPTIONAL_RESOURCES` is active;
- raw FName index/number identity for every serialized FName above.

`PackageName` is semantically significant even when `OuterIndex` is non-null. UE5 may also legally have an import whose outer is an export when explicit package identity is present.

`bImportOptional` must not be dropped. It marks imports from optional packages/resources and is used when generating the appropriate optional package/chunk identity. UnrealDB must be able to classify an absent optional import separately from a required missing dependency.

### Exports

At minimum UEDB5 must retain:

- export index;
- raw `ClassIndex`;
- raw `SuperIndex`;
- raw `TemplateIndex`;
- raw `OuterIndex`;
- `ObjectName` FName and its raw index/number;
- complete serialized `ObjectFlags` value for that source format;
- `PackageFlags`;
- `bForcedExport`;
- `bNotForClient`;
- `bNotForServer`;
- `bNotAlwaysLoadedForEditorGame` when serialized;
- `bIsAsset` when serialized;
- `bIsInheritedInstance` when serialized;
- `bGeneratePublicHash` when serialized;
- serial size/offset;
- preload/dependency range fields;
- script serialization start/end offsets when serialized.

The reader must honor `REMOVE_OBJECT_EXPORT_PACKAGE_GUID`: the legacy per-export `PackageGuid` is present only before that UE5 version. It must also begin reading `bIsInheritedInstance` at `TRACK_OBJECT_EXPORT_IS_INHERITED`. Misapplying either gate shifts the remaining export record.

UE5 classic `FObjectExport` serializes the RF_Load object-flag mask through a 32-bit value. UEDB5 may use a common uint64 storage type across engines, but it must record the source width/semantics and must not assume UE5 serialized the same 64-bit QWORD layout as UE3.

### Classic UE5 dependency-resolution consequences

The implemented classic UE5 UEDB5 resolver operates on the retained raw graph rather than converting the package to the UE3 matching model.

UE5 5.8.3 `VerifyImportInner()` requires UEDB5 to preserve enough information for these source behaviors:

- explicit `PackageName` can select the provider linker independently of the outer chain;
- imports and exports can cross-reference each other as outers;
- candidate `ObjectName` can be accepted before exact class identity is known; class/package mismatch can be deferred to create-time verification rather than immediately rejecting the provider;
- when source linkers differ, the candidate export's outer can itself be an import whose object/class identity must be examined;
- a private provider export can be legal when the import/export containment graph satisfies Epic's `ImportIsInAnyExport`, `AnyExportIsInImport`, or `AnyExportShareOuterWithImport` rules;
- runtime-only/native/transient lookup and editor-only safe-replace behavior must not be fabricated when the required runtime state is unavailable.

The first three graph tests above depend on complete import/export `OuterIndex` relationships. Private-export handling must therefore never be reduced to a simple `RF_Public` bit check for UE5.

`bGeneratePublicHash` is dependency-relevant for cooked/Zen identity. Epic's PackageStore optimizer considers an export public when `RF_Public`, `RF_GeneratePublicHash`, or the serialized `bGeneratePublicHash` requests public hashing.

## UE5 Zen / IoStore package identity

Zen/IoStore does not use classic `FPackageIndex` import identity as its authoritative cross-package representation. UEDB5 must store this as a distinct package family rather than flattening it into classic imports.

### Package identity and imports

Retain losslessly:

- the package's raw `FPackageId`;
- ordered `ImportedPackageIds` from the package store entry;
- ordered `ImportedPublicExportHashes` from the Zen package header;
- each raw 64-bit `FPackageObjectIndex::TypeAndId`;
- the decoded `FPackageObjectIndex` type: Export, ScriptImport, PackageImport, or Null;
- for PackageImport: decoded imported-package index and imported-public-export-hash index;
- for ScriptImport: the raw 62-bit/hash identity used by the package object index;
- `ImportedPackageNames` where the Zen version supplies them.

For a package import, Epic's provider key is effectively:

`ImportedPackageIds[ImportedPackageIndex] + ImportedPublicExportHashes[ImportedPublicExportHashIndex]`

Imported package names are useful descriptive identity but must not replace this PackageId + public-export-hash key.

### Unsigned 64-bit Zen identity

Zen identity values must not be routed through PHP signed integers. `FPackageId` is an unrestricted `uint64`, `PublicExportHash` is `uint64`, and `FPackageObjectIndex::TypeAndId` uses the top two bits as its type tag. A valid PackageImport therefore has bit 63 set and can exceed `PHP_INT_MAX`.

Canonical UEDB5 storage for these values must be fixed-width 8-byte binary (or an exactly equivalent lossless 16-hex-digit representation at API/debug boundaries):

- `FPackageId`;
- `ImportedPublicExportHashes`;
- `FExportMapEntry::PublicExportHash`;
- raw `FPackageObjectIndex::TypeAndId`;
- any other UE5 source `uint64` identity/hash used for matching.

The reader may expose decoded high/low `uint32` components for arithmetic, but must never cast the canonical value through a signed PHP `int`. SQL projections, if required, should use a lossless 8-byte/binary representation rather than a signed `BIGINT` path.
### Zen container/package-store provenance

For file-backed IoStore, the ordered imported package IDs are supplied by container package-store metadata, not solely by the extracted package header. `FIoContainerHeader` contains the package ID list and serialized `FFilePackageStoreEntry` data; each store entry owns its ordered `ImportedPackages` array.

The container header also carries source-backed package-resolution state that must not be lost:

- `ContainerId`;
- package IDs and the package-specific store entry;
- optional-segment package IDs and optional-segment store entries;
- complete localized-package mapping rows for the selected container;
- complete package redirects (`SourcePackageId`, `TargetPackageId`, source package name) for the selected container;
- soft-package-reference tables.

Epic's file package store treats redirect/localization rows as container-global lookup state. Mounted containers are sorted by mount order descending and, for equal order, later mount sequence descending; `Update()` then uses first-in-effective-order `FindOrAdd` semantics. `GetPackageRedirectInfo()` checks explicit package redirects first, then outside the editor may apply active-culture localization only when the localized target package exists. Explicit package-store redirects are separate from CoreRedirects and are not gated by `s.AllowPackageRedirectorSupport`.

When an imported package source ID redirects to a target package ID, UEDB5 must retain the serialized source `FPackageId` as the required identity and record the target only as effective provider-lookup identity. The serialized `PublicExportHash` is then resolved against the redirected target package's ordinary or cell export map. By contrast, `GetSoftReferences()` enumerates raw container soft-reference IDs and does not itself apply package-store redirects.

UEDB5 must therefore retain, or durably reference, the exact container/package-store context used for a Zen package. A standalone extracted package must not be declared fully verified if its `FPackageObjectIndex` PackageImport references cannot be paired with the authoritative ordered `ImportedPackageIds` for that package. A single-container snapshot must not claim to know the winning redirect/localization mapping across multiple mounted containers unless authoritative mount order is also available.

To avoid metadata duplication, UnrealDB should not copy an entire `.utoc` container header into every UEDB5 file. Store the shared container metadata once and persist a stable provenance/key plus the package-specific ordered store-entry data required to resolve that package's references. Any denormalized accelerator must be verifiable against that source context.

Full cooked UE5 support therefore requires IoStore container ingestion for the `.utoc` table-of-contents plus its `.ucas` data container(s); PAK-only or standalone-package ingestion is not sufficient for authoritative Zen dependency identity.

### Zen exports

For every `FExportMapEntry`, retain:

- local export index;
- cooked serial offset and size;
- mapped `ObjectName` identity;
- raw `OuterIndex` `FPackageObjectIndex`;
- raw `ClassIndex` `FPackageObjectIndex`;
- raw `SuperIndex` `FPackageObjectIndex`;
- raw `TemplateIndex` `FPackageObjectIndex`;
- 64-bit `PublicExportHash`;
- `ObjectFlags`;
- export filter flags.

The 64-bit `PublicExportHash` is authoritative cross-package object identity for public Zen exports and must not be recomputed as a substitute when the serialized/optimized value is available.

### Zen load/dependency graph

Retain the structures that describe create/serialize ordering rather than reducing them to an unordered package list:

- `FExportBundleEntry` local export index and command type (Create / Serialize);
- dependency bundle headers and their per-command dependency counts;
- dependency bundle entries and their raw local import/export `FPackageIndex` identity;
- Zen package versioning information (`EZenPackageVersion`, package file version, licensee version, custom versions).

These structures are needed to reproduce the cooked package's dependency/load graph and to distinguish object identity dependencies from load-order dependencies.

### Package store and optional resources

Retain relevant `FPackageStoreEntryResource` identity separately from ordinary object imports:

- package-store flags;
- package name;
- package ID;
- imported package IDs;
- optional-segment imported package IDs;
- soft package references;
- whether an optional segment is present / auto-optional semantics where available from the source representation.

Hard imported packages, optional imported packages, and soft package references must remain distinguishable in UnrealDB. They must not all become identical `missing dependency` rows.

### Cell / Verse resources

Current UE5 package summaries and Zen headers have separate cell import/export maps. UEDB5 must preserve them when present.

For classic cell resources retain at least:

- `FCellImport` Verse path and raw `PackageIndex`;
- `FCellExport` Verse path;
- C++ class info identity;
- serial offset, layout size and total serial size;
- dependency-range fields.

For Zen cell maps retain raw `FPackageObjectIndex` cell imports and each cell export's serialized identity including its public-export hash and class information.

Cell identities must not be silently inserted into the ordinary UObject import/export arrays because their serialization and hash identity are distinct.

## UE5 auxiliary source blocks and compactness

Some UE5 structures are valuable for verification, inspection, payload ownership, or future dependency work but should not automatically become row-per-entry SQL projections. Keep them as compressed/source-shaped UEDB5 blocks unless a measured query requires an index.

Retain when present and relevant to supported features:

- package `SavedHash` / `FIoHash`;
- decoded soft-object/package references rather than merely their summary offsets;
- cell import/export data;
- ImportTypeHierarchies when needed for dynamic-import evidence or inspection;
- complete `FObjectDataResource` records;
- bulk-data / payload ownership information needed to associate payloads with exports;
- package-store flags and package/container provenance;
- Zen imported package names and versioning data;
- script object entries used by ScriptImport hash resolution.

Summary offsets such as `MetaDataOffset`, `PayloadTocOffset`, and `DataResourceOffset` must be parsed correctly, but do not need to be persisted after the pointed-to data has been normalized into a verified UEDB5 block. Likewise, count/offset pairs should not be duplicated into SQL merely because the source summary contains them.

The default rule is: **preserve source semantics in the `.uedb5` file, project only fields needed for indexed catalog operations into MySQL.**
## UE5 soft and asset-registry dependency metadata

UE5 soft-object-path serialization is versioned independently of ordinary imports. At `FSOFTOBJECTPATH_REMOVE_ASSET_PATH_FNAMES`, `FSoftObjectPath` changes away from the older single `FName` asset-path representation; subpath serialization is also custom-version gated. The reader must apply the source version/custom-version rules rather than assuming the UE4 representation.

When soft references are projected into UEDB5, preserve their package/asset/subpath identity without converting them into hard `FObjectImport` rows.

The asset-registry package dependency section separately serializes:

- one `ImportUsedInGame` bit for each import;
- one `SoftPackageUsedInGame` bit for each soft package reference;
- at `ASSETREGISTRY_PACKAGEBUILDDEPENDENCIES`, `ExtraPackageDependencies` as package name plus dependency flags.

Current UE5 saving uses the extra dependency list for package build dependencies with `Build | PropagateManage`. UEDB5 should retain these classifications when the section is present, but build/cook dependencies must remain distinct from runtime linker imports and from soft references.

## UE5 dynamic-import boundary

UE5 dynamic imports are not ordinary serialized `FObjectImport` records. `FLinkerLoad::AddDynamicImports` creates them during loading for exports carrying `RF_HasDynamicImports`.

The runtime derives them from state that is only partly present in package bytes:

- the export's serialized `RF_HasDynamicImports` flag;
- the export class reference/path;
- saved `ImportTypeHierarchies` when the current class is not directly available;
- core redirect state;
- the loaded native class implementation of `InjectDynamicImportsFor()`;
- optional instancing context.

UEDB5 must therefore retain `RF_HasDynamicImports`, the complete class reference graph, and serialized ImportTypeHierarchies. It must not serialize fabricated dynamic-import rows as though they came from the package.

If UnrealDB does not execute the exact source/runtime class injection logic, these references must be classified as runtime-derived / non-deterministically reconstructible from package bytes. They must not be reported as ordinary hard serialized imports merely because UE5 would add them at runtime.

## Dependency classification requirements

UEDB5 must allow UnrealDB dependency rows to distinguish at least:

- hard required object/package import;
- optional import / optional segment dependency;
- soft package reference;
- asset-registry build/cook package dependency;
- script import;
- Zen package-import/public-export-hash reference;
- cell/Verse import;
- load-order dependency bundle edge;
- runtime-only or otherwise non-deterministically-resolvable reference when Epic requires state not present in package metadata.

A missing optional or soft dependency must not inflate the same counter used for a missing required hard dependency unless the source semantics explicitly require that classification.

## Current UEDB5 container foundation

The format-5 container and production-capable isolated V5 file components are implemented without changing the production UEDB4 runtime:

- `Uedb5MetadataContainer` defines format version `5`, magic `UEDBM5` followed by two NUL bytes, the `.uedb5` extension, gzip-block framing, per-block SHA-256 verification, explicit `package_family` / `source_policy`, and optional per-section schema identifiers;
- `Uedb5MetadataStagingReader` remains an explicit-path migration/debug reader and has no database-registration path;
- `Uedb5MetadataReader` reads the canonical `.uedb5` path from explicit game/file identity, verifies the entire container before caching its manifest, verifies every touched block again, supports paging/sparse positions/full snapshot reconstruction, and never falls back to UEDB4;
- `Uedb5MetadataSnapshotWriter` builds and verifies a temporary V5 file, then replaces the canonical `.uedb5` path with a same-filesystem rename; it does not write `ue_file_metadata` or any SQL projection;
- `Uedb5DependencyRebuilder` reads only V5 snapshots, requires explicitly selected physical provider files, dispatches to the applicable source-family resolver, replaces the `dependency_results` section and writes it back through the V5 writer; classic resolver diagnostics are normalized to the frozen five-state outcome contract;
- `catalog/bin/verify-uedb5-production-reader-writer.php` and `catalog/bin/verify-uedb5-dependency-rebuilder.php` verify the production-capable V5 components and prove that matching `.uedb4` files are not touched;
- production `BlockedCompressedMetadataContainer` / `BlockedCompressedMetadataReader` remain format 4 only and continue to use `.uedb4` until the migration/cutover is ready.

The Step 3 components are production-capable file primitives, not production publication. They deliberately have no PDO dependency, no V5 registration lookup, no V5 SQL projection writer and no `try V5, else V4` path. UT99 source reparse and base V5 projection publication are implemented; remaining game builders, dependency second-pass publication, catalogue cutover and V4 retirement remain later implementation sections.

## Current UEDB5 SQL projection contract

Step 4 freezes the SQL boundary before any V5 projection publisher is allowed to create/populate database tables. The normative contract is `uedb5-sql-projection-contract.md`; `Uedb5SqlProjectionContract` exposes the same baseline table/column/outcome/classification rules to verification code.

The baseline projection is deliberately side-by-side under `ue_uedb5_*`: file registration/package identity, provider keys, one distinct normalized search-key dictionary, deduplicated per-file FName candidates, narrow ObjectName/public-export-hash object candidates, compact dependency edges, and dependency package summaries. The optional object-path candidate table is disabled by default and requires a measured query contract.

The baseline object candidate row contains only file/kind/index plus lookup hashes. Class package/name, class/super/template/outer graph, flags, serialization fields, preload ranges, raw Zen object indexes and resolver diagnostic payloads remain authoritative in `.uedb5`. `verify-uedb5-sql-projection-contract.php` also pins the current UT3 `SQL candidate export indexes -> UEDB source-row hydration` implementation as the model V5 resolver architecture.

Step 4 defines the contract only; Step 5 adds the staging registration/schema implementation described below. Production queries still remain on UEDB4.

## Current UEDB5 staging database registration

Step 5 adds migration `202609300001_uedb5_staging_registration.php` and `PdoUedb5StagingRegistrationRepository` without switching production runtime. The migration makes `ue_file_metadata` V5-capable by adding nullable `block_count`, `package_family`, `source_policy`, and `section_counts_json` columns, but it does not rewrite existing rows or change the one-row-per-file primary key.

Pre-cutover V5 registration therefore lives only in `ue_uedb5_files`. The repository re-verifies the canonical `.uedb5` file, derives size/hash/block count/source policy/section counts and package identity from that file, requires the corresponding verified catalogue file to remain registered as UEDB4, and then upserts the staged V5 row. It never inserts or updates `ue_file_metadata`. Classic families use a normalized package-name candidate key; Zen stores the exact 8-byte `FPackageId` candidate key.

The migration also creates the baseline Step 4 projection tables before catalogue migration begins. V5 projection rows foreign-key to `ue_uedb5_files`, so removing a staged registration cascades its V5-only projections. The optional object-path table remains absent/off by default. `verify-uedb5-staging-registration-contract.php` verifies both classic and Zen registration shapes and the V4/V5 isolation boundary.

## Current Step 6 source reparse migration

`Uedb5GameSourceMigrationService` and `catalog/bin/migrate-uedb5-game.php` provide the resumable game-by-game migration path. A file is read from the original verified store, checked against catalogue size/MD5/SHA1, parsed by the canonical game/engine reader, written as UEDB5, registered in `ue_uedb5_files`, and given base provider/search/FName/object projections. UEDB4 is never opened as migration input.

Pass 1 supports 1-8 local worker processes through `--workers=N`. Worker slots partition eligible file IDs by `MOD(file_id,N)`, so workers never select the same package and successful existing `ue_uedb5_files` registrations remain the resumable completion checkpoint. The shared search-key dictionary is published in deterministic fingerprint order, and base-projection transactions retry MySQL deadlock/lock-wait outcomes (`1213`/`1205`/`40001`) up to five bounded jittered attempts. A legacy single-worker invocation must not overlap a partitioned worker pool for the same game.

Step 6 now uses stable `game_id` as catalogue identity (`--game-id=N`). Mutable `ue_games.slug` is compatibility/UI metadata only and is resolved to an ID before migration logic begins. `Uedb5GameSourceRegistry` maps each supported game ID to a stable source/storage key; notably game `12` remains `unrealgold` for source policy and `games/unrealgold/verified` storage even if its database slug is renamed to `unreal`. Step 8 source revalidation and Step 11 source-coverage checks use the same registry.

Pass-1 source reparse is implemented for the registered game IDs/source keys `3/ut99`, `12/unrealgold`, `2/unreal2`, `4/ut2003`, `5/ut2004`, `6/ut3`, and `7/ut4`; classic `ue5` remains source-ready but has no invented catalogue game ID. **The assigned game profile is the game-version admission gate.** `package_version_min` / `package_version_max` define the ordinary accepted range, while explicit `compatibility_rules_json` header rules may admit an otherwise out-of-range package and select its reader. UEDB5 snapshot builders do not maintain a second private game-version ceiling. After the profile admits a package, the canonical engine reader and source-specific serializer rules determine whether the actual format is readable; malformed or genuinely unsupported package layouts still fail closed.

This matches the audited UE1/UE2 loader behavior. Unreal, UT99, Unreal II, UT2003, and UT2004 load the serialized package version into the archive and their `CheckVersion` path explicitly handles packages below `PACKAGE_MIN_VERSION`; they do not reject a package merely because its version is newer than that build's `PACKAGE_FILE_VERSION`. Neighboring versions may therefore be admitted by the game profile when appropriate. Version-specific source behavior remains authoritative after admission: for example, UT2004 source contains real `Ar.Ver() < 129` serialization branches, so UEDB5 retains the v129 source-policy distinction rather than treating 129 as an admission boundary. UE3/UE4/UE5 readers likewise retain source-backed format/layout capability checks; those checks answer whether the reader can correctly deserialize the package family and are distinct from the configurable game profile gate. Unversioned UE4/UE5 packages continue to use the assigned parser-profile assumptions while preserving serialized versus effective version state.

`Uedb5Ut99SnapshotBuilder`, the dedicated legacy-game wrappers, `Uedb5Ut3SnapshotBuilder`, `Uedb5Ut4SnapshotBuilder`, and `Uedb5Ue5ClassicSnapshotBuilder` all build from the original package bytes. This is implementation readiness, not a claim that every game catalogue has already been migrated. UE5 Zen/IoStore container migration remains a separate input path because `.utoc`/`.ucas` package-store identity is not the same one-file classic package model.

Base projection publication deliberately clears dependency projections. Dependency results are a second game pass after every provider candidate for that game has been staged, preventing partial-provider resolution from becoming authoritative. The live runtime remains UEDB4 throughout.

The Pass-2 resolver foundation is implemented by `Uedb5ClassicDependencyResolver` and `Uedb5DependencyRebuilder`: source-shaped UEDB5 snapshots now drive the audited UE1/UE2, UE3, UE4, UE5-classic and Zen dependency semantics without reading UEDB4 metadata. UE1 and UE2 dispatch through version-bounded source profiles rather than a shared legacy variant: Unreal II package versions 60-69 use the latest complete local v69 `VerifyImport`, which rejects private exports; UT2003 package versions 60-120 use v2107; later Unreal II/UT2003 versions fail closed for object verification because no complete local implementation proves inheritance. UT2004 package versions 60-129 use the latest complete local `UT2004SrcCmake` v129 `VerifyImport`; v130+ fail closed. The old UT2004 shared-legacy/ClassRemap path has been removed because neither ClassRemap nor the generic legacy ancestor handling is active source behavior. UE3 now dispatches through an exact UT3-v512/licensee-0 source profile: direct `NAME_None`, cooked export-outers, private-export SafeReplace context, runtime native/transient/`LOAD_FindIfFail` fallbacks, and `ObjectRedirector` evidence are preserved as distinct source outcomes; other UT3/UE3 versions fail closed instead of inheriting that profile. UE4 preserves the file-backed VerifyImport/redirector rules. `PdoUedb5PhysicalProviderSelector` discovers candidates from `ue_uedb5_provider_keys` and excludes known-invalid catalogue identities **without opening candidate contents to decide which provider wins**. Exactly one physical candidate is selected; more than one candidate becomes `provider_environment_ambiguous`/`unresolved` because the original runtime package-search or mount order is unavailable. Provider exports are never merged across files. `PdoUedb5DependencyProjectionPublisher` atomically replaces the V5 dependency-edge and package-summary SQL accelerators from authoritative `dependency_results`, using deterministic batched inserts, staging write guards and bounded contention retry. `Uedb5GameDependencyPassService` plus `migrate-uedb5-dependencies.php` now provide the resumable game-level Pass-2 orchestration: physical provider selection -> V5 dependency rebuild -> existing V5 registration/hash refresh -> V5 dependency projection publication -> exact-payload completion checkpoint. The checkpoint is valid only when `dependency_payload_sha256` equals the current `ue_uedb5_files.payload_sha256` under the current dependency policy, so partial publication or a later V5 rewrite is retried rather than silently skipped. Pass 2 does not read UEDB4 metadata. Catalogue execution remains pending.

## Current Step 7 staging isolation

Step 7 makes the pre-cutover coexistence rule executable. `Uedb5StagingIsolationContract` allows SQL writes only to the baseline `ue_uedb5_*` tables and rejects live V4 registration/projection targets. `Uedb5MetadataSnapshotWriter` asserts that the canonical `.uedb5` path is distinct from the corresponding `.uedb4` path before publication. `PdoUedb5StagingRegistrationRepository` and `PdoUedb5BaseProjectionPublisher` pass their write targets through the isolation guard.

`verify-uedb5-staging-isolation-contract.php` verifies the code boundary. `verify-uedb5-staging-coexistence.php` is a read-only live audit that proves no verified `ue_file_metadata` row has switched to format 5, every staged V5 registration still has a live format-4 registration, staged registrations are format 5, and sampled migrated files retain both `.uedb4` and `.uedb5` containers. Cutover remains a later explicit step.

## Current Step 8 migration validation

Step 8 is implemented by migration `202609300002_uedb5_migration_status.php`, `Uedb5MigrationStatus`, `PdoUedb5MigrationStatusRepository`, `Uedb5MigrationValidator`, and `Uedb5MigrationValidationService`. Every verified file can have one durable state: `pending`, `staged`, `validated`, or `failed`. Existing Step 6 staging is reconciled into this table without rerunning the source migration merely to create status rows.

Final validation reopens the original verified Unreal bytes and rebuilds a fresh source-shaped snapshot through `Uedb5SourceSnapshotFactory`, the same game/engine reader dispatch used by Step 6. The staged V5 source snapshot must exactly match that fresh parse after excluding only derived `dependency_results`. Container/manifest/registration integrity, source hashes, name/import/export counts, engine-specific source fields, base SQL projections, dependency completeness, and dependency SQL projections are all checked. Validation contains no UEDB4 metadata reader or `ue_file_metadata` dependency.

For UE4 unversioned packages, serialized version/licensee `0/0` and the source-policy parser-profile assumption are authoritative. A legacy `ue_files.package_version` value produced by an older parser assumption is not used to override or reject the fresh source parse; validation instead requires serialized `0/0`, `unversioned=true`, and an effective package version equal to the retained parser-profile assumption.

A correct Pass-1 file whose dependency Pass 2 is not yet complete remains `staged`, not `failed`. `validated` is tied to the exact V5 payload SHA-256 and validator-policy version, so a later dependency rebuild or validator-contract change makes old validation stale and requires revalidation. See `uedb5-migration-validation.md`.

## Current Step 9 game-level parity audit

Step 9 is implemented as a read-only pre-cutover harness by `Uedb5GameParityAuditService`, `Uedb5ParityV5ReadService`, `Uedb5GameParityExpectedDifferences`, and `audit-uedb5-game-parity.php`. The actual audit refuses to run until every verified file for the game still has live V4 registration, has staged V5 registration, and is Step-8 `validated`, with zero pending/staged/failed rows.

The audit compares behavioural outcomes rather than container bytes: search results, dependency counts and outcomes, physical provider selection, resolved object coverage, missing/base-game dependencies, Requires / Required By relationships, package aliases, duplicate-provider handling, public/private VerifyImport decisions, and invalid-file exclusions. V5 search uses narrow SQL candidates followed by `.uedb5` hydration; the V5 parity reader never reads UEDB4 bytes.

Intentional source fixes are accepted only through narrow evidence-backed rules. The initial `ut3_source_unresolved` rule permits V4 `missing` to become V5 `unresolved` only when the UEDB5 dependency row carries UE3 source/resolver evidence. This rule is propagated into affected aggregate comparisons rather than suppressing arbitrary differences. See `uedb5-game-parity-audit.md`.

## Current Step 10/11 cutover readiness

Step 10 remains the operational migration-completion target rather than an automated switch: the complete verified catalogue must have current validated V5 metadata and V5-derived projections before production cutover. No `try V5 else V4` mixed runtime is permitted.

Step 11 is implemented by `Uedb5CutoverReadinessVerifier`, `verify-uedb5-cutover-ready.php`, and `verify-uedb5-cutover-ready-contract.php`. Source-only mode checks the candidate V5 read/validation path for executable V4 fallback references. Database mode requires complete staged format-5 coverage, current Step-8 validation status/hash, invalid-file exclusion, provider/dependency baseline completeness, a registered source reader contract, and active game-profile/compatibility-rule coverage for every verified file. If any cheap global blocker remains, the expensive phase is skipped.

Once global blockers are zero, the cutover gate read-only runs `Uedb5MigrationValidator` again for every verified file. This rechecks current container/hash integrity, fresh authoritative source parity, engine-specific fields, provider/search/name/object projections, dependency completeness, and dependency SQL projections. An old `validated` status therefore cannot hide later container corruption or SQL projection drift. `cutover_ready=true` is emitted only when the database mode deep-validates the entire verified catalogue with zero failures. See `uedb5-cutover-readiness.md`.

Because Step 7 requires live UEDB4 to remain intact until the atomic switch, pre-cutover readiness does not require `ue_file_metadata` to have already changed to format 5. The gate instead proves that no verified file *needs* V4 for metadata correctness and that the prepared V5 read path has no V4 fallback. A later post-cutover contract must verify the live runtime/registrations are actually V5-only.

## Current UE5 classic UEDB5 persistence

Classic UE5 5.8.3 source-shaped persistence is implemented for offline/staging UEDB5 files without changing the production UEDB4 runtime:

- `Uedb5Ue5ClassicSnapshotBuilder` emits `package_family=classic-linkerload` and an explicit UE5 5.8.3 source-policy identifier;
- the summary block preserves the serialized package tag/byte order, serialized UE4/UE5/licensee versions, separate effective parser versions for unversioned packages, parser-profile identity, custom versions, package flags, `SavedHash`, engine-version records and other retained package identity;
- import rows preserve raw FName index/number/text for `ClassPackage`, `ClassName`, `ObjectName` and serialized `PackageName`, the signed raw `OuterIndex`, the post-load effective `PackageName`, and `bImportOptional`;
- export rows preserve raw `ClassIndex`, `SuperIndex`, `TemplateIndex`, `OuterIndex`, raw FName identity, serial size/offset, package flags, filter/inheritance/asset/public-hash bits, preload dependency ranges and script serialization offsets;
- version-gated import/export members carry explicit presence state; when a source version did not serialize a member, UEDB5 records it as absent/null rather than fabricating a serialized zero/false value;
- UE5 classic `ObjectFlags` are stored canonically as 16 hex digits while explicitly recording `object_flags_serialized_width_bits=32` and RF_Load-mask semantics, so the common representation cannot be mistaken for UE3 QWORD serialization;
- soft package references remain a separate section and retain every serialized FName row, including a serialized None entry if one exists;
- `catalog/bin/verify-uedb5-ue5-classic-persistence.php` verifies full `.uedb5` round-trip preservation, including the filtered-editor-only serialized/effective `PackageName` distinction and serialized-zero versus assumed version identity for unversioned packages.

The persistence layer itself does not publish UEDB5 SQL registrations or switch production readers. Classic UE5 `VerifyImportInner` source-parity resolution is implemented separately below; isolated Zen/IoStore staging is implemented below; production cutover remains pending.

## Current UE5 classic dependency resolution

The deterministic file-backed portion of UE5 5.8.3 `FLinkerLoad::VerifyImportInner()` is implemented for source-shaped UEDB5 staging metadata:

- `Uedb5Ue5ClassicVerifyImportResolver` consumes the Section 4 classic import/export schemas and requires one selected physical provider linker per package; it never combines exports from multiple same-name files into a synthetic provider;
- effective `PackageName` after source load-time fixups selects a provider linker independently of the outer chain; otherwise child imports inherit the outer import's resolved linker, while an import with an export outer requires explicit package identity;
- provider export traversal follows Epic's `ExportHash` ordering, including higher-index-first traversal for duplicate object names;
- same-linker and cross-linker outer checks reproduce the source graph rules; cross-linker outer class/package mismatch is retained as deferred create-time verification rather than rejecting the provider;
- requested class-package/name mismatch on the matched export likewise remains a resolved candidate with deferred class verification, matching `ImportsToVerifyOnCreate`;
- UE5 `RF_Public` is tested as `0x00000001`; the three source graph exceptions `ImportIsInAnyExport`, `AnyExportIsInImport`, and `AnyExportShareOuterWithImport` can permit an otherwise-private export;
- The current staging resolver labels an absent optional import `optional_missing` and unavailable live-runtime branches `runtime_only`. These are staging diagnostics only; the canonical UEDB5 dependency-results writer must normalize them to `missing` plus optional classification and `unresolved` plus runtime-derived classification, while retaining detailed reason/provenance.
- `catalog/bin/verify-uedb5-ue5-classic-dependency-resolution.php` verifies these rules, including that `RF_HasDynamicImports` does not cause synthetic serialized import rows.

This resolver is deliberately not wired into production `PdoDependencyResolver` / `CompactDependencyRebuilder`; those still consume UEDB4 metadata and must remain unchanged until the UEDB5 migration/publication cutover.


## Current UE5 Zen / IoStore staging implementation

Step 2 is implemented as an isolated UEDB5 staging path and does not alter production UEDB4 runtime behavior:

- `Uedb5IoStoreTocReader` reads UE5 5.8.3 `.utoc` framing, chunk IDs, offset/length records, perfect-hash sections, compression blocks/methods, signed/indexed framing and `.ucas` partitions; chunk reads use source-sized block reconstruction.
- `Uedb5IoStoreCodec` handles uncompressed, zlib and gzip blocks directly, AES-256 encrypted block reads when the caller supplies the key, and an explicit Oodle FFI boundary; Oodle or any unsupported codec fails closed when its runtime is unavailable.
- `Uedb5IoStoreContainerHeaderReader` reads current supported container-header versions, ordered package-store imports, shader-map hashes, optional-segment entries, redirects/localization and soft-reference relative views.
- `Uedb5ZenPackageReader` parses the Zen summary/versioning/name map, bulk-data map, imported public-export hashes, typed raw `FPackageObjectIndex` maps, ordinary and cell exports, export/dependency bundles and imported package names with source-sized span/bounds checks.
- `Uedb5Ue5ZenIoStoreSnapshotBuilder` persists the exact container/package-store provenance and Zen source-shaped sections under `package_family=zen-iostore`, keeping unsigned 64-bit identities as fixed-width hex and retaining the selected container's complete redirect/localization rows rather than filtering them to the current package.
- `Uedb5Ue5ZenDependencyResolver` resolves ordinary PackageImports by serialized `FPackageId + PublicExportHash` against the provider ordinary export map and cell imports against the provider cell-export map. Explicit package-store redirects change only the effective provider lookup ID; the required/source package ID remains unchanged for audit/projection. The same public-export hash is then checked inside the redirected target package. Culture-dependent localization remains `unresolved` unless authoritative runtime/editor context is supplied, while soft-reference enumeration retains raw source IDs. The resolver follows Epic table order for duplicate hashes, preserves ScriptImport as unresolved without script-object runtime context, separates soft/optional/cell/load-order classifications, emits only the five canonical UEDB5 outcomes, and uses prefixed string keys internally so fixed-width unsigned-64 IDs/hashes cannot be coerced into PHP integer array keys.
- `PdoUedb5PhysicalProviderSelector` selects Zen candidates by the effective provider-lookup `FPackageId` when a package-store redirect applies; it still refuses to content-score duplicate physical providers. `Uedb5DependencyRebuilder` propagates ambiguity against that effective lookup ID without rewriting the serialized required package identity. `Uedb5DependencyProjectionBuilder` continues to project the serialized required `FPackageId`/`PublicExportHash` as the dependency key while `resolved_file_id` identifies the selected redirected provider.
- `verify-uedb5-ue5-iostore-foundation.php`, `verify-uedb5-ue5-zen-package.php`, `verify-uedb5-ue5-zen-dependency-resolution.php`, `verify-uedb5-physical-provider-selector.php`, `verify-uedb5-dependency-rebuilder.php`, and `verify-uedb5-dependency-projection-publisher.php` cover container reconstruction, Zen source-shape/UEDB5 round-trip, redirect/localization/provider semantics, unsigned-64 key preservation, range rejection and dependency-resolution semantics.

This completes the isolated UE5 Zen/IoStore staging scope. It does not implement UE5 AssetRegistry build/cook dependency metadata, V5 SQL publication, catalogue-wide migration, production cutover, or V4 retirement.


## UE5 implementation checklist for UEDB5

Before full UE5 support can be marked complete, the UEDB5 update must include all of the following. Classic and Zen/IoStore staging are implemented; production publication/cutover, UE5 AssetRegistry build/cook dependency ingestion, and the remaining catalogue-wide migration/projection work remain:

1. [implemented in `b95dfe2c`] a dedicated UE5 reader using `FPackageFileVersion` and the UE5 summary/import/export gates, not `UnrealPackageReader4`;
2. [implemented by `Uedb5Ue5ClassicSnapshotBuilder`] classic UE5 metadata blocks retaining `PackageName`, `bImportOptional`, complete raw package-index graphs and public-hash semantics;
3. [implemented by `Uedb5Ue5ClassicVerifyImportResolver`] a UE5 classic dependency resolver implementing the audited deterministic file-backed `VerifyImportInner` rules without borrowing UE3 exact-tuple policy; runtime-only branches remain explicitly unresolved;
4. [implemented by `Uedb5IoStoreTocReader`, `Uedb5IoStoreContainerHeaderReader`, and `Uedb5IoStoreCodec`] IoStore `.utoc`/`.ucas` ingestion preserving package-store provenance, redirects, optional segments, soft references, partition/block framing, compression dispatch and AES-key boundaries;
5. [implemented by `Uedb5ZenPackageReader` and `Uedb5Ue5ZenIoStoreSnapshotBuilder`] Zen metadata blocks for PackageId/public-export-hash identity, typed `FPackageObjectIndex` values, export/dependency bundles, script imports and cell maps, using lossless unsigned-64 storage rather than PHP signed integers;
6. [Zen/IoStore portion implemented by `Uedb5Ue5ZenIoStoreSnapshotBuilder` and `Uedb5Ue5ZenDependencyResolver`] separate hard, optional, soft, script, cell/Verse, load-order and runtime-derived classifications; build/cook dependencies remain separate AssetRegistry-source work and must not be fabricated from Zen package bytes;
7. [base projection publication, V5 dependency resolver/provider selection, game-level Pass-2 orchestration and dependency SQL publication implemented] compact SQL projections only for fields that need indexed catalog lookup; auxiliary UE5 source blocks remain in compressed `.uedb5` metadata by default;
8. [classic Pass-1 source reparse and Pass-2 execution tooling implemented game-by-game] migration/verification reparses original bytes whenever UEDB4 did not retain a required serialized field; catalogue-wide Pass-2 execution, Zen/IoStore catalogue migration, final validation/parity and cutover remain pending.

## UEDB4 to UEDB5 migration rules

A migration must never synthesize serialized identity that UEDB4 discarded.

Known mandatory source-byte reparses include:

- UE3 files whose complete 64-bit ObjectFlags were not retained in UEDB4;
- UE4/UE5 classic packages for which serialized `FObjectImport::PackageName` is required but absent from UEDB4;
- UE5 packages requiring `bImportOptional` or other UE5-only import/export fields not present in UEDB4;
- all Zen/IoStore package metadata, because UEDB4 has no representation for its PackageId/hash/object-index identity model.

A container-only conversion is permitted only for an engine/source policy that has been audited and proven to have every UEDB5-required source field already present losslessly in UEDB4. Absence of a field must fail conversion; it must not trigger inference from paths, names, hashes, or neighboring records.

The safest default migration is therefore:

`authoritative Unreal package/container bytes -> source-specific reader -> UEDB5 -> projections -> dependency rebuild`

not:

`UEDB4 -> guessed UEDB5`

If the authoritative source bytes required for a mandatory reparse are unavailable, the file must not be registered as fully verified UEDB5 metadata.

## Cutover requirement

UEDB5 should use a clean production cutover consistent with the existing metadata policy:

1. introduce a new format constant, magic and `.uedb5` extension;
2. implement source-specific UEDB5 readers/writers without adding UEDB4 compatibility branches to the production v5 reader;
3. reparse or audited-convert files into UEDB5;
4. verify the UEDB5 container against the authoritative package source fields;
5. publish v5 SQL projections;
6. rebuild source-specific dependency data from UEDB5;
7. switch runtime registration to format 5 only;
8. delete retired UEDB4 containers only after verification;
9. remove temporary migration tooling after cutover.

UEDB5 verification must compare source-derived raw fields, not merely counts and derived hashes.


## UE5 source-reference matrix

| Requirement | UE5 5.8.3 proving source |
|---|---|
| UE5 global package version model | `Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h`, `EUnrealEngineObjectUE5Version`, `FPackageFileVersion` comments |
| Package summary/version gates | `Engine/Source/Runtime/CoreUObject/Public/UObject/PackageFileSummary.h` and `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp`, `FPackageFileSummary` serializer |
| `FObjectImport` fields, `PackageName` fixup, `bImportOptional` | `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp`, `operator<<(FStructuredArchive::FSlot, FObjectImport&)` |
| `FObjectExport` version gates and flags | `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp`, `operator<<(FStructuredArchive::FSlot, FObjectExport&)` |
| UE5 classic RF_Public / RF_Load object-flag values | Engine/Source/Runtime/CoreUObject/Public/UObject/ObjectMacros.h, EObjectFlags, RF_Load |
| Classic import/provider resolution, export-outers, deferred class verification and private-export exceptions | `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`, `FLinkerLoad::VerifyImportInner` |
| Private-import containment tests | `Engine/Source/Runtime/CoreUObject/Private/UObject/Linker.cpp`, `ResourceIsIn`, `ImportIsInAnyExport`, `AnyExportIsInImport`, `AnyExportShareOuterWithImport` |
| Runtime-created dynamic imports | `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`, `FLinkerLoad::AddDynamicImports` |
| Soft-object-path versioned serialization | `Engine/Source/Runtime/CoreUObject/Private/UObject/SoftObjectPath.cpp`, `FSoftObjectPath::SerializePathWithoutFixup` |
| Asset-registry import/soft/build dependency metadata | `Engine/Source/Runtime/AssetRegistry/Private/PackageReader.cpp`, `ReadPackageDataDependencies`; `Engine/Source/Runtime/CoreUObject/Private/UObject/SavePackage/SavePackageUtilities.cpp`, `WritePackageData` |
| Zen object index and package-import reference encoding | `Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h`, `FPackageImportReference`, `FPackageObjectIndex`, `FPublicExportKey` |
| Package ID raw `uint64` identity | `Engine/Source/Runtime/Core/Public/IO/PackageId.h`, `FPackageId` |
| Zen summary, exports, dependency bundles and cell maps | `Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h`, `FZenPackageSummary`, `FExportMapEntry`, `FDependencyBundleHeader`, `FDependencyBundleEntry`, `FZenPackageCellOffsets` |
| Zen header table slicing and imported package names | `Engine/Source/Runtime/CoreUObject/Private/Serialization/ZenPackageHeader.cpp`, `FZenPackageHeader::MakeView` |
| Package-store IDs, optional segments and soft references | `Engine/Source/Runtime/CoreUObject/Public/Serialization/PackageStore.h`, `FPackageStoreEntry`, `FPackageStoreEntryResource` |
| IoStore container-owned package store, redirects and optional segments | `Engine/Source/Runtime/Core/Public/IO/IoContainerHeader.h`, `FFilePackageStoreEntry`, `FIoContainerHeader`; `Engine/Source/Runtime/PakFile/Private/FilePackageStore.cpp`, `FFilePackageStoreBackend::Mount`, `Update`, `GetPackageRedirectInfo`, `GetSoftReferences` |
| IoStore file containers (`.utoc` / `.ucas`) | `Engine/Source/Runtime/Core/Internal/IO/IoStore.h`, `IIoStoreTocReader::ReadFromDisk`; `Engine/Source/Runtime/Core/Public/IO/IoDispatcher.h`, `FIoContainerSettings` |
| Public-export hash generation and `bGeneratePublicHash` | `Engine/Source/Developer/IoStoreUtilities/Private/PackageStoreOptimizer.cpp`, `FPackageStoreOptimizer::ProcessExports` |
| Zen imported-package redirect/load order and target export-hash lookup | `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, `FAsyncPackage2::ImportPackagesRecursiveInner`, `ConditionalCreateImport`, `ConditionalSerializeImport`, `ConditionalCreateCellImport`, `ConditionalSerializeCellImport` |
| Cell/Verse resources | `Engine/Source/Runtime/CoreUObject/Public/UObject/ObjectResource.h`, `FCellResource`, `FCellImport`, `FCellExport` |
| Import type hierarchy reading | `Engine/Source/Runtime/AssetRegistry/Private/PackageReader.cpp`, `FPackageReader::ReadImportTypeHierarchies` |

This matrix is intentionally limited to behaviors audited for UEDB5. The dedicated UE5 classic package-format and dependency-resolution specifications cover the implemented LinkerLoad reader and deterministic file-backed `VerifyImportInner` resolver. `ue5-5.8.3-zen-iostore-format.md` now covers the source-audited `.utoc`/`.ucas`, package-store, Zen-header, PackageImport/public-export-hash, export/dependency-bundle, cell-map, optional-segment, redirect/localization, soft-reference, and codec/encryption boundaries. The isolated Zen/IoStore reader, UEDB5 persistence path, and source-keyed staging dependency resolver are now implemented. Production publication/cutover and AssetRegistry build/cook dependency work remain separate.
