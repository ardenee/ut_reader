# Future database migrations

The schema is consolidated into `catalog/install.sql` through baseline
`202608090002`.

Add only new immutable PHP migrations with a version greater than that
baseline. Existing installations retain historical rows in
`ue_schema_migrations`; the runner treats baseline rows as archived.

## Applied migrations are byte-immutable

Once a migration has been applied to any installation, do not edit that file
again for any reason. This includes comments, audit headers, formatting,
whitespace, line-ending normalization, descriptions, or SQL/PHP changes.
`MigrationRunner` stores a SHA-256 checksum of the complete file and will stop
future migrations if the file changes.

Any follow-up schema or data change must be implemented as a new migration with
a new version. Documentation/audit tooling must exclude already-applied
migration files from cosmetic rewrites.
