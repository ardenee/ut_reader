# Windows backup and recovery

## Purpose

The primary UnrealDB deployment is a single Windows host. Recovery therefore optimizes for a coherent, verifiable database + package-storage backup rather than high-availability failover.

The Windows scripts live in `deploy/backup/`:

- `unrealdb-backup-readiness.ps1` — non-destructive preflight for tools, paths, database connectivity and destination/storage capacity;
- `unrealdb-backup.ps1` — create a database-only or full database+storage recovery point;
- `verify-unrealdb-backup.ps1` — verify checksums, fully decompress the database dump, and validate the storage archive listing;
- `unrealdb-restore.ps1` — destructive restore with explicit maintenance and target confirmation, followed by schema and compact-metadata verification.

## Configuration

The scripts accept parameters directly or these environment variables:

```powershell
$env:UNREALDB_BACKUP_PATH = 'L:\UnrealDB\backups'
$env:UNREALDB_STORAGE_PATH = 'D:\Apache24\htdocs\unrealdb\catalog\storage'
$env:UNREALDB_DB_HOST = '127.0.0.1'
$env:UNREALDB_DB_PORT = '3306'
$env:UNREALDB_DB_NAME = 'ut_reader_catalog'
$env:UNREALDB_DB_USER = '<database-user>'
$env:UNREALDB_DB_PASSWORD = '<database-password>'
$env:UNREALDB_MYSQL_BIN = 'C:\Program Files\MySQL\MySQL Server 8.4\bin'
$env:UNREALDB_BACKUP_RETENTION_DAYS = '30'
```

Optional executable overrides:

```powershell
$env:UNREALDB_PHP = 'D:\php8.5\php.exe'
$env:UNREALDB_TAR = 'C:\Windows\System32\tar.exe'
```

Do not commit credentials into the repository.

## Backup readiness preflight

Before the first backup on a host, or before entering a planned full-backup maintenance window, run the non-destructive preflight.

For an online database-only backup:

```powershell
.\deploy\backup\unrealdb-backup-readiness.ps1 -DatabaseOnly
```

For a full database + package-storage backup:

```powershell
.\deploy\backup\unrealdb-backup-readiness.ps1
```

The preflight does **not** create a backup, write package data, stop workers or change the database. It checks:

- required configuration is present;
- `mysqldump` is available;
- the MySQL client is available and `SELECT 1` succeeds against the configured database;
- PHP is available for recovery/post-restore verification;
- `tar` is available when a storage backup is requested;
- the configured backup destination already exists;
- the package-storage directory exists for a full backup;
- destination and storage-drive free-space figures can be read.

Capacity is reported rather than guessed. The preflight deliberately does not invent a required free-space multiplier because database/storage compression ratios vary significantly between installations.

## Database-only backup

A database-only backup uses `mysqldump --single-transaction --quick` and can normally run while the site is online:

```powershell
.\deploy\backup\unrealdb-backup.ps1 -DatabaseOnly
```

The SQL stream is compressed directly to `database.sql.gz`; the complete SQL dump is not loaded into PowerShell memory.

The backup is first written under an `.incomplete-*` directory. It is promoted to its timestamp directory only after verification succeeds.

## Full database + package-storage backup

A coherent full backup requires a short maintenance window because package files and their MySQL metadata must represent the same point in application activity.

Before running a full backup:

1. Run the full backup readiness preflight while the site is still operating.
2. Stop or pause new uploads/imports and other write-producing maintenance activity.
3. Stop UnrealDB workers after the current intended work has been dealt with.
4. Confirm that no package-storage write operation is still running.
5. Run the full backup with explicit acknowledgement:

```powershell
.\deploy\backup\unrealdb-backup.ps1 -MaintenanceConfirmed
```

The full recovery point contains:

```text
<timestamp>/
├── database.sql.gz
├── storage.tar.gz
├── metadata.json
└── SHA256SUMS
```

For low-terabyte package storage, `storage.tar.gz` can be large and can take substantial I/O time. Choose the backup destination and retention period accordingly. The script does not pretend a multi-terabyte copy is instantaneous.

## Verify an existing backup

Verification is safe and read-only:

```powershell
.\deploy\backup\verify-unrealdb-backup.ps1 'L:\UnrealDB\backups\20260815T140000Z'
```

It checks:

- every SHA-256 entry;
- `metadata.json`;
- complete gzip decompression of the database dump;
- storage archive readability/listing when storage is included.

A backup should not be considered a recovery point merely because the files exist; verification must pass.

## Restore policy

A production restore is intentionally difficult to run by accident.

It requires:

- a maintenance window;
- `-MaintenanceConfirmed`;
- an exact database-name confirmation;
- backup metadata whose database name matches the target;
- successful backup verification before any restore starts.

Example shape:

```powershell
$env:UNREALDB_RESTORE_CONFIRM = $env:UNREALDB_DB_NAME
.\deploy\backup\unrealdb-restore.ps1 `
    'L:\UnrealDB\backups\20260815T140000Z' `
    -MaintenanceConfirmed
```

To restore package storage as well:

```powershell
.\deploy\backup\unrealdb-restore.ps1 `
    'L:\UnrealDB\backups\20260815T140000Z' `
    -MaintenanceConfirmed `
    -RestoreStorage
```

`-RestoreStorage` empties the configured target storage directory before extracting the backed-up storage archive. Do not use it casually.

## Post-restore verification

After restoration the script automatically runs:

```powershell
php catalog/bin/migrate.php verify
php catalog/bin/verify-compact-only-metadata-runtime.php --database
```

The restore is treated as failed if schema or compact-metadata verification fails.

After those automated checks, manually verify:

1. `/catalog/api/v1/readiness.php` returns ready;
2. `System Operations` reports database/storage/queue health;
3. representative verified files can be examined/downloaded;
4. workers can be restarted normally.

## Restore drills

Before relying on a new recovery process, perform a restore drill against a disposable/scratch database and disposable storage directory where practical. Do not make the first execution of `unrealdb-restore.ps1` an emergency production restore.

## Retention

`UNREALDB_BACKUP_RETENTION_DAYS` defaults to 30. Cleanup only removes completed timestamp directories matching `yyyyMMddTHHmmssZ`; `.incomplete-*` directories are deliberately not treated as successful backups.

Keep an appropriate number of recovery points on storage independent from the live database/package disk. A backup located only on the same failed disk is not a useful recovery strategy.
