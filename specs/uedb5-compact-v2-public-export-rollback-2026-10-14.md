# Compact v2 public-export and rollback checks (2026-10-14)

**Production SQL was read-only.** Both new tests used only session-scoped InnoDB TEMPORARY tables, dropped upon completion.

## Public-export availability

A bounded indexed probe examined 350 verified V5 files (50 each across game IDs 2, 3, 4, 5, 6, 7, 12), distributed through file ID ranges. No sampled object row contained a non-null `public_export_hash`. This is not proof of complete absence: no full table scan was performed.

The production projection builder returns a binary 8-byte `public_export_hash` only for valid nonzero 16-character hexadecimal source values; missing/zero produces SQL NULL.

## Controlled source-backed fixtures

`catalog/bin/verify-uedb5-compact-v2-public-export-fixtures.php` injects known non-null valid public hashes into **in-memory copies** of seven real V5 source snapshots, keeping original object identities. It derives canonical V5 candidate rows and compares a legacy-shaped temporary table with the compact v2 lookup adapter.

Result: **7/7 games, 14/14 query comparisons, 24 matching export rows, zero mismatches**. Duplicate hashes and index ordering are covered. These are controlled fixtures, not a direct comparison with populated production public-export rows.

## Failure atomicity

`catalog/bin/verify-uedb5-compact-v2-rollback-fault.php` deliberately adds a rejecting CHECK constraint to the temporary compact objects table. Publishing must fail after earlier dictionary and name inserts. It verifies that the publisher's own transaction is rolled back and **all three temporary tables remain empty**.

Result: **7/7 games**, no surviving partial rows, no errors.

## Still outstanding

- Production public-export identity parity on real non-null rows whenever such V5 objects are registered.
- Multi-connection contention tests, the actual permanent foreign-key constraints, and transactional dual-publish lifecycle integration.
- Resumable candidate backfill, read-path switch, full semantic regression and rollback plan before deleting large old tables.
- No disk savings can be claimed yet. No separate database was set up.
