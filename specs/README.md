# UnrealDB Official Format Specifications

This directory is the source-backed specification library for UnrealDB.

Its purpose is to let an engineer implement, review, or repair UnrealDB's parsers and dependency logic without first needing access to the original Epic/game source trees.

## Specification rules

1. **The supplied Epic/game source is authoritative.**
2. Document behavior exactly as implemented by the relevant engine/game revision.
3. Do not invent compatibility behavior, heuristics, fallback rules, fields, limits, or validation rules that are not present in source.
4. Do not omit serialized fields or conditional/version-dependent branches.
5. Where engine/game revisions differ, document the difference explicitly rather than flattening them into a generic rule.
6. Runtime/config-only behavior that cannot be reconstructed from package bytes must be identified as unavailable to UnrealDB rather than emulated.
7. A source reference must identify the repository, revision/branch, file, symbol/function, and the behavior it proves.
8. If a source file or implementation body is unavailable, mark that part as unresolved. Do not fill the gap from memory or third-party documentation.
9. UnrealDB implementation notes are secondary. Each spec first states what the official engine/game code does.
10. Source-compatible behavior takes precedence over convenience or historical UnrealDB behavior.

Current implementation audit ledger: `epic-source-conformance.md`.

## Authoritative local source trees

Current conformance work must use the local source mirrors below. Historical GitHub repository names may remain in older spec provenance, but they are not a substitute for checking these local trees when auditing current code.

| Area | Local source authority |
|---|---|
| UE2 / UE2.5 | `L:\Source\Engine\UE2` |
| UE3 | `L:\Source\Engine\UE3` |
| UE4 | `L:\Source\Engine\UE4` |
| UE5 | `L:\Source\Engine\UE5` |
| UDK / later UE3 | `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]` (engine 8364) and `L:\Source\Engine\UE3\Unreal Engine 3 (10897)` (engine 10897 / changelist 1532151). The audited 10897 Core files are byte-identical to `CodeRedModding/UnrealEngine3`. |
| Unreal | `L:\Source\Games\Unreal` |
| Unreal II | `L:\Source\Games\Unreal II` |
| UT99 | `L:\Source\Games\UT99` |
| UT2003 | `L:\Source\Games\UT2003` |
| UT2004 | `L:\Source\Games\UT2004` |
| UT3 | `L:\Source\Games\UT3` |
| UT4 | `L:\Source\Games\UT4` |
| Tools/reference utilities | `L:\Source\Tools` |

## Required specification coverage

### Unreal package formats and readers

- [x] Unreal / Unreal Gold UE1 v227 package serialization and pre-dependency preprocessing — `unreal-v227-package-format.md` (latest public v227 surface audited independently; missing Core serializer/linker bodies explicitly remain unresolved)
- [x] UE1 / UT99 package format and reading — `ue1-ut99-retail-v1400-package-format.md`
- [x] UE1 / UT99 dependency and import resolution — `ue1-ut99-retail-v1400-dependency-resolution.md`
- [x] Unreal II / UE2 package format and reading — `unreal2-ue2-package-format.md`
- [x] Unreal II / UE2 dependency and import resolution — `unreal2-ue2-dependency-resolution.md`
- [x] UE2.5 package format and reading — `ue2.5-unreal-warfare-package-format.md`
- [x] UE2.5 dependency and import resolution — `ue2.5-unreal-warfare-dependency-resolution.md`
- [x] UT2003 package/version differences — `ut2003-v2107-package-format.md`
- [x] UT2003 dependency and import resolution — `ut2003-v2107-dependency-resolution.md`
- [x] UT2004 package/version differences — `ut2004-package-format.md`
- [x] UT2004 dependency and import resolution — `ut2004-dependency-resolution.md`
- [x] UE3 package format and reading — `ue3-udkultimate-package-format.md`
- [x] UE3 dependency resolution — `ue3-udkultimate-dependency-resolution.md`
- [x] UT3 package-version-512 dependency resolution — `ut3-v512-dependency-resolution.md`
- [x] UE4 package format and reading — `ue4-4.27.2-package-format.md`
- [x] UE4 dependency resolution — `ue4-4.27.2-dependency-resolution.md`
- [x] UE5 5.8.3 classic package format and reading — `ue5-5.8.3-classic-package-format.md` (classic LinkerLoad path implemented; Zen/IoStore is implemented separately in isolated UEDB5 staging)
- [x] UE5 5.8.3 classic dependency resolution — `ue5-5.8.3-classic-dependency-resolution.md` (UEDB5 staging/source-parity `VerifyImportInner` resolver implemented; production cutover remains pending)
- [x] UE5 5.8.3 Zen / IoStore format — `ue5-5.8.3-zen-iostore-format.md` (source-audited `.utoc`/`.ucas`, package-store and Zen-header contract; isolated reader, UEDB5 persistence and Zen dependency resolver implemented)
- [x] UEDB5 normative format contract — `uedb5-format.md` (physical container and schema contract frozen; isolated production-capable V5 reader/writer/dependency rebuilder implemented while live runtime remains UEDB4)
- [x] UEDB5 SQL projection contract — `uedb5-sql-projection-contract.md` (minimal side-by-side `ue_uedb5_*` accelerator schema frozen; source-shaped class/outer/flags/serialization data remains file-backed)
- [x] UEDB5 staging isolation contract — `uedb5-staging-isolation.md` (Step 7 actively guards `.uedb4` + live V4 SQL from V5 staging writes until atomic cutover)
- [x] UEDB5 migration validation contract — `uedb5-migration-validation.md` (Step 8 durable pending/staged/validated/failed state, fresh-source reparse validation, exact projection agreement, resumable batches)
- [x] UEDB5 game-level parity audit - `uedb5-game-parity-audit.md` (Step 9 read-only V4-production vs staged-V5 behavioural audit harness; execution waits for full V4/V5 coverage plus current completed Pass-2 dependency payloads)
- [x] UEDB5 cutover readiness gate - `uedb5-cutover-readiness.md` (Step 11 strict read-only whole-catalogue gate; source-only fallback checks plus exhaustive final V5/source/projection revalidation once global blockers are zero)
- [x] UEDB5 source-audit requirements — `uedb5-metadata-requirements.md` (format-5 foundation, isolated V5 reader/writer/dependency rebuild, Step 5 staging schema, and resumable Step 6 Pass-1 source reparse are implemented for UT99, Unreal, Unreal II, UT2003, UT2004, UT3, UT4 and UE5 classic; dependency second-pass, Zen/IoStore catalogue migration, migration execution/verification and cutover remain pending)

### Redirect/archive/mod formats

- [x] UZ — `uz-compression-decompression.md`
- [x] UZ2 — `uz2-compression-decompression.md`
- [x] UZ3 — `uz3-ue3-compression-decompression.md`
- [x] UMOD — `umod-format.md`
- [x] UT2MOD — `ut2mod-format.md`
- [x] UT4MOD — `ut4mod-format.md`
- [x] UPK format — `upk-format.md`
- [x] UPK compression handling — `upk-compression-handling.md`
- [x] UPK encryption handling, where applicable — `upk-encryption-handling.md`
- [x] PAK format — `pak-format.md`
- [x] PAK compression handling — `pak-compression-handling.md`
- [x] PAK encryption handling — `pak-encryption-handling.md`

### Shared serialization and package semantics

- [x] Primitive persistent serialization and byte order — `primitive-persistent-serialization-byte-order.md`
- [x] Compact index encoding — `compact-index-encoding.md`
- [x] Name-table serialization and version changes — `name-table-serialization-version-changes.md`
- [x] Package summary/version encoding — `package-summary-version-encoding.md`
- [x] GUID/package identity — `guid-package-identity.md`
- [x] Import indices — `import-indices.md`
- [x] Export indices — `export-indices.md`
- [x] Outer/package-index traversal — `outer-package-index-traversal.md`
- [x] Import class identity — `import-class-identity.md`
- [x] Export class identity — `export-class-identity.md`
- [x] Serialized object payload boundaries — `serialized-object-payload-boundaries.md`
- [x] Package flags/object flags relevant to loading — `package-object-flags-loading.md`
- [x] Compression dispatch — `compression-dispatch.md`
- [x] Encryption dispatch — `encryption-dispatch.md`

### Dependency operations

- [x] Exact import verification rules by engine revision — `dependency-exact-import-verification.md`
- [x] Package-provider selection — `dependency-package-provider-selection.md`
- [x] Object-path construction — `dependency-object-path-construction.md`
- [x] Required package identification — `dependency-required-package-identification.md`
- [x] Required object identification — `dependency-required-object-identification.md`
- [x] Class/package matching — `dependency-class-package-matching.md`
- [x] Outer matching — `dependency-outer-matching.md`
- [x] Engine-specific fallbacks that are actually present in source — `dependency-engine-specific-fallbacks.md`
- [x] Runtime-only fallbacks that UnrealDB cannot reproduce from package data — `dependency-runtime-only-fallbacks.md`

## UE1 / UT99 source status

Repository: `ardenee/UT99src`

The authoritative UT99 retail implementation is available on the `main` branch under:

`Unreal Tournament [v1.400] [1999-11-30] (Retail)/Core/Src/`

Verified relevant files include:

- `UnObj.cpp`
- `UnName.cpp`
- `UnLinker.h`
- `UnGUID.cpp`
- `UnProp.cpp`

The corresponding retail headers are under:

`Unreal Tournament [v1.400] [1999-11-30] (Retail)/Core/Inc/`

including `UnObjVer.h`, which defines the retail package tag/version contract. The retail v1.400 tree is the primary source for the first UE1/UT99 specification. `ardenee/UT99src-ext` is supplemental newer code and must be documented as a revision-specific difference rather than merged silently into the retail format.

## Document structure

Each completed format specification should contain:

1. Scope and exact engine/game revision.
2. Authoritative source references.
3. Magic/signature and version identification.
4. Primitive serialization rules.
5. Header/summary layout in serialized order.
6. Version-conditional fields.
7. Name-table encoding.
8. Import-table encoding.
9. Export-table encoding.
10. Index semantics and object/outer traversal.
11. Payload/data serialization boundaries.
12. Compression/encryption rules where applicable.
13. Load/validation sequence.
14. Dependency/import-resolution behavior where part of the format.
15. Known revision differences.
16. Behaviors that depend on runtime/configuration and therefore cannot be inferred from package bytes.
17. UnrealDB conformance requirements.
18. Source-reference matrix mapping every rule to its proving file/symbol.

