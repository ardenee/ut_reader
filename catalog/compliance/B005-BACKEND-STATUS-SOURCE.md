# B005 — UE5 FPackageStore backend status and priority

**Original operation:** UE5-583-BACKEND-STATUS-001. This document is part of the existing compliance register, not a new numbered batch.

**Epic source:** `Engine/Source/Runtime/CoreUObject/Private/Serialization/PackageStore.cpp`, UE5.8.3, pinned SHA256 `fb5c1930f5b8e6611392c3009dde16922661c7942f2eaa8d875492c8981f67b9`.

## Ordinary backend contract

- `FPackageStore::Mount` lines 560–562 uses descending backend priority, with a newly mounted backend taking precedence at equal priority.
- `GetPackageStoreEntry` lines 565–580 consults backends in that order. Only `None` and `Missing` permit searching a lower-priority backend. `Pending`, `NotInstalled` and `Ok` terminate the search. Exhaustion returns `Missing`.
- The source operates on binary `FPackageId`. Textual hex casing must not change package identity.
- Separate `FPackageStore::GetPackageRedirectInfo` walks the same priority list for the first matching redirect; this is also used by existing B008/B006 source rules.

## UnrealDB routes

The audited selector and dependency resolver must use the same evidenced backend list. `Uedb5GameDependencyPassService` passes explicit mounted backend results and context; `PdoUedb5PhysicalProviderSelector::selectZen` chooses a physically verified file only on authoritative `Ok`; `Uedb5DependencyRebuilder::rebuild` supplies the same results to `Uedb5Ue5ZenDependencyResolver`, which writes V5 outcomes. `PdoUedb5DependencyProjectionPublisher` persists compact outcome counts while detailed reasons remain in V5 metadata.

**D013 correction:** the selector formerly read only the top-level `zen_package_store_backend_results`; the resolver/rebuilder could use `zen_runtime_context.package_store_backend_results`. A nested authoritative `Pending` or `Ok` could therefore be ignored by physical selection. Rebuilder also overwrote a contradictory nested result with the top-level one without rejection. The shared `Uedb5ZenPackageStoreBackendArbitrator::evidencedBackends` now accepts either legitimate evidence location and rejects inconsistent copies. Physical selection and reconstruction cannot silently use different answers for a given explicitly supplied snapshot.

The corrections are covered by three failing-before / passing-after PDO-selector assertions and one failing-before / passing-after rebuilder assertion. The source-specific oracle independently models priority, terminal statuses and equality/contradiction of evidence inputs. The formal B005 implementation gate hashes the arbitrator, selector and rebuilder, and reruns the pinned assertions.

## Final operation acceptance — VERIFIED

B005 now passes the executable operation-level test `verify-uedb5-b005-operation-acceptance.php`. It runs eight independent source, caller, PDO, V5 metadata and real session-temporary MySQL drivers, with fifteen named acceptance assertions. Both ordinary and hybrid cooked-editor source branches are accounted for. The formal verifier requires live passing tests, pinned Epic source, current implementation hashes and exhaustive caller reconciliation before it counts B005 among verified operations.

This verifies **B005's package-store backend status/priority policy applied to explicitly evidenced mount results**. It does not certify whole-game loading, install-specific editor AssetRegistry state, or global UnrealDB compliance. The remaining general mounted-runtime completeness belongs to B006 and the overall runtime-context coverage inventory, not to an invented unresolved B005 backend-status branch.

## Runtime context boundary — explicitly source-handled

**B005 is verified for backend status and priority.** `PackageStore.cpp:520–557` handles a special `WITH_EDITOR` hybrid cooked editor mount path that combines the loose LinkerLoad backend and Zen backend into one hybrid backend. That behavior is not equivalent to simple ordering of separate descriptors. The pinned C++ branch is now exhaustively traced in `verify-uedb5-zen-hybrid-source-contract.php` for forced loose, writable/read-only/unknown assets, cooked-only terminal statuses, Missing-to-loose fallback and the resulting hybrid's **single** slot in the outer priority-ordered list.

A real mounted-store runtime context is likewise not supplied by a static `.utoc` alone. `package_store_context_authoritative` must come from explicit evidence, not a convenient lookup heuristic. Existing read-only retained package tests do not constitute a captured hybrid or live Pending runtime session.

**Tests:** `catalog/bin/verify-uedb5-zen-backend-source-oracle.php`, `verify-uedb5-zen-backend-arbitration.php`, `verify-uedb5-physical-provider-selector.php`, `verify-uedb5-dependency-rebuilder.php`, `verify-uedb5-ue5-zen-dependency-resolution.php`, and `verify-uedb5-dependency-sql-isolated.php` (session-temporary MySQL tables). No scan or production DB rewrite was performed.
