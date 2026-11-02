# B012 — optional Zen export segment: complete source-defined metadata audit

Original operation: `UE5-583-OPTIONAL-EXPORT-SEGMENT-001`.
Source baseline: Epic UE5.8.3 `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`
SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`.

## Epic branch inventory

| Source location | Engine decision | UnrealDB source-shaped handling |
|---|---|---|
| 5539–5598 | Under `WITH_EDITOR`, main and optional imported FPackageId lists are separate, and `bHasOptionalSegment` creates **two** export bundles. Non-editor has **one** bundle. | ContainerHeader ordinary and optional store entry lists remain separate, and Zen reader resolves imports against each own store entry. |
| 7443–7466 | `ImportPackagesRecursiveInner` traverses main, then optional when editor and present | Resolver retains optional imports in their own V5 section and marks non-editor/unknown optional outcomes conservative, not as ordinary hard references. |
| 7591–7631 | Editor requests optional `ExportBundleData` chunk index **1** when present. Main read normally uses index **0**, but `bRequestOptionalChunk` may choose **1** in editor. Non-editor always uses **0**. | Static archival reader captures both chunks when optional store evidence exists; errors if declared optional chunk 1 is absent. It records actual source chunk indices/provenance and never fabricates live editor `bRequestOptionalChunk` authority. |
| 8415–8435 and 8544–8576 | Separate optional header is parsed under `WITH_EDITOR`; `InitializeExportArchive` switches `HeaderData` for optional exports. | Main and optional headers are parsed independently; V5 persists each header/import/bulk/name/source version and routes classification to appropriate section. |
| 8609–8645, 8930–8953 | Processing uses local segment export maps; public export-hash lookup scans **main first**, returning immediately on hit, then optional under editor, with optional index offset by main export count. | Dependency resolver selects main candidate if the same hash is present in main and optional, retaining source-consistent optional-only editor behavior. |
| 12167–12169 | Optional imported package references are released separately | Separate provider-id/index metadata remains in the optional V5 section. |

## D015: demonstrated difference and correction

`Uedb5Ue5ZenDependencyResolver` formerly added main and optional export hashes to a candidate list and classified a hash appearing in **both** maps as `public_export_hash_runtime_collision_ambiguous`. This contradicted Epic's main-first return in `GetPublicExportIndex()`. The dedicated test `b012_epic_main_public_export_precedes_matching_optional_hash` failed before correction.

Commit `27788226c68ae3a7179d3646a9405fd351d069a0` now applies main-map precedence only to ordinary export hashes. Duplicates inside one map and **cell export hash handling** retain existing separate conservative checks; B007/B016 remain distinct general public export/cell obligations. Editor, explicit non-editor and unknown runtime-context variants pass.

Existing optional IoStore fixture previously supplied the same imported package ID to both ordinary and optional store entries, obscuring swapped-context mistakes. The source-shaped `.utoc/.ucas` fixture now supplies **different provider IDs** in the two lists and asserts that both V5 segments preserve their own imports. A negative fixture removes the declared index-1 chunk and proves that the archive reader does not silently replace optional data with ordinary data. All fixture changes are temporary and restored.

## Executable closure criteria

`verify-uedb5-b012-optional-source.php` independently pins the exact Epic file hash and 27 source-branch, caller and classification assertions. `verify-uedb5-b012-operation-acceptance.php` runs that oracle plus the constructed `.utoc/.ucas` fixture, V5 snapshot/validation, and the real dependency resolver, with **12 mandatory operation assertions**. The formal source verifier binds the current reader, resolver and snapshot builder implementation fingerprints, reruns required source assertions and acceptance test, and refuses a verified state after regressions.

**Scope boundary:** This operation verifies the encoding, retention, provenance and source-defined metadata/dependency treatment of optional export segments. The actual decision to enable `WITH_EDITOR`, use a hybrid editor loader or set `bRequestOptionalChunk` is live runtime state; a static package archive cannot prove it and UnrealDB does not infer it. No UnrealEditor executable is required to establish the C++ source rules. UObject payload execution, general public export/cell collision policies and whole-engine parity remain separate obligations, not claims made by this closure.

D030 downstream source consumer remediation: B012-OPTIONAL-MEMBER-PUBLICATION-SOURCE.md documents name, publish and read main-only identity policy while optional export data remains in the owning main V5 snapshot; no live-editor claim.
