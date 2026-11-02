# B015-2 — UE5.8.3 recursive imported-package requirements

**Original operation:** `UE5-583-B015-2` (same existing B015 manifest as the still-open B015-1). This record accepts the **offline V5 dependency-graph portion** of Epic's recursive imported-package contract, not Unreal Engine's live asynchronous loader, package allocation, queue threads or actual loaded state.

## Pinned Epic source and trace

`Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`, source lines 7158–7464. The dedicated `verify-uedb5-b015-recursive-imports.php` independently checks the original function bodies, not merely symbol presence.

| Source branch / behavior | UnrealDB mapping | Limit |
| --- | --- | --- |
| `ImportPackagesRecursiveInner` iterates every `Header.ImportedPackageIds`, even if no object import uses it; zero IDs returns. Names may be empty or one per ID. | Main `imported_package_ids` rows become distinct V5 `ImportedPackage` dependency results; original index, 16-hex package ID, serialized name retained. | No artificial object hash or object export is invented. |
| `ImportPackagesRecursive` invokes main header once and optional segment only under `WITH_EDITOR`. | Optional IDs have their own section `optional_segment_imported_package_ids`. When editor build state is false/unknown, no optional-runtime resolution is claimed. | The actual `WITH_EDITOR` binary is not executed. |
| Package redirect/CoreRedirect -> linker/descriptor instancing -> loose-file localization -> package-store redirect -> backend lookup. | Explicit runtime `package_identity_rewrites`, effective global package redirects/localization and prioritized evidenced backend results are reused from B005/B008. The source identity and lookup identity stay distinct. | Missing environment evidence returns `unresolved`; static consumer container is not an authoritative global store. |
| Existing loaded package can win before package-store status; fully loaded checks precede NotInstalled/Missing skip. Pending queue and cross-loader dependency barriers are runtime-only. | `Pending` remains unresolved. NotInstalled/Missing do not prove an absent already-loaded package, so imported-package rows stay unresolved. `Ok` + exact verified physical provider means **package_only** (known catalogue provider), **not** loaded/ready/scheduled. `runtime_schedule_authoritative=false` always. | No simulated `FindOrInsertPackage`, refcount, `PendingPackages`, sync-load contexts, or cross-loader event edges. |

## Confirmed difference: UE5-D023

The original Zen V5 snapshot builder preserved ordinary and optional `imported_package_ids`, but the resolver emitted dependency results only for object imports, cell imports, soft references and dependency bundles. Provider selection itself enumerates resolver dependency requirements, so a package referenced only in `ImportedPackageIds` was skipped by **both** candidate discovery and the stored dependency graph. Epic explicitly enumerates that package.

The `15208171` correction emits the missing package-only requirement rows; source kinds **9** and **10** distinguish main/optional in `ue_uedb5_dependency_edges`. Existing 1–8 source codes remain unchanged. Both V5 metadata and SQL package summaries retain exact package IDs. `source_kind` is already `TINYINT UNSIGNED`, so no persistent table schema change is needed.

`verify-uedb5-b015-recursive-before-after.php` loads the pinned unmodified `f6d59d11` resolver under a second class name. With two imported package IDs and *zero* object imports, the original returned 0 rows; the corrected resolver returns 2 separate rows, without claiming live scheduling. Four historical assertions pass.

`verify-uedb5-b015-recursive-imports.php` passes **25** Epic source, main/optional, runtime-status, identity rewrite, V5, SQLite and SQL projection assertions, including real compressed V5 writer/reader and **real disposable SQLite publisher tables**. `verify-uedb5-b015-operation-acceptance.php` passes **17** enforced assertions across 11 drivers, including B005 backend, B007 public export, B008 runtime identity, B011 imported names, B012 optional segment, B016 Verse cells, physical caller inventory and V5 rebuilder/publisher.

**Persistence boundary:** Existing stored dependencies are not automatically updated. A bounded, separately verified re-publication is required for already-staged Zen package snapshots to acquire the new package rows; this audit did not rewrite the production database or rescan the complete IoStore container.

## Verified scope and exclusions

The B015-2 offline *source-defined package-requirement* operation is complete under its acceptance driver and source fingerprint; **B015-1 remains partial** in the same manifest. The separate source scanner verifies existing direct resolver callers throughout 559 production PHP files (two direct consumers) and the B005 backend caller inventory was updated to count the new use of its shared arbitration entry point.

Unverified wider areas: actual live `FGlobalImportStore` contents, redirector and linker states, package loader extensions, synchronous/asynchronous scheduling side effects, cross-loader serialized-export barriers, runtime editor/culture context, full mounted session state and complete UE1–UE5 engine/source inventory. Passing these offline tests never implies the runtime engine itself was executed. Any implementation/source/consumer change invalidates this bounded acceptance until the verifier passes again.
