# UEDB5 object dictionary collision-safety checkpoint

> **Historical CLI references:** Some commands and utility filenames quoted in this dated record were retired or renamed during V5 cutover. They are provenance only and **must not be used as current maintenance instructions**. Use the supported commands in [current project status](../docs/current-project-status.md) and the [V5 lifecycle evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md).


> **Current production status (15 October 2026):** UnrealDB uses **UEDB5-only** metadata registration and runtime. All **235,239** verified packages have validated format-5 registration; the bounded production verifier and **17/17** current regressions passed. The canonical live metadata tree contains **235,239 `.uedb5` files and no V1-V4 containers**. Actual upload, stable-ID reimport, backup restore, and non-deferred deletion/reconciliation were successfully exercised across all seven games against isolated MySQL; the results do not imply a new exhaustive production-package parse. Historical V4 staging/candidate statements below are **not current operational instructions**. See [the maintained V5 lifecycle evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md).


## Measured production sample

On 2026-10-13, the bounded `catalog/bin/audit-uedb5-object-dictionary-identity.php` command ran on PRD. It selected 10,000 `ue_uedb5_object_candidates` rows from fourteen indexed file IDs and joined the distinct object-name hash/length identities to the current `ue_uedb5_search_keys` dictionary. Its session-local temporary tables were dropped.

| Measure | Value |
|---|---:|
| Object candidates | 10,000 |
| Distinct hash/length pairs | 4,674 |
| Dictionary keys absent | 0 |
| Distinct SHA-256 fingerprints per hash/length greater than one | 0 |
| Rows mapped to one dictionary fingerprint | 10,000 |
| Matching fingerprint dictionary joins | 10,000 |

This proves **sample feasibility only**, not catalogue-wide uniqueness and not proof that MD5 plus string length is collision-free.

## Mandatory collision-safe production design

Do not treat `(MD5(normalized_name), byte_length)` as a collision-proof primary key. The global name dictionary retains `normalized_text` and `key_fingerprint=BINARY(32)` so every candidate dictionary entry can be resolved by full normalized bytes. If an object candidate produces more than one eligible fingerprint, **do not take MIN(fingerprint) or MIN(name_id) as its identity**. Instead hydrate the object name from the authoritative UEDB5 row, reapply the same normalization, and select the exact dictionary identity. If the match cannot be proved, fail the projection/rebuild and retain the current production schema.

Name-candidate rows already have SHA-256 fingerprints; object candidates do not. A transition to a compact shared name ID must therefore derive the object dictionary ID at publication time from source-backed V5 data rather than trusting a hash-only join. Existing search and VerifyImport behavior must remain unchanged.

## Measurement and migration requirements

The current 10,000-row test and 120 previous exact-lookup comparisons had no mismatches. They did not measure InnoDB physical storage for the redesigned tables: MySQL session `TEMPORARY TABLE` storage is not reliably reported as individual `information_schema.tables` records. No guaranteed database savings can be asserted yet.

Before any production switch, use a **separate isolated MySQL database** for representative persistent shadow tables so `DATA_LENGTH`, `INDEX_LENGTH`, index plans and benchmark timings can be measured; rebuild only a bounded corpus, not all 676 million candidate rows. Create explicit artificial collisions in that test database, and prove the new publisher resolves from UEDB5 or refuses ambiguous identities. Validate name/object candidate search, duplicate providers, dependency decisions and source-package parity before rollout. Require independent recovery and rollback capability, enough disk headroom and a resumable migration. Current production tables/indexes remain untouched.
