# B007 — Epic UE5.8.3 public-export identity and collision acceptance

**Original operation:** `UE5-583-PUBLIC-EXPORT-001`. This record covers the source-defined metadata and dependency lookup behavior, not execution of Epic's live `UObject` graph.

## Pinned Epic source and semantic inventory

| Epic code | Behavior | UnrealDB mapping |
|---|---|---|
| `CoreUObject/Public/Serialization/AsyncLoading2.h:179-250` (SHA256 `088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2`) | `FPublicExportKey::FromPackageImport` gets package ID and hash from the indexed header arrays; `MakeKey` checks valid `FPackageId` and nonzero hash, key equality requires **both** | Fixed-width FPackageId + PublicExportHash parsed independently; no package-name or hash-only fallback |
| `CoreUObject/Private/Serialization/AsyncLoading2.cpp:2587–2610` (SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`) | Global import-store lookup resolves package ref by `FPackageId`, then hash, and checks reachable UObject | Resolver uses selected physical package ID + hash. Static archives cannot prove an object has already loaded or is reachable |
| `AsyncLoading2.cpp:1214–1260,2630–2692` | Global per-package hash store overwrites the prior stored object when the same key is registered again, with collision logging and object-index cleanup | Do **not** claim a unique live UObject from multiple static exports sharing a hash; retain `unresolved/public_export_hash_runtime_collision_ambiguous` |
| `AsyncLoading2.cpp:8930–8955,8988–9054` | Conditional create/serialize fallback searches header **main ExportMap first**, then optional only under `WITH_EDITOR`, stopping at the first matching index | Resolver annotates source-header first candidate separately, without falsely resolving a runtime collision. B012's main-before-optional rule remains |
| `AsyncLoading2.cpp:2829–2850,8066–8090,9570–9590` | Package import uses `FPublicExportKey::FromPackageImport`, handles editor-only UObjectRedirector destination, export registration is conditional on importable package / nonzero public hash | Unresolved context/filtered/redirector branches remain explicit, with candidate and outcome preserved |
| `AsyncLoading2.cpp:9056–9142` | Verse public-cell export lookup is separate from ordinary UObject export lookup | B016 remains a separate original operation, not implicitly closed here |

## Demonstrated differences

**D019 — invalid key accepted as ordinary Missing:** UnrealDB previously accepted zero public-export hash and zero FPackageId in a package import/provider, reporting a normal missing provider. Epic's `FPublicExportKey::MakeKey` checks valid package ID and nonzero hash. Three negative-before source-derived tests identified invalid hash/import package/provider package and now pass after explicit rejection; valid nonzero package/hash and wrong-package collision negative controls also pass.

**D020 — header-first candidate lost on runtime hash collision:** UnrealDB previously marked two exports with the same public hash as ambiguous without identifying Epic's first header-scan candidate. `GetPublicExportIndex` returns the first entry in main ExportMap (main beats optional); live global import store may replace the registered object under the same hash. Source-first candidate is now recorded in `source_header_first_export` (`segment`, `index`, `runtime_lookup_authoritative=false`) while the row remains `unresolved/public_export_hash_runtime_collision_ambiguous`. The new provenance regression failed before and passed after, and a temporary real UEDB5 container preserves the reason, both identities, both candidate indices and nonauthoritative first index. It does not pretend static metadata captures the winning live object.

## Executable closure boundary

`verify-uedb5-b007-public-export-source.php` pins both original Epic files; its **24 named checks** cover key equality, invalid identities, two distinct runtime/header lookup paths, global collision overwrite, first-candidate provenance, exact-provider matching, and both direct resolver call sites across **559 production PHP files**, including an unregistered-caller negative control.

`verify-uedb5-b007-operation-acceptance.php` requires nine independently executed drivers and **17 named acceptance assertions**, spanning source, resolver, real temporary V5 metadata writer/reader, SQLite compact dependency reader, B005 backend and B008 identity-provider arbitration, B012 main/optional precedence, V5 rebuilder and session-temporary MySQL status projection. `--backend-status-only` is used for portable SQL acceptance, not as a substitute for PRD's separate seven retained game snapshots.

The original source manifest and formal source-compliance verifier pin implementation fingerprints and rerun both the source oracle and operation-level acceptance. B007's verified scope is *source-defined offline key resolution and evidence-qualified provider identity*. It does not certify the complete UE runtime object store, actual UObject allocation/load timings, general Verse cell behavior (B016) or global UE1–UE5 parity.
