# UEDB5 compact candidate permanent-schema checkpoint (2026-10-13)

Added `catalog/sql/uedb5-compact-candidate-v2-schema.sql` as an **opt-in schema proposal**, not part of `catalog/install.sql` and not applied to production. Defines `ue_uedb5_compact_name_dictionary` with SHA-256 uniqueness, `ue_uedb5_compact_name_candidates` with file/name primary key, and `ue_uedb5_compact_object_candidates` with file/kind/index primary key. Both candidate tables reference V5 files and dictionary IDs and retain their respective lookup and public-export indexes.

Added `catalog/bin/verify-uedb5-compact-schema-temp.php`. It derives three session-only temporary tables from the actual SQL DDL, omitting foreign keys because MySQL does not support them in temporary tables, and verifies required indexes through SHOW INDEX. PHP lint and test on PRD MySQL 8.4.11 both passed: 3/3 tables, all required indexes. No persistent SQL changes or whole-catalogue scans.

**Limitations:** This does not validate actual foreign-key creation, persistent InnoDB physical space, concurrency, real publishing, rollback across sessions, search routing, or backfill. The production V5 readers and writers still use the existing candidate schema and must not be switched until full parity and resumable rollback are demonstrated. No separate database is required for these temporary-table checks, as requested.
