# UnrealDB installation and first-run setup

This guide covers a clean UnrealDB installation, the supported Windows/Apache/PHP/MySQL production foundation, database creation, application configuration, storage, migrations, the first administrator, workers, optional GeoIP data and optional federation.

For later upgrades, also read [production-deployment.md](production-deployment.md) and [database-migrations.md](database-migrations.md).

## Supported production foundation

The current production target is a **single Windows host** running:

- Apache 2.4;
- PHP 8.5 for Apache and CLI;
- MySQL 8.4;
- local durable catalogue/package storage;
- independent PHP background workers.

UnrealDB does not require Docker, Kubernetes, Redis, a message broker, Composer, Node.js, npm, or a frontend build step. The CSS/JavaScript assets are tracked in the repository. There is no separate Foundation/Bootstrap framework installation step.

Other environments may work, but the production deployment and operational tooling are developed against the stack above.

## Hardware and storage planning

There is no useful fixed disk minimum because database/storage size depends on the amount of Unreal content, metadata, dependency/search projections, temporary imports and backups.

Plan for:

- enough RAM for Apache/PHP plus the MySQL buffer pool;
- fast MySQL data/index storage;
- a separate large package-storage volume where practical;
- temporary headroom during migrations, archive extraction and maintenance;
- free-space monitoring for both MySQL and package storage.

## PHP requirements

A normal deployment should enable:

| Extension | Use |
| --- | --- |
| `pdo_mysql` | MySQL access; required. |
| `mbstring` | UTF-8 text handling. |
| `curl` | Federation and trusted remote transfers. |
| `openssl` | Federation secret encryption/TLS support. |
| `sodium` | Ed25519 federation signing. |
| `zip` | ZIP ingestion through `ZipArchive`. |
| `zlib` | Unreal redirect/archive compression paths. |
| `fileinfo` | MIME/content inspection where available. |

Archive ingestion has extra optional extensions:

- ZIP: `ext-zip` / `ZipArchive` is preferred.
- 7z and general libarchive decoding: PHP `ext-archive` (cataphract/libarchive).
- RAR compatibility/solid-RAR fallback: PECL `rar` (`RarArchive`).

The current archive reader is PHP-extension based; UnrealDB does **not** require or launch command-line 7-Zip/UnRAR tools.

Check the CLI runtime:

```powershell
php -v
php -m
php -m | Select-String -Pattern 'PDO|pdo_mysql|mbstring|curl|openssl|sodium|zip|zlib|fileinfo|archive|rar'
```

The worker CLI must load the same required extensions as the web runtime.

A reasonable PHP starting point is:

```ini
file_uploads=On
upload_max_filesize=64M
post_max_size=64M
memory_limit=512M
max_execution_time=120

display_errors=Off
log_errors=On

opcache.enable=1
opcache.memory_consumption=256
```

Uploads are chunked (16 MiB by default), so PHP does not need a multi-gigabyte per-request upload limit. Keep `upload_max_filesize` and `post_max_size` comfortably above the configured chunk size.

## Apache requirements

Enable PHP plus:

- `mod_rewrite`;
- `mod_headers`;
- `mod_ssl` for HTTPS production/federation.

The root and `catalog/.htaccess` files provide routing/security rules, so Apache needs `AllowOverride All` (or equivalent rules in the VirtualHost).

Example:

```apache
<Directory "C:/path/to/ut_reader">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

DirectoryIndex index.php
```

The repository root should normally be the DocumentRoot so `/` is the public landing page and `/catalog/` is the catalogue.

Verify Apache configuration before restart:

```powershell
httpd.exe -t
```

## MySQL requirements

Use MySQL 8.4 for the supported production deployment.

Create a database and a database-scoped account. Do not grant the application account global privileges such as `SUPER` or `BINLOG_ADMIN`.

Run as a MySQL administrator:

```sql
CREATE DATABASE unrealdb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'unrealdb'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON unrealdb.* TO 'unrealdb'@'localhost';
FLUSH PRIVILEGES;
```

Database-level DDL privileges are required if this account also runs UnrealDB migrations. Server-wide MySQL maintenance should use a separate administrator account.

## Install the application

Clone/deploy the repository:

```powershell
git clone https://github.com/ardenee/ut_reader.git
cd ut_reader
```

Create the live configuration:

```powershell
Copy-Item .\catalog\config.example.php .\catalog\config.php
```

Edit `catalog/config.php`. At minimum set the database credentials, `site_name` and `storage_path`.

Keep deployment-specific paths in `config.php` or environment configuration, not hard-coded in application source. Package storage may be outside the web root/on another local volume. The Apache/PHP identity and CLI worker identity both need read/write/create/delete access to it.

Leave `queue.worker_php_binary` empty unless auto-detection cannot find the intended PHP CLI.

## Load the base schema

For a **new empty database only**:

```powershell
mysql -u root -p -D unrealdb -e "source catalog/install.sql"
```

Use the installed `mysql.exe` path if it is not in `PATH`.

Do **not** import `catalog/install.sql` over an existing UnrealDB database.

## Apply migrations

Run:

```powershell
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

A fresh install is not current until migration verification succeeds.

## Create the first administrator

```powershell
php catalog/bin/create-admin.php --username=admin
```

The command prompts for a password and requires at least 12 characters. It refuses to create another bootstrap administrator once one exists.

## Verify readiness

Run:

```powershell
php catalog/bin/verify-system-readiness-contract.php
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-security-hardening.php
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/migrate.php verify
```

Operational endpoints:

- `/catalog/api/v1/live.php`
- `/catalog/api/v1/readiness.php`

## Background workers

Long-running import, dependency, Full Sync, archive, package-generation and maintenance work requires CLI workers.

Initial one-job smoke test:

```powershell
php catalog/bin/catalog-worker.php --max-jobs=1
```

The administrator **Background Jobs** page can start/reconcile the configured detached pool.

For production, supervise workers independently from Apache/browser sessions so they start at boot and restart after unexpected exits. See [background-jobs.md](background-jobs.md) and [production-deployment.md](production-deployment.md).

## First-run site setup

After login:

1. Open **Game Manager** and create/verify the games to catalogue.
2. Configure the required game/parser profiles.
3. Review **Download Settings**, **Package Export Settings** and public upload limits.
4. Review **Background Jobs** worker count/resource-class limits.
5. Run System Operations/readiness checks.
6. Import a small known-good package set before a very large ingestion.
7. Confirm metadata, dependencies and downloads before scaling up.

Typical routes:

- `/` — public landing page;
- `/catalog/` — catalogue/search;
- `/catalog/index.php?page=login` — administrator login;
- `/catalog/dashboard.php` — admin dashboard.

## Optional GeoIP city/region maps

GeoIP lookup is local; visitor IPs are not sent to an external geolocation API.

DB-IP City Lite:

```powershell
php catalog/bin/import-geoip-city-dbip.php "C:\path\to\dbip-city-lite-YYYY-MM.csv.gz"
```

MaxMind City CSV:

```powershell
php catalog/bin/import-geoip-city-maxmind.php "C:\path\to\GeoLite2-City-CSV_YYYYMMDD"
```

Both importers stage data and atomically swap it into service after a successful load.

## Optional federation setup

Federation requires HTTPS for normal remote production use plus PHP `curl`, `openssl` and `sodium`.

Generate the peer-secret master key:

```powershell
php catalog/bin/generate-federation-master-key.php
```

Store the returned value securely as:

```text
UNREALDB_FEDERATION_MASTER_KEY=base64:...
```

This key encrypts stored federation peer secrets and must remain stable. Existing plaintext secrets can then be converted with:

```powershell
php catalog/bin/encrypt-federation-secrets.php
```

Generate local Ed25519 signing material:

```powershell
php catalog/bin/federation-key.php generate
```

Configure the returned:

```text
UNREALDB_FEDERATION_ED25519_PRIVATE_KEY=...
UNREALDB_FEDERATION_SIGNATURE_ALGORITHM=ed25519
```

Restart Apache/workers after changing process environment variables, then verify:

```powershell
php catalog/bin/federation-key.php show
```

In the admin UI:

1. Open **Federation → Settings**.
2. Set the site name and canonical HTTPS `site_url`.
3. Review inventory/transfer/security settings.
4. Open **Federation → Connections**.
5. Submit/approve the Parent/Child connection.
6. Confirm inventory synchronization before enabling automatic transfer/import behaviour.

Role is connection-derived; do not force Parent/Child state manually in SQL.

Recommended federation checks:

```powershell
php catalog/bin/verify-federation-boundaries.php
php catalog/bin/verify-federation-http-boundary.php
php catalog/bin/verify-federation-secret-boundary.php
php catalog/bin/verify-federation-transfer-auth-boundary.php
```

## Security and backup baseline

Before public exposure:

- use HTTPS;
- keep `catalog/config.php` out of source control;
- keep package storage non-public;
- verify Apache honors both `.htaccess` files;
- use a database-scoped application MySQL account;
- keep federation secrets/keys outside source control;
- disable PHP `display_errors`;
- rotate Apache/PHP/worker logs;
- configure backups and disk-space alerts.

The database and package storage together form the catalogue recovery set. Backup tooling is under `deploy/backup/`.

## Upgrade an existing installation

Do not re-import the base SQL. Use:

```powershell
git pull
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/verify-system-readiness-contract.php --run
```

Restart/reconcile workers after code/schema changes so every worker loads the current revision.
