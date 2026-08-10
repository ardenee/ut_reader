# Windows MySQL/MariaDB storage during catalogue maintenance

The Windows parent keeps the live MySQL database on `C:`:

- `datadir` remains on `C:`.
- MySQL `tmpdir` and `innodb_tmpdir` remain under MySQL control.
- Normal UnrealDB application work does not use `L:` for database storage.

`L:` is reserved for explicit temporary maintenance output such as a requested dump, export, archive or migration backup. It is not the application data drive and the database must not be moved there.

## Consolidated baseline

Historical migrations through `202608090002` are consolidated into `catalog/install.sql`. Completed conversion/retirement migrations and the retired search-document migration/backfill utilities are no longer part of the active migration set.

`catalog/install.sql` is for a new empty database only. Do not import it over the populated catalogue.

Existing installations should use:

```powershell
php catalog\bin\migrate.php status
php catalog\bin\migrate.php migrate
php catalog\bin\migrate.php verify
```

Applied migration records at or below `202608090002` are retained as archived history even though their individual PHP files have been removed.

## Before a future large migration

1. Stop imports and detached workers.
2. Confirm a recent database backup exists.
3. Check free space on `C:` for MySQL internal temporary files.
4. Use `L:` only when the maintenance command explicitly creates a named backup or export there.
5. Run `status`, then `migrate`, then `verify`.
6. Restart workers only after verification succeeds.

Inspect MySQL's configured paths with:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

These settings are informational. UnrealDB does not silently relocate MySQL files.

## Failed temporary-file cleanup

A failed MySQL statement normally removes its temporary file. When an error names a leftover file:

1. Stop the MySQL service.
2. Confirm the exact path and timestamp from the error.
3. Remove only that confirmed failed temporary file.
4. Never delete `ibdata1`, redo logs, undo files, `ibtmp1`, database folders or unidentified files in the data directory.
5. Start MySQL and run database verification.

Keep at least one validated backup before any manual cleanup.
