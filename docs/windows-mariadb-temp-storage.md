# Windows MySQL/MariaDB temporary storage for large catalogue migrations

Large `ALTER TABLE`, index-build and server-side backfill operations use the database server's temporary directories. Changing PHP's `upload_tmp_dir`, `sys_temp_dir`, `TEMP` or `TMP` in the PowerShell session does not redirect an already-running Windows MySQL service.

This procedure does **not** move the MySQL database. The MySQL `datadir` remains on `C:`. Only disposable temporary sort and work files are redirected to `L:`.

## Inspect the active MySQL paths

Run in MySQL/phpMyAdmin:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

Expected layout for the Windows parent server:

- `tmpdir`: `L:/MySQLTemp`
- `innodb_tmpdir`: `L:/MySQLTemp`
- `datadir`: unchanged on `C:`

`tmpdir` and `innodb_tmpdir` are temporary work locations. `datadir` is where the real database tables and completed indexes remain permanently.

MySQL can still require some free space inside `datadir` for the completed table/index and, for some online DDL operations, an intermediate table file. Redirecting temporary work to `L:` prevents sort/temp files from consuming `C:\Windows\ServiceProfiles\...\Temp`; it does not eliminate normal permanent database growth on `C:`.

## Identify the Windows MySQL service account and option file

Run PowerShell as Administrator:

```powershell
$mysqlService = Get-CimInstance Win32_Service |
    Where-Object { $_.PathName -match 'mysqld|mariadbd' } |
    Select-Object -First 1

$mysqlService | Format-List Name, StartName, State, PathName
```

The account shown under `StartName` needs Modify permission on the temporary directory. The `PathName` may contain `--defaults-file=...`, which identifies the active `my.ini`.

## Create the L: temporary directory

```powershell
New-Item -ItemType Directory -Path 'L:\MySQLTemp' -Force
icacls 'L:\MySQLTemp' /grant "$($mysqlService.StartName):(OI)(CI)M"
```

## Configure MySQL

Add these settings under the existing `[mysqld]` section in the active `my.ini`:

```ini
[mysqld]
tmpdir=L:/MySQLTemp
innodb_tmpdir=L:/MySQLTemp
```

Use forward slashes in the Windows option file. `innodb_tmpdir` must differ from the MySQL `datadir`.

Restart only the MySQL service:

```powershell
Restart-Service -Name $mysqlService.Name
```

Verify the active paths again:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

A configuration edit has not taken effect until `@@global.tmpdir` reports `L:/MySQLTemp`. The `datadir` should remain unchanged on `C:`.

## Recover system-drive space after a failed migration

A failed server statement normally removes its temporary file, but an open MySQL process can retain the file or its disk allocation until the service stops.

1. Stop the MySQL service.
2. Inspect the exact temporary directory reported in the SQL error.
3. Delete only the named failed-migration file and other clearly related temporary files created at the same migration time.
4. Do not delete `ibdata1`, redo logs, undo files, `ibtmp1`, database folders or anything inside `@@global.datadir`.
5. Start MySQL and verify the database before continuing.

Example:

```powershell
Stop-Service -Name $mysqlService.Name

Get-ChildItem 'C:\Windows\ServiceProfiles\NetworkService\AppData\Local\Temp' -File |
    Sort-Object Length -Descending |
    Select-Object -First 20 FullName, Length, CreationTime, LastWriteTime

# Remove only the exact failed temporary file after checking its path and timestamp.
Remove-Item 'C:\Windows\ServiceProfiles\NetworkService\AppData\Local\Temp\MLdhst0p3m7e23z88p' -Force

Start-Service -Name $mysqlService.Name
```

## Resume migration 202607270003 safely

The migration CLI uses bounded source-ID batches for migration `202607270003`. It is safe to rerun after a partial failure because each batch upserts its document rows.

Complete the data migrations while postponing the large search index builds:

```powershell
php catalog\bin\migrate.php migrate --search-backfill-batch=10000 --defer-search-indexes
php catalog\bin\migrate.php verify
```

The deferred indexes are optional for correctness. Search uses the compact document-table `LIKE` fallback until they are built.

After MySQL reports `L:/MySQLTemp` for its temporary directories:

```powershell
php catalog\bin\search-document-indexes.php status
php catalog\bin\search-document-indexes.php build
php catalog\bin\search-document-indexes.php status
```

The build command creates each missing index separately. It checks only MySQL's temporary paths; it does not require or attempt to move the database `datadir` from `C:`.
