# All UnrealDB code paths requiring Epic parity assessment

The [readable acceptance plan](TEST-PLAN.md) lists why and what each of 24 file behaviours must prove. The [complete code-path index](code-paths.json) lists every tracked production PHP, JavaScript, TypeScript and SQL source under catalog, including API endpoints, plus retained operational catalog/bin commands with its current SHA. Roles are initial discovery hints. [callgraph.json](callgraph.json) supplies lexical class-reference edges but cannot resolve reflection, SQL-driven jobs or runtime control flow. Each applicable contract is only accepted after actual call-path tracing.

## Confirmed reader dispatch (source inspected, not yet end-to-end certified)

Catalog source snapshot factory: catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php selects the game source profile, checks the game version and calls CatalogReaderResolver::resolve() in catalog/src/Infrastructure/Readers/CatalogReaderResolver.php.

| Engine | Canonical reader | Snapshot family | Import verification |
|---|---|---|---|
| UE1 | catalog/src/Infrastructure/Readers/CatalogUE1PackageReader.php | Uedb5UnrealSnapshotBuilder.php and Uedb5Ut99SnapshotBuilder.php | PdoUe1VerifyImportProjectionResolver.php |
| UE2 | catalog/src/Infrastructure/Readers/CatalogUE2PackageReader.php | Uedb5Unreal2SnapshotBuilder.php, Uedb5Ut2003SnapshotBuilder.php, Uedb5Ut2004SnapshotBuilder.php | PdoUe2VerifyImportProjectionResolver.php |
| UE3 | catalog/parsers/UE3CatalogReader.php (CatalogUE3PackageReader), with EpicUE3PackageReader.php | Uedb5Ut3SnapshotBuilder.php | PdoUe3VerifyImportProjectionResolver.php |
| UE4 | UE4/UnrealPackageReader.php (UnrealPackageReader4 configured reader) | Uedb5Ut4SnapshotBuilder.php | PdoUe4VerifyImportProjectionResolver.php |
| UE5 classic | UE5/UnrealPackageReader.php (UnrealPackageReader5) | Uedb5Ue5ClassicSnapshotBuilder.php | Uedb5Ue5ClassicVerifyImportResolver.php as source-selected |
| UE5 Zen/IoStore | Uedb5IoStoreTocReader.php, Uedb5ZenPackageReader.php, Uedb5Ue5ZenIoStoreSnapshotBuilder.php | Zen IoStore snapshot builder via IoStore publisher/import | Uedb5Ue5ZenDependencyResolver.php |

The UE4/UE5 reader files are **outside catalog/**, and were missing from the first code-path index. The code-path index now includes root UE4 and UE5. The Graphify UnrealDB source-only graph currently indexes catalog/, so it does not show those external root readers.

UE2's original source has backward-compatible package loading: `PACKAGE_MIN_VERSION 60` in Unreal II 2000, UT2003 v2107, UT2004 v3186/v3369 and Unreal Warfare UE2.5. `ULinkerLoad` reads the package's serialized version before importing name, import and export tables; pre-60 files trigger an interactive `OldVersion` prompt, not automatic admission. Native `VerifyImport` must still resolve the required classes and source packages. A header match alone never proves gameplay/runtime loadability.

UnrealDB dispatches Unreal II and UT2003 legacy-compatible packages to `CatalogUE2PackageReader`, and UT2004's profile-selected pre-v100 UE1-wire-format packages to the distinct UE2-owned `CatalogUE2LegacyCompatibilityPackageReader`. Verified import and unverified staging/promotion now use the destination game's source-profile decision; they do not load `CatalogUE1PackageReader` merely because a UE2 asset has a UE1-like package version. The UEDB5 source snapshot factory already used the UE2-owned compatibility branch. Never import the UE1 parser to satisfy a UE2 source selection.

Cross-generation original-package differential (2026-11-02, DEV): Unreal 1998 `Female2Skins.utx` v61 SHA256 `198ea3d832875767932c9539fb2fedbea357b4cb670ed41d77f74f213ea74576` (34 names, 3 imports, 16 exports) and UT99 v432 `Engine.u` v69 SHA256 `d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695` (3345 names, 60 imports, 5370 exports). Both UE2-owned readers reproduced UE1 decoded table counts and corresponding name/object identities; Unreal II, UT2003 and UT2004 compatibility table snapshots built successfully. This is **PHP table-format comparison only**; not a genuine UE2 native runtime `VerifyImport` proof or an end-to-end published UEDB5 acceptance. The disposable probe remains in `C:/Temp/UnrealDB/UE2/compatibility/source_owned_probe.php`.

UE1 v69 alone cannot identify Unreal v1.227 versus UT99 source profile. Source and game context remain mandatory before declaring a package understood.

Common downstream candidates: Uedb5ClassicDependencyResolver.php; Uedb5GameDependencyPassService.php; Uedb5DependencyRebuilder.php; PdoUedb5PhysicalProviderSelector.php; Uedb5RuntimeProviderSnapshotLoader.php; PdoUedb5DependencyProjectionPublisher.php; CatalogParsedPackageMetadataSnapshotBuilder.php; PdoDependencyResolver.php. These are identified components but not proof of a complete runtime invocation chain from every entrypoint.

## Production flow

User upload / source scan → file and source-profile selection → package/container reading → summary, name, import and export tables → serialized properties, native object and script references → staged UEDB5 snapshot → original mount/provider selection → VerifyImport and dependencies → SQL publication → file-examine, missing-dependency and download outputs.

| Boundary | Key existing UnrealDB paths | Contracts |
|---|---|---|
| User intake | catalog/public-upload.php, profiled-upload.php, source-scan.php and Application services | F01,F02,F18 |
| Reader selection | catalog/src/Infrastructure/Readers/CatalogReaderResolver.php, CatalogReaderResolver.php, CatalogUE1ClassicSourceFamily.php | F01–F06 |
| Classic source/serialization | CatalogUE1ClassicGameSourceOverlay.php, CatalogUE1ClassicTaggedPropertyReader.php, CatalogUE1ClassicNativeObjectReader.php, CatalogUE1ClassicScriptArchiveReader.php | F02–F10 |
| IoStore/Zen/Pak | Infrastructure/Metadata/Uedb5ZenPackageReader.php, Uedb5IoStoreTocReader.php, Uedb5ZenMountedStoreContextBuilder.php, catalog/iostore-* and pak-* | F03–F07,F23,F24 |
| Import verification | Infrastructure/Persistence/PdoUe1VerifyImportProjectionResolver.php, PdoUe2VerifyImportProjectionResolver.php, PdoUe3VerifyImportProjectionResolver.php, PdoUe4VerifyImportProjectionResolver.php | F12–F15 |
| Physical package provider | Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php, Uedb5RuntimeProviderSnapshotLoader.php, Uedb5ZenMountedStoreContextBuilder.php | F11,F14,F15 |
| Dependency graph | Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php, Uedb5Ue5ZenDependencyResolver.php, Uedb5GameDependencyPassService.php, Uedb5DependencyRebuilder.php, Uedb5DependencyProjectionBuilder.php | F12–F17 |
| UEDB5 persistence | Infrastructure/Metadata/PdoUedb5DependencyProjectionPublisher.php, PdoUedb5ProviderKeyPublisher.php, PdoUedb5ObjectCandidateSynchronizer.php, and Infrastructure/Persistence | F16–F20 |
| User outcomes | catalog/file-examine.php, file-dependency-files.php, missing.php, game-missing.php, dependency-cross-examine.php, download-package.php and Infrastructure/Downloads | F17,F21,F22 |
| Jobs and error recovery | Infrastructure/Maintenance, Infrastructure/Import, Infrastructure/Telemetry, background-jobs.php, and retry queues | F17–F20 |

## Highest-risk examples

An Unreal v1.227 and UT99 v432 file can share package version 69 but need different source profiles. Duplicate physical classic packages are selected by original loader search order and already-loaded linkers; upload order is not authoritative. A package absent from a database is not proof that a particular running game would consider it missing. Misread name, class, outer or export identity can produce a false missing dependency even when the bytes decode without error. Correctness includes final UEDB5 edges and user-facing results, not only package parser output.

Use Epic code under L:\Source as source of truth, engine base plus proven game-specific override. See specs/engine-game-source-precedence.md. Any unsupported provenance must remain explicit UNKNOWN/AMBIGUOUS instead of selecting a different game revision.

## Reader separation and cross-engine change impact

UE1 through UE5 now have independent concrete parsers. UE1 and UE2 each own their full package-summary/name/import/export parser, bounded byte streams and table snapshot serializers; they do not share engine-specific reader modules. Exact original package decoding and UEDB5 snapshot digests were compared before and after splitting the source on four real UE1/UE2 packages. Only generic catalog job, persistence and dependency services remain cross-engine impact boundaries.

Run python tests/tools/impact.py --path catalog/src/Infrastructure/Readers/CatalogUE1BinaryStream.php to get affected existing operation cases, before authorising focused regressions. Versioned Epic source overlays must be attached to the applicable case rather than silently changing a different game.
