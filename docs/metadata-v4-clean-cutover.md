# Metadata format 4 clean cutover

> **Historical CLI references:** Some commands and utility filenames quoted in this dated record were retired or renamed during V5 cutover. They are provenance only and **must not be used as current maintenance instructions**. Use the supported commands in [current project status](../docs/current-project-status.md) and the [V5 lifecycle evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md).


> **Current production status (15 October 2026):** UnrealDB uses **UEDB5-only** metadata registration and runtime. All **235,239** verified packages have validated format-5 registration; the bounded production verifier and **17/17** current regressions passed. The canonical live metadata tree contains **235,239 `.uedb5` files and no V1-V4 containers**. Actual upload, stable-ID reimport, backup restore, and non-deferred deletion/reconciliation were successfully exercised across all seven games against isolated MySQL; the results do not imply a new exhaustive production-package parse. Historical V4 staging/candidate statements below are **not current operational instructions**. See [the maintained V5 lifecycle evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md).


At the time of this **historical format-4 cutover**, UnrealDB production metadata was format 4 only. Current production uses UEDB5; do not execute the retired commands in this document.

## Production format

- Container extension: `.uedb4`
- Container magic: `UEDBM4\0\0`
- Database registration: `ue_file_metadata.format_version = 4`
- Runtime readers/writers support format 4 only.
- No production fallback reads `.uedb2` or `.uedb3`.

Format 4 retains raw serialized package identity and adds durable derived accelerators:

- case-insensitive normalized object-path hash;
- UE1/UE2 `VerifyImport` identity hash over `ObjectName + ClassName + ClassPackage`;
- exact UE1/UE2 export class package/name used to derive that identity;
- serialized outer/package index and object flags remain authoritative.

Hashes are accelerators only. Exact projected terms/raw metadata are used to confirm hash candidates.

## Future format changes

Do not add compatibility branches to the production reader.

For a future v5:

1. increment the current format constant;
2. use a new magic and `.uedb5` extension;
3. add version-specific offline migration tooling under `catalog/bin/v5-migration/`;
4. reparse the authoritative Unreal/package bytes whenever UEDB4 did not retain a UEDB5-required serialized field; use container-only conversion only for source policies explicitly audited as lossless;
5. write and verify each UEDB5 container before publishing it;
6. publish the new DB projections/registration;
7. rebuild source-specific dependency data from UEDB5;
8. delete the prior-format file only after source-field verification succeeds;
9. remove retired migration tooling after the cutover is complete.

The detailed v5 field and migration requirements are defined in `specs/uedb5-metadata-requirements.md`. A migration must never infer discarded serialized fields from paths, hashes, neighboring records, or old projections.

## v3 -> v4 migration

Apply schema migrations first:

```text
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Preview conversion:

```text
php catalog/bin/migrate-uedb3-to-uedb4.php --limit=100
```

Convert a small batch:

```text
php catalog/bin/migrate-uedb3-to-uedb4.php --apply --limit=100
```

Continue with the returned `last_id`:

```text
php catalog/bin/migrate-uedb3-to-uedb4.php --apply --after-id=<LAST_ID> --limit=1000
```

The converter reads format 3 only through `catalog/bin/v4-migration/`. Runtime code remains format-4-only.

After all metadata registrations are format 4, rebuild UE1/UE2 dependencies using the indexed `VerifyImport` resolver.

## Obsolete file cleanup

`.uedb2` may be removed because it is no longer a supported production format.

`.uedb3` cleanup is refused while any database registration still uses format 3.

Preview:

```text
php catalog/bin/cleanup-obsolete-metadata-files.php
```

Delete:

```text
php catalog/bin/cleanup-obsolete-metadata-files.php --apply
```

The cleanup command defaults to both `.uedb2` and `.uedb3`.
