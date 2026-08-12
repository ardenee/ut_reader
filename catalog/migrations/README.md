# Future database migrations

The consolidated base schema in `catalog/install.sql` currently represents baseline `202608090002`.

New immutable migrations with a version greater than that baseline live in this directory. A fresh/current deployment must load the base schema and then run the migration runner so these post-baseline changes are applied.

Current post-baseline migrations are:

- `202608110001_unverified_game_match_cache.php` — creates the cached per-unverified-file exact game/dependency match projection used by background refresh and fast Unverified page reads.
- `202608120001_job_workflow_recovery_logging.php` — adds durable parent/child workflow identity (`parent_job_id`, `workflow_unit_key`) and job-event logging settings used by resumable/recoverable background workflows.

Apply them with:

```bash
php catalog/bin/migrate.php migrate
```

For the current code, `202608120001` must be applied **before starting/restarting workers that can create child workflow jobs**. The recovery architecture relies on the parent/unit uniqueness constraint to make coordinator replay idempotent.

The workflow/schema prerequisites can be checked read-only with:

```bash
php catalog/bin/verify-resumable-job-workflows.php --database
```

Existing installations retain historical migration rows in `ue_schema_migrations`; the runner treats migrations consolidated into the baseline as archived history.

## Applied migrations are byte-immutable

Once a migration has been applied to any installation, do not edit that file again for any reason. This includes comments, audit headers, formatting, whitespace, line-ending normalization, descriptions or SQL/PHP changes.

`MigrationRunner` stores a SHA-256 checksum of the complete migration file and will stop future migrations if an applied file changes.

Any follow-up schema/data change must therefore use a **new migration version**. Documentation/audit tooling must exclude already-applied migration files from cosmetic rewrites.

## Relationship to `install.sql`

Do not silently copy the contents of an active post-baseline migration into `install.sql` while deployed databases still depend on that migration/version as the upgrade boundary. When a future schema consolidation deliberately advances the baseline, consolidate migrations as one coordinated change and update this README at the same time.
