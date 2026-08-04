# Future database migrations

The schema was consolidated into `catalog/install.sql` through baseline
`202608030001`.

Add only new immutable PHP migrations with a version greater than that
baseline. Existing installations retain historical rows in
`ue_schema_migrations`; the runner treats baseline rows as archived.
