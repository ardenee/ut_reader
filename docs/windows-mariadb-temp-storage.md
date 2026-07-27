# Windows MySQL/MariaDB storage during large catalogue migrations

The Windows parent keeps MySQL entirely on `C:`:

- `datadir` remains on `C:`.
- MySQL `tmpdir` remains on `C:`.
- MySQL `innodb_tmpdir` remains on `C:`.
- Normal UnrealDB application operation does not use `L:` for database work.

`L:` is reserved only for explicit files created by UnrealDB maintenance operations, such as a requested database dump, exported report, archive, or other migration backup. Migration `202607270003` does not create such a backup file, so it does not use `L:`.

## Why migration 202607270003 consumed substantial space

The original migration performed four large `INSERT ... SELECT` operations and then built several indexes, including a FULLTEXT index. MySQL can create large internal temporary files while processing those statements. Those files are controlled by MySQL and remain in its configured storage locations on `C:`.

The migration runner now replaces the large data copy with bounded, restart-safe source-ID batches. This reduces peak temporary pressure and allows a failed migration to be rerun without deleting the partially populated `ue_search_documents` table.

## Inspect the configured paths

Run in MySQL or phpMyAdmin:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

These values are informational. The migration tools do not require them to be moved.

## Resume migration 202607270003

Stop imports and the detached worker first. Pull the latest code, then run:

```powershell
git pull

php -l catalog\bin\migrate.php
php -l catalog\bin\search-document-indexes.php
php -l catalog\src\Infrastructure\Persistence\SearchDocumentMigrationExecutor.php
php catalog\tests\large-migration-temp-storage-contract-test.php
```

Complete the data migrations with 10,000-source-ID batches while postponing the large search index builds:

```powershell
php catalog\bin\migrate.php migrate --search-backfill-batch=10000 --defer-search-indexes
php catalog\bin\migrate.php verify
```

The command is restart-safe. Existing rows are updated through `ON DUPLICATE KEY UPDATE`, so a prior partial run does not require the table to be dropped.

Search remains correct while the indexes are deferred. It uses the bounded search-document `LIKE` fallback until the indexes are built.

## Build deferred indexes

With adequate free space available on `C:`, inspect and build each missing index separately:

```powershell
php catalog\bin\search-document-indexes.php status
php catalog\bin\search-document-indexes.php build
php catalog\bin\search-document-indexes.php status
```

The index command reports MySQL's configured paths for information only. It does not refuse the system drive and does not attempt to move any MySQL files.

## Failed temporary-file cleanup

A failed MySQL statement normally removes its temporary file. If a file remains allocated after a failure:

1. Stop the MySQL service.
2. Inspect the exact path named in the error.
3. Delete only the named failed temporary file after confirming its timestamp.
4. Do not delete `ibdata1`, redo logs, undo files, `ibtmp1`, database folders, or anything in the MySQL data directory.
5. Start MySQL and verify the database.

`L:` should be used only when a future UnrealDB maintenance task explicitly creates a backup or export file and clearly identifies that output path.