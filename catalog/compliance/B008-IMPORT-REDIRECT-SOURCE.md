# B008 — package import identity rewriting, redirect and localization contracts

**Original operation:** `UE5-583-IMPORT-REDIRECT-001` — source-defined operation verified by executable acceptance. This audit does not claim to reconstruct a running Unreal Engine session.

## Pinned Epic source

| Source | SHA256 | Observed decision |
|---|---|---|
| `CoreUObject/Private/Serialization/AsyncLoading2.cpp` | `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9` | `ImportPackagesRecursiveInner` performs conditional CoreRedirect first, then linker/name or package-ID instancing, loose cooked localization, FPackageStore redirect, then package lookup (lines 7156–7275). |
| `CoreUObject/Private/Serialization/PackageStore.cpp` | `fb5c1930f5b8e6611392c3009dde16922661c7942f2eaa8d875492c8981f67b9` | `FPackageStore::GetPackageRedirectInfo` walks the globally ordered backend list and returns the first backend reporting success (lines 583–593). |
| `PakFile/Private/FilePackageStore.cpp` | `dfdd4e226b3a41f36476ddd93225aa34284ea63943f1752216e0f2a25c2a2418` | File backend tries explicit redirect first, then localized package only when not `GIsEditor`, culture localization exists and target is mounted (lines 359–387); `Update()` permits a later mount to fill an earlier `NAME_None` (lines 788–808). |

### Epic branch consequences and UnrealDB mapping

**Core redirects:** gated by `GAllowPackageRedirectorSupport` and presence of an imported package name. A changed name updates both the imported UPackage identity and package ID in the runtime import map. UnrealDB cannot obtain `FCoreRedirects` or editor `FLinkerInstancingContext` from a cooked file, so it requires the caller to supply the **explicit aggregate identity result** as `package_identity_rewrites` (possibly an observed empty list) and `package_identity_context_authoritative=true`.

**Instancing:** a name-based linker remap or package-ID-only `RemapPackageId` changes the in-memory identity before load. These are not substitute-able by matching `PublicExportHash` against an arbitrary staged provider. UnrealDB keeps original `required_package_id`, separately reported `effective_import_package_id`, and `provider_lookup_package_id`. The source-derived unit tests enforce that distinction.

**Loose localization:** runs only outside `GIsEditor` when `bLooseFileLoadingEnabled`. It rewrites the name/ID **to load**, rather than the imported object/UPackage identity. The later FilePackageStore localization branch additionally needs runtime culture and a mounted localized target. UnrealDB does not guess these facts; without an authoritative aggregate identity result or culture it returns a specific unresolved outcome.

**Package store:** `GetPackageRedirectInfo` is global across backends, not a consumer `ContainerHeader` lookup. When redirected, Epic sets `ImportedPackageIdToLoad` to the target and `ImportedPackageNameToLoad` to `NAME_None`. UnrealDB matches the hash only after authoritative global lookup, preserving the pre-redirect identity and individual redirect provenance.

## Demonstrated source differences and corrections

**D017 — store authority bypass:** Previously `Uedb5Ue5ZenDependencyResolver::packageStoreContext()` could accept `package_store_context_authoritative=true` while the effective redirect/localization arrays were absent. It then silently fell back to the importing container's header redirects and could resolve the wrong target. The isolated regression `b008_authority_flag_without_effective_global_store_maps_fails_closed` **failed before correction** and passes afterwards. The resolver requires both explicit global mapping arrays before setting the store authority bit; otherwise it reports `package_store_mount_context_required` and records only candidate local evidence.

**D018 — identity authority bypass:** Previously a bare `package_identity_context_authoritative=true` was treated as authoritative *no rewrite*, even with no `package_identity_rewrites` result. The isolated regression `b008_authority_flag_without_explicit_identity_result_fails_closed` **failed before correction** and passes afterwards. No table is **unknown**, while an explicitly provided empty result is asserted *no applicable rewrite*.

Both corrections are in [commit `62007888`](https://github.com/ardenee/ut_reader/commit/620078887282d82e7b2bb32a8f0b2aebce378b03). The resolver's direct reconstruction of `FindOrAdd` maps also now retains B006's `NAME_None` refill behavior and does **not** infer `NAME_None` from a missing source-name field. The original expected provider and redirector/filter states remain covered by the other original source operations.

## Operation-level acceptance evidence

`verify-uedb5-b008-import-redirect-source.php` pins all three Epic source files, explicitly verifies branch/order/name-versus-ID decisions, checks the two required authority maps, inventories all **559 production PHP files** and the two exact resolver call sites (`PdoUedb5PhysicalProviderSelector`, `Uedb5DependencyRebuilder`), and rejects additional unregistered caller identities.

`verify-uedb5-b008-operation-acceptance.php` combines **seven executable drivers** and **15 required assertions**: independent Epic source oracle, complete Zen resolver fixtures, B006 mounted-store and B005 backend acceptance drivers, actual rebuilder, isolated MySQL/SQL dependency publication and per-game context options. Red-before/green-after authority regressions prove the change. Existing B005/B006 source and SQL fixtures now expressly supply empty identity rewrite results when that absence is *known*; test fixtures relying on authority flags alone no longer pass.

**Scope boundary:** This operation verifies UnrealDB's safe static-metadata handling and faithful interpretation of supplied, provenance-backed Epic identity outcomes. It does not execute `GAllowPackageRedirectorSupport`, query `FCoreRedirects`, invent active culture, PIE instancing, loose-file mode, or mounted session state. It cannot pronounce an arbitrary game asset's actual runtime identity without those external facts. Complete UE1–UE5 application compliance remains a separate, open global denominator.
