# Game-profile package-version admission — 2026-10-10

## Decision

A stored game-profile minimum/maximum package version or licensee version **does not block file processing**. Epic's `PACKAGE_FILE_VERSION` is commonly a version the engine writes, not necessarily the highest it attempts to read. The latest source's `PACKAGE_MIN_VERSION` can invoke an old-package confirmation path rather than an unconditional reject.

UnrealDB therefore attempts the selected canonical reader on an otherwise eligible file, regardless of its serialized version. The reader must still apply all format, layout, header, licensee-specific, serializer, and game-policy checks justified by the corresponding Epic source. A readable header alone is **not** validated metadata or proven dependency compatibility.

## Policy separation

- **Package identity:** Save actual serialized package version, licensee version, and UE4/UE5 multicomponent/custom versions as before. Original byte identity, checksums, package tag, file size, and reader validation remain mandatory.
- **Game profile:** Select the game's engine family and source discovery policy. Legacy `package_version_min/max` and `licensee_version_min/max` columns remain inert historical fields for old schema/backup compatibility. New profiles and fresh installs no longer set ranges.
- **Explicit compatibility rules:** Continue to use serialized engine/version/licensee facts solely for intentional alternate-reader selection (for example, the UT2004 UE1 legacy-texture case). They are **not** allow/deny range overrides.
- **Legacy UE1/UE2/UE3 engine hint:** Header-only engine inference from numeric version bands is provisional. A user-selected game reader can attempt a legacy package outside those bands; invalid or genuinely incompatible files must still fail at reader validation. This is not a promise that every version parses successfully.
- **Modern UE4/UE5 headers:** Keep distinct structural preambles, effective vs serialized versions, unversioned-package assumptions, custom-version gates, and source-enforced serializer limits.
- **Version-bounded dependency semantics:** A source-specific VerifyImport contract may have a narrower proven range than structural parsing. Preserve that distinction. Do not borrow a neighboring game's implementation or call an unverified dependency a valid result.

## Paths audited and changed

1. **File import, manual upload, bucket upload, source scanning, add/reimport, and re-sync:** `CatalogVerifiedPackageInspector` / `gp_classify_file` no longer rejects based on profile ranges; reader validation still occurs before verified status.
2. **Unverified-file matching and promotion:** `PdoUnverifiedGameMatchQuery` no longer applies profile version/licensee bounds, and legacy engine-version heuristics do not hard-block a game candidate. `CatalogUnverifiedPromotion` goes through the shared classifier and reader parse.
3. **Game backup restoration:** `GameBackupImportJobHandler` invokes `PdoCatalogPackageImporter::importUploadedFile`, which uses the same shared inspection path. Old backup manifests can still carry historical profile bounds without enforcing them.
4. **UEDB5 staging/re-staging, migration, repair, and verification:** `Uedb5SourceSnapshotFactory` uses the canonical reader and preserves explicit reader-dispatch compatibility. Profile min/max checks no longer veto file rows or parsed headers. Preflight/cutover reporting distinguishes reader-dispatch incompatibility from historical version range values.
5. **Profile management:** Obsolete version-limit inputs/labels were removed. Editing an existing profile preserves its old columns rather than erasing provenance. New profiles and installer seeds leave the historical bounds unset.

## Regression verification

Run (development-only, no production mutation):

```powershell
C:\php8.5\php.exe catalog\bin\verify-profile-reader-admission.php
C:\php8.5\php.exe catalog\bin\verify-uedb5-game-migration-contract.php
C:\php8.5\php.exe catalog\bin\verify-uedb5-cutover-ready-contract.php
C:\php8.5\php.exe catalog\bin\verify-header-only-staging-first-upload.php
C:\php8.5\php.exe catalog\bin\verify-full-sync-invalid-package-cleanup.php
C:\php8.5\php.exe catalog\bin\verify-profile-mismatch-unverified-outcome-contract.php
C:\php8.5\php.exe catalog\bin\verify-ue1-verify-import-profile-contract.php
C:\php8.5\php.exe catalog\bin\verify-ue2-profile-verify-import-contract.php
```

The bounded classifier regression uses synthetic valid-magic legacy headers with versions 34, 54, 69, 75, 99, 130, 250, and 868, plus an explicit UE1 compatibility dispatch and SQLite-only profile save/read checks. **These are classification tests, not complete-package parser acceptance tests.**

## Operational boundary

This change is development-repository-only until deliberately deployed. No live database range columns are updated, no production migration worker is stopped/restarted, and no full-game rescan is implied. Failed files should be retried selectively with source-backed error classification after deployment.
