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

## Authoritative repositories

| Area | Repository | Source role |
|---|---|---|
| UE1 / UT99 retail v1.400 | `ardenee/UT99src` | Primary UT99 retail source of truth; use the `main` branch retail tree, including `Unreal Tournament [v1.400] [1999-11-30] (Retail)` |
| UE1 / UT99 later code | `ardenee/UT99src-ext` | Supplemental newer UT99 source; may omit base files, so use only for explicitly newer behavior and never to overwrite retail rules without documenting the revision difference |
| Unreal II / UE2 | `ardenee/unreal2src` | Unreal II / UE2 source of truth |
| UE2.5 | `ardenee/UE2.5` | UE2.5 source of truth |
| UT2003 | `ardenee/Unreal_Tournament_2003_v2107` | UT2003 source of truth |
| UT2004 | `ardenee/UT2004src` | UT2004 source of truth |
| UE3 | `ardenee/UE3src` | Primary UE3 source of truth |
| UT3 | `ardenee/UT3src-comunity` | UT3 game-specific supplemental source |
| UE4 | `ardenee/UnrealEngine4` | UE4 source of truth; 4.27.2-release lineage |
| UE5 | unavailable | Not documented until authoritative source is available |

## Required specification coverage

### Unreal package formats and readers

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
- [x] UE4 package format and reading — `ue4-4.27.2-package-format.md`
- [x] UE4 dependency resolution — `ue4-4.27.2-dependency-resolution.md`
- [ ] UE5 package format and reading — blocked until source is available

### Redirect/archive/mod formats

- [x] UZ — `uz-compression-decompression.md`
- [x] UZ2 — `uz2-compression-decompression.md`
- [x] UZ3 — `uz3-ue3-compression-decompression.md`
- [x] UMOD — `umod-format.md`
- [x] UT2MOD — `ut2mod-format.md`
- [ ] UT4MOD
- [ ] UPK format
- [ ] UPK compression handling
- [ ] UPK encryption handling, where applicable
- [ ] PAK format
- [ ] PAK compression handling
- [ ] PAK encryption handling

### Shared serialization and package semantics

- [ ] Primitive persistent serialization and byte order
- [ ] Compact index encoding
- [ ] Name-table serialization and version changes
- [ ] Package summary/version encoding
- [ ] GUID/package identity
- [ ] Import indices
- [ ] Export indices
- [ ] Outer/package-index traversal
- [ ] Import class identity
- [ ] Export class identity
- [ ] Serialized object payload boundaries
- [ ] Package flags/object flags relevant to loading
- [ ] Compression dispatch
- [ ] Encryption dispatch

### Dependency operations

- [ ] Exact import verification rules by engine revision
- [ ] Package-provider selection
- [ ] Object-path construction
- [ ] Required package identification
- [ ] Required object identification
- [ ] Class/package matching
- [ ] Outer matching
- [ ] Engine-specific fallbacks that are actually present in source
- [ ] Runtime-only fallbacks that UnrealDB cannot reproduce from package data

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

