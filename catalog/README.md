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

It also provides workflows for uploads, unverified-file review, duplicate/alias handling, dependency repair, Full Sync, source-identity repair, PAK/UPK management, Game Backups, federation and generated download packages.

## Install

1. Create a MySQL-compatible database.
2. Import `catalog/install.sql` into the new empty database.
3. Copy `catalog/config.example.php` to `catalog/config.php`.
4. Configure database credentials, storage paths and required application settings.
5. Run `php catalog/bin/migrate.php migrate` from a trusted shell.
6. Run `php catalog/bin/migrate.php verify`.
7. Ensure `catalog/storage/` and its required subdirectories are writable by the PHP/web-server and worker identities.
8. Create the initial administrator with `php catalog/bin/create-admin.php --username=admin`.
9. Start/reconcile the background worker pool.
10. Open `catalog/index.php` and sign in.

For an existing database, **do not re-import `install.sql`**. Back up the database and package storage, then use the migration runner:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

See [`migrations/README.md`](migrations/README.md) and [`../docs/database-migrations.md`](../docs/database-migrations.md).

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
php catalog/bin/verify-solo-maintainer-hardening.php --run
```

## Production and recovery documentation

See:

- [`../docs/production-deployment.md`](../docs/production-deployment.md)
- [`../docs/background-jobs.md`](../docs/background-jobs.md)
- [`../docs/catalog-architecture.md`](../docs/catalog-architecture.md)

Backup/restore tooling is maintained under [`../deploy/backup`](../deploy/backup).
