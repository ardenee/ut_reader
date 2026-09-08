# UnrealDB Catalog

`catalog/` contains the main UnrealDB web application, database model, durable background-job system, package metadata store, dependency engine, administration tools, APIs, migrations and production diagnostics.

The catalogue builds on the Unreal Engine readers in the repository and currently targets UE1 through UE5 material, with the strongest coverage in UE1/UE2 and ongoing validation of UE3/UE4/UE5 edge cases.

## Runtime model

A normal installation consists of:

```text
Web server
PHP
MySQL
catalogue/package storage
independent PHP background workers
```

The browser is not responsible for completing long-running work. Upload/import, dependency, repair, backup and maintenance operations that can take significant time are handed to the durable MySQL-backed job queue and continue independently from the web request.

## What the catalogue does

The catalogue is intended to answer questions such as:

- Which files does this map/package depend on?
- Which dependency objects are missing?
- Which verified file provides a required exported object?
- Is this package structurally valid for the selected parser/profile?
- Do we already have these exact bytes by size/hash?
- Does another compatible game contain a verified provider for a missing dependency?
- Is verified compact metadata complete and healthy for this file?

It also provides workflows for uploads, archive unpacking, unverified-file review, duplicate/alias handling, dependency repair, Full Sync, source-identity repair, PAK/UPK management, Game Backups, federation and generated download packages.

## Install and first-run setup

The supported production foundation is a **single Windows host** with:

- Apache 2.4;
- PHP 8.5 for both Apache and CLI workers;
- MySQL 8.4;
- local durable package/catalogue storage;
- independent PHP background workers.

There is no Composer, Node.js/npm, Docker, Redis, message broker, or separate frontend Foundation/Bootstrap installation/build step.

### PHP modules

Enable at least:

- `pdo_mysql` — required database access;
- `mbstring` — UTF-8 handling;
- `curl` — federation/trusted remote transfers;
- `openssl` — federation secret encryption/TLS support;
- `sodium` — Ed25519 federation signing;
- `zip` — native ZIP support;
- `zlib` — redirect/archive compression;
- `fileinfo` — content/MIME inspection where available.

For archive ingestion:

- ZIP prefers `ZipArchive` / `ext-zip`;
- 7z/general libarchive decoding uses PHP `ext-archive` (cataphract/libarchive);
- RAR compatibility/solid-RAR fallback can use PECL `rar` / `RarArchive`.

The current archive stack is PHP-extension-only; UnrealDB does not execute command-line 7-Zip or UnRAR tools.

Check the CLI runtime with:

```powershell
php -v
php -m
php -m | Select-String -Pattern 'PDO|pdo_mysql|mbstring|curl|openssl|sodium|zip|zlib|fileinfo|archive|rar'
```

The worker CLI must load the same required extensions as the Apache PHP runtime.

### Apache

Enable PHP plus `mod_rewrite`, `mod_headers`, and `mod_ssl` for production HTTPS/federation. Apache must honor the repository `.htaccess` files, normally with:

```apache
<Directory "C:/path/to/ut_reader">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

The repository root should normally be the DocumentRoot. The root `.htaccess` protects development reader directories, while `catalog/.htaccess` blocks direct access to configuration, source/runtime directories, storage and SQL/log-style files.

### MySQL

Create an empty database and a dedicated database-scoped account. If the same account runs migrations, it needs DDL rights on that database. Do not grant the application user global privileges such as `SUPER` or `BINLOG_ADMIN`.

Example:

```sql
CREATE DATABASE unrealdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'unrealdb'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON unrealdb.* TO 'unrealdb'@'localhost';
FLUSH PRIVILEGES;
```

### Application configuration

From the repository root:

```powershell
Copy-Item .\catalog\config.example.php .\catalog\config.php
```

Edit `catalog/config.php` and configure at minimum:

- database host/port/name/user/password;
- `site_name`;
- `storage_path`;
- upload/container limits appropriate to the host;
- queue worker settings.

Keep machine-specific paths in configuration/environment variables rather than hard-coding drive letters into application source.

The Apache/PHP identity and CLI worker identity must both be able to read/write/create/delete within the configured storage tree.

### Fresh database

For a new empty database only, load `catalog/install.sql`, then apply every post-baseline migration:

```powershell
mysql -u root -p -D unrealdb -e "source catalog/install.sql"

php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Do **not** import `install.sql` over an existing UnrealDB database.

### First administrator

```powershell
php catalog/bin/create-admin.php --username=admin
```

The bootstrap command prompts for a password of at least 12 characters and refuses to create another bootstrap administrator once one exists.

### Readiness and workers

Run:

```powershell
php catalog/bin/verify-system-readiness-contract.php
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-security-hardening.php
php catalog/bin/verify-queue-runtime-invariants.php
```

One-job worker smoke test:

```powershell
php catalog/bin/catalog-worker.php --max-jobs=1
```

Use **Background Jobs** to start/reconcile the detached pool. In production, supervise workers independently from Apache/browser sessions so they start at boot and recover from unexpected process exits.

### First-run UI setup

After signing in:

1. Create/verify games in **Game Manager**.
2. Configure the relevant game/parser profiles.
3. Review public upload, download and generated-package settings.
4. Configure worker count/resource-class limits.
5. Run readiness/System Operations checks.
6. Test a small known-good import before starting a large ingestion.

The complete installation guide, including PHP/Apache/MySQL prerequisites, storage planning, GeoIP and federation, is **[../docs/installation.md](../docs/installation.md)**.

For an existing database, back up the database/package storage and use only the migration runner:

```powershell
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

See [`migrations/README.md`](migrations/README.md), [`../docs/database-migrations.md`](../docs/database-migrations.md), and [`../docs/production-deployment.md`](../docs/production-deployment.md).

## Durable background jobs

Long operations are represented by operator-visible jobs in `ue_background_jobs`.

Current queue behaviour is intentionally job-centric:

- a parent/coordinator job represents the operation the administrator started;
- large workflows can create bounded child jobs without changing the meaning of the parent job count;
- child progress rolls up into the parent workflow status;
- completed units are retained so restart does not replay successful work;
- one failed package/unit does not stop unrelated queued work;
- healthy long-running jobs are not failed simply because they exceed a timer;
- worker/process ownership is used to determine whether running work is still alive;
- genuinely stuck work is handled by explicit operator cancel/kill/retry actions;
- resource-class limits control expensive job types independently from total worker-process capacity.

See [`../docs/background-jobs.md`](../docs/background-jobs.md).

## Upload and import behaviour

Uploads are **not** expected to complete package processing inside the browser request.

The normal path is:

```text
complete file received/found
        |
controlled staging
        |
durable import job
        |
redirect/archive preparation if needed
        |
parser + identity resolution
        |
physical storage + database publication
        |
compact metadata publication
        |
dependency follow-up
```

Important rules:

- verified files are stored under controlled game storage;
- files that cannot yet be assigned confidently can be retained in database-backed unverified storage for review;
- unsupported/non-package input fails validation rather than being silently accepted as a verified package;
- physical duplicate decisions use file size/content hashes, not filename similarity;
- byte-identical files can retain additional logical package identities through aliases where appropriate;
- failed redirect/package processing does not block unrelated queued imports.

Upload Files to Game and Upload Bucket use the same durable server-side boundary once a complete file reaches controlled storage.

### ZIP / 7z / RAR unpacking

ZIP, 7z and RAR are transport containers only. The archive itself is not published as an Unreal package.

The archive coordinator lists entries first, filters them using the target game/profile or Upload Bucket policy, and extracts one accepted regular file at a time. Each extracted member is placed in controlled incoming storage and queued as its own durable job.

- normal package members enter `catalog.import_staged_package` or the Upload Bucket package path;
- `.uz`, `.uz2` and `.uz3` members are decoded by the existing redirect processor before normal package handling;
- `.pak` members use the existing PAK import workflow when a selected UE4/UE5 target permits them;
- unsupported files are skipped rather than extracted unnecessarily;
- a failed member does not stop unrelated members from being queued;
- nested ZIP/7z/RAR archives are skipped rather than recursively expanded;
- encrypted/password-protected archive members are not imported;
- unsafe archive paths and link entries are rejected before extraction;
- archive/member-count and unpacked-byte limits are configurable under `archive` in `config.php`.

The archive source is deleted once expansion succeeds. If one or more accepted members fail to unpack or queue, the source archive is retained for diagnosis/retry.

Run the no-database archive regression verifier with:

```text
php catalog/bin/verify-archive-ingestion.php
```

## Compact metadata

Verified package metadata uses the format-2 compact metadata model.

- `ue_files` stores stable catalogue/file identity and operational summary state.
- `ue_file_metadata` registers the current compact metadata container.
- detailed Names/Imports/Exports are stored in blocked compressed `.uedb2` metadata.
- lookup/dependency/search projections remain relational where indexed access is useful.
- the historical row-per-object `ue_names`, `ue_imports`, `ue_exports` and `ue_dependencies` model is no longer the verified runtime metadata architecture.
- verified files expose explicit metadata publication state (`pending`, `ready`, `failed`) so incomplete publication can be detected and repaired.

Compact metadata publication is treated as one recoverable publication operation rather than allowing a verified file to appear healthy with partially published metadata.

## Dependency policy

The catalogue is deliberately strict about dependency proof.

A dependency is considered resolved when the required package/object path can be matched to a verified provider according to the current engine/profile rules.

Package-name-only evidence is not sufficient proof that the exact required object exists. That distinction is also used by cross-game dependency matching: automatic fulfilment requires exact provider evidence, not merely a similarly named package.

Base-game package identities can be classified/protected separately so they remain useful for dependency analysis while being excluded from distribution policies where configured.

## Package identity

Unreal package references are package/object based, not simply filename based.

Filenames therefore are not treated as dependency proof. UnrealDB combines physical hashes, package GUID/version information, parser output, package names/aliases and exact object-path evidence according to the relevant workflow.

Administrators can repair package/source identity where required. Identity changes that affect dependency resolution trigger the appropriate targeted dependency follow-up rather than relying on display-layer rewriting.

## Engine/container status

Current broad status:

- **UE1:** strong package support.
- **UE2 / UE2.5:** strong package support.
- **UE3:** active package/UPK support; uncommon compression/version cases remain under validation.
- **UE4:** active package/PAK/dependency support with engine/version-specific edge cases still being investigated.
- **UE5:** partial support; IoStore `.utoc`/`.ucas` is not fully supported.
- **`.uz`:** historical 1234 and 5678 FCodec variants supported.
- **`.uz2`:** chunked zlib support present; malformed/non-standard archives fail safely.
- **`.uz3`:** active UT3 tag + uncompressed-size + whole-file zlib compression/decompression, validated against real `UT3.exe Compress` output.
- **`.zip` / `.7z` / `.rar`:** active unpack-only upload containers; supported Unreal members are passed to the normal durable import workflows.

## Operational endpoints

The catalogue exposes operational endpoints for deployment monitoring:

- `/catalog/api/v1/live.php` — lightweight PHP/process liveness;
- `/catalog/api/v1/readiness.php` — MySQL, queue-schema and writable-storage readiness;
- `/catalog/api/v1/metrics.php` — protected Prometheus-format application metrics when configured.

Useful verification commands include:

```text
php catalog/bin/migrate.php verify
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-archive-ingestion.php
php catalog/bin/verify-solo-maintainer-hardening.php --run
```

## Production and recovery documentation

See:

- [`../docs/production-deployment.md`](../docs/production-deployment.md)
- [`../docs/background-jobs.md`](../docs/background-jobs.md)
- [`../docs/catalog-architecture.md`](../docs/catalog-architecture.md)

Backup/restore tooling is maintained under [`../deploy/backup`](../deploy/backup).

## Detailed GeoIP city/region maps

Administrator IP maps can use a local city/region dataset for approximate placement. The browser never sends visitor IP addresses to a geolocation API; all lookups use the local `ue_geoip_country_ranges` table.

First apply migrations:

```powershell
php catalog/bin/migrate.php migrate
```

### DB-IP City Lite

For the existing DB-IP workflow, use the monthly **IP to City Lite CSV** file (`dbip-city-lite-YYYY-MM.csv.gz`) directly:

```powershell
php catalog/bin/import-geoip-city-dbip.php "C:\path\to\dbip-city-lite-YYYY-MM.csv.gz"
```

The DB-IP City Lite format supplies IP start/end, country, state/province, city, latitude and longitude.

### MaxMind GeoLite2 / GeoIP2 City

Alternatively, extract the MaxMind City CSV bundle containing:

- `GeoLite2-City-Blocks-IPv4.csv` / `GeoIP2-City-Blocks-IPv4.csv`
- `GeoLite2-City-Blocks-IPv6.csv` / `GeoIP2-City-Blocks-IPv6.csv`
- `GeoLite2-City-Locations-en.csv` / `GeoIP2-City-Locations-en.csv`

Then run:

```powershell
php catalog/bin/import-geoip-city-maxmind.php "C:\path\to\GeoLite2-City-CSV_YYYYMMDD"
```

Both importers build a staging table and atomically swap it into service only after the full dataset has loaded. Existing country-only datasets remain supported; when detailed coordinates are unavailable, maps fall back to the country's main map component.

GeoIP coordinates are approximate. Tooltips include city/region when available and an accuracy radius when the selected dataset supplies one.

