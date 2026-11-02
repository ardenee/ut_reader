# All UnrealDB code paths requiring Epic parity assessment

The [readable acceptance plan](TEST-PLAN.md) lists why and what each of 24 file behaviours must prove. The [complete code-path index](code-paths.json) lists every tracked production PHP, JavaScript, TypeScript and SQL source under catalog, including API endpoints, plus retained operational catalog/bin commands with its current SHA. Roles are initial discovery hints. [callgraph.json](callgraph.json) supplies lexical class-reference edges but cannot resolve reflection, SQL-driven jobs or runtime control flow. Each applicable contract is only accepted after actual call-path tracing.

## Production flow

User upload / source scan → file and source-profile selection → package/container reading → summary, name, import and export tables → serialized properties, native object and script references → staged UEDB5 snapshot → original mount/provider selection → VerifyImport and dependencies → SQL publication → file-examine, missing-dependency and download outputs.

| Boundary | Key existing UnrealDB paths | Contracts |
|---|---|---|
| User intake | catalog/public-upload.php, profiled-upload.php, source-scan.php and Application services | F01,F02,F18 |
| Reader selection | catalog/src/Infrastructure/Readers/CatalogReaderResolver.php, CatalogLegacyPackageReader.php, CatalogUE1ClassicSourceFamily.php | F01–F06 |
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
