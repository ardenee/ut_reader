# B009 / B010 — bounded source-to-UnrealDB reachability inventory

**Scope:** Epic UE5.8.3 cooked Zen package *versioned-header decoding and conditional header sections*, from package-store-selected ExportBundleData chunks through source-shaped V5 metadata and consumer validation. **Not** all UObject payload serializers, the entire IoStore mount-session/runtime package store, or the complete UE5 engine.

## Pinned Epic source path

1. `AsyncLoading2.cpp`: `FAsyncPackage2::Event_ProcessPackageSummary` receives cooked package bytes and invokes `FZenPackageHeader::MakeView` (approx. 8415).
2. `ZenPackageHeader.cpp:18-35`: `bHasVersioningInfo != 0` loads `FZenPackageVersioningInfo`. `AsyncLoading2.cpp:164-170`, `ObjectVersion.cpp:34-40`, `CustomVersion.cpp:287-351` define field order and custom GUID/version decoding.
3. `ZenPackageHeader.cpp:37-65`: absent version => current layout; present UE5>=1015 => cell offsets; present UE5>=1009 => bulk map; otherwise sections absent. Eight-byte scratch pad is permitted, content need not be zero.
4. `AsyncLoading2.cpp:8464-8474,8544-8553`: if serialized version exists it becomes `LinkerRoot` effective package/licensee/custom versions and is transferred to `InitializeExportArchive`; absent version falls back to engine constants. This handoff governs *engine UObject payload loading*, which is not reproduced by UnrealDB's metadata-only Zen reader.

All five Epic source SHA256 values and 14 runtime handoff checks are executable in `catalog/bin/verify-uedb5-zen-runtime-version-source.php`. Original B009/B010 source-hash manifests remain pinned. This is source evidence, **not** a claim to have executed Epic C++.

## Exhaustive direct PHP entry points for this narrow contract

The production scanner examines **all PHP files under `catalog/src`** (559 at this baseline), strips comments and checks every direct `Uedb5ZenPackageReader::parse` and `Uedb5Ue5ZenIoStoreSnapshotBuilder::build` reference. Any new/unmapped direct call or changed count fails the verifier.

| Actual entry point | Header/conditional-layout propagation | Error contract |
|---|---|---|
| `Uedb5Ue5ZenIoStoreSnapshotBuilder::build` | The **only** direct production `Uedb5ZenPackageReader::parse` caller: calls twice, for main and optional ExportBundleData. Preserves versioning, cell offsets and bulk rows in respective V5 sections | Parser/build errors propagate; no fabricated fallback parsing |
| `CatalogIoStorePackageNameIndexer::index` | Builds per-member source-shaped snapshot to extract names and counts; job route uses `CatalogIoStoreNameIndexJobHandler` | Catches member exception, writes failed metadata status/error, processes next member |
| `CatalogIoStoreIndexedPackageReader::inspect` | Builds source snapshot on demand for read-only `iostore-package-info.php`; `extract` returns raw bytes without interpreting Zen header | Throws on malformed member; cannot publish an inferred result |
| `CatalogZenIoStorePackagePublisher::publish` | Source build -> V5 snapshot writer -> SQL registration/projections -> `Uedb5MigrationValidator::validate` before stage/validated status; called by `CatalogIoStoreMemberCatalogPublisher` | Catches exception, SQL rollback, removes new V5 file; never marks validated after failed source parse |
| `CatalogIoStoreMemberReimportService::reimport` | Retained TOC and source hash verification -> source rebuild -> new V5 -> same validator, preserving stable file ID. Called by `CatalogFileMaintenanceReimportService` for `.zenpkg` | Backs up prior V5 and restores it upon error, rolls back SQL; does not route Zen to classic reimport |
| `Uedb5MigrationValidator::validateZenFields` | Step 8 enforces persisted version flag presence and 1009/1015 conditions on main/optional segments after source-byte comparison. Repair and cutover-validation entry points share it | Raises typed `Uedb5ValidationException` when staged header facts contradict source version layout |

**Additional bounded exclusions:** normal classic `Uedb5SourceSnapshotFactory` handles UE1–5 classic packages, not the `.zenpkg` member pipeline; `Uedb5MetadataReader` loads already decoded V5 sections; `Uedb5DependencyRebuilder` resolves stored dependency object indices/provider evidence, not header byte layout. Backup/restore must preserve the published V5 snapshot and is not a new Zen header deserializer. The Epic export archive serializes UObject payloads beyond the metadata-only contract.

## Executable evidence

```powershell
$env:UNREALDB_EPIC_SOURCE_ROOT = 'L:\Source\Engine\UE5\UE 5.8.3'
& C:\php8.5\php.exe catalog\bin\verify-uedb5-zen-version-callers.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-zen-runtime-version-source.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-ue5-zen-package.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-zen-version-staging-contract.php
& C:\php8.5\php.exe catalog\bin\verify-source-compliance.php --all
```

An additional temporary, wholly synthetic `.utoc/.ucas` regression mutates the main chunk's custom-version count and the serialized cell offset; both errors must propagate through the real `Uedb5Ue5ZenIoStoreSnapshotBuilder::build` path. Its `.ucas` bytes are restored unconditionally, and no production data is touched. These two named assertions are part of the original B009/B010 formal source-contract checks.

The original B009/B010 inventory closure applies to this **specifically named header-contract operation only**, not to every reachable UE5 function. The source-first scope and all production direct calls are enumerated and machine monitored. A reviewer must still independently accept those boundaries and any excluded runtime behavior before the *entire* original operation can be certified.

## Post-change revalidation

- When Epic source or any PHP direct caller is changed or added, the pinned source verifier or production scanner fails.
- When the parser or final Step 8 validator changes, the original manifest SHA evidence fails until justified and retested.
- When source-byte fixtures or tests fail, the existing source-contract and consumer subgates fail.
- No large package rescans, production DB writes, inferred runtime mount authority or V4 fallback are involved.
