# Windows MariaDB temporary storage for large catalogue migrations

Large `ALTER TABLE`, index-build and server-side backfill operations use MariaDB's server temporary directories. Changing PHP's `upload_tmp_dir`, `sys_temp_dir`, `TEMP` or `TMP` in the PowerShell session does not redirect an already-running MariaDB Windows service.

## Inspect the active MariaDB paths

Run in MariaDB/phpMyAdmin:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

`tmpdir` and `innodb_tmpdir` should point to a volume with enough free space for temporary index copies. `datadir` is where the final table and indexes are stored permanently.

## Identify the Windows service account

Run PowerShell as Administrator:

```powershell
Get-CimInstance Win32_Service |
    Where-Object { $_.PathName -match 'mariadbd|mysqld' } |
    Select-Object Name, StartName, State, PathName
```

The account shown under `StartName` needs Modify permission on the new temporary directory. The UnrealDB development server commonly runs MariaDB as `NT AUTHORITY\NETWORK SERVICE`, but use the actual value returned on the server.

## Create the L: temporary directory

Example for a MariaDB service running as Network Service:

```powershell
New-Item -ItemType Directory -Path 'L:\MariaDBTemp' -Force
icacls 'L:\MariaDBTemp' /grant 'NT AUTHORITY\NETWORK SERVICE:(OI)(CI)M'
```

Use the service account returned by the previous command when it differs.

## Configure MariaDB

Find the `my.ini` used by the MariaDB service. Running the service binary with `--help --verbose` lists the option-file search order. Add these settings under the existing `[mysqld]` section:

```ini
[mysqld]
tmpdir=L:/MariaDBTemp
innodb_tmpdir=L:/MariaDBTemp
```

Use forward slashes in the Windows option file. `innodb_tmpdir` must be outside MariaDB's `datadir`.

Restart the MariaDB service, replacing `MariaDB` with the service name returned earlier:

```powershell
Restart-Service -Name 'MariaDB'
```

Verify the active paths again:

```sql
SELECT
    @@global.tmpdir AS tmpdir,
    @@global.innodb_tmpdir AS innodb_tmpdir,
    @@global.datadir AS datadir;
```

A configuration-file edit has not taken effect until `@@global.tmpdir` reports the new location.

## Recover system-drive space after a failed migration

A failed server statement normally removes its temporary file, but an open MariaDB process can retain the file or its disk allocation until the server stops.

1. Stop the MariaDB service.
2. Inspect the exact directory reported in the SQL error.
3. Delete only the named failed-migration file and other clearly related files created at the same migration time.
4. Do not delete MariaDB data files such as `ibdata1`, `ib_logfile*`, `ibtmp1`, database folders or files from `@@global.datadir`.
5. Start MariaDB and verify the database before continuing.

Example inspection:

```powershell
Stop-Service -Name 'MariaDB'

Get-ChildItem 'C:\Windows\ServiceProfiles\NetworkService\AppData\Local\Temp' -File |
    Sort-Object Length -Descending |
    Select-Object -First 20 FullName, Length, CreationTime, LastWriteTime

# Remove only the exact failed temporary file after checking its path/timestamp.
Remove-Item 'C:\Windows\ServiceProfiles\NetworkService\AppData\Local\Temp\MLdhst0p3m7e23z88p' -Force

Start-Service -Name 'MariaDB'
```

## Resume migration 202607270003 safely

The CLI uses bounded source-ID batches for migration `202607270003`. It is safe to rerun after a partial failure because each batch upserts its document rows.

To complete all data migrations while postponing the large search index builds:

```powershell
php catalog\bin\migrate.php migrate --search-backfill-batch=10000 --defer-search-indexes
php catalog\bin\migrate.php verify
```

The deferred indexes are optional for correctness. Search uses the compact document-table `LIKE` fallback until they are built.

After MariaDB reports the new `L:` temporary directories:

```powershell
php catalog\bin\search-document-indexes.php status
php catalog\bin\search-document-indexes.php build
php catalog\bin\search-document-indexes.php status
```

The build command creates each missing index separately and refuses to run against the Windows system-drive temp directory unless `--allow-system-temp` is explicitly supplied.
