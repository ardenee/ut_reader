# UE5.8.3 end-to-end source and workflow reconciliation — active scope

This document is the **next, finite UE5-only coverage requirement**, not a new B operation or an arbitrary checkpoint. Pinned Epic authority: `L:\Source\Engine\UE5\UE 5.8.3`. Reference implementation: DEV `ut_reader`; default public Epic build means `bUseVerseBPVM=true` / `WITH_VERSE_VM=0`. Optional VerseVM is not a default-build release gate.

## What the 18/18 result actually establishes

The existing 15 manifest / 18 original-operation verifier passes (18 verified / 0 open / 0 issues). It verifies those **registered** source obligations. It does **not** assert an exhaustive source-to-entrypoint inventory: the checked recovery register still explicitly reports `scope_complete=false`, UE5 additional target `inventory=partial`, and all 16 common workflow paths `coverage=unverified`. Thus 18/18 **must not be represented as proof that UnrealDB handles every possible UE5 file the way Epic does**. Do not reopen a verified B gate merely to create a new counting system; reconcile the missing reachability scope separately.

## Concrete code entrypoints inspected

- Classic package bytes: `catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php` dispatches `ue5` to the canonical UE5 reader and classic snapshot builder. Its `buildForGameId` caller uses profile/reader identity, rather than substituting a UE4 or UE5 Zen parser.
- Live V5 registration: `catalog/src/Infrastructure/Metadata/Uedb5VerifiedFilePublisher.php::publish` reparses the selected file via the source factory, writes compressed metadata, registers the file, publishes the base projection, marks stage success and invokes dependency Pass 2; failures are marked failed.
- IoStore route is distinct: `CatalogZenIoStorePackagePublisher`, `CatalogIoStoreIndexedPackageReader`, `CatalogIoStorePackageNameIndexer`, `CatalogIoStoreMemberReimportService`, and `Uedb5Ue5ZenIoStoreSnapshotBuilder`. This must not be treated as a classic `.uasset` load.
- Profile ranges: `catalog/lib/GameProfiles.php::gp_profile_version_decision` expressly retains min/max for historical reporting and returns reader validation rather than a range-based admission failure. Compatibility reader overrides remain separate. Source-defined format failures are still valid errors.

## Checks executed on DEV (18 October 2026)

The following returned process exit 0 and reported `ok=true`: `verify-ue5-583-reader-contract.php`, `verify-uedb5-ue5-classic-dependency-resolution.php`, `verify-uedb5-ue5-zen-package.php`, `verify-uedb5-ue5-iostore-foundation.php`, `verify-uedb5-ue5-classic-persistence.php`.

Five deeper IoStore standalone lifecycle checks were attempted (container index, extraction, member reimport, container backup/restore and source validation). Their local CLI defaults connect to a **dedicated isolated MySQL instance** on port 3339, database `uedb5_isolated_integration`, and verify the isolated datadir. All five failed at PDO connection before any package logic could execute. Docker Desktop's Linux daemon was unavailable during inspection; Windows `MySQL80` service was running, which is **not** a substitute for the guarded isolated target. These attempts are **environment-blocked, not passing or failing Epic-parity evidence**. Do not retarget to production MySQL.

## Required UE5-only closure

1. **Finite source denominator:** enumerate Epic's public default UE5.8.3 classic loader/serializer, Zen IoStore readers, package-store and dependency operations relevant to files we accept. For each relevant symbol/conditional branch map authoritative source/hash to the active UnrealDB reader or mark intentional scope exclusion. Do not chase private Verse toolchains or optional internal configurations.
2. **Consumer denominator:** map each of the 16 named workflow paths from `RECOVERY.json` to actual production dispatch, package reader, V5 publisher, Pass 2 and read/query consumers. Mark each verified only with executable entrypoint evidence and failure/retry tests; don't bulk-set coverage flags.
3. **Differential:** run authentic Epic-produced classic and IoStore fixtures through supported default engine parse/load tools where feasible and compare actual name/import/export/dependency/identity semantics against UnrealDB output. Keep editor-only/runtime states separate from offline file metadata; synthetic-only fixtures cannot certify all real-world formats.
4. **Defects:** only create a new D-ledger difference for a reproducible Epic-vs-UnrealDB mismatch, including original source location, affected production callers, negative-before and positive-after fixture, corrected code, and commit. Version metadata must not be used as a made-up file rejection range.
5. **Completion:** update `RECOVERY.json` per genuine source-to-workflow coverage and only claim full UE5 file parity when the UE5 scope inventory and consumer/differential gates are actually complete. Keep seven historical game profiles and UE4.27.2 entirely separate.

**Current decision:** The 18 registered UE5 operations are closed for the default public engine. Broader UE5 source/workflow coverage remains open. No production parser discrepancy was established by the checks in this entry; no code alteration is justified solely to turn an environment-blocked test green.
