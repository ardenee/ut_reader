# Metadata format 4 clean cutover

UnrealDB production metadata is format 4 only.

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
3. add a version-specific offline prior-format reader under `catalog/bin/v5-migration/`;
4. convert each old container to the new format atomically;
5. publish the new DB projections/registration;
6. verify the new file;
7. delete the prior-format file;
8. remove retired migration tooling after the cutover is complete.

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
