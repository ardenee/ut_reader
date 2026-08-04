# UnrealDB database schema and migrations

## Consolidated baseline

`catalog/install.sql` is the canonical schema for a new empty MySQL 8+ or
MariaDB database. It includes every schema and seed-data change through
baseline version `202608030001`.

The historical migration files that built this baseline have been removed.
Existing installations may still contain their rows in
`ue_schema_migrations`; the migration runner reports those rows as `archived`
rather than treating the removed files as schema drift.

Do not import `catalog/install.sql` over a populated database.

## Fresh installation

1. Create an empty database.
2. Import `catalog/install.sql`.
3. Configure `catalog/config.php`.
4. Run `php catalog/bin/migrate.php verify`.
5. Create the first administrator with `catalog/bin/create-admin.php`.

A fresh baseline has no pending migrations.

## Future schema changes

New migration files belong in `catalog/migrations/` and must:

- use a version greater than `202608030001`;
- follow the immutable `<version>_<name>.php` format;
- be idempotent because MySQL and MariaDB DDL may commit implicitly;
- update `catalog/install.sql` only at a later reviewed schema-squash point.

After deploying a future migration:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
```

The migration runner still rejects changed checksums and missing migration
files above the consolidated baseline.

## Runtime database access

Application code uses the shared PDO connection and prepared-query helpers in
`CatalogSupportCore.php`. Reads of the retained legacy Names/Imports/Exports
and dependency tables must pass through the compact compatibility source.
`php catalog/bin/audit-legacy-runtime-references.php` enforces this rule and
also rejects any return of the removed `ue_search_documents` projection.
