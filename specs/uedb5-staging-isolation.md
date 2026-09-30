# UEDB5 Staging Isolation Contract

## Purpose

Step 7 freezes the pre-cutover coexistence rule. UEDB5 migration may build and verify V5 state while production continues to use UEDB4, but staging must not mutate, replace, or partially retire the live V4 runtime.

```text
verified Unreal source bytes
|-- production metadata: <file_id>.uedb4 + live V4 SQL
`-- staged metadata:     <file_id>.uedb5 + ue_uedb5_* SQL
```

This coexistence is temporary. It exists only until the explicit atomic UEDB5 cutover and later V4 retirement.

## File isolation

`BlockedCompressedMetadataContainer::path()` remains the production V4 path and ends in `.uedb4`.

`Uedb5MetadataContainer::path()` is the staging V5 path and ends in `.uedb5`.

`Uedb5MetadataSnapshotWriter` must call `Uedb5StagingIsolationContract::assertContainerPathIsolation()` before publication. V5 staging must never unlink, rename over, or otherwise replace the matching `.uedb4` file.

## SQL isolation

Before cutover, V5 staging may write only the baseline tables declared by `Uedb5SqlProjectionContract` plus the V5-only migration-control table `ue_uedb5_migration_status`:

- `ue_uedb5_files`
- `ue_uedb5_provider_keys`
- `ue_uedb5_search_keys`
- `ue_uedb5_name_candidates`
- `ue_uedb5_object_candidates`
- `ue_uedb5_dependency_edges`
- `ue_uedb5_dependency_packages`
- `ue_uedb5_migration_status`

Production catalogue tables may be read for identity/preflight, but live V4 registration/projection state is read-only during staging. In particular staging must not write `ue_file_metadata`, `ue_terms`, `ue_name_lookup`, `ue_export_lookup`, `ue_export_path_lookup`, `ue_legacy_export_identity_lookup`, `ue_dependency_links`, `ue_dependency_identity_lookup`, or `ue_search_documents`.

`Uedb5StagingIsolationContract::assertWriteTable()` is the runtime guard for all staging publishers. A future V5 publisher must pass every SQL write target through that guard.

## Registration rule

`ue_file_metadata` remains the live one-row-per-file production registration and therefore stays format 4 during migration.

A successfully staged V5 file is registered separately in `ue_uedb5_files`. `PdoUedb5StagingRegistrationRepository` must first prove that the matching verified file still has a format-4 live registration.

## Verification

`catalog/bin/verify-uedb5-staging-isolation-contract.php` verifies the code boundary: only `ue_uedb5_*` write targets are allowed, dynamic staging DML is guarded, and V4/V5 paths remain distinct.

`catalog/bin/verify-uedb5-staging-coexistence.php` is a read-only live audit. During migration it verifies that:

- no verified live `ue_file_metadata` row has been switched to format 5;
- every staged `ue_uedb5_files` row still has a live format-4 registration;
- staged registrations themselves are format 5;
- sampled staged files have both their `.uedb4` and `.uedb5` containers present.

The coexistence audit may be run at any migration percentage. Failure blocks cutover investigation; it does not repair or mutate data.

## Cutover boundary

Step 7 does not implement cutover. Only the later explicit cutover step may replace the production registration/runtime with V5. V4 projection tables and `.uedb4` files are retired only after full V5 migration, dependency publication, source-level verification, and successful cutover validation.
