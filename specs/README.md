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
| UE1 / UT99 | `ardenee/UT99src` | Primary UE1/UT99 source of truth |
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

- [ ] **UE1 / UT99 package format and reading** — in progress
- [ ] UE1 / UT99 dependency and import resolution
- [ ] Unreal II / UE2 package format and reading
- [ ] Unreal II / UE2 dependency and import resolution
- [ ] UE2.5 package format and reading
- [ ] UE2.5 dependency and import resolution
- [ ] UT2003 package/version differences
- [ ] UT2004 package/version differences
- [ ] UE3 package format and reading
- [ ] UE3 dependency resolution
- [ ] UE4 package format and reading
- [ ] UE4 dependency resolution
- [ ] UE5 package format and reading — blocked until source is available

### Redirect/archive/mod formats

- [ ] UZ
- [ ] UZ2
- [ ] UZ3
- [ ] UMOD
- [ ] UT2MOD
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

The GitHub connector currently exposes the flattened header subset including:

- `Core/Inc/UnLinker.h`
- `Core/Inc/UnObjVer.h`
- `Core/Inc/UnArc.h`
- `Core/Inc/UnName.h`

The user's local source tree also contains the retail implementation subtree:

`Unreal Tournament [v1.400] [1999-11-30] (Retail)/Core/Src/`

including at least:

- `UnObj.cpp` — contains the `FCompactIndex` serializer
- `UnName.cpp`
- `UnLinker.cpp`

Those implementation files are not yet visible through the GitHub connector as of this specification update. The UE1 package spec remains **in progress** until those authoritative implementation files are available from the repository. No substitute implementation is to be treated as authoritative in their place.

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

